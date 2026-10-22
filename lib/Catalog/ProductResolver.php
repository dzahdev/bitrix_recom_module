<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Catalog;

use Bitrix\Catalog\CatalogIblockTable;
use Bitrix\Iblock\ElementPropertyTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\IblockTable;
use Bitrix\Main\Application;

/**
 * Приводит элемент торгового каталога к товару: предложение - к родительскому товару.
 */
final class ProductResolver
{
	/** @var array<int, array{productIblock: int, skuProperty: int, version: int}|null> */
	private array $iblocks = [];

	/**
	 * @return int|null ID товара; null, если элемент не входит в торговый каталог
	 * @throws \RuntimeException если у предложения нет привязки к товару
	 */
	public function resolve(int $elementId): ?int
	{
		$element = ElementTable::getRow([
			'select' => ['IBLOCK_ID'],
			'filter' => ['=ID' => $elementId],
		]);
		if ($element === null)
		{
			return null;
		}

		$iblockId = (int)$element['IBLOCK_ID'];
		$catalog = $this->catalogIblock($iblockId);
		if ($catalog === null)
		{
			return null;
		}
		if ($catalog['productIblock'] === 0)
		{
			return $elementId;
		}

		$parentId = $this->parentId($elementId, $iblockId, $catalog);
		if ($parentId <= 0)
		{
			throw new \RuntimeException("Offer {$elementId} has no link to a parent product");
		}

		return $parentId;
	}

	/**
	 * @return array{productIblock: int, skuProperty: int, version: int}|null
	 */
	private function catalogIblock(int $iblockId): ?array
	{
		if (array_key_exists($iblockId, $this->iblocks))
		{
			return $this->iblocks[$iblockId];
		}

		$catalog = CatalogIblockTable::getRow([
			'select' => ['PRODUCT_IBLOCK_ID', 'SKU_PROPERTY_ID'],
			'filter' => ['=IBLOCK_ID' => $iblockId],
		]);
		if ($catalog === null)
		{
			return $this->iblocks[$iblockId] = null;
		}

		$iblock = IblockTable::getRow([
			'select' => ['VERSION'],
			'filter' => ['=ID' => $iblockId],
		]);

		return $this->iblocks[$iblockId] = [
			'productIblock' => (int)$catalog['PRODUCT_IBLOCK_ID'],
			'skuProperty' => (int)$catalog['SKU_PROPERTY_ID'],
			'version' => (int)$iblock['VERSION'],
		];
	}

	/**
	 * @param array{productIblock: int, skuProperty: int, version: int} $catalog
	 */
	private function parentId(int $elementId, int $iblockId, array $catalog): int
	{
		if ($catalog['version'] === 1)
		{
			$row = ElementPropertyTable::getRow([
				'select' => ['VALUE'],
				'filter' => [
					'=IBLOCK_ELEMENT_ID' => $elementId,
					'=IBLOCK_PROPERTY_ID' => $catalog['skuProperty'],
				],
			]);

			return $row === null ? 0 : (int)$row['VALUE'];
		}

		// Инфоблок с хранением свойств в отдельной таблице (версия 2): ORM-сущности у нее нет.
		$value = Application::getConnection()->queryScalar(sprintf(
			'SELECT PROPERTY_%d FROM b_iblock_element_prop_s%d WHERE IBLOCK_ELEMENT_ID = %d',
			$catalog['skuProperty'],
			$iblockId,
			$elementId
		));

		return (int)$value;
	}
}
