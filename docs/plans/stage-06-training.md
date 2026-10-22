# Этап 6. Обучение: загрузка данных, хранение моделей, команда и агенты

**Результат:** `php local/modules/dzahdev.recom/tools/train.php [--full]` (и Symfony-команда
`dzahdev.recom:train` на сайтах с composer) обучает ALS, считает похожие
товары, популярное и готовые подборки, сохраняет их в таблицы и пишет прогон в журнал обучения.
Агент обучения работает на cron, агент очистки удаляет старые события.

**Файлы:**
- Создать: `lib/Training/DatasetLoader.php`, `lib/Training/ModelStore.php`,
  `lib/Training/TrainResult.php`, `lib/Training/Trainer.php`
- Создать: `tools/train.php`, `lib/Cli/TrainCommand.php`, `lib/Agent/TrainAgent.php`, `lib/Agent/CleanupAgent.php`
- Изменить: `.settings.php` (секция `console`), `install/index.php` (`getAgents()`)

**Интерфейсы:**
- Потребляет: `Config`, ORM-таблицы (этап 1); `Dataset`, `VectorCodec`, `Als`, `AlsModel`, `Ndcg`,
  `ItemSimilarity`, `Popular`, `PersonalRanker` (этапы 2-4); `EventType` (этап 5).
- Производит:
  - `DatasetLoader::loadPairs(list<string> $types, bool $withHoldout): array{pairs: list<array{0:int,1:int}>, holdout: array<int,int>}`,
    `loadPopularRows(list<string> $types, array<string,float> $weights, int $windowDays): list<array{0:int,1:int,2:float}>`,
    `activeUsers(int $days): list<int>`,
    `recentProducts(int $fuserId, array<string,float> $weights, int $limit): array<int,float>`,
    `boughtProducts(int $fuserId): list<int>`,
    `itemSections(list<int> $productIds): array<int,int>` (ID товара => ID основного раздела).
  - `ModelStore::loadFactors(string $entityType): array<int, list<float>>`,
    `replaceFactors(array $users, array $items): void`, `replaceItemSims(array): void`,
    `replacePopular(array): void`, `replaceUserRecs(array, string $source): void`,
    `loadUserRec(int $fuserId): array<int,float>`, `loadItemSims(list<int>): array<int, array<int,float>>`,
    `loadPopular(int $sectionId): array<int,float>`.
  - `Trainer::create(): Trainer`, `run(string $mode): TrainResult`, константы
    `Trainer::MODE_FULL = 'full'`, `Trainer::MODE_INCREMENTAL = 'incremental'`.
  - `TrainResult` - публичные readonly `mode`, `startedAt`, `durationSec`, `users`, `items`, `pairs`,
    `ndcg` (ALS), `ndcgFm` (FM, на этом этапе всегда `null`, заполняется на этапе 7).
  - Внутри `Trainer` ранжирование подборок получает функцию оценки
    `callable(int $userIndex, int $itemIndex): float`; этап 7 подставляет вместо ALS оценку FM.

**Уточнение спецификации (раздел 6).** Похожие товары, популярное и готовые подборки
пересчитываются при каждом прогоне, а не только при полном: на синтетике этапов 3-4 это занимает
секунды, а подборки остаются свежими после каждого дообучения. Полное и дообучение различаются
стартом ALS, числом эпох и расчетом NDCG@10.

---

- [ ] **Шаг 1. Загрузка данных**

