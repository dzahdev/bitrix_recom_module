<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

/**
 * Бинарная матрица "пользователь - товар" с плотными индексами 0..n-1.
 * Внешние ID (FUSER_ID, ID товара) получают индексы в порядке первого появления.
 */
final class Dataset
{
	/** @var array<int, int> ID пользователя => индекс */
	private array $userIndex = [];
	/** @var list<int> */
	private array $userIds = [];
	/** @var array<int, int> ID товара => индекс */
	private array $itemIndex = [];
	/** @var list<int> */
	private array $itemIds = [];
	/** @var array<int, list<int>> индекс пользователя => отсортированные индексы товаров */
	private array $userItems = [];
	/** @var array<int, list<int>> индекс товара => отсортированные индексы пользователей */
	private array $itemUsers = [];
	private int $pairs = 0;

	/**
	 * @param iterable<array{0: int, 1: int}> $pairs пары (ID пользователя, ID товара), повторы допускаются
	 */
	public static function fromPairs(iterable $pairs): self
	{
		$dataset = new self();
		$sets = [];
		foreach ($pairs as [$userId, $itemId])
		{
			$u = $dataset->userIndex[$userId] ?? $dataset->addUser($userId);
			$i = $dataset->itemIndex[$itemId] ?? $dataset->addItem($itemId);
			$sets[$u][$i] = true;
		}

		// Ключи $sets идут по возрастанию индекса пользователя, поэтому списки itemUsers отсортированы.
		foreach ($sets as $u => $set)
		{
			$items = array_keys($set);
			sort($items);
			$dataset->userItems[$u] = $items;
			$dataset->pairs += count($items);
			foreach ($items as $i)
			{
				$dataset->itemUsers[$i][] = $u;
			}
		}

		return $dataset;
	}

	private function addUser(int $userId): int
	{
		$this->userIds[] = $userId;
		return $this->userIndex[$userId] = count($this->userIds) - 1;
	}

	private function addItem(int $itemId): int
	{
		$this->itemIds[] = $itemId;
		return $this->itemIndex[$itemId] = count($this->itemIds) - 1;
	}

	public function countUsers(): int
	{
		return count($this->userIds);
	}

	public function countItems(): int
	{
		return count($this->itemIds);
	}

	public function countPairs(): int
	{
		return $this->pairs;
	}

	public function userIndexOf(int $userId): ?int
	{
		return $this->userIndex[$userId] ?? null;
	}

	public function itemIndexOf(int $itemId): ?int
	{
		return $this->itemIndex[$itemId] ?? null;
	}

	public function userId(int $u): int
	{
		return $this->userIds[$u];
	}

	public function itemId(int $i): int
	{
		return $this->itemIds[$i];
	}

	/** @return list<int> */
	public function userIds(): array
	{
		return $this->userIds;
	}

	/** @return list<int> */
	public function itemIds(): array
	{
		return $this->itemIds;
	}

	/** @return list<int> */
	public function userItems(int $u): array
	{
		return $this->userItems[$u];
	}

	/** @return list<int> */
	public function itemUsers(int $i): array
	{
		return $this->itemUsers[$i];
	}

	/**
	 * Вес пользователя в сходстве товаров - обратная частота пользователя (inverse user frequency,
	 * J. Breese, D. Heckerman, C. Kadie, UAI 1998): idf(u) = max(ln(число товаров / число товаров
	 * пользователя), 0.001). Нижняя граница не дает обнулить вес пользователя со всеми товарами.
	 *
	 * @return list<float> индекс пользователя => вес
	 */
	public function userIdf(): array
	{
		$itemCount = count($this->itemIds);
		$idf = [];
		foreach ($this->userItems as $u => $items)
		{
			$idf[$u] = max(log($itemCount / count($items)), 1e-3);
		}

		return $idf;
	}
}
