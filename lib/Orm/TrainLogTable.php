<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\FloatField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

class TrainLogTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_dzahdev_recom_train_log';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new StringField('MODE'))->configureRequired()->configureSize(16),
			(new DatetimeField('STARTED_AT'))->configureRequired(),
			(new FloatField('DURATION_SEC'))->configureRequired(),
			(new IntegerField('USERS_COUNT'))->configureRequired(),
			(new IntegerField('ITEMS_COUNT'))->configureRequired(),
			(new IntegerField('PAIRS_COUNT'))->configureRequired(),
			(new FloatField('NDCG'))->configureNullable(),
			(new FloatField('NDCG_FM'))->configureNullable(),
			(new TextField('ERROR'))->configureNullable(),
		];
	}
}
