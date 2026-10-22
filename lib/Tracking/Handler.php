<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Tracking;

use Bitrix\Catalog\CatalogViewedProductTable;
use Bitrix\Main\Diag\EventLogger;
use Bitrix\Main\Event;
use Bitrix\Main\ORM\Event as OrmEvent;
use Bitrix\Sale\BasketItem;
use Bitrix\Sale\Order;
use Dzahdev\Recom\Config;

final class Handler
{
	public static function onViewedProductAdd(OrmEvent $event): void
	{
		self::guard(static function () use ($event): void {
			$fields = $event->getParameter('fields');
			EventRecorder::create()->record(
				(int)$fields['FUSER_ID'],
				(int)$fields['PRODUCT_ID'],
				EventType::VIEW,
				(string)$fields['SITE_ID']
			);
		});
	}

	/**
	 * При повторном просмотре refresh() обновляет строку без FUSER_ID и SITE_ID в полях события,
	 * поэтому строка перечитывается по первичному ключу.
	 */
	public static function onViewedProductUpdate(OrmEvent $event): void
	{
		self::guard(static function () use ($event): void {
			$primary = $event->getParameter('primary');
			$id = is_array($primary) ? (int)$primary['ID'] : (int)$primary;
			$row = CatalogViewedProductTable::getRow([
				'select' => ['FUSER_ID', 'PRODUCT_ID', 'SITE_ID'],
				'filter' => ['=ID' => $id],
			]);
			if ($row === null)
			{
				throw new \RuntimeException("Viewed product row {$id} not found after update");
			}

			EventRecorder::create()->record(
				(int)$row['FUSER_ID'],
				(int)$row['PRODUCT_ID'],
				EventType::VIEW,
				(string)$row['SITE_ID']
			);
		});
	}

	public static function onBasketItemSaved(Event $event): void
	{
		self::guard(static function () use ($event): void {
			/** @var BasketItem $item */
			$item = $event->getParameter('ENTITY');
			if ($item->getField('MODULE') !== 'catalog' || (int)$item->getField('ORDER_ID') > 0)
			{
				// Не товар каталога либо позиция уже в заказе: покупку учитывает onOrderSaved.
				return;
			}

			$delayChanged = array_key_exists('DELAY', (array)$event->getParameter('VALUES'));
			if (!$event->getParameter('IS_NEW') && !$delayChanged)
			{
				// Изменение количества или цены не является новым сигналом интереса.
				return;
			}

			EventRecorder::create()->record(
				(int)$item->getFUserId(),
				(int)$item->getProductId(),
				$item->getField('DELAY') === 'Y' ? EventType::DELAY : EventType::CART,
				(string)$item->getField('LID')
			);
		});
	}

	public static function onOrderSaved(Event $event): void
	{
		self::guard(static function () use ($event): void {
			if (!$event->getParameter('IS_NEW'))
			{
				return;
			}

			/** @var Order $order */
			$order = $event->getParameter('ENTITY');
			$recorder = EventRecorder::create();
			/** @var BasketItem $item */
			foreach ($order->getBasket() as $item)
			{
				if ($item->getField('MODULE') !== 'catalog')
				{
					continue;
				}
				$recorder->record((int)$item->getFUserId(), (int)$item->getProductId(), EventType::BUY, $order->getSiteId());
			}
		});
	}

	/**
	 * Сбой учета события не должен ломать просмотр, корзину и оформление заказа:
	 * ошибка пишется в журнал событий и дальше в ядро магазина не передается.
	 */
	private static function guard(callable $action): void
	{
		try
		{
			$action();
		}
		catch (\Throwable $e)
		{
			(new EventLogger(Config::MODULE_ID, 'DZAHDEV_RECOM_TRACKING'))->error(
				'{message} in {file}:{line}',
				['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]
			);
		}
	}
}
