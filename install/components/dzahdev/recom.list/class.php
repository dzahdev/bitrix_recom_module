<?php

use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Bitrix\Sale\Fuser;
use Dzahdev\Recom\Recommend\Recommender;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class DzahdevRecomListComponent extends CBitrixComponent
{
	private const MODES = ['personal', 'similar', 'popular'];

	/**
	 * Запас списка: catalog.section отбросит неактивные и недоступные к покупке товары.
	 */
	private const RESERVE_FACTOR = 3;

	public function onPrepareComponentParams($arParams): array
	{
		$mode = (string)($arParams['MODE'] ?? '');
		if (!in_array($mode, self::MODES, true))
		{
			throw new SystemException("Unknown MODE '{$mode}' of dzahdev:recom.list");
		}

		$arParams['MODE'] = $mode;
		$arParams['IBLOCK_ID'] = (int)($arParams['IBLOCK_ID'] ?? 0);
		$arParams['PRODUCT_ID'] = (int)($arParams['PRODUCT_ID'] ?? 0);
		$arParams['SECTION_ID'] = (int)($arParams['SECTION_ID'] ?? 0);
		$arParams['COUNT'] = (int)($arParams['COUNT'] ?? 8);
		$arParams['PRICE_CODE'] = array_values(array_filter((array)($arParams['PRICE_CODE'] ?? ['BASE'])));
		$arParams['SECTION_TEMPLATE'] = (string)($arParams['SECTION_TEMPLATE'] ?? '.default');

		if ($arParams['IBLOCK_ID'] <= 0)
		{
			throw new SystemException('IBLOCK_ID of dzahdev:recom.list is required');
		}
		if ($arParams['COUNT'] <= 0)
		{
			throw new SystemException('COUNT of dzahdev:recom.list must be positive');
		}
		if ($mode === 'similar' && $arParams['PRODUCT_ID'] <= 0)
		{
			throw new SystemException('PRODUCT_ID of dzahdev:recom.list is required for MODE=similar');
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

		$recommender = Recommender::create();
		$limit = $this->arParams['COUNT'] * self::RESERVE_FACTOR;
		$result = match ($this->arParams['MODE']) {
			'personal' => $recommender->personal(Fuser::getId(true), SITE_ID, $limit),
			'similar' => $recommender->similar($this->arParams['PRODUCT_ID'], $limit),
			'popular' => $recommender->popular($this->arParams['SECTION_ID'], $limit),
		};

		$this->arResult = [
			'IDS' => $result['ids'],
			'SOURCE' => $result['source'],
		];
		$this->includeComponentTemplate();
	}
}