`lib/Training/DatasetLoader.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Training;

use Bitrix\Iblock\ElementTable;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\Type\DateTime;
use Dzahdev\Recom\Orm\EventTable;
use Dzahdev\Recom\Tracking\EventType;

/**
 * Чтение событий для обучения и выдачи через ORM.
 */
final class DatasetLoader
{
	/**
	 * Положительные пары "покупатель - товар" и отложенная выборка: у покупателя с двумя и более
	 * товарами откладывается последний по времени.
	 *
	 * @param list<string> $types
	 * @return array{pairs: list<array{0: int, 1: int}>, holdout: array<int, int>}
	 */
	public function loadPairs(array $types, bool $withHoldout): array
	{
		$rows = EventTable::getList([
			'select' => ['FUSER_ID', 'PRODUCT_ID', 'LAST_AT'],
			'filter' => ['@TYPE' => $types],
			'group' => ['FUSER_ID', 'PRODUCT_ID'],
			'runtime' => [
				(new ExpressionField('LAST_AT', 'MAX(%s)', ['CREATED_AT']))->configureValueType(DatetimeField::class),
			],
		]);

		$byUser = [];
		while ($row = $rows->fetch())
		{
			$byUser[(int)$row['FUSER_ID']][(int)$row['PRODUCT_ID']] = $row['LAST_AT']->getTimestamp();
		}

		$pairs = [];
		$holdout = [];
		foreach ($byUser as $fuserId => $items)
		{
			if ($withHoldout && count($items) >= 2)
			{
				arsort($items);
				$heldOut = array_key_first($items);
				$holdout[$fuserId] = $heldOut;
				unset($items[$heldOut]);
			}
			foreach ($items as $productId => $_)
			{
				$pairs[] = [$fuserId, $productId];
			}
		}

		return ['pairs' => $pairs, 'holdout' => $holdout];
	}

	/**
	 * @param list<string> $types
	 * @param array<string, float> $weights тип события => вес
	 * @return list<array{0: int, 1: int, 2: float}> (ID товара, ID раздела или 0, сумма весов за окно)
	 */
	public function loadPopularRows(array $types, array $weights, int $windowDays): array
	{
		$rows = EventTable::getList([
			'select' => ['PRODUCT_ID', 'TYPE', 'CNT'],
			'filter' => ['@TYPE' => $types, '>=CREATED_AT' => (new DateTime())->add("-{$windowDays} days")],
			'group' => ['PRODUCT_ID', 'TYPE'],
			'runtime' => [
				(new ExpressionField('CNT', 'COUNT(*)'))->configureValueType(IntegerField::class),
			],
		]);

		$scores = [];
		while ($row = $rows->fetch())
		{
			$productId = (int)$row['PRODUCT_ID'];
			$scores[$productId] = ($scores[$productId] ?? 0.0) + (int)$row['CNT'] * $weights[$row['TYPE']];
		}

		$sections = $this->itemSections(array_keys($scores));
		$result = [];
		foreach ($scores as $productId => $score)
		{
			// Товар без раздела или уже удаленный участвует только в общем рейтинге (раздел 0).
			$result[] = [$productId, $sections[$productId] ?? 0, $score];
		}

		return $result;
	}

	/**
	 * @return list<int> FUSER_ID с событиями за последние $days дней
	 */
	public function activeUsers(int $days): array
	{
		$rows = EventTable::getList([
			'select' => ['FUSER_ID'],
			'filter' => ['>=CREATED_AT' => (new DateTime())->add("-{$days} days")],
			'group' => ['FUSER_ID'],
		]);

		$result = [];
		while ($row = $rows->fetch())
		{
			$result[] = (int)$row['FUSER_ID'];
		}

		return $result;
	}

	/**
	 * Последние товары покупателя: сумма весов событий по товару, при равенстве - более свежие выше.
	 *
	 * @param array<string, float> $weights тип события => вес; типы вне массива не учитываются
	 * @return array<int, float> ID товара => сумма весов
	 */
	public function recentProducts(int $fuserId, array $weights, int $limit): array
	{
		$rows = EventTable::getList([
			'select' => ['PRODUCT_ID', 'TYPE'],
			'filter' => ['=FUSER_ID' => $fuserId, '@TYPE' => array_keys($weights)],
			'order' => ['CREATED_AT' => 'DESC', 'ID' => 'DESC'],
			'limit' => 100,
		]);

		$scores = [];
		while ($row = $rows->fetch())
		{
			$productId = (int)$row['PRODUCT_ID'];
			$scores[$productId] = ($scores[$productId] ?? 0.0) + $weights[$row['TYPE']];
		}
		arsort($scores);

		return array_slice($scores, 0, $limit, true);
	}

	/**
	 * @return list<int>
	 */
	public function boughtProducts(int $fuserId): array
	{
		$rows = EventTable::getList([
			'select' => ['PRODUCT_ID'],
			'filter' => ['=FUSER_ID' => $fuserId, '=TYPE' => EventType::BUY],
			'group' => ['PRODUCT_ID'],
		]);

		$result = [];
		while ($row = $rows->fetch())
		{
			$result[] = (int)$row['PRODUCT_ID'];
		}

		return $result;
	}

	/**
	 * @param list<int> $productIds
	 * @return array<int, int> ID товара => ID основного раздела
	 */
	public function itemSections(array $productIds): array
	{
		$result = [];
		foreach (array_chunk($productIds, 500) as $chunk)
		{
			$rows = ElementTable::getList([
				'select' => ['ID', 'IBLOCK_SECTION_ID'],
				'filter' => ['@ID' => $chunk],
			]);
			while ($row = $rows->fetch())
			{
				$result[(int)$row['ID']] = (int)$row['IBLOCK_SECTION_ID'];
			}
		}

		return $result;
	}
}
```

