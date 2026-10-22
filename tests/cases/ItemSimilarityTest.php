<?php

declare(strict_types=1);

use Dzahdev\Recom\Engine\Dataset;
use Dzahdev\Recom\Engine\ItemSimilarity;

// Порядок соседей: у товара 10 покупатели 1-10; у 20 - 1-9 (9 общих); у 30 - 1-3 и 50-56 (3 общих);
// у 40 - 50-60 (общих с 10 нет). Ближайший к 10 - 20, затем 30; 40 в соседях нет.
$pairs = [];
foreach ([10 => range(1, 10), 20 => range(1, 9), 30 => array_merge(range(1, 3), range(50, 56)), 40 => range(50, 60)] as $item => $users)
{
	foreach ($users as $user)
	{
		$pairs[] = [$user, $item];
	}
}
$dataset = Dataset::fromPairs($pairs);
$similar = (new ItemSimilarity(10))->compute($dataset, array_fill(0, $dataset->countUsers(), 1.0));
check(array_keys($similar[10]) === [20, 30], 'similarity: neighbors ordered by weighted overlap, no pair without common users');

// Сжатие n / (n + 100): при одинаковом косинусе (0.7071) пара с 20 общими покупателями ближе пары с 2.
// A = {1, 2}, B = {1..4}; C = {101..120}, D = {101..140}.
$pairs = [];
foreach ([1 => range(1, 2), 2 => range(1, 4), 3 => range(101, 120), 4 => range(101, 140)] as $item => $users)
{
	foreach ($users as $user)
	{
		$pairs[] = [$user, $item];
	}
}
$dataset = Dataset::fromPairs($pairs);
$similar = (new ItemSimilarity(10))->compute($dataset, array_fill(0, $dataset->countUsers(), 1.0));
check($similar[3][4] > $similar[1][2], 'similarity: shrinkage favors pairs with more common users');

// Ручной расчет: A = {1, 2}, B = {1, 2, 3}, idf = 1.
// расстояние = 1 - 2·2 / (√2·√3·102) = 0.983990263, оценка = 1 / 1.983990263 = 0.504034732
$dataset = Dataset::fromPairs([[1, 10], [2, 10], [1, 20], [2, 20], [3, 20]]);
$similar = (new ItemSimilarity(5))->compute($dataset, [1.0, 1.0, 1.0]);
near($similar[10][20], 0.504034732, 1e-9, 'similarity: hand-computed score A to B');
near($similar[20][10], 0.504034732, 1e-9, 'similarity: symmetric score B to A');

// Совпадающие множества пользователей: расстояние 0, оценка 1.
$dataset = Dataset::fromPairs([[1, 10], [2, 10], [1, 20], [2, 20]]);
$similar = (new ItemSimilarity(5))->compute($dataset, [1.0, 1.0]);
near($similar[10][20], 1.0, 1e-12, 'similarity: identical user sets');

// Нет общих пользователей - пары нет; товар без соседей получает пустой список.
$dataset = Dataset::fromPairs([[1, 10], [2, 20]]);
$similar = (new ItemSimilarity(5))->compute($dataset);
check($similar[10] === [] && $similar[20] === [], 'similarity: no common users gives no neighbors');

// Пользователь с историей длиннее maxUserItems не участвует в сходстве.
$dataset = Dataset::fromPairs([[1, 10], [1, 20], [1, 30]]);
$similar = (new ItemSimilarity(5, 100, 2))->compute($dataset, [1.0]);
check($similar[10] === [], 'similarity: heavy user skipped');
