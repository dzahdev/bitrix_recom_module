<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

final class Gaussian
{
	/**
	 * Стандартное нормальное распределение, преобразование Бокса - Мюллера на mt_rand:
	 * mt_srand() в моделях делает результат воспроизводимым.
	 */
	public static function sample(): float
	{
		$u1 = (mt_rand() + 1) / (mt_getrandmax() + 1);
		$u2 = mt_rand() / mt_getrandmax();

		return sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
	}
}
