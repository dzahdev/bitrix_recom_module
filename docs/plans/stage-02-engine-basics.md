# Этап 2. Основа движка: Dataset, VectorCodec, TopK

**Результат:** чистые классы движка без зависимостей от Битрикса и раннер тестов. Все тесты
проходят.

**Файлы:**
- Создать: `lib/Engine/Dataset.php`, `lib/Engine/VectorCodec.php`, `lib/Engine/TopK.php`
- Создать: `tests/autoload.php`, `tests/run.php`
- Тесты: `tests/cases/DatasetTest.php`, `tests/cases/VectorCodecTest.php`, `tests/cases/TopKTest.php`

**Интерфейсы:**
- Потребляет: ничего.
- Производит:
  - `Dataset::fromPairs(iterable $pairs): Dataset` - пары `[int $userId, int $itemId]`;
    `countUsers(): int`, `countItems(): int`, `countPairs(): int`,
    `userIndexOf(int $userId): ?int`, `itemIndexOf(int $itemId): ?int`,
    `userId(int $u): int`, `itemId(int $i): int`, `userIds(): list<int>`, `itemIds(): list<int>`,
    `userItems(int $u): list<int>`, `itemUsers(int $i): list<int>`, `userIdf(): list<float>`.
  - `VectorCodec::packVector(list<float>): string`, `unpackVector(string): list<float>`,
    `packScores(array<int,float>): string`, `unpackScores(string): array<int,float>`.
  - `TopK::select(array<int,float> $scores, int $k): array<int,float>` - по убыванию, ключи сохранены,
    при равенстве порядок вставки.
  - В тестах: функции `check(bool, string)`, `near(float, float, float, string)`.

---

- [ ] **Шаг 1. Раннер тестов**

`tests/autoload.php`:

```php
<?php

declare(strict_types=1);

// Автозагрузка классов модуля для тестов без ядра Битрикса.
spl_autoload_register(static function (string $class): void {
	$prefix = 'Dzahdev\\Recom\\';
	if (!str_starts_with($class, $prefix))
	{
		return;
	}

	$file = dirname(__DIR__) . '/lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($file))
	{
		require $file;
	}
});
```

`tests/run.php`:

```php
<?php

declare(strict_types=1);

/**
 * Тесты чистой математики модуля, без загрузки Битрикса.
 * Запуск: docker exec bitrix_php php /var/www/bitrix/local/modules/dzahdev.recom/tests/run.php
 */

require __DIR__ . '/autoload.php';

$failed = 0;
$passed = 0;

function check(bool $condition, string $message): void
{
	global $failed, $passed;
	if ($condition)
	{
		echo "OK   {$message}\n";
		$passed++;
	}
	else
	{
		echo "FAIL {$message}\n";
		$failed++;
	}
}

function near(float $actual, float $expected, float $epsilon, string $message): void
{
	check(abs($actual - $expected) <= $epsilon, sprintf('%s (got %.9f, want %.9f)', $message, $actual, $expected));
}

$cases = glob(__DIR__ . '/cases/*Test.php');
sort($cases);
foreach ($cases as $case)
{
	require $case;
}

echo "\nPassed: {$passed}, failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
```

- [ ] **Шаг 2. Тесты Dataset (падают)**

`tests/cases/DatasetTest.php`:

```php
<?php

declare(strict_types=1);

use Dzahdev\Recom\Engine\Dataset;

$dataset = Dataset::fromPairs([[10, 100], [10, 101], [11, 100], [10, 100]]);

check($dataset->countUsers() === 2, 'dataset: two users');
check($dataset->countItems() === 2, 'dataset: two items');
check($dataset->countPairs() === 3, 'dataset: duplicate pair counted once');
check($dataset->userIndexOf(10) === 0 && $dataset->userIndexOf(11) === 1, 'dataset: user indexes in order of appearance');
check($dataset->itemIndexOf(100) === 0 && $dataset->itemIndexOf(101) === 1, 'dataset: item indexes in order of appearance');
check($dataset->userIndexOf(999) === null, 'dataset: unknown user');
check($dataset->userItems(0) === [0, 1], 'dataset: items of user 10');
check($dataset->itemUsers(0) === [0, 1], 'dataset: users of item 100');
check($dataset->userId(1) === 11 && $dataset->itemId(1) === 101, 'dataset: index to id');
check($dataset->userIds() === [10, 11] && $dataset->itemIds() === [100, 101], 'dataset: id lists');

// idf(u) = max(log(nItems / |items(u)|), 1e-3): у пользователя 10 оба товара, у 11 - один из двух
$idf = $dataset->userIdf();
near($idf[0], 0.001, 1e-12, 'dataset: idf floor for user with all items');
near($idf[1], log(2.0), 1e-12, 'dataset: idf of user with one item');

$empty = Dataset::fromPairs([]);
check($empty->countUsers() === 0 && $empty->countPairs() === 0, 'dataset: empty');
```

