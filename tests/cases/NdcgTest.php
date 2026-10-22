<?php

declare(strict_types=1);

use Dzahdev\Recom\Engine\Ndcg;

// Одна цель на второй позиции: DCG = 1/log2(3), IDCG = 1/log2(2) = 1.
near(Ndcg::score([5], [3, 5, 7]), 0.630929754, 1e-9, 'ndcg: target at position 2');
near(Ndcg::score([5], [5, 3, 7]), 1.0, 1e-12, 'ndcg: target at position 1');
near(Ndcg::score([5], [1, 2, 3]), 0.0, 1e-12, 'ndcg: target missing');
near(Ndcg::score([5], []), 0.0, 1e-12, 'ndcg: empty ranking scores zero');

$thrown = false;
try
{
	Ndcg::score([], [1, 2]);
}
catch (\InvalidArgumentException)
{
	$thrown = true;
}
check($thrown, 'ndcg: no targets throws');
