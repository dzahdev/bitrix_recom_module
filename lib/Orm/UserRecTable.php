<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Готовые персональные подборки. ITEMS - base64 от VectorCodec::packScores.
 */
class UserRecTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_dzahdev_recom_user_rec';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('FUSER_ID'))->configurePrimary(),
			(new TextField('ITEMS'))->configureRequired(),
			(new StringField('SOURCE'))->configureRequired()->configureSize(16),
			(new DatetimeField('UPDATED_AT'))->configureRequired(),
		];
	}
}
