<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Training;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Dzahdev\Recom\Engine\VectorCodec;
use Dzahdev\Recom\Orm\FactorTable;
use Dzahdev\Recom\Orm\ItemSimTable;
use Dzahdev\Recom\Orm\PopularTable;
use Dzahdev\Recom\Orm\UserRecTable;

/**
 * Чтение и атомарная замена обученных данных. Векторы и списки хранятся как base64 от VectorCodec.
 */
final class ModelStore
{
	/**
	 * @param string $entityType FactorTable::USER или FactorTable::ITEM
	 * @return array<int, list<float>> ID => вектор
	 */
	public function loadFactors(string $entityType): array
	{
		$rows = FactorTable::getList([
			'select' => ['ENTITY_ID', 'VECTOR'],
			'filter' => ['=ENTITY_TYPE' => $entityType],
		]);

		$result = [];
		while ($row = $rows->fetch())
		{
			$result[(int)$row['ENTITY_ID']] = VectorCodec::unpackVector(self::decode($row['VECTOR']));
		}

		return $result;
	}

	/**
	 * @param array<int, list<float>> $users
	 * @param array<int, list<float>> $items
	 */
	public function replaceFactors(array $users, array $items): void
	{
		$rows = [];
		foreach ($users as $id => $vector)
		{
			$rows[] = ['ENTITY_TYPE' => FactorTable::USER, 'ENTITY_ID' => $id, 'VECTOR' => base64_encode(VectorCodec::packVector($vector))];
		}
		foreach ($items as $id => $vector)
		{
			$rows[] = ['ENTITY_TYPE' => FactorTable::ITEM, 'ENTITY_ID' => $id, 'VECTOR' => base64_encode(VectorCodec::packVector($vector))];
		}

		$this->replace(FactorTable::getTableName(), $rows);
	}

	/**
	 * @param array<int, array<int, float>> $sims ID товара => [ID похожего => оценка]
	 */
	public function replaceItemSims(array $sims): void
	{
		$rows = [];
		foreach ($sims as $productId => $scores)
		{
			$rows[] = ['PRODUCT_ID' => $productId, 'ITEMS' => base64_encode(VectorCodec::packScores($scores))];
		}

		$this->replace(ItemSimTable::getTableName(), $rows);
	}

	/**
	 * @param array<int, array<int, float>> $popular ID раздела => [ID товара => оценка]
	 */
	public function replacePopular(array $popular): void
	{
		$rows = [];
		foreach ($popular as $sectionId => $scores)
		{
			$rows[] = ['SECTION_ID' => $sectionId, 'ITEMS' => base64_encode(VectorCodec::packScores($scores))];
		}

		$this->replace(PopularTable::getTableName(), $rows);
	}

	/**
	 * @param array<int, array<int, float>> $recs FUSER_ID => [ID товара => оценка]
	 */
	public function replaceUserRecs(array $recs, string $source): void
	{
		$now = new DateTime();
		$rows = [];
		foreach ($recs as $fuserId => $scores)
		{
			$rows[] = ['FUSER_ID' => $fuserId, 'ITEMS' => base64_encode(VectorCodec::packScores($scores)), 'SOURCE' => $source, 'UPDATED_AT' => $now];
		}

		$this->replace(UserRecTable::getTableName(), $rows);
	}

	/**
	 * @return array<int, float> пусто, если подборки для покупателя нет
	 */
	public function loadUserRec(int $fuserId): array
	{
		$row = UserRecTable::getRow(['select' => ['ITEMS'], 'filter' => ['=FUSER_ID' => $fuserId]]);

		return $row === null ? [] : VectorCodec::unpackScores(self::decode($row['ITEMS']));
	}

	/**
	 * @param list<int> $productIds
	 * @return array<int, array<int, float>> только товары, известные модели
	 */
	public function loadItemSims(array $productIds): array
	{
		if ($productIds === [])
		{
			return [];
		}

		$rows = ItemSimTable::getList(['select' => ['PRODUCT_ID', 'ITEMS'], 'filter' => ['@PRODUCT_ID' => $productIds]]);
		$result = [];
		while ($row = $rows->fetch())
		{
			$result[(int)$row['PRODUCT_ID']] = VectorCodec::unpackScores(self::decode($row['ITEMS']));
		}

		return $result;
	}

	/**
	 * @return array<int, float> пусто, если по разделу не было сигналов
	 */
	public function loadPopular(int $sectionId): array
	{
		$row = PopularTable::getRow(['select' => ['ITEMS'], 'filter' => ['=SECTION_ID' => $sectionId]]);

		return $row === null ? [] : VectorCodec::unpackScores(self::decode($row['ITEMS']));
	}

	/**
	 * Замена содержимого таблицы в одной транзакции: выдача видит либо старую, либо новую модель.
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	private function replace(string $tableName, array $rows): void
	{
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$connection->queryExecute('DELETE FROM ' . $connection->getSqlHelper()->quote($tableName));
			foreach (array_chunk($rows, 500) as $chunk)
			{
				$connection->addMulti($tableName, $chunk);
			}
			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();
			throw $e;
		}
	}

	private static function decode(string $value): string
	{
		$binary = base64_decode($value, true);
		if ($binary === false)
		{
			throw new \UnexpectedValueException('Stored model value is not valid base64');
		}

		return $binary;
	}
}
