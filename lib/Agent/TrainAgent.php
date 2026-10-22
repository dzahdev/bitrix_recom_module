<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Agent;

use Bitrix\Main\Diag\EventLogger;
use Dzahdev\Recom\Config;
use Dzahdev\Recom\Training\Trainer;

final class TrainAgent
{
	public static function run(): string
	{
		$next = '\\' . self::class . '::run();';

		if (PHP_SAPI !== 'cli' && !(defined('BX_CRONTAB') && BX_CRONTAB === true))
		{
			(new EventLogger(Config::MODULE_ID, 'DZAHDEV_RECOM_TRAIN'))->warning(
				'Training skipped: agents run on page hits. Configure agents on cron or run php local/modules/dzahdev.recom/tools/train.php from the site root'
			);

			return $next;
		}

		$full = Config::string('full_train_requested') === 'Y'
			|| time() - Config::int('last_full_train_at') >= Config::int('train_full_interval');

		try
		{
			Trainer::create()->run($full ? Trainer::MODE_FULL : Trainer::MODE_INCREMENTAL);
		}
		catch (\Throwable)
		{
			// Trainer уже записал ошибку в журнал событий и журнал обучения. Агент остается
			// в расписании, выдача работает на последней успешной модели.
		}

		return $next;
	}
}
