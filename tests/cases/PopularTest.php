<?php

declare(strict_types=1);

use Dzahdev\Recom\Engine\Popular;

$popular = Popular::compute([
	[1, 5, 3.0],
	[2, 5, 1.0],
	[3, 6, 2.0],
	[1, 5, 4.0],
	[4, 0, 10.0],
], 2);

check($popular[0] === [4 => 10.0, 1 => 7.0], 'popular: whole catalog top 2 with summed scores');
check($popular[5] === [1 => 7.0, 2 => 1.0], 'popular: section 5');
check($popular[6] === [3 => 2.0], 'popular: section 6');
check(!isset($popular[-1]) && count($popular) === 3, 'popular: product without section only in catalog list');
check(Popular::compute([], 10) === [], 'popular: no signals');
