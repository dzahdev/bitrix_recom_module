<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

class EventTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_dzahdev_recom_event';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new IntegerField('FUSER_ID'))->configureRequired(),
			(new IntegerField('PRODUCT_ID'))->configureRequired(),
			(new StringField('TYPE'))->configureRequired()->configureSize(16),
			(new StringField('SITE_ID'))->configureRequired()->configureSize(2),
			(new DatetimeField('CREATED_AT'))->configureRequired(),
		];
	}
}
