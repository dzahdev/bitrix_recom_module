<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Training;

use Bitrix\Iblock\ElementTable;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\Type\DateTime;
use Dzahdev\Recom\Orm\EventTable;
use Dzahdev\Recom\Tracking\EventType;

/**
 * Чтение событий для обучения и выдачи через ORM.
 */
final class DatasetLoader
{
	/**
	 * Положительные пары "покупатель - товар" и отложенная выборка: у покупателя с двумя и более
	 * товарами откладывается последний по времени.
	 *
	 * @param list<string> $types
	 * @return array{pairs: list<array{0: int, 1: int}>, holdout: array<int, int>}
	 */
	public function loadPairs(array $types, bool $withHoldout): array
	{
		$rows = EventTable::getList([
			'select' => ['FUSER_ID', 'PRODUCT_ID', 'LAST_AT'],
			'filter' => ['@TYPE' => $types],
			'group' => ['FUSER_ID', 'PRODUCT_ID'],
			'runtime' => [
				(new ExpressionField('LAST_AT', 'MAX(%s)', ['CREATED_AT']))->configureValueType(DatetimeField::class),
			],
		]);

		$byUser = [];
		while ($row = $rows->fetch())
		{
			$byUser[(int)$row['FUSER_ID']][(int)$row['PRODUCT_ID']] = $row['LAST_AT']->getTimestamp();
		}

		$pairs = [];
		$holdout = [];
		foreach ($byUser as $fuserId => $items)
		{
			if ($withHoldout && count($items) >= 2)
			{
				arsort($items);
				$heldOut = array_key_first($items);
				$holdout[$fuserId] = $heldOut;
				unset($items[$heldOut]);
			}
			foreach ($items as $productId => $_)
			{
				$pairs[] = [$fuserId, $productId];
			}
		}

		return ['pairs' => $pairs, 'holdout' => $holdout];
	}

	/**
	 * @param list<string> $types
	 * @param array<string, float> $weights тип события => вес
	 * @return list<array{0: int, 1: int, 2: float}> (ID товара, ID раздела или 0, сумма весов за окно)
	 */
	public function loadPopularRows(array $types, array $weights, int $windowDays): array
	{
		$rows = EventTable::getList([
			'select' => ['PRODUCT_ID', 'TYPE', 'CNT'],
			'filter' => ['@TYPE' => $types, '>=CREATED_AT' => (new DateTime())->add("-{$windowDays} days")],
			'group' => ['PRODUCT_ID', 'TYPE'],
			'runtime' => [
				(new ExpressionField('CNT', 'COUNT(*)'))->configureValueType(IntegerField::class),
			],
		]);

		$scores = [];
		while ($row = $rows->fetch())
		{
			$productId = (int)$row['PRODUCT_ID'];
			$scores[$productId] = ($scores[$productId] ?? 0.0) + (int)$row['CNT'] * $weights[$row['TYPE']];
		}

		$sections = $this->itemSections(array_keys($scores));
		$result = [];
		foreach ($scores as $productId => $score)
		{
			// Товар без раздела или уже удаленный участвует только в общем рейтинге (раздел 0).
			$result[] = [$productId, $sections[$productId] ?? 0, $score];
		}

		return $result;
	}

	/**
	 * @return list<int> FUSER_ID с событиями за последние $days дней
	 */
	public function activeUsers(int $days): array
	{
		$rows = EventTable::getList([
			'select' => ['FUSER_ID'],
			'filter' => ['>=CREATED_AT' => (new DateTime())->add("-{$days} days")],
			'group' => ['FUSER_ID'],
		]);

		$result = [];
		while ($row = $rows->fetch())
		{
			$result[] = (int)$row['FUSER_ID'];
		}

		return $result;
	}

	/**
	 * Последние товары покупателя: сумма весов событий по товару, при равенстве - более свежие выше.
	 *
	 * @param array<string, float> $weights тип события => вес; типы вне массива не учитываются
	 * @return array<int, float> ID товара => сумма весов
	 */
	public function recentProducts(int $fuserId, array $weights, int $limit): array
	{
		$rows = EventTable::getList([
			'select' => ['PRODUCT_ID', 'TYPE'],
			'filter' => ['=FUSER_ID' => $fuserId, '@TYPE' => array_keys($weights)],
			'order' => ['CREATED_AT' => 'DESC', 'ID' => 'DESC'],
			'limit' => 100,
		]);

		$scores = [];
		while ($row = $rows->fetch())
		{
			$productId = (int)$row['PRODUCT_ID'];
			$scores[$productId] = ($scores[$productId] ?? 0.0) + $weights[$row['TYPE']];
		}
		arsort($scores);

		return array_slice($scores, 0, $limit, true);
	}

	/**
	 * @return list<int>
	 */
	public function boughtProducts(int $fuserId): array
	{
		$rows = EventTable::getList([
			'select' => ['PRODUCT_ID'],
			'filter' => ['=FUSER_ID' => $fuserId, '=TYPE' => EventType::BUY],
			'group' => ['PRODUCT_ID'],
		]);

		$result = [];
		while ($row = $rows->fetch())
		{
			$result[] = (int)$row['PRODUCT_ID'];
		}

		return $result;
	}

	/**
	 * @param list<int> $productIds
	 * @return array<int, int> ID товара => ID основного раздела
	 */
	public function itemSections(array $productIds): array
	{
		$result = [];
		foreach (array_chunk($productIds, 500) as $chunk)
		{
			$rows = ElementTable::getList([
				'select' => ['ID', 'IBLOCK_SECTION_ID'],
				'filter' => ['@ID' => $chunk],
			]);
			while ($row = $rows->fetch())
			{
				$result[(int)$row['ID']] = (int)$row['IBLOCK_SECTION_ID'];
			}
		}

		return $result;
	}
}
