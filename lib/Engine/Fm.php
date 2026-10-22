<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

/**
 * Обучение factorization machine второго порядка стохастическим градиентным спуском с
 * L2-регуляризацией и логистической потерей (S. Rendle, Factorization Machines, ICDM 2010,
 * разделы III-B и III-C). Модель без изменений относительно статьи; признаки бинарные.
 */
final class Fm
{
	public function __construct(
		private readonly int $factors,
		private readonly float $learningRate,
		private readonly float $reg,
		private readonly float $initStdDev,
		private readonly ?int $seed = null,
	)
	{
	}

	/**
	 * @param list<array{0: list<int>, 1: int}> $samples (индексы активных признаков, метка 0 или 1)
	 */
	public function fit(array $samples, int $featureCount, int $epochs): FmModel
	{
		if ($this->seed !== null)
		{
			mt_srand($this->seed);
		}

		$k = $this->factors;
		$eta = $this->learningRate;
		$reg = $this->reg;

		$bias = 0.0;
		$weights = array_fill(0, $featureCount, 0.0);
		$factors = [];
		for ($j = 0; $j < $featureCount; $j++)
		{
			for ($f = 0; $f < $k; $f++)
			{
				$factors[$j][$f] = Gaussian::sample() * $this->initStdDev;
			}
		}

		$order = array_keys($samples);
		for ($epoch = 0; $epoch < $epochs; $epoch++)
		{
			shuffle($order);
			foreach ($order as $s)
			{
				[$features, $label] = $samples[$s];

				$y = $bias;
				$sums = array_fill(0, $k, 0.0);
				foreach ($features as $j)
				{
					$y += $weights[$j];
					for ($f = 0; $f < $k; $f++)
					{
						$sums[$f] += $factors[$j][$f];
					}
				}
				$pairs = 0.0;
				for ($f = 0; $f < $k; $f++)
				{
					$squares = 0.0;
					foreach ($features as $j)
					{
						$squares += $factors[$j][$f] * $factors[$j][$f];
					}
					$pairs += $sums[$f] * $sums[$f] - $squares;
				}
				$y += 0.5 * $pairs;

				$g = self::sigmoid($y) - $label;
				$bias -= $eta * $g;
				foreach ($features as $j)
				{
					$weights[$j] -= $eta * ($g + $reg * $weights[$j]);
					for ($f = 0; $f < $k; $f++)
					{
						$vf = $factors[$j][$f];
						$factors[$j][$f] = $vf - $eta * ($g * ($sums[$f] - $vf) + $reg * $vf);
					}
				}
			}
		}

		return new FmModel($bias, $weights, $factors);
	}

	/**
	 * Сигмоида без переполнения exp() на больших |y|.
	 */
	private static function sigmoid(float $y): float
	{
		if ($y >= 0.0)
		{
			return 1.0 / (1.0 + exp(-$y));
		}
		$e = exp($y);

		return $e / (1.0 + $e);
	}
}
