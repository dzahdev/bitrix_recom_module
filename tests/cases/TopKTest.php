<?php

declare(strict_types=1);

use Dzahdev\Recom\Engine\TopK;

check(TopK::select([1 => 0.1, 2 => 0.9, 3 => 0.5], 2) === [2 => 0.9, 3 => 0.5], 'topk: two best with keys');
check(TopK::select([5 => 1.0, 6 => 1.0, 7 => 2.0], 3) === [7 => 2.0, 5 => 1.0, 6 => 1.0], 'topk: ties keep insertion order');
check(TopK::select([], 5) === [], 'topk: empty');
