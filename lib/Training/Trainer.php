<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Training;

use Bitrix\Main\Application;
use Bitrix\Main\Diag\EventLogger;
use Bitrix\Main\Type\DateTime;
use Dzahdev\Recom\Config;
use Dzahdev\Recom\Engine\Als;
use Dzahdev\Recom\Engine\AlsModel;
use Dzahdev\Recom\Engine\Dataset;
use Dzahdev\Recom\Engine\FeatureIndex;
use Dzahdev\Recom\Engine\Fm;
use Dzahdev\Recom\Engine\FmSamples;
use Dzahdev\Recom\Engine\ItemSimilarity;
use Dzahdev\Recom\Engine\Ndcg;
use Dzahdev\Recom\Engine\PersonalRanker;
use Dzahdev\Recom\Engine\Popular;
use Dzahdev\Recom\Modules;
use Dzahdev\Recom\Orm\FactorTable;
use Dzahdev\Recom\Orm\TrainLogTable;
use Psr\Log\LoggerInterface;

final class Trainer
{
	public const MODE_FULL = 'full';
	public const MODE_INCREMENTAL = 'incremental';

	private const LOCK = 'dzahdev_recom_train';
	private const SOURCE_ALS = 'als';
	private const SOURCE_FM = 'fm';

	public function __construct(
		private readonly DatasetLoader $loader,
		private readonly ModelStore $store,
		private readonly LoggerInterface $logger,
	)
	{
	}

	public static function create(): self
	{
		Modules::require('iblock');

		return new self(new DatasetLoader(), new ModelStore(), new EventLogger(Config::MODULE_ID, 'DZAHDEV_RECOM_TRAIN'));
	}

	/**
	 * @throws \RuntimeException если обучение уже идет; любая ошибка обучения пробрасывается после записи в журналы
	 */
	public function run(string $mode): TrainResult
	{
		if ($mode !== self::MODE_FULL && $mode !== self::MODE_INCREMENTAL)
		{
			throw new \InvalidArgumentException("Unknown training mode {$mode}");
		}

		$connection = Application::getConnection();
		if (!$connection->lock(self::LOCK))
		{
			$this->logger->warning('Training skipped: another run holds the lock');
			throw new \RuntimeException('Training is already running');
		}

		$startedAt = new DateTime();
		$start = microtime(true);
		try
		{
			$result = $this->train($mode, $startedAt, $start);
			$this->writeLog($result, null);

			return $result;
		}
		catch (\Throwable $e)
		{
			$this->logger->error('Training failed: {message} in {file}:{line}', [
				'message' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
			]);
			// Если упадет и запись журнала обучения, наружу уйдет ее исключение; исходное уже в журнале событий.
			$this->writeLog(new TrainResult($mode, $startedAt, microtime(true) - $start, 0, 0, 0, null, null), $e->getMessage());
			throw $e;
		}
		finally
		{
			$connection->unlock(self::LOCK);
		}
	}

