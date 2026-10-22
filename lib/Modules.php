<?php

declare(strict_types=1);

namespace Dzahdev\Recom;

use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;

final class Modules
{
	/**
	 * Подключает модули ядра, без которых код модуля не работает. Вне страницы сайта (консоль,
	 * агент, AJAX-контроллер) ядро их само не подключает.
	 *
	 * @throws SystemException если модуль не установлен
	 */
	public static function require(string ...$moduleIds): void
	{
		foreach ($moduleIds as $moduleId)
		{
			if (!Loader::includeModule($moduleId))
			{
				throw new SystemException("Module {$moduleId} is not installed");
			}
		}
	}
}
