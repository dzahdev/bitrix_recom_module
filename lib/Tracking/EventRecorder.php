<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Tracking;

use Bitrix\Main\Type\DateTime;
use Dzahdev\Recom\Catalog\ProductResolver;
use Dzahdev\Recom\Orm\EventTable;

final class EventRecorder
{
	public function __construct(private readonly ProductResolver $resolver)
	{
	}

	public static function create(): self
	{
		return new self(new ProductResolver());
	}

	/**
	 * @param int $elementId товар или торговое предложение
	 * @return bool false - элемент вне торгового каталога, событие не записано
	 * @throws \InvalidArgumentException для неизвестного типа события
	 * @throws \RuntimeException если строку не удалось сохранить
	 */
	public function record(int $fuserId, int $elementId, string $type, string $siteId): bool
	{
		if (!in_array($type, EventType::ALL, true))
		{
			throw new \InvalidArgumentException("Unknown event type {$type}");
		}

		$productId = $this->resolver->resolve($elementId);
		if ($productId === null)
		{
			// Элемент вне торгового каталога (например, услуга из другого модуля): это не товар.
			return false;
		}

		$result = EventTable::add([
			'FUSER_ID' => $fuserId,
			'PRODUCT_ID' => $productId,
			'TYPE' => $type,
			'SITE_ID' => $siteId,
			'CREATED_AT' => new DateTime(),
		]);
		if (!$result->isSuccess())
		{
			throw new \RuntimeException('Cannot save recommendation event: ' . implode('; ', $result->getErrorMessages()));
		}

		return true;
	}
}
