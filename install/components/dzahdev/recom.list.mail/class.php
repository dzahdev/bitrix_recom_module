<?php

use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Dzahdev\Recom\Recommend\AvailableFilter;
use Dzahdev\Recom\Recommend\FuserLocator;
use Dzahdev\Recom\Recommend\Recommender;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class DzahdevRecomListMailComponent extends CBitrixComponent
{
	private const MODES = ['personal', 'similar', 'popular'];

	/**
	 * Запас списка до фильтра активности и доступности.
	 */
	private const RESERVE_FACTOR = 3;

	public function onPrepareComponentParams($arParams): array
	{
		$mode = (string)($arParams['MODE'] ?? '');
		if (!in_array($mode, self::MODES, true))
		{
			throw new SystemException("Unknown MODE '{$mode}' of dzahdev:recom.list.mail");
		}

		$arParams['MODE'] = $mode;
		// Письмо гостю или событие без поля USER_ID дает пустую строку: получатель без истории.
		$arParams['USER_ID'] = (int)($arParams['USER_ID'] ?? 0);
		$arParams['PRODUCT_ID'] = (int)($arParams['PRODUCT_ID'] ?? 0);
		$arParams['SECTION_ID'] = (int)($arParams['SECTION_ID'] ?? 0);
		$arParams['COUNT'] = (int)($arParams['COUNT'] ?? 4);

		if ($arParams['COUNT'] <= 0)
		{
			throw new SystemException('COUNT of dzahdev:recom.list.mail must be positive');
		}
		if ($mode === 'similar' && $arParams['PRODUCT_ID'] <= 0)
		{
			throw new SystemException('PRODUCT_ID of dzahdev:recom.list.mail is required for MODE=similar');
		}

		return $arParams;
	}

	public function executeComponent(): void
	{
		foreach (['dzahdev.recom', 'sale', 'catalog'] as $module)
		{
			if (!Loader::includeModule($module))
			{
				throw new SystemException("Module {$module} is not installed");
			}
		}

		$siteId = (string)$this->getSiteId();
		if ($siteId === '')
		{
			throw new SystemException('Site of the mail message is not defined');
		}

		$recommender = Recommender::create();
		$limit = $this->arParams['COUNT'] * self::RESERVE_FACTOR;
		$result = match ($this->arParams['MODE']) {
			'personal' => $recommender->personal(
				$this->arParams['USER_ID'] > 0 ? FuserLocator::byUserId($this->arParams['USER_ID']) : null,
				$siteId,
				$limit
			),
			'similar' => $recommender->similar($this->arParams['PRODUCT_ID'], $limit),
			'popular' => $recommender->popular($this->arParams['SECTION_ID'], $limit),
		};

		$this->arResult = [
			'IDS' => AvailableFilter::filter($result['ids'], $this->arParams['COUNT']),
			'SOURCE' => $result['source'],
		];
		$this->includeComponentTemplate();
	}
}
