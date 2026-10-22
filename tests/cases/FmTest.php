<?php

declare(strict_types=1);

use Dzahdev\Recom\Engine\Dataset;
use Dzahdev\Recom\Engine\FeatureIndex;
use Dzahdev\Recom\Engine\Fm;
use Dzahdev\Recom\Engine\FmModel;
use Dzahdev\Recom\Engine\FmSamples;
use Dzahdev\Recom\Engine\Ndcg;
use Dzahdev\Recom\Engine\TopK;

// Формула на заданных весах: w0 = 0.5, w = [1, 2, 3], v0 = (1, 0), v1 = (0, 1), v2 = (1, 1).
$model = new FmModel(0.5, [1.0, 2.0, 3.0], [[1.0, 0.0], [0.0, 1.0], [1.0, 1.0]]);
near($model->predict([0, 2]), 0.5 + 1.0 + 3.0 + 1.0, 1e-12, 'fm: two features, one pair');
near($model->predict([0, 1, 2]), 0.5 + 6.0 + 0.0 + 1.0 + 1.0, 1e-12, 'fm: three features, three pairs');
near($model->predict([]), 0.5, 1e-12, 'fm: no features gives bias');

$index = new FeatureIndex();
check($index->id('u1') === 0 && $index->id('i5') === 1 && $index->id('u1') === 0, 'fm: feature index is stable');
check($index->find('s9') === null && $index->count() === 2, 'fm: find does not create');

// Синтетика с разделами: товары 1-10 в разделе 1, 11-20 в разделе 2;
// покупатели 1-20 выбирают 6 товаров раздела 1, покупатели 21-40 - 6 товаров раздела 2.
$sections = [];
for ($item = 1; $item <= 21; $item++)
{
	$sections[$item] = ($item <= 10 || $item === 21) ? 1 : 2;
}
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

$index = new FeatureIndex();
mt_srand(3);
$samples = FmSamples::build($dataset, $sections, 2, $index);
check(count($samples) === $dataset->countPairs() * 3, 'fm: one positive and two negatives per pair');
check(count(array_filter($samples, static fn(array $s): bool => $s[1] === 1)) === $dataset->countPairs(), 'fm: positives count');

$fm = (new Fm(4, 0.05, 0.0001, 0.01, 42))->fit($samples, $index->count(), 30);
$score = static fn(int $userId, int $itemId): float
	=> $fm->predict(FmSamples::features($index, $userId, $itemId, $sections[$itemId]));

// Верхние 4 невыбранных товара - из раздела покупателя.
$inCluster = 0;
$total = 0;
for ($u = 0; $u < $dataset->countUsers(); $u++)
{
	$userId = $dataset->userId($u);
	$seen = array_flip(array_map([$dataset, 'itemId'], $dataset->userItems($u)));
	$scores = [];
	for ($item = 1; $item <= 20; $item++)
	{
		if (!isset($seen[$item]))
		{
			$scores[$item] = $score($userId, $item);
		}
	}
	foreach (array_keys(TopK::select($scores, 4)) as $itemId)
	{
		$inCluster += (($userId <= 20) === ($itemId <= 10)) ? 1 : 0;
		$total++;
	}
}
check($inCluster / $total >= 0.9, sprintf('fm: %.0f%% of top-4 inside user section', 100 * $inCluster / $total));

// Новый товар 21 в разделе 1 без истории: признак товара неизвестен, оценка идет через раздел.
$sectionOneUsers = 0.0;
$sectionTwoUsers = 0.0;
for ($user = 1; $user <= 40; $user++)
{
	if ($user <= 20)
	{
		$sectionOneUsers += $score($user, 21);
	}
	else
	{
		$sectionTwoUsers += $score($user, 21);
	}
}
check($sectionOneUsers / 20 > $sectionTwoUsers / 20, 'fm: cold item scores higher for users of its section');

// Отложенная выборка: NDCG@4 выше случайной выдачи (около 0.2).
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
$train = Dataset::fromPairs($trainPairs);
$trainIndex = new FeatureIndex();
mt_srand(3);
$trainFm = (new Fm(4, 0.05, 0.0001, 0.01, 42))->fit(FmSamples::build($train, $sections, 2, $trainIndex), $trainIndex->count(), 30);
$ndcg = Ndcg::evaluate(
	$train,
	static fn(int $u, int $i): float => $trainFm->predict(
		FmSamples::features($trainIndex, $train->userId($u), $train->itemId($i), $sections[$train->itemId($i)])
	),
	$holdout,
	4
);
check($ndcg !== null && $ndcg > 0.35, sprintf('fm: holdout NDCG@4 = %.3f above random', $ndcg ?? -1));
