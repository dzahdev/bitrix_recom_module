<?php

declare(strict_types=1);

use Dzahdev\Recom\Engine\Dataset;

$dataset = Dataset::fromPairs([[10, 100], [10, 101], [11, 100], [10, 100]]);

check($dataset->countUsers() === 2, 'dataset: two users');
check($dataset->countItems() === 2, 'dataset: two items');
check($dataset->countPairs() === 3, 'dataset: duplicate pair counted once');
check($dataset->userIndexOf(10) === 0 && $dataset->userIndexOf(11) === 1, 'dataset: user indexes in order of appearance');
check($dataset->itemIndexOf(100) === 0 && $dataset->itemIndexOf(101) === 1, 'dataset: item indexes in order of appearance');
check($dataset->userIndexOf(999) === null, 'dataset: unknown user');
check($dataset->userItems(0) === [0, 1], 'dataset: items of user 10');
check($dataset->itemUsers(0) === [0, 1], 'dataset: users of item 100');
check($dataset->userId(1) === 11 && $dataset->itemId(1) === 101, 'dataset: index to id');
check($dataset->userIds() === [10, 11] && $dataset->itemIds() === [100, 101], 'dataset: id lists');

// idf(u) = max(log(nItems / |items(u)|), 1e-3): у пользователя 10 оба товара, у 11 - один из двух
$idf = $dataset->userIdf();
near($idf[0], 0.001, 1e-12, 'dataset: idf floor for user with all items');
near($idf[1], log(2.0), 1e-12, 'dataset: idf of user with one item');

$empty = Dataset::fromPairs([]);
check($empty->countUsers() === 0 && $empty->countPairs() === 0, 'dataset: empty');