Запуск: `docker exec bitrix_php php /var/www/bitrix/local/modules/dzahdev.recom/tests/run.php`
Ожидается: фатальная ошибка `Class "Dzahdev\Recom\Engine\Dataset" not found`.

- [ ] **Шаг 3. Реализация Dataset**

`lib/Engine/Dataset.php`:

```php
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
```

Запуск тестов. Ожидается: все строки `OK   dataset: ...`, `failed: 0`.

- [ ] **Шаг 4. Тесты VectorCodec и TopK (падают)**

`tests/cases/VectorCodecTest.php`:

```php
<?php

declare(strict_types=1);

use Dzahdev\Recom\Engine\VectorCodec;

// Значения точно представимы во float32, поэтому круговое преобразование без потерь.
$vector = [0.5, -1.25, 3.0, 0.0];
check(VectorCodec::unpackVector(VectorCodec::packVector($vector)) === $vector, 'codec: vector round trip');
check(strlen(VectorCodec::packVector($vector)) === 16, 'codec: 4 bytes per factor');
check(VectorCodec::unpackVector('') === [], 'codec: empty vector');

$scores = [7 => 0.5, 3 => 0.25, 42 => -2.0];
check(VectorCodec::unpackScores(VectorCodec::packScores($scores)) === $scores, 'codec: scores round trip keeps keys and order');
check(VectorCodec::packScores([]) === '' && VectorCodec::unpackScores('') === [], 'codec: empty scores');

$thrown = false;
try
{
	VectorCodec::unpackScores('abc');
}
catch (\InvalidArgumentException)
{
	$thrown = true;
}
check($thrown, 'codec: broken scores payload throws');
```

`tests/cases/TopKTest.php`:

```php
<?php

declare(strict_types=1);

use Dzahdev\Recom\Engine\TopK;

check(TopK::select([1 => 0.1, 2 => 0.9, 3 => 0.5], 2) === [2 => 0.9, 3 => 0.5], 'topk: two best with keys');
check(TopK::select([5 => 1.0, 6 => 1.0, 7 => 2.0], 3) === [7 => 2.0, 5 => 1.0, 6 => 1.0], 'topk: ties keep insertion order');
check(TopK::select([], 5) === [], 'topk: empty');
```

Запуск тестов. Ожидается: `Class "Dzahdev\Recom\Engine\VectorCodec" not found`.

- [ ] **Шаг 5. Реализация VectorCodec и TopK**

`lib/Engine/VectorCodec.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

/**
 * Упаковка векторов и списков оценок в бинарные строки: float32 и uint32 little-endian.
 */
final class VectorCodec
{
	/** @param list<float> $vector */
	public static function packVector(array $vector): string
	{
		return pack('g*', ...$vector);
	}

	/** @return list<float> */
	public static function unpackVector(string $binary): array
	{
		if ($binary === '')
		{
			return [];
		}

		return array_values(unpack('g*', $binary));
	}

	/**
	 * @param array<int, float> $scores ID => оценка; порядок сохраняется
	 */
	public static function packScores(array $scores): string
	{
		if ($scores === [])
		{
			return '';
		}

		return pack('V*', ...array_keys($scores)) . pack('g*', ...array_values($scores));
	}

	/**
	 * @return array<int, float>
	 * @throws \InvalidArgumentException если длина строки не кратна 8 байтам
	 */
	public static function unpackScores(string $binary): array
	{
		if ($binary === '')
		{
			return [];
		}

		$length = strlen($binary);
		if ($length % 8 !== 0)
		{
			throw new \InvalidArgumentException("Packed scores length {$length} is not a multiple of 8");
		}

		$count = intdiv($length, 8);
		$ids = array_values(unpack('V' . $count, substr($binary, 0, 4 * $count)));
		$scores = array_values(unpack('g' . $count, substr($binary, 4 * $count)));

		return array_combine($ids, $scores);
	}
}
```

`lib/Engine/TopK.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

final class TopK
{
	/**
	 * Первые $k элементов по убыванию оценки. Сортировка PHP 8 устойчивая:
	 * при равных оценках сохраняется порядок вставки.
	 *
	 * @param array<int, float> $scores ID => оценка
	 * @return array<int, float>
	 */
	public static function select(array $scores, int $k): array
	{
		arsort($scores);

		return array_slice($scores, 0, $k, true);
	}
}
```

Запуск тестов. Ожидается: `failed: 0`.

- [ ] **Шаг 6. Коммит**

```bash
cd /home/dzrise/projects/bitrix-modules/www/local/modules/dzahdev.recom
git add lib/Engine tests
git -c user.name="Denis Zakharov" -c user.email=dzahdev@gmail.com commit -m "Add engine dataset, vector codec, top-k and test runner."
```

**Критерии приемки:** `tests/run.php` завершается с кодом 0; в `lib/Engine` нет обращений к
`Bitrix\` (`grep -r 'Bitrix' lib/Engine` пуст) - инвариант 4.
