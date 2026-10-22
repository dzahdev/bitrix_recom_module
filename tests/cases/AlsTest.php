<?php

declare(strict_types=1);

use Dzahdev\Recom\Engine\Als;
use Dzahdev\Recom\Engine\AlsModel;
use Dzahdev\Recom\Engine\Dataset;
use Dzahdev\Recom\Engine\TopK;

// Два кластера: пользователи 1-20 выбирают 6 из товаров 1-10, пользователи 21-40 - 6 из товаров 11-20.
mt_srand(7);
$pairs = [];
for ($user = 1; $user <= 40; $user++)
{
	$base = $user <= 20 ? 1 : 11;
	$items = range($base, $base + 9);
	shuffle($items);
	foreach (array_slice($items, 0, 6) as $item)
	{
		$pairs[] = [$user, $item];
	}
}
$dataset = Dataset::fromPairs($pairs);
$model = (new Als(8, 0.06, 0.001, 0.1, 42))->fit($dataset, 20);

// У каждого пользователя 4 невыбранных товара своего кластера: верхние 4 должны быть из него.
$inCluster = 0;
$total = 0;
for ($u = 0; $u < $dataset->countUsers(); $u++)
{
	$userId = $dataset->userId($u);
	$seen = array_flip($dataset->userItems($u));
	$scores = [];
	for ($i = 0; $i < $dataset->countItems(); $i++)
	{
		if (!isset($seen[$i]))
		{
			$scores[$dataset->itemId($i)] = AlsModel::dot($model->userVector($u), $model->itemVector($i));
		}
	}
	foreach (array_keys(TopK::select($scores, 4)) as $itemId)
	{
		$sameCluster = ($userId <= 20) === ($itemId <= 10);
		$inCluster += $sameCluster ? 1 : 0;
		$total++;
	}
}
check($inCluster / $total >= 0.9, sprintf('als: %.0f%% of top-4 inside user cluster', 100 * $inCluster / $total));

// Детерминированность при одинаковом seed.
$again = (new Als(8, 0.06, 0.001, 0.1, 42))->fit($dataset, 20);
check($again->userFactorsById() === $model->userFactorsById(), 'als: same seed gives same factors');

// Теплый старт без эпох возвращает переданные векторы; вектор другой размерности заменяется случайным.
$warmUsers = $model->userFactorsById();
$warmItems = $model->itemFactorsById();
$warmItems[1] = [1.0, 2.0];
$warm = (new Als(8, 0.06, 0.001, 0.1, 1))->fit($dataset, 0, $warmUsers, $warmItems);
check($warm->userFactorsById() === $warmUsers, 'als: warm start keeps user vectors');
check(count($warm->itemFactorsById()[1]) === 8 && $warm->itemFactorsById()[1] !== [1.0, 2.0], 'als: warm vector of wrong size is reinitialized');

// Метрика на отложенной выборке выше случайной выдачи (у случайной NDCG@4 около 0.2).
$holdout = [];
$trainPairs = [];
foreach ($pairs as [$user, $item])
{
	if (!isset($holdout[$user]))
	{
		$holdout[$user] = $item;
		continue;
	}
	$trainPairs[] = [$user, $item];
}
$trainModel = (new Als(8, 0.06, 0.001, 0.1, 42))->fit(Dataset::fromPairs($trainPairs), 20);
$ndcg = \Dzahdev\Recom\Engine\Ndcg::evaluate(
	$trainModel->dataset(),
	static fn(int $u, int $i): float => AlsModel::dot($trainModel->userVector($u), $trainModel->itemVector($i)),
	$holdout,
	4
);
check($ndcg !== null && $ndcg > 0.35, sprintf('als: holdout NDCG@4 = %.3f above random', $ndcg ?? -1));
