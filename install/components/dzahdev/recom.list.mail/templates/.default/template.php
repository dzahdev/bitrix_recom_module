<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

/**
 * @var array $arResult
 * @var CBitrixComponentTemplate $this
 */

if ($arResult['IDS'] === [])
{
	return;
}

\Bitrix\Main\Mail\EventMessageThemeCompiler::includeComponent(
	'bitrix:catalog.show.products.mail',
	'.default',
	['LIST_ITEM_ID' => $arResult['IDS']],
	false
);
