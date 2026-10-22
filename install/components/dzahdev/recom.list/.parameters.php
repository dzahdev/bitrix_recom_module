<?php

use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

$arComponentParameters = [
	'PARAMETERS' => [
		'MODE' => [
			'PARENT' => 'BASE',
			'NAME' => Loc::getMessage('DZAHDEV_RECOM_LIST_MODE'),
			'TYPE' => 'LIST',
			'VALUES' => [
				'personal' => Loc::getMessage('DZAHDEV_RECOM_LIST_MODE_PERSONAL'),
				'similar' => Loc::getMessage('DZAHDEV_RECOM_LIST_MODE_SIMILAR'),
				'popular' => Loc::getMessage('DZAHDEV_RECOM_LIST_MODE_POPULAR'),
			],
			'DEFAULT' => 'personal',
		],
		'IBLOCK_ID' => [
			'PARENT' => 'BASE',
			'NAME' => Loc::getMessage('DZAHDEV_RECOM_LIST_IBLOCK_ID'),
			'TYPE' => 'STRING',
		],
		'PRODUCT_ID' => [
			'PARENT' => 'BASE',
			'NAME' => Loc::getMessage('DZAHDEV_RECOM_LIST_PRODUCT_ID'),
			'TYPE' => 'STRING',
		],
		'SECTION_ID' => [
			'PARENT' => 'BASE',
			'NAME' => Loc::getMessage('DZAHDEV_RECOM_LIST_SECTION_ID'),
			'TYPE' => 'STRING',
			'DEFAULT' => '0',
		],
		'COUNT' => [
			'PARENT' => 'BASE',
			'NAME' => Loc::getMessage('DZAHDEV_RECOM_LIST_COUNT'),
			'TYPE' => 'STRING',
			'DEFAULT' => '8',
		],
		'PRICE_CODE' => [
			'PARENT' => 'BASE',
			'NAME' => Loc::getMessage('DZAHDEV_RECOM_LIST_PRICE_CODE'),
			'TYPE' => 'STRING',
			'MULTIPLE' => 'Y',
			'DEFAULT' => ['BASE'],
		],
		'SECTION_TEMPLATE' => [
			'PARENT' => 'VISUAL',
			'NAME' => Loc::getMessage('DZAHDEV_RECOM_LIST_SECTION_TEMPLATE'),
			'TYPE' => 'STRING',
			'DEFAULT' => '.default',
		],
	],
];
