<?php

declare(strict_types=1);

namespace Dzahdev\Recom;

use Bitrix\Main\Config\Option;

final class Config
{
	public const MODULE_ID = 'dzahdev.recom';

	/**
	 * @throws \LogicException если настройка не задана и нет значения в default_option.php
	 */
	public static function string(string $name): string
	{
		$value = Option::get(self::MODULE_ID, $name);
		if ($value === '')
		{
			throw new \LogicException("Option {$name} of module " . self::MODULE_ID . ' is not defined');
		}

		return $value;
	}

	public static function int(string $name): int
	{
		return (int)self::string($name);
	}

	public static function float(string $name): float
	{
		return (float)self::string($name);
	}

	public static function set(string $name, string $value): void
	{
		Option::set(self::MODULE_ID, $name, $value);
	}

	/**
	 * @return list<string> типы событий, которые считаются положительным сигналом
	 */
	public static function positiveTypes(): array
	{
		return array_values(array_filter(array_map('trim', explode(',', self::string('positive_types')))));
	}

	public static function weight(string $type): float
	{
		return self::float('weight_' . $type);
	}
}
