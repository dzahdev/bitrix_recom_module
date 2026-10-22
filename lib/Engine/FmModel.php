<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

/**
 * Обученная factorization machine второго порядка (S. Rendle, Factorization Machines, ICDM 2010).
 */
final class FmModel
{
	/**
	 * @param list<float> $weights вес признака по индексу
	 * @param list<list<float>> $factors вектор признака по индексу
	 */
	public function __construct(
		private readonly float $bias,
		private readonly array $weights,
		private readonly array $factors,
	)
	{
	}

	/**
	 * y = w0 + Σ w_i + ½·Σ_f [(Σ v_if)² - Σ v_if²] по активным бинарным признакам (лемма 3.1).
	 *
	 * @param list<int> $features индексы активных признаков
	 */
	public function predict(array $features): float
	{
		$y = $this->bias;
		foreach ($features as $j)
		{
			$y += $this->weights[$j];
		}

		$pairs = 0.0;
		$factorCount = $features === [] ? 0 : count($this->factors[$features[0]]);
		for ($f = 0; $f < $factorCount; $f++)
		{
			$sum = 0.0;
			$squares = 0.0;
			foreach ($features as $j)
			{
				$value = $this->factors[$j][$f];
				$sum += $value;
				$squares += $value * $value;
			}
			$pairs += $sum * $sum - $squares;
		}

		return $y + 0.5 * $pairs;
	}
}
