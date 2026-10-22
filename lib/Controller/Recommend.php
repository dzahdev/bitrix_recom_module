<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Controller;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\Controller;
use Bitrix\Sale\Fuser;
use Dzahdev\Recom\Modules;
use Dzahdev\Recom\Recommend\Recommender;
use Dzahdev\Recom\Tracking\EventRecorder;
use Dzahdev\Recom\Tracking\EventType;

class Recommend extends Controller
{
	/**
	 * В AJAX-запросе ядро не подключает модули магазина: учет событий и выдача без них не работают.
	 */
	protected function init(): void
	{
		parent::init();

		Modules::require('iblock', 'catalog', 'sale');
	}

	public function configureActions(): array
	{
		// Гости тоже покупатели: действия доступны без авторизации, проверка sessid остается.
		return [
			'trackView' => ['-prefilters' => [Authentication::class]],
			'getList' => ['-prefilters' => [Authentication::class]],
		];
	}

	/**
	 * @return array{recorded: bool} false - элемент вне торгового каталога
	 */
	public function trackViewAction(int $productId): array
	{
		return [
			'recorded' => EventRecorder::create()->record((int)Fuser::getId(), $productId, EventType::VIEW, $this->siteId()),
		];
	}

	/**
	 * @return array{ids: list<int>, source: string}
	 * @throws ArgumentException для неизвестного режима или режима similar без товара
	 */
	public function getListAction(string $mode, int $productId = 0, int $sectionId = 0, int $count = 10): array
	{
		$recommender = Recommender::create();

		return match ($mode) {
			'personal' => $recommender->personal(Fuser::getId(true), $this->siteId(), $count),
			'similar' => $productId > 0
				? $recommender->similar($productId, $count)
				: throw new ArgumentException('productId is required for mode similar', 'productId'),
			'popular' => $recommender->popular($sectionId, $count),
			default => throw new ArgumentException("Unknown mode {$mode}", 'mode'),
		};
	}

	private function siteId(): string
	{
		$siteId = Application::getInstance()->getContext()->getSite();
		if ($siteId === null || $siteId === '')
		{
			throw new \RuntimeException('Site of the request is not defined');
		}

		return $siteId;
	}
}