- [ ] **Шаг 2. Хранение моделей**

`lib/Training/ModelStore.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Training;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Dzahdev\Recom\Engine\VectorCodec;
use Dzahdev\Recom\Orm\FactorTable;
use Dzahdev\Recom\Orm\ItemSimTable;
use Dzahdev\Recom\Orm\PopularTable;
use Dzahdev\Recom\Orm\UserRecTable;

/**
 * Чтение и атомарная замена обученных данных. Векторы и списки хранятся как base64 от VectorCodec.
 */
final class ModelStore
{
	/**
	 * @param string $entityType FactorTable::USER или FactorTable::ITEM
	 * @return array<int, list<float>> ID => вектор
	 */
	public function loadFactors(string $entityType): array
	{
		$rows = FactorTable::getList([
			'select' => ['ENTITY_ID', 'VECTOR'],
			'filter' => ['=ENTITY_TYPE' => $entityType],
		]);

		$result = [];
		while ($row = $rows->fetch())
		{
			$result[(int)$row['ENTITY_ID']] = VectorCodec::unpackVector(self::decode($row['VECTOR']));
		}

		return $result;
	}

	/**
	 * @param array<int, list<float>> $users
	 * @param array<int, list<float>> $items
	 */
	public function replaceFactors(array $users, array $items): void
	{
		$rows = [];
		foreach ($users as $id => $vector)
		{
			$rows[] = ['ENTITY_TYPE' => FactorTable::USER, 'ENTITY_ID' => $id, 'VECTOR' => base64_encode(VectorCodec::packVector($vector))];
		}
		foreach ($items as $id => $vector)
		{
			$rows[] = ['ENTITY_TYPE' => FactorTable::ITEM, 'ENTITY_ID' => $id, 'VECTOR' => base64_encode(VectorCodec::packVector($vector))];
		}

		$this->replace(FactorTable::getTableName(), $rows);
	}

	/**
	 * @param array<int, array<int, float>> $sims ID товара => [ID похожего => оценка]
	 */
	public function replaceItemSims(array $sims): void
	{
		$rows = [];
		foreach ($sims as $productId => $scores)
		{
			$rows[] = ['PRODUCT_ID' => $productId, 'ITEMS' => base64_encode(VectorCodec::packScores($scores))];
		}

		$this->replace(ItemSimTable::getTableName(), $rows);
	}

	/**
	 * @param array<int, array<int, float>> $popular ID раздела => [ID товара => оценка]
	 */
	public function replacePopular(array $popular): void
	{
		$rows = [];
		foreach ($popular as $sectionId => $scores)
		{
			$rows[] = ['SECTION_ID' => $sectionId, 'ITEMS' => base64_encode(VectorCodec::packScores($scores))];
		}

		$this->replace(PopularTable::getTableName(), $rows);
	}

	/**
	 * @param array<int, array<int, float>> $recs FUSER_ID => [ID товара => оценка]
	 */
	public function replaceUserRecs(array $recs, string $source): void
	{
		$now = new DateTime();
		$rows = [];
		foreach ($recs as $fuserId => $scores)
		{
			$rows[] = ['FUSER_ID' => $fuserId, 'ITEMS' => base64_encode(VectorCodec::packScores($scores)), 'SOURCE' => $source, 'UPDATED_AT' => $now];
		}

		$this->replace(UserRecTable::getTableName(), $rows);
	}

	/**
	 * @return array<int, float> пусто, если подборки для покупателя нет
	 */
	public function loadUserRec(int $fuserId): array
	{
		$row = UserRecTable::getRow(['select' => ['ITEMS'], 'filter' => ['=FUSER_ID' => $fuserId]]);

		return $row === null ? [] : VectorCodec::unpackScores(self::decode($row['ITEMS']));
	}

	/**
	 * @param list<int> $productIds
	 * @return array<int, array<int, float>> только товары, известные модели
	 */
	public function loadItemSims(array $productIds): array
	{
		if ($productIds === [])
		{
			return [];
		}

		$rows = ItemSimTable::getList(['select' => ['PRODUCT_ID', 'ITEMS'], 'filter' => ['@PRODUCT_ID' => $productIds]]);
		$result = [];
		while ($row = $rows->fetch())
		{
			$result[(int)$row['PRODUCT_ID']] = VectorCodec::unpackScores(self::decode($row['ITEMS']));
		}

		return $result;
	}

	/**
	 * @return array<int, float> пусто, если по разделу не было сигналов
	 */
	public function loadPopular(int $sectionId): array
	{
		$row = PopularTable::getRow(['select' => ['ITEMS'], 'filter' => ['=SECTION_ID' => $sectionId]]);

		return $row === null ? [] : VectorCodec::unpackScores(self::decode($row['ITEMS']));
	}

	/**
	 * Замена содержимого таблицы в одной транзакции: выдача видит либо старую, либо новую модель.
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	private function replace(string $tableName, array $rows): void
	{
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$connection->queryExecute('DELETE FROM ' . $connection->getSqlHelper()->quote($tableName));
			foreach (array_chunk($rows, 500) as $chunk)
			{
				$connection->addMulti($tableName, $chunk);
			}
			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();
			throw $e;
		}
	}

	private static function decode(string $value): string
	{
		$binary = base64_decode($value, true);
		if ($binary === false)
		{
			throw new \UnexpectedValueException('Stored model value is not valid base64');
		}

		return $binary;
	}
}
```

