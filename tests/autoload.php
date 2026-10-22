<?php

declare(strict_types=1);

// Автозагрузка классов модуля для тестов без ядра Битрикса.
spl_autoload_register(static function (string $class): void {
	$prefix = 'Dzahdev\\Recom\\';
	if (!str_starts_with($class, $prefix))
	{
		return;
	}

	$file = dirname(__DIR__) . '/lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($file))
	{
		require $file;
	}
});
