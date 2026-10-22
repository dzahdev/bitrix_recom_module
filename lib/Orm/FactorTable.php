<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Векторы факторов ALS. VECTOR - base64 от float32 little-endian (VectorCodec::packVector).
 */
class FactorTable extends DataManager
{
	public const USER = 'U';
	public const ITEM = 'I';

	public static function getTableName(): string
	{
		return 'b_dzahdev_recom_factor';
	}

	public static function getMap(): array
	{
		return [
			(new StringField('ENTITY_TYPE'))->configurePrimary()->configureSize(1),
			(new IntegerField('ENTITY_ID'))->configurePrimary(),
			(new TextField('VECTOR'))->configureRequired(),
		];
	}
}
