<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

/**
 * @var array $arParams
 * @var array $arResult
 * @var CMain $APPLICATION
 * @var CBitrixComponent $component
 * @var CBitrixComponentTemplate $this
 */

if ($arResult['IDS'] === [])
{
	return;
}

$filterName = 'dzahdevRecomFilter' . $this->randString();
$GLOBALS[$filterName] = ['=ID' => $arResult['IDS']];
?>
<div class="dzahdev-recom" data-source="<?= htmlspecialcharsbx($arResult['SOURCE']) ?>">
<?php
$APPLICATION->IncludeComponent(
	'bitrix:catalog.section',
	$arParams['SECTION_TEMPLATE'],
	[
		'IBLOCK_ID' => $arParams['IBLOCK_ID'],
		'FILTER_NAME' => $filterName,
		'SECTION_ID' => '',
		'SECTION_CODE' => '',
		'SHOW_ALL_WO_SECTION' => 'Y',
		'INCLUDE_SUBSECTIONS' => 'Y',
		'PAGE_ELEMENT_COUNT' => $arParams['COUNT'],
		'CUSTOM_ELEMENT_SORT' => ['ID' => $arResult['IDS']],
		'HIDE_NOT_AVAILABLE' => 'Y',
		'PRICE_CODE' => $arParams['PRICE_CODE'],
		'DISPLAY_TOP_PAGER' => 'N',
		'DISPLAY_BOTTOM_PAGER' => 'N',
		'SET_TITLE' => 'N',
		'SET_BROWSER_TITLE' => 'N',
		'SET_META_KEYWORDS' => 'N',
		'SET_META_DESCRIPTION' => 'N',
		'ADD_SECTIONS_CHAIN' => 'N',
		'SET_STATUS_404' => 'N',
		'CACHE_TYPE' => 'N',
		'BASKET_URL' => '/personal/cart/',
		'ACTION_VARIABLE' => 'action',
		'PRODUCT_ID_VARIABLE' => 'id',
		'ADD_TO_BASKET_ACTION' => 'ADD',
		'PRODUCT_DISPLAY_MODE' => 'Y',
		'COMPATIBLE_MODE' => 'N',
	],
	$component,
	['HIDE_ICONS' => 'Y']
);
?>
</div>
