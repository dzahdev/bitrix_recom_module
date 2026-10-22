<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Agent;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Dzahdev\Recom\Config;
use Dzahdev\Recom\Orm\EventTable;

final class CleanupAgent
{
	/**
	 * Удаляет события старше event_ttl_days одним запросом: построчное удаление через ORM
	 * на сотнях тысяч строк заняло бы агент надолго.
	 */
	public static function run(): string
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$border = (new DateTime())->add('-' . Config::int('event_ttl_days') . ' days');

		$connection->queryExecute(sprintf(
			'DELETE FROM %s WHERE %s < %s',
			$helper->quote(EventTable::getTableName()),
			$helper->quote('CREATED_AT'),
			$helper->convertToDbDateTime($border)
		));

		return '\\' . self::class . '::run();';
	}
}