	private function train(string $mode, DateTime $startedAt, float $start): TrainResult
	{
		$full = $mode === self::MODE_FULL;
		$types = Config::positiveTypes();
		$weights = [];
		foreach ($types as $type)
		{
			$weights[$type] = Config::weight($type);
		}

		$data = $this->loader->loadPairs($types, $full);
		$dataset = Dataset::fromPairs($data['pairs']);

		$als = new Als(Config::int('als_factors'), Config::float('als_reg'), Config::float('als_alpha'), 0.1);
		$model = $full
			? $als->fit($dataset, Config::int('als_epochs_full'))
			: $als->fit(
				$dataset,
				Config::int('als_epochs_incremental'),
				$this->store->loadFactors(FactorTable::USER),
				$this->store->loadFactors(FactorTable::ITEM)
			);
		$holdout = array_slice($data['holdout'], 0, Config::int('eval_users'), true);
		$alsScore = static fn(int $u, int $i): float => AlsModel::dot($model->userVector($u), $model->itemVector($i));
		$ndcg = $full ? Ndcg::evaluate($dataset, $alsScore, $holdout, 10) : null;

		$sims = (new ItemSimilarity(Config::int('similar_limit'), 100, Config::int('max_user_items_similarity')))->compute($dataset);
		$popular = Popular::compute(
			$this->loader->loadPopularRows($types, $weights, Config::int('popular_window_days')),
			Config::int('popular_limit')
		);
		// Раздела 0 нет, если за окно не было ни одного сигнала: популярных кандидатов тогда нет.
		$score = $alsScore;
		$source = self::SOURCE_ALS;
		$ndcgFm = null;
		if (Config::string('fm_enabled') === 'Y')
		{
			$sections = $this->loader->itemSections($dataset->itemIds());
			$index = new FeatureIndex();
			$samples = FmSamples::build($dataset, $sections, Config::int('fm_negatives'), $index);
			$fm = (new Fm(Config::int('fm_factors'), Config::float('fm_learning_rate'), Config::float('fm_reg'), 0.01))
				->fit($samples, $index->count(), Config::int('fm_epochs'));
			// Товар без раздела или удаленный из каталога оценивается без признака раздела.
			$score = static fn(int $u, int $i): float => $fm->predict(FmSamples::features(
				$index,
				$dataset->userId($u),
				$dataset->itemId($i),
				$sections[$dataset->itemId($i)] ?? 0
			));
			$ndcgFm = $full ? Ndcg::evaluate($dataset, $score, $holdout, 10) : null;
			$source = self::SOURCE_FM;
		}

		$recs = $this->personalRecs($dataset, $score, $sims, $popular[0] ?? [], $data['holdout']);

		$this->store->replaceFactors($model->userFactorsById(), $model->itemFactorsById());
		$this->store->replaceItemSims($sims);
		$this->store->replacePopular($popular);
		$this->store->replaceUserRecs($recs, $source);

		if ($full)
		{
			Config::set('last_full_train_at', (string)time());
			Config::set('full_train_requested', 'N');
		}

		return new TrainResult(
			$mode,
			$startedAt,
			microtime(true) - $start,
			$dataset->countUsers(),
			$dataset->countItems(),
			$dataset->countPairs(),
			$ndcg,
			$ndcgFm
		);
	}

	/**
	 * Подборки для покупателей с событиями за active_user_days и не меньше min_user_events товаров.
	 * Остальным на странице отвечает цепочка выдачи (похожие на последние товары, затем популярное).
	 *
	 * @param callable(int, int): float $score оценка модели по индексам пользователя и товара в $dataset
	 * @param array<int, array<int, float>> $sims
	 * @param array<int, float> $popularAll
	 * @param array<int, int> $holdout товар, отложенный для оценки при полном обучении: покупатель с ним
	 *     уже взаимодействовал, поэтому он исключается из подборки так же, как обучающие товары
	 * @return array<int, array<int, float>>
	 */
	private function personalRecs(Dataset $dataset, callable $score, array $sims, array $popularAll, array $holdout): array
	{
		$ranker = new PersonalRanker(Config::int('rec_limit'));
		$minEvents = Config::int('min_user_events');
		$popularIds = array_keys($popularAll);

		$recs = [];
		foreach ($this->loader->activeUsers(Config::int('active_user_days')) as $fuserId)
		{
			$u = $dataset->userIndexOf($fuserId);
			if ($u === null)
			{
				// Активен только событиями, которые не входят в положительные типы.
				continue;
			}

			$items = $dataset->userItems($u);
			if (count($items) < $minEvents)
			{
				continue;
			}

			$seen = isset($holdout[$fuserId]) ? [$holdout[$fuserId] => true] : [];
			$candidates = $popularIds;
			foreach ($items as $i)
			{
				$itemId = $dataset->itemId($i);
				$seen[$itemId] = true;
				foreach (array_keys($sims[$itemId]) as $neighborId)
				{
					$candidates[] = $neighborId;
				}
			}

			$recs[$fuserId] = $ranker->rank(
				static function (int $itemId) use ($dataset, $score, $u): ?float {
					$i = $dataset->itemIndexOf($itemId);

					return $i === null ? null : $score($u, $i);
				},
				$candidates,
				$seen
			);
		}

		return $recs;
	}

	private function writeLog(TrainResult $result, ?string $error): void
	{
		$saved = TrainLogTable::add([
			'MODE' => $result->mode,
			'STARTED_AT' => $result->startedAt,
			'DURATION_SEC' => round($result->durationSec, 3),
			'USERS_COUNT' => $result->users,
			'ITEMS_COUNT' => $result->items,
			'PAIRS_COUNT' => $result->pairs,
			'NDCG' => $result->ndcg,
			'NDCG_FM' => $result->ndcgFm,
			'ERROR' => $error,
		]);
		if (!$saved->isSuccess())
		{
			throw new \RuntimeException('Cannot write training log: ' . implode('; ', $saved->getErrorMessages()));
		}
	}
}