- [ ] **Шаг 3. Результат и обучение**

`lib/Training/TrainResult.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Training;

use Bitrix\Main\Type\DateTime;

final class TrainResult
{
	public function __construct(
		public readonly string $mode,
		public readonly DateTime $startedAt,
		public readonly float $durationSec,
		public readonly int $users,
		public readonly int $items,
		public readonly int $pairs,
		public readonly ?float $ndcg,
		public readonly ?float $ndcgFm,
	)
	{
	}

	/**
	 * Строка итога для консольного скрипта и Symfony-команды.
	 */
	public function summary(): string
	{
		return sprintf(
			'%s training: %d users, %d items, %d pairs, %.1fs, NDCG@10 ALS %s, FM %s',
			$this->mode,
			$this->users,
			$this->items,
			$this->pairs,
			$this->durationSec,
			$this->ndcg === null ? 'not evaluated' : sprintf('%.4f', $this->ndcg),
			$this->ndcgFm === null ? 'not evaluated' : sprintf('%.4f', $this->ndcgFm)
		);
	}
}
```

`lib/Training/Trainer.php`:

```php
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
		$recs = $this->personalRecs($dataset, $alsScore, $sims, $popular[0] ?? [], $data['holdout']);

		$this->store->replaceFactors($model->userFactorsById(), $model->itemFactorsById());
		$this->store->replaceItemSims($sims);
		$this->store->replacePopular($popular);
		$this->store->replaceUserRecs($recs, self::SOURCE_ALS);

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
			null
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
```

- [ ] **Шаг 4. Консольная команда**

`tools/train.php` - основной запуск из консоли, без composer:

`tools/train.php`:

```php
<?php

/**
 * Обучение моделей из консоли без Symfony Console и composer:
 * php <корень сайта>/local/modules/dzahdev.recom/tools/train.php [--full]
 * Код выхода 0 - обучение прошло, 1 - ошибка (текст в stderr, подробности в журнале обучения).
 */

declare(strict_types=1);

use Bitrix\Main\Loader;
use Dzahdev\Recom\Training\Trainer;

if (PHP_SAPI !== 'cli')
{
	http_response_code(404);
	exit(1);
}

// tools -> dzahdev.recom -> modules -> local или bitrix -> корень сайта
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 4);
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (!Loader::includeModule('dzahdev.recom'))
{
	fwrite(STDERR, "Module dzahdev.recom is not installed\n");
	exit(1);
}

// Обработчик исключений ядра печатает общее сообщение и завершает процесс с кодом 0, поэтому
// cron и CI не увидели бы сбой. Граница процесса - единственное место, где ошибку переводят в код выхода.
try
{
	$full = in_array('--full', $argv, true);
	$result = Trainer::create()->run($full ? Trainer::MODE_FULL : Trainer::MODE_INCREMENTAL);
}
catch (\Throwable $e)
{
	fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n");
	exit(1);
}

echo $result->summary(), "\n";
```

