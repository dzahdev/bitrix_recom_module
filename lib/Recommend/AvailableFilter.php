<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Recommend;

use Bitrix\Catalog\ProductTable;

final class AvailableFilter
{
	/**
	 * В письме нет catalog.section, который сам отбросил бы неактивные и недоступные товары,
	 * поэтому список фильтруется здесь.
	 *
	 * @param list<int> $ids по убыванию оценки
	 * @return list<int> первые $limit активных и доступных к покупке товаров в исходном порядке
	 */
	public static function filter(array $ids, int $limit): array
	{
		if ($ids === [])
		{
			return [];
		}

		$rows = ProductTable::getList([
			'select' => ['ID'],
			'filter' => [
				'@ID' => $ids,
				'=AVAILABLE' => 'Y',
				'=IBLOCK_ELEMENT.ACTIVE' => 'Y',
			],
		]);
		$available = [];
		while ($row = $rows->fetch())
		{
			$available[(int)$row['ID']] = true;
		}

		$result = [];
		foreach ($ids as $id)
		{
			if (isset($available[$id]))
			{
				$result[] = $id;
				if (count($result) >= $limit)
				{
					break;
				}
			}
		}

		return $result;
	}
}
