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
			'NAME' => Loc::getMessage('DZAHDEV_RECOM_MAIL_MODE'),
			'TYPE' => 'LIST',
			'VALUES' => [
				'personal' => Loc::getMessage('DZAHDEV_RECOM_MAIL_MODE_PERSONAL'),
				'similar' => Loc::getMessage('DZAHDEV_RECOM_MAIL_MODE_SIMILAR'),
				'popular' => Loc::getMessage('DZAHDEV_RECOM_MAIL_MODE_POPULAR'),
			],
			'DEFAULT' => 'personal',
		],
		'USER_ID' => [
			'PARENT' => 'BASE',
			'NAME' => Loc::getMessage('DZAHDEV_RECOM_MAIL_USER_ID'),
			'TYPE' => 'STRING',
			'DEFAULT' => '{#USER_ID#}',
		],
		'PRODUCT_ID' => [
			'PARENT' => 'BASE',
			'NAME' => Loc::getMessage('DZAHDEV_RECOM_MAIL_PRODUCT_ID'),
			'TYPE' => 'STRING',
		],
		'SECTION_ID' => [
			'PARENT' => 'BASE',
			'NAME' => Loc::getMessage('DZAHDEV_RECOM_MAIL_SECTION_ID'),
			'TYPE' => 'STRING',
			'DEFAULT' => '0',
		],
		'COUNT' => [
			'PARENT' => 'BASE',
			'NAME' => Loc::getMessage('DZAHDEV_RECOM_MAIL_COUNT'),
			'TYPE' => 'STRING',
			'DEFAULT' => '4',
		],
	],
];
