<?php

declare(strict_types=1);

use Dzahdev\Recom\Engine\PersonalRanker;

$scores = [1 => 1.0, 2 => 0.0, 3 => 0.5];
$score = static fn(int $itemId): ?float => $scores[$itemId] ?? null;
$ranked = (new PersonalRanker(5))->rank($score, [2, 3, 1, 3, 99], [1 => true]);
check($ranked === [3 => 0.5, 2 => 0.0], 'ranker: excludes seen, dedups, skips unknown, sorts by score');
check((new PersonalRanker(1))->rank($score, [2, 3], []) === [3 => 0.5], 'ranker: limit');
