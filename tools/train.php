<?php

/**
 * Обучение моделей из консоли без Symfony Console и composer:
 * php <корень сайта>/local/modules/dzahdev.recom/tools/train.php [--full]
 * Код выхода 0 - обучение прошло, 1 - ошибка (текст в stderr, подробности в журнале обучения).
 */

declare(strict_types=1);

use Bitrix\Main\Loader;
use Dzahdev\Recom\Training\Trainer;

if (PHP_SAPI !== 'cli')
{
	http_response_code(404);
	exit(1);
}

// tools -> dzahdev.recom -> modules -> local или bitrix -> корень сайта
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 4);
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (!Loader::includeModule('dzahdev.recom'))
{
	fwrite(STDERR, "Module dzahdev.recom is not installed\n");
	exit(1);
}

// Обработчик исключений ядра печатает общее сообщение и завершает процесс с кодом 0, поэтому
// cron и CI не увидели бы сбой. Граница процесса - единственное место, где ошибку переводят в код выхода.
try
{
	$full = in_array('--full', $argv, true);
	$result = Trainer::create()->run($full ? Trainer::MODE_FULL : Trainer::MODE_INCREMENTAL);
}
catch (\Throwable $e)
{
	fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n");
	exit(1);
}

echo $result->summary(), "\n";
