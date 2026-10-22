<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

final class Ndcg
{
	/**
	 * NDCG (K. Jarvelin, J. Kekalainen, Cumulated Gain-Based Evaluation of IR Techniques, ACM TOIS 2002)
	 * для бинарной релевантности. Пустая выдача дает 0: пользователь ничего не получил.
	 *
	 * @param list<int> $targets товары, которые пользователь выбрал в отложенной выборке
	 * @param list<int> $ranked выдача по убыванию оценки
	 * @throws \InvalidArgumentException если целей нет
	 */
	public static function score(array $targets, array $ranked): float
	{
		if ($targets === [])
		{
			throw new \InvalidArgumentException('NDCG needs at least one target item');
		}
		if ($ranked === [])
		{
			return 0.0;
		}

		$targetSet = array_flip($targets);
		$idcg = 0.0;
		for ($i = 0, $n = min(count($targets), count($ranked)); $i < $n; $i++)
		{
			$idcg += 1.0 / log($i + 2.0, 2);
		}

		$dcg = 0.0;
		foreach (array_values($ranked) as $i => $itemId)
		{
			if (isset($targetSet[$itemId]))
			{
				$dcg += 1.0 / log($i + 2.0, 2);
			}
		}

		return $dcg / $idcg;
	}

	/**
	 * Средний NDCG@k по отложенной выборке: для каждого пользователя ранжируются все товары,
	 * которых нет в его обучающей истории.
	 *
	 * @param callable(int, int): float $score оценка модели по индексам пользователя и товара в $dataset
	 * @param array<int, int> $holdout ID пользователя => отложенный ID товара
	 * @return float|null null, если ни одну пару нельзя оценить
	 */
	public static function evaluate(Dataset $dataset, callable $score, array $holdout, int $k): ?float
	{
		$sum = 0.0;
		$count = 0;
		foreach ($holdout as $userId => $itemId)
		{
			$u = $dataset->userIndexOf($userId);
			$target = $dataset->itemIndexOf($itemId);
			if ($u === null || $target === null)
			{
				// Товар встретился только в отложенной выборке: модель о нем не знает, оценивать нечем.
				continue;
			}

			$seen = array_flip($dataset->userItems($u));
			$scores = [];
			for ($i = 0, $n = $dataset->countItems(); $i < $n; $i++)
			{
				if (!isset($seen[$i]))
				{
					$scores[$i] = $score($u, $i);
				}
			}

			$sum += self::score([$target], array_keys(TopK::select($scores, $k)));
			$count++;
		}

		return $count > 0 ? $sum / $count : null;
	}
}
