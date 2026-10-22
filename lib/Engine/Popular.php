<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

/**
 * Популярное: сумма оценок сигналов, отдельный рейтинг для всего каталога (раздел 0) и для каждого
 * раздела.
 */
final class Popular
{
	/**
	 * @param iterable<array{0: int, 1: int, 2: float}> $rows (ID товара, ID раздела, оценка)
	 * @return array<int, array<int, float>> ID раздела => [ID товара => оценка]
	 */
	public static function compute(iterable $rows, int $limit): array
	{
		$bySection = [];
		foreach ($rows as [$itemId, $sectionId, $score])
		{
			$bySection[0][$itemId] = ($bySection[0][$itemId] ?? 0.0) + $score;
			if ($sectionId > 0)
			{
				$bySection[$sectionId][$itemId] = ($bySection[$sectionId][$itemId] ?? 0.0) + $score;
			}
		}

		return array_map(static fn(array $scores): array => TopK::select($scores, $limit), $bySection);
	}
}
