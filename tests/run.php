<?php

declare(strict_types=1);

/**
 * Тесты чистой математики модуля, без загрузки Битрикса.
 * Запуск: docker exec bitrix_php php /var/www/bitrix/local/modules/dzahdev.recom/tests/run.php
 */

require __DIR__ . '/autoload.php';

$failed = 0;
$passed = 0;

function check(bool $condition, string $message): void
{
	global $failed, $passed;
	if ($condition)
	{
		echo "OK   {$message}\n";
		$passed++;
	}
	else
	{
		echo "FAIL {$message}\n";
		$failed++;
	}
}

function near(float $actual, float $expected, float $epsilon, string $message): void
{
	check(abs($actual - $expected) <= $epsilon, sprintf('%s (got %.9f, want %.9f)', $message, $actual, $expected));
}

$cases = glob(__DIR__ . '/cases/*Test.php');
sort($cases);
foreach ($cases as $case)
{
	require $case;
}

echo "\nPassed: {$passed}, failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
