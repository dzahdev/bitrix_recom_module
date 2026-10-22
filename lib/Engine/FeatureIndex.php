<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

/**
 * Отображение имен признаков ("u15", "i204", "s7") в индексы 0..n-1 для FM.
 */
final class FeatureIndex
{
	/** @var array<string, int> */
	private array $index = [];

	public function id(string $name): int
	{
		return $this->index[$name] ??= count($this->index);
	}

	public function find(string $name): ?int
	{
		return $this->index[$name] ?? null;
	}

	public function count(): int
	{
		return count($this->index);
	}
}
