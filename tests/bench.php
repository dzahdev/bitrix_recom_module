<?php

declare(strict_types=1);

/**
 * Замер обучения на синтетике: 5 000 пользователей, 1 000 товаров, 100 000 событий.
 * Запуск: docker exec bitrix_php php /var/www/bitrix/local/modules/dzahdev.recom/tests/bench.php
 */

require __DIR__ . '/autoload.php';

use Dzahdev\Recom\Engine\Als;
use Dzahdev\Recom\Engine\Dataset;
use Dzahdev\Recom\Engine\FeatureIndex;
use Dzahdev\Recom\Engine\Fm;
use Dzahdev\Recom\Engine\FmSamples;
use Dzahdev\Recom\Engine\ItemSimilarity;

mt_srand(1);
$pairs = [];
for ($e = 0; $e < 100000; $e++)
{
	// Квадрат равномерной величины дает перекос популярности к товарам с малыми номерами.
	$pairs[] = [mt_rand(1, 5000), (int)floor(1000 * (mt_rand() / mt_getrandmax()) ** 2) + 1];
}

$start = microtime(true);
$dataset = Dataset::fromPairs($pairs);
$datasetTime = microtime(true) - $start;

$start = microtime(true);
(new Als(16, 0.06, 0.001, 0.1, 1))->fit($dataset, 20);
$alsTime = microtime(true) - $start;

$start = microtime(true);
(new ItemSimilarity(20))->compute($dataset);
$similarityTime = microtime(true) - $start;

// Синтетические разделы: 20 разделов по номеру товара.
$sections = [];
foreach ($dataset->itemIds() as $itemId)
{
	$sections[$itemId] = $itemId % 20 + 1;
}
$start = microtime(true);
$index = new FeatureIndex();
$samples = FmSamples::build($dataset, $sections, 2, $index);
(new Fm(8, 0.05, 0.0001, 0.01, 1))->fit($samples, $index->count(), 30);
$fmTime = microtime(true) - $start;

printf("pairs=%d users=%d items=%d\n", $dataset->countPairs(), $dataset->countUsers(), $dataset->countItems());
printf("dataset %.1fs, als 20 epochs %.1fs, peak memory %.0f MB\n", $datasetTime, $alsTime, memory_get_peak_usage(true) / 1048576);
printf("similarity %.1fs\n", $similarityTime);
printf("fm 30 epochs, %d samples %.1fs\n", count($samples), $fmTime);
