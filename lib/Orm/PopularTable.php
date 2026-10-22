<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Популярные товары. SECTION_ID = 0 - весь каталог. ITEMS - base64 от VectorCodec::packScores.
 */
class PopularTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_dzahdev_recom_popular';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('SECTION_ID'))->configurePrimary(),
			(new TextField('ITEMS'))->configureRequired(),
		];
	}
}
