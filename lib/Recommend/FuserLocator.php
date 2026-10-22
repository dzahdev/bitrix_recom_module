<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Recommend;

use Bitrix\Sale\Internals\FuserTable;

final class FuserLocator
{
	/**
	 * Только чтение: Fuser::getIdByUserId() при отсутствии записи создает новую корзину покупателя,
	 * а отправка письма не должна менять данные магазина.
	 *
	 * @return int|null null - у пользователя нет корзины покупателя, значит нет и истории событий
	 */
	public static function byUserId(int $userId): ?int
	{
		$row = FuserTable::getRow([
			'select' => ['ID'],
			'filter' => ['=USER_ID' => $userId],
			'order' => ['ID' => 'DESC'],
		]);

		return $row === null ? null : (int)$row['ID'];
	}
}
