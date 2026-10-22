<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

/**
 * Обученные векторы ALS. Внутри индексы Dataset, наружу - внешние ID.
 */
final class AlsModel
{
	/**
	 * @param array<int, list<float>> $userFactors индекс пользователя => вектор
	 * @param array<int, list<float>> $itemFactors индекс товара => вектор
	 */
	public function __construct(
		private readonly Dataset $dataset,
		private readonly array $userFactors,
		private readonly array $itemFactors,
	)
	{
	}

	public function dataset(): Dataset
	{
		return $this->dataset;
	}

	/** @return list<float> */
	public function userVector(int $u): array
	{
		return $this->userFactors[$u];
	}

	/** @return list<float> */
	public function itemVector(int $i): array
	{
		return $this->itemFactors[$i];
	}

	/** @return array<int, list<float>> ID пользователя => вектор */
	public function userFactorsById(): array
	{
		$result = [];
		foreach ($this->userFactors as $u => $vector)
		{
			$result[$this->dataset->userId($u)] = $vector;
		}

		return $result;
	}

	/** @return array<int, list<float>> ID товара => вектор */
	public function itemFactorsById(): array
	{
		$result = [];
		foreach ($this->itemFactors as $i => $vector)
		{
			$result[$this->dataset->itemId($i)] = $vector;
		}

		return $result;
	}

	/**
	 * @param list<float> $a
	 * @param list<float> $b
	 */
	public static function dot(array $a, array $b): float
	{
		$sum = 0.0;
		foreach ($a as $f => $value)
		{
			$sum += $value * $b[$f];
		}

		return $sum;
	}
}
