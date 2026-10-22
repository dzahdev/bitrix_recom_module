<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Training;

use Bitrix\Main\Type\DateTime;

final class TrainResult
{
	public function __construct(
		public readonly string $mode,
		public readonly DateTime $startedAt,
		public readonly float $durationSec,
		public readonly int $users,
		public readonly int $items,
		public readonly int $pairs,
		public readonly ?float $ndcg,
		public readonly ?float $ndcgFm,
	)
	{
	}

	/**
	 * Строка итога для консольного скрипта и Symfony-команды.
	 */
	public function summary(): string
	{
		return sprintf(
			'%s training: %d users, %d items, %d pairs, %.1fs, NDCG@10 ALS %s, FM %s',
			$this->mode,
			$this->users,
			$this->items,
			$this->pairs,
			$this->durationSec,
			$this->ndcg === null ? 'not evaluated' : sprintf('%.4f', $this->ndcg),
			$this->ndcgFm === null ? 'not evaluated' : sprintf('%.4f', $this->ndcgFm)
		);
	}
}
