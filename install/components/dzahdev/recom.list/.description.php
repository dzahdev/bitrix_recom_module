<?php

use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

$arComponentDescription = [
	'NAME' => Loc::getMessage('DZAHDEV_RECOM_LIST_NAME'),
	'DESCRIPTION' => Loc::getMessage('DZAHDEV_RECOM_LIST_DESCRIPTION'),
	'PATH' => [
		'ID' => 'dzahdev',
		'NAME' => 'dzahdev',
	],
];
