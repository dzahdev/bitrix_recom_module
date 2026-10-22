<?php

declare(strict_types=1);

use Dzahdev\Recom\Engine\VectorCodec;

// Значения точно представимы во float32, поэтому круговое преобразование без потерь.
$vector = [0.5, -1.25, 3.0, 0.0];
check(VectorCodec::unpackVector(VectorCodec::packVector($vector)) === $vector, 'codec: vector round trip');
check(strlen(VectorCodec::packVector($vector)) === 16, 'codec: 4 bytes per factor');
check(VectorCodec::unpackVector('') === [], 'codec: empty vector');

$scores = [7 => 0.5, 3 => 0.25, 42 => -2.0];
check(VectorCodec::unpackScores(VectorCodec::packScores($scores)) === $scores, 'codec: scores round trip keeps keys and order');
check(VectorCodec::packScores([]) === '' && VectorCodec::unpackScores('') === [], 'codec: empty scores');

$thrown = false;
try
{
	VectorCodec::unpackScores('abc');
}
catch (\InvalidArgumentException)
{
	$thrown = true;
}
check($thrown, 'codec: broken scores payload throws');
