<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Recommend;

use Bitrix\Sale\Basket;
use Bitrix\Sale\BasketItem;
use Dzahdev\Recom\Catalog\ProductResolver;
use Dzahdev\Recom\Config;
use Dzahdev\Recom\Training\DatasetLoader;
use Dzahdev\Recom\Training\ModelStore;

/**
 * Выдача на странице: только чтение готовых таблиц и данных текущего покупателя.
 */
final class Recommender
{
	public const SOURCE_PERSONAL = 'personal';
	public const SOURCE_SIMILAR = 'similar';
	public const SOURCE_POPULAR = 'popular';

	private const RECENT_LIMIT = 10;

	public function __construct(
		private readonly ModelStore $store,
		private readonly DatasetLoader $loader,
		private readonly ProductResolver $resolver,
	)
	{
	}

	public static function create(): self
	{
		return new self(new ModelStore(), new DatasetLoader(), new ProductResolver());
	}

	/**
	 * @param int|null $fuserId null - посетитель без корзины покупателя, истории у него нет
	 * @return array{ids: list<int>, source: string}
	 */
	public function personal(?int $fuserId, string $siteId, int $limit): array
	{
		if ($fuserId === null)
		{
			return $this->result($this->store->loadPopular(0), [], $limit, self::SOURCE_POPULAR);
		}

		$exclude = $this->excluded($fuserId, $siteId);

		$personal = $this->result($this->store->loadUserRec($fuserId), $exclude, $limit, self::SOURCE_PERSONAL);
		if ($personal['ids'] !== [])
		{
			return $personal;
		}

		$weights = [];
		foreach (Config::positiveTypes() as $type)
		{
			$weights[$type] = Config::weight($type);
		}
		$recent = $this->loader->recentProducts($fuserId, $weights, self::RECENT_LIMIT);
		if ($recent !== [])
		{
			$scores = [];
			foreach ($this->store->loadItemSims(array_keys($recent)) as $productId => $neighbors)
			{
				foreach ($neighbors as $neighborId => $score)
				{
					$scores[$neighborId] = ($scores[$neighborId] ?? 0.0) + $score * $recent[$productId];
				}
			}
			arsort($scores);

			$similar = $this->result($scores, $exclude + $recent, $limit, self::SOURCE_SIMILAR);
			if ($similar['ids'] !== [])
			{
				return $similar;
			}
		}

		return $this->result($this->store->loadPopular(0), $exclude, $limit, self::SOURCE_POPULAR);
	}

	/**
	 * @return array{ids: list<int>, source: string} пустой список, если модель еще не видела товар
	 */
	public function similar(int $productId, int $limit): array
	{
		$sims = $this->store->loadItemSims([$productId]);

		return $this->result($sims[$productId] ?? [], [], $limit, self::SOURCE_SIMILAR);
	}

	/**
	 * @param int $sectionId 0 - весь каталог
	 * @return array{ids: list<int>, source: string}
	 */
	public function popular(int $sectionId, int $limit): array
	{
		return $this->result($this->store->loadPopular($sectionId), [], $limit, self::SOURCE_POPULAR);
	}

	/**
	 * @return array<int, true|float> ID товаров, которые не выдаются
	 */
	private function excluded(int $fuserId, string $siteId): array
	{
		$exclude = array_fill_keys($this->loader->boughtProducts($fuserId), true);

		/** @var BasketItem $item */
		foreach (Basket::loadItemsForFUser($fuserId, $siteId) as $item)
		{
			if ($item->getField('MODULE') !== 'catalog')
			{
				continue;
			}
			$productId = $this->resolver->resolve((int)$item->getProductId());
			if ($productId !== null)
			{
				$exclude[$productId] = true;
			}
		}

		return $exclude;
	}

	/**
	 * @param array<int, float> $scores ID => оценка, уже по убыванию
	 * @param array<int, mixed> $exclude
	 * @return array{ids: list<int>, source: string}
	 */
	private function result(array $scores, array $exclude, int $limit, string $source): array
	{
		$ids = [];
		foreach (array_keys($scores) as $productId)
		{
			if (isset($exclude[$productId]))
			{
				continue;
			}
			$ids[] = $productId;
			if (count($ids) >= $limit)
			{
				break;
			}
		}

		return ['ids' => $ids, 'source' => $source];
	}
}
