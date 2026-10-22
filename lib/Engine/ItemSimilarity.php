<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

/**
 * Похожие товары: косинус множеств покупателей с весами IDF (Dataset::userIdf) и сжатием n/(n + 100)
 * по числу общих покупателей n (shrinkage, Y. Koren, Factorization Meets the Neighborhood, KDD 2008).
 * Расстояние = 1 - косинус * n / (n + 100), оценка = 1 / (1 + расстояние). Расчет - полный проход по
 * совместной встречаемости: для каталогов интернет-магазина он укладывается в обучение агентом.
 */
final class ItemSimilarity
{
	public function __construct(
		private readonly int $neighbors,
		private readonly int $shrinkage = 100,
		private readonly int $maxUserItems = 500,
	)
	{
	}

	/**
	 * @param list<float>|null $idf вес пользователя по индексу; null - Dataset::userIdf()
	 * @return array<int, array<int, float>> ID товара => [ID похожего товара => 1 / (1 + расстояние)]
	 */
	public function compute(Dataset $dataset, ?array $idf = null): array
	{
		$idf ??= $dataset->userIdf();
		$itemCount = $dataset->countItems();

		$weightSum = [];
		for ($i = 0; $i < $itemCount; $i++)
		{
			$sum = 0.0;
			foreach ($dataset->itemUsers($i) as $u)
			{
				$sum += $idf[$u];
			}
			$weightSum[$i] = $sum;
		}

		$result = [];
		for ($i = 0; $i < $itemCount; $i++)
		{
			$users = $dataset->itemUsers($i);
			$commonSum = [];
			$commonCount = [];
			foreach ($users as $u)
			{
				$items = $dataset->userItems($u);
				if (count($items) > $this->maxUserItems)
				{
					// Слишком широкая история (опт, боты) связывает почти все товары и дает шум.
					continue;
				}
				foreach ($items as $j)
				{
					if ($j === $i)
					{
						continue;
					}
					$commonSum[$j] = ($commonSum[$j] ?? 0.0) + $idf[$u];
					$commonCount[$j] = ($commonCount[$j] ?? 0) + 1;
				}
			}

			$scores = [];
			foreach ($commonSum as $j => $sum)
			{
				$common = $commonCount[$j];
				if ($common === count($users) && $common === count($dataset->itemUsers($j)))
				{
					$distance = 0.0;
				}
				else
				{
					$distance = 1.0 - $sum * $common / sqrt($weightSum[$i]) / sqrt($weightSum[$j]) / ($common + $this->shrinkage);
				}
				$scores[$dataset->itemId($j)] = 1.0 / (1.0 + $distance);
			}

			$result[$dataset->itemId($i)] = TopK::select($scores, $this->neighbors);
		}

		return $result;
	}
}