`lib/Cli/TrainCommand.php` - для сайтов с Symfony Console:

`lib/Cli/TrainCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Cli;

use Dzahdev\Recom\Training\Trainer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * php bitrix/bitrix.php dzahdev.recom:train [--full] - для сайтов с Symfony Console (composer).
 * Без composer тот же запуск - tools/train.php.
 * Ошибка обучения не перехватывается: консоль печатает ее и завершается с ненулевым кодом.
 */
class TrainCommand extends Command
{
	protected function configure(): void
	{
		$this
			->setName('dzahdev.recom:train')
			->setDescription('Train recommendation models of module dzahdev.recom')
			->addOption('full', null, InputOption::VALUE_NONE, 'Full training from scratch with NDCG@10 evaluation');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$result = Trainer::create()->run($input->getOption('full') ? Trainer::MODE_FULL : Trainer::MODE_INCREMENTAL);

		$output->writeln($result->summary());

		return 0;
	}
}
```

В `.settings.php` добавить секцию после `controllers`:

```php
	'console' => [
		'value' => [
			'commands' => [
				\Dzahdev\Recom\Cli\TrainCommand::class,
			],
		],
		'readonly' => true,
	],
```

- [ ] **Шаг 5. Агенты**

`lib/Agent/TrainAgent.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Agent;

use Bitrix\Main\Diag\EventLogger;
use Dzahdev\Recom\Config;
use Dzahdev\Recom\Training\Trainer;

final class TrainAgent
{
	public static function run(): string
	{
		$next = '\\' . self::class . '::run();';

		if (PHP_SAPI !== 'cli' && !(defined('BX_CRONTAB') && BX_CRONTAB === true))
		{
			(new EventLogger(Config::MODULE_ID, 'DZAHDEV_RECOM_TRAIN'))->warning(
				'Training skipped: agents run on page hits. Configure agents on cron or run php local/modules/dzahdev.recom/tools/train.php from the site root'
			);

			return $next;
		}

		$full = Config::string('full_train_requested') === 'Y'
			|| time() - Config::int('last_full_train_at') >= Config::int('train_full_interval');

		try
		{
			Trainer::create()->run($full ? Trainer::MODE_FULL : Trainer::MODE_INCREMENTAL);
		}
		catch (\Throwable)
		{
			// Trainer уже записал ошибку в журнал событий и журнал обучения. Агент остается
			// в расписании, выдача работает на последней успешной модели.
		}

		return $next;
	}
}
```

`lib/Agent/CleanupAgent.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Agent;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Dzahdev\Recom\Config;
use Dzahdev\Recom\Orm\EventTable;

final class CleanupAgent
{
	/**
	 * Удаляет события старше event_ttl_days одним запросом: построчное удаление через ORM
	 * на сотнях тысяч строк заняло бы агент надолго.
	 */
	public static function run(): string
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$border = (new DateTime())->add('-' . Config::int('event_ttl_days') . ' days');

		$connection->queryExecute(sprintf(
			'DELETE FROM %s WHERE %s < %s',
			$helper->quote(EventTable::getTableName()),
			$helper->quote('CREATED_AT'),
			$helper->convertToDbDateTime($border)
		));

		return '\\' . self::class . '::run();';
	}
}
```

В `install/index.php` добавить `use Dzahdev\Recom\Config;` и заменить `getAgents()`:

```php
	private function getAgents(): array
	{
		return [
			['\Dzahdev\Recom\Agent\TrainAgent::run();', Config::int('train_incremental_interval')],
			['\Dzahdev\Recom\Agent\CleanupAgent::run();', 86400],
		];
	}
```

`Config::int` читает значение из `default_option.php`; модуль к этому моменту уже подключен в
`InstallDB()`.

- [ ] **Шаг 6. Синтаксис, переустановка, агенты**

Синтаксис - как на этапе 5, шаг 6. Переустановить модуль в админке. Проверка:

```bash
docker exec bitrix_mysql mysql -ubitrix -pbitrix bitrix -N -e "select NAME, AGENT_INTERVAL, ACTIVE from b_agent where MODULE_ID='dzahdev.recom'" 2>/dev/null
```

Ожидается: 2 агента, интервалы 3600 и 86400, `ACTIVE = Y`.

- [ ] **Шаг 7. Синтетические события на стенде**

