<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

final class TopK
{
	/**
	 * Первые $k элементов по убыванию оценки. Сортировка PHP 8 устойчивая:
	 * при равных оценках сохраняется порядок вставки.
	 *
	 * @param array<int, float> $scores ID => оценка
	 * @return array<int, float>
	 */
	public static function select(array $scores, int $k): array
	{
		arsort($scores);

		return array_slice($scores, 0, $k, true);
	}
}
