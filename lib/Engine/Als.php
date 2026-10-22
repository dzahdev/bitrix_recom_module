<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

/**
 * Матричная факторизация для неявных сигналов, покоординатный ALS с кешем матрицы Грама (eALS:
 * X. He, H. Zhang, M.-Y. Kan, T.-S. Chua, Fast Matrix Factorization for Online Recommendation with
 * Implicit Feedback, SIGIR 2016; модель неявных сигналов - Y. Hu, Y. Koren, C. Volinsky, ICDM 2008).
 * Положительная пара имеет цель 1 и вес 1, все остальные пары - цель 0 и одинаковый вес alpha.
 */
final class Als
{
	public function __construct(
		private readonly int $factors,
		private readonly float $reg,
		private readonly float $alpha,
		private readonly float $initStdDev,
		private readonly ?int $seed = null,
	)
	{
	}

	/**
	 * @param array<int, list<float>> $warmUsers ID пользователя => вектор прошлого обучения
	 * @param array<int, list<float>> $warmItems ID товара => вектор прошлого обучения
	 */
	public function fit(Dataset $dataset, int $epochs, array $warmUsers = [], array $warmItems = []): AlsModel
	{
		if ($this->seed !== null)
		{
			mt_srand($this->seed);
		}

		$p = [];
		foreach ($dataset->userIds() as $u => $userId)
		{
			$p[$u] = $this->initVector($warmUsers[$userId] ?? null);
		}
		$q = [];
		foreach ($dataset->itemIds() as $i => $itemId)
		{
			$q[$i] = $this->initVector($warmItems[$itemId] ?? null);
		}

		$userCount = $dataset->countUsers();
		$itemCount = $dataset->countItems();
		for ($epoch = 0; $epoch < $epochs; $epoch++)
		{
			$s = $this->gram($q);
			for ($u = 0; $u < $userCount; $u++)
			{
				$p[$u] = $this->solve($p[$u], $dataset->userItems($u), $q, $s);
			}

			$s = $this->gram($p);
			for ($i = 0; $i < $itemCount; $i++)
			{
				$q[$i] = $this->solve($q[$i], $dataset->itemUsers($i), $p, $s);
			}
		}

		return new AlsModel($dataset, $p, $q);
	}

	/**
	 * Обновление одного вектора по всем факторам.
	 *
	 * @param list<float> $x вектор пользователя (или товара)
	 * @param list<int> $observed индексы товаров (или пользователей) с положительным сигналом
	 * @param array<int, list<float>> $other векторы противоположной стороны
	 * @param list<list<float>> $s матрица Грама противоположной стороны
	 * @return list<float>
	 */
	private function solve(array $x, array $observed, array $other, array $s): array
	{
		$k = $this->factors;
		$alpha = $this->alpha;

		$prediction = [];
		foreach ($observed as $j)
		{
			$prediction[$j] = AlsModel::dot($x, $other[$j]);
		}

		for ($f = 0; $f < $k; $f++)
		{
			$a = 0.0;
			$c = 0.0;
			$residual = [];
			foreach ($observed as $j)
			{
				$yf = $other[$j][$f];
				$r = $prediction[$j] - $x[$f] * $yf;
				$residual[$j] = $r;
				$a += (1.0 - (1.0 - $alpha) * $r) * $yf;
				$c += (1.0 - $alpha) * $yf * $yf;
			}

			$b = 0.0;
			for ($m = 0; $m < $k; $m++)
			{
				if ($m !== $f)
				{
					$b += $alpha * $x[$m] * $s[$m][$f];
				}
			}

			$x[$f] = ($a - $b) / ($c + $alpha * $s[$f][$f] + $this->reg);

			foreach ($observed as $j)
			{
				$prediction[$j] = $residual[$j] + $x[$f] * $other[$j][$f];
			}
		}

		return $x;
	}

	/**
	 * @param array<int, list<float>> $vectors
	 * @return list<list<float>> S = Σ v·vᵀ
	 */
	private function gram(array $vectors): array
	{
		$k = $this->factors;
		$s = array_fill(0, $k, array_fill(0, $k, 0.0));
		foreach ($vectors as $v)
		{
			for ($a = 0; $a < $k; $a++)
			{
				$va = $v[$a];
				for ($b = $a; $b < $k; $b++)
				{
					$s[$a][$b] += $va * $v[$b];
				}
			}
		}
		for ($a = 0; $a < $k; $a++)
		{
			for ($b = 0; $b < $a; $b++)
			{
				$s[$a][$b] = $s[$b][$a];
			}
		}

		return $s;
	}

	/**
	 * Вектор прошлого обучения берется как есть. Если число факторов в настройках изменилось,
	 * старый вектор несовместим и заменяется случайным: это смена модели, а не сбой.
	 *
	 * @param list<float>|null $warm
	 * @return list<float>
	 */
	private function initVector(?array $warm): array
	{
		if ($warm !== null && count($warm) === $this->factors)
		{
			return $warm;
		}

		$vector = [];
		for ($f = 0; $f < $this->factors; $f++)
		{
			$vector[] = Gaussian::sample() * $this->initStdDev;
		}

		return $vector;
	}
}