Переустановка удалила события этапа 5. Для осмысленного обучения - 200 тестовых покупателей
(FUSER_ID 900001-900200) с событиями по товарам демо-каталога (инфоблок 2), из двух групп разделов:

```bash
docker exec -i bitrix_mysql mysql -ubitrix -pbitrix bitrix 2>/dev/null <<'SQL'
SET SESSION cte_max_recursion_depth = 10000;
INSERT INTO b_dzahdev_recom_event (FUSER_ID, PRODUCT_ID, TYPE, SITE_ID, CREATED_AT)
SELECT 900000 + u.n,
       p.ID,
       ELT(1 + FLOOR(RAND(u.n * 1000 + p.ID) * 4), 'view', 'view', 'cart', 'buy'),
       's1',
       NOW() - INTERVAL FLOOR(RAND(p.ID * 7 + u.n) * 20) DAY
FROM (WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < 200) SELECT n FROM s) u
JOIN b_iblock_element p ON p.IBLOCK_ID = 2 AND p.ACTIVE = 'Y'
WHERE MOD(p.IBLOCK_SECTION_ID, 2) = MOD(u.n, 2) AND RAND(u.n * 31 + p.ID) < 0.35;
SELECT COUNT(*) AS events, COUNT(DISTINCT FUSER_ID) AS users, COUNT(DISTINCT PRODUCT_ID) AS items FROM b_dzahdev_recom_event;
SQL
```

Ожидается: сотни событий, около 200 покупателей, десятки товаров.

- [ ] **Шаг 8. Обучение из консоли**

```bash
docker exec -w /var/www/bitrix bitrix_php php local/modules/dzahdev.recom/tools/train.php --full
docker exec -w /var/www/bitrix bitrix_php php local/modules/dzahdev.recom/tools/train.php
docker exec bitrix_mysql mysql -ubitrix -pbitrix bitrix -e "
select MODE, DURATION_SEC, USERS_COUNT, ITEMS_COUNT, PAIRS_COUNT, NDCG, ERROR from b_dzahdev_recom_train_log order by ID;
select ENTITY_TYPE, count(*) from b_dzahdev_recom_factor group by ENTITY_TYPE;
select count(*) from b_dzahdev_recom_item_sim; select SECTION_ID from b_dzahdev_recom_popular; select count(*) from b_dzahdev_recom_user_rec;" 2>/dev/null
```

Ожидается: строка `full training: ... NDCG@10 ALS 0.xxxx, FM not evaluated` (FM появится на
этапе 7) и `incremental training: ... not evaluated`;
два прогона в журнале без ошибок; факторы `U` и `I`; похожие и популярное заполнены (раздел 0 и
разделы каталога); подборки есть у покупателей с 3+ товарами. NDCG@10 записать в артефакт этапа.

Symfony-команда `dzahdev.recom:train` появляется в `php bitrix/bitrix.php list` только на сайтах с
Symfony Console (composer); на стенде ее нет - это ожидаемо, основной запуск - `tools/train.php`.
Код выхода скрипта: 0 - успех, 1 - ошибка (сообщение в stderr).

- [ ] **Шаг 9. Блокировка и агент на хите**

Параллельный запуск:

```bash
docker exec -w /var/www/bitrix bitrix_php sh -c 'php local/modules/dzahdev.recom/tools/train.php --full & sleep 0.2; php local/modules/dzahdev.recom/tools/train.php; wait'
```

Ожидается: один прогон успешен, второй завершается ошибкой `Training is already running` с
ненулевым кодом. На хите: в админке "Настройки - Инструменты - Командная PHP-строка" выполнить
`\Dzahdev\Recom\Agent\TrainAgent::run();` (без `BX_CRONTAB`) - в журнале событий появляется
предупреждение `Training skipped: agents run on page hits`, новых прогонов в журнале обучения нет.

- [ ] **Шаг 10. Коммит**

```bash
cd /home/dzrise/projects/bitrix-modules/www/local/modules/dzahdev.recom
git add -A
git -c user.name="Denis Zakharov" -c user.email=dzahdev@gmail.com commit -m "Add training pipeline, console command and agents."
git push
```

**Критерии приемки:** шаги 6-9 дали ожидаемый результат; замена данных идет в транзакции
(проверено чтением `ModelStore::replace`); инварианты 2 (обучение только в CLI и cron), 5 и 6
соблюдены.
