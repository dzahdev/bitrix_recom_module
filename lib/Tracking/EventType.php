<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Tracking;

final class EventType
{
	public const VIEW = 'view';
	public const CART = 'cart';
	public const DELAY = 'delay';
	public const BUY = 'buy';

	public const ALL = [self::VIEW, self::CART, self::DELAY, self::BUY];
}
