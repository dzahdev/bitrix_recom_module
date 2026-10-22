<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

/**
 * Признаки FM для пары "покупатель - товар": u<ID покупателя>, i<ID товара>, s<ID раздела>.
 */
final class FmSamples
{
	/**
	 * Обучающие примеры: каждая положительная пара с меткой 1 и $negatives случайных товаров без
	 * сигнала от этого покупателя с меткой 0. Без записи показов это единственный источник класса 0.
	 *
	 * @param array<int, int> $itemSections ID товара => ID раздела; 0 или отсутствие - без раздела
	 * @return list<array{0: list<int>, 1: int}>
	 */
	public static function build(Dataset $dataset, array $itemSections, int $negatives, FeatureIndex $index): array
	{
		$itemCount = $dataset->countItems();
		$samples = [];
		for ($u = 0, $userCount = $dataset->countUsers(); $u < $userCount; $u++)
		{
			$userId = $dataset->userId($u);
			$items = $dataset->userItems($u);
			$seen = array_flip($items);
			// Покупатель со всеми товарами каталога: товаров без сигнала нет, отрицательных примеров тоже.
			$canSample = count($items) < $itemCount;

			foreach ($items as $i)
			{
				$samples[] = [self::encode($index, $userId, $dataset->itemId($i), $itemSections), 1];
				if (!$canSample)
				{
					continue;
				}
				for ($n = 0; $n < $negatives; $n++)
				{
					do
					{
						$j = mt_rand(0, $itemCount - 1);
					}
					while (isset($seen[$j]));
					$samples[] = [self::encode($index, $userId, $dataset->itemId($j), $itemSections), 0];
				}
			}
		}

		return $samples;
	}

	/**
	 * Признаки для оценки обученной моделью. Признак, которого не было в обучении, пропускается:
	 * новый товар оценивается по покупателю и разделу.
	 *
	 * @param int $sectionId 0 - товар без раздела
	 * @return list<int>
	 */
	public static function features(FeatureIndex $index, int $userId, int $itemId, int $sectionId): array
	{
		$names = ['u' . $userId, 'i' . $itemId];
		if ($sectionId > 0)
		{
			$names[] = 's' . $sectionId;
		}

		$features = [];
		foreach ($names as $name)
		{
			$id = $index->find($name);
			if ($id !== null)
			{
				$features[] = $id;
			}
		}

		return $features;
	}

	/**
	 * @param array<int, int> $itemSections
	 * @return list<int>
	 */
	private static function encode(FeatureIndex $index, int $userId, int $itemId, array $itemSections): array
	{
		$features = [$index->id('u' . $userId), $index->id('i' . $itemId)];
		$sectionId = $itemSections[$itemId] ?? 0;
		if ($sectionId > 0)
		{
			$features[] = $index->id('s' . $sectionId);
		}

		return $features;
	}
}
