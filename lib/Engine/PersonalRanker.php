<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

/**
 * Персональная выдача по набору кандидатов (соседи товаров пользователя и популярное) с оценкой
 * модели ALS или FM. Перебор всего каталога для каждого пользователя заменен кандидатами, чтобы
 * подготовка подборок росла линейно с числом пользователей.
 */
final class PersonalRanker
{
	public function __construct(private readonly int $limit)
	{
	}

	/**
	 * @param callable(int): ?float $score оценка товара для пользователя; null - товар модели неизвестен
	 * @param list<int> $candidateIds ID товаров, повторы допускаются
	 * @param array<int, true> $exclude ID товаров, которые не выдаются
	 * @return array<int, float> ID товара => оценка, по убыванию
	 */
	public function rank(callable $score, array $candidateIds, array $exclude): array
	{
		$scores = [];
		$unknown = [];
		foreach ($candidateIds as $itemId)
		{
			if (isset($exclude[$itemId]) || isset($scores[$itemId]) || isset($unknown[$itemId]))
			{
				continue;
			}
			$value = $score($itemId);
			if ($value === null)
			{
				$unknown[$itemId] = true;
				continue;
			}
			$scores[$itemId] = $value;
		}

		return TopK::select($scores, $this->limit);
	}
}
