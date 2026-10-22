<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Похожие товары. ITEMS - base64 от VectorCodec::packScores.
 */
class ItemSimTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_dzahdev_recom_item_sim';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('PRODUCT_ID'))->configurePrimary(),
			(new TextField('ITEMS'))->configureRequired(),
		];
	}
}
