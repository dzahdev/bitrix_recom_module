<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

/**
 * Упаковка векторов и списков оценок в бинарные строки: float32 и uint32 little-endian.
 */
final class VectorCodec
{
	/** @param list<float> $vector */
	public static function packVector(array $vector): string
	{
		return pack('g*', ...$vector);
	}

	/** @return list<float> */
	public static function unpackVector(string $binary): array
	{
		if ($binary === '')
		{
			return [];
		}

		return array_values(unpack('g*', $binary));
	}

	/**
	 * @param array<int, float> $scores ID => оценка; порядок сохраняется
	 */
	public static function packScores(array $scores): string
	{
		if ($scores === [])
		{
			return '';
		}

		return pack('V*', ...array_keys($scores)) . pack('g*', ...array_values($scores));
	}

	/**
	 * @return array<int, float>
	 * @throws \InvalidArgumentException если длина строки не кратна 8 байтам
	 */
	public static function unpackScores(string $binary): array
	{
		if ($binary === '')
		{
			return [];
		}

		$length = strlen($binary);
		if ($length % 8 !== 0)
		{
			throw new \InvalidArgumentException("Packed scores length {$length} is not a multiple of 8");
		}

		$count = intdiv($length, 8);
		$ids = array_values(unpack('V' . $count, substr($binary, 0, 4 * $count)));
		$scores = array_values(unpack('g' . $count, substr($binary, 4 * $count)));

		return array_combine($ids, $scores);
	}
}
