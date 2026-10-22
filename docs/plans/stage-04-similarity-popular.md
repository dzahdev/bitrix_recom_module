# Этап 4. Похожие товары, популярное, ранжирование кандидатов

**Результат:** сходство товаров по пользователям (косинус с весами IDF и сжатием), популярное по
разделам и персональное ранжирование набора кандидатов.

**Файлы:**
- Создать: `lib/Engine/ItemSimilarity.php`, `lib/Engine/Popular.php`, `lib/Engine/PersonalRanker.php`
- Тесты: `tests/cases/ItemSimilarityTest.php`, `tests/cases/PopularTest.php`,
  `tests/cases/PersonalRankerTest.php`
- Изменить: `tests/bench.php` (добавить замер сходства)

**Интерфейсы:**
- Потребляет: `Dataset`, `TopK` (этапы 2, 3).
- Производит:
  - `new ItemSimilarity(int $neighbors, int $shrinkage = 100, int $maxUserItems = 500)`;
    `compute(Dataset $dataset, ?array $idf = null): array<int ID товара, array<int ID, float>>`,
    оценка `1 / (1 + расстояние)`.
  - `Popular::compute(iterable $rows, int $limit): array<int ID раздела, array<int ID товара, float>>`,
    строка `[int $itemId, int $sectionId, float $score]`, раздел `0` - весь каталог.
  - `new PersonalRanker(int $limit)`;
    `rank(callable $score, list<int> $candidateIds, array<int, true> $exclude): array<int, float>`,
    где `$score(int $itemId): ?float` - оценка модели (ALS или FM), `null` - товар модели неизвестен.

**Формула расстояния** (косинус с весами IDF и сжатием `n/(n+100)`, Breese et al. 1998, Koren 2008) для
множеств пользователей `A` и `B` товаров:

- `A` и `B` совпадают - 0;
- `n = |A ∩ B| > 0` - `1 - Σ_{u∈A∩B} idf(u) · n / (√Σ_{A} idf · √Σ_{B} idf · (n + 100))`;
- общих нет - 1 (такие пары в результат не попадают: сходства нет).

---

- [ ] **Шаг 1. Тесты сходства (падают)**

`tests/cases/ItemSimilarityTest.php`:

```php
<?php

declare(strict_types=1);

use Dzahdev\Recom\Engine\Dataset;
use Dzahdev\Recom\Engine\ItemSimilarity;

// Порядок соседей: у товара 10 покупатели 1-10; у 20 - 1-9 (9 общих); у 30 - 1-3 и 50-56 (3 общих);
// у 40 - 50-60 (общих с 10 нет). Ближайший к 10 - 20, затем 30; 40 в соседях нет.
$pairs = [];
foreach ([10 => range(1, 10), 20 => range(1, 9), 30 => array_merge(range(1, 3), range(50, 56)), 40 => range(50, 60)] as $item => $users)
{
	foreach ($users as $user)
	{
		$pairs[] = [$user, $item];
	}
}
$dataset = Dataset::fromPairs($pairs);
$similar = (new ItemSimilarity(10))->compute($dataset, array_fill(0, $dataset->countUsers(), 1.0));
check(array_keys($similar[10]) === [20, 30], 'similarity: neighbors ordered by weighted overlap, no pair without common users');

// Сжатие n / (n + 100): при одинаковом косинусе (0.7071) пара с 20 общими покупателями ближе пары с 2.
// A = {1, 2}, B = {1..4}; C = {101..120}, D = {101..140}.
$pairs = [];
foreach ([1 => range(1, 2), 2 => range(1, 4), 3 => range(101, 120), 4 => range(101, 140)] as $item => $users)
{
	foreach ($users as $user)
	{
		$pairs[] = [$user, $item];
	}
}
$dataset = Dataset::fromPairs($pairs);
$similar = (new ItemSimilarity(10))->compute($dataset, array_fill(0, $dataset->countUsers(), 1.0));
check($similar[3][4] > $similar[1][2], 'similarity: shrinkage favors pairs with more common users');

// Ручной расчет: A = {1, 2}, B = {1, 2, 3}, idf = 1.
// расстояние = 1 - 2·2 / (√2·√3·102) = 0.983990263, оценка = 1 / 1.983990263 = 0.504034732
$dataset = Dataset::fromPairs([[1, 10], [2, 10], [1, 20], [2, 20], [3, 20]]);
$similar = (new ItemSimilarity(5))->compute($dataset, [1.0, 1.0, 1.0]);
near($similar[10][20], 0.504034732, 1e-9, 'similarity: hand-computed score A to B');
near($similar[20][10], 0.504034732, 1e-9, 'similarity: symmetric score B to A');

// Совпадающие множества пользователей: расстояние 0, оценка 1.
$dataset = Dataset::fromPairs([[1, 10], [2, 10], [1, 20], [2, 20]]);
$similar = (new ItemSimilarity(5))->compute($dataset, [1.0, 1.0]);
near($similar[10][20], 1.0, 1e-12, 'similarity: identical user sets');

// Нет общих пользователей - пары нет; товар без соседей получает пустой список.
$dataset = Dataset::fromPairs([[1, 10], [2, 20]]);
$similar = (new ItemSimilarity(5))->compute($dataset);
check($similar[10] === [] && $similar[20] === [], 'similarity: no common users gives no neighbors');

// Пользователь с историей длиннее maxUserItems не участвует в сходстве.
$dataset = Dataset::fromPairs([[1, 10], [1, 20], [1, 30]]);
$similar = (new ItemSimilarity(5, 100, 2))->compute($dataset, [1.0]);
check($similar[10] === [], 'similarity: heavy user skipped');
```

Запуск тестов. Ожидается: `Class "Dzahdev\Recom\Engine\ItemSimilarity" not found`.

- [ ] **Шаг 2. Реализация ItemSimilarity**

`lib/Engine/ItemSimilarity.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

/**
 * Похожие товары: косинус множеств покупателей с весами IDF (Dataset::userIdf) и сжатием n/(n + 100)
 * по числу общих покупателей n (shrinkage, Y. Koren, Factorization Meets the Neighborhood, KDD 2008).
 * Расстояние = 1 - косинус * n / (n + 100), оценка = 1 / (1 + расстояние). Расчет - полный проход по
 * совместной встречаемости: для каталогов интернет-магазина он укладывается в обучение агентом.
 */
final class ItemSimilarity
{
	public function __construct(
		private readonly int $neighbors,
		private readonly int $shrinkage = 100,
		private readonly int $maxUserItems = 500,
	)
	{
	}

	/**
	 * @param list<float>|null $idf вес пользователя по индексу; null - Dataset::userIdf()
	 * @return array<int, array<int, float>> ID товара => [ID похожего товара => 1 / (1 + расстояние)]
	 */
	public function compute(Dataset $dataset, ?array $idf = null): array
	{
		$idf ??= $dataset->userIdf();
		$itemCount = $dataset->countItems();

		$weightSum = [];
		for ($i = 0; $i < $itemCount; $i++)
		{
			$sum = 0.0;
			foreach ($dataset->itemUsers($i) as $u)
			{
				$sum += $idf[$u];
			}
			$weightSum[$i] = $sum;
		}

		$result = [];
		for ($i = 0; $i < $itemCount; $i++)
		{
			$users = $dataset->itemUsers($i);
			$commonSum = [];
			$commonCount = [];
			foreach ($users as $u)
			{
				$items = $dataset->userItems($u);
				if (count($items) > $this->maxUserItems)
				{
					// Слишком широкая история (опт, боты) связывает почти все товары и дает шум.
					continue;
				}
				foreach ($items as $j)
				{
					if ($j === $i)
					{
						continue;
					}
					$commonSum[$j] = ($commonSum[$j] ?? 0.0) + $idf[$u];
					$commonCount[$j] = ($commonCount[$j] ?? 0) + 1;
				}
			}

			$scores = [];
			foreach ($commonSum as $j => $sum)
			{
				$common = $commonCount[$j];
				if ($common === count($users) && $common === count($dataset->itemUsers($j)))
				{
					$distance = 0.0;
				}
				else
				{
					$distance = 1.0 - $sum * $common / sqrt($weightSum[$i]) / sqrt($weightSum[$j]) / ($common + $this->shrinkage);
				}
				$scores[$dataset->itemId($j)] = 1.0 / (1.0 + $distance);
			}

			$result[$dataset->itemId($i)] = TopK::select($scores, $this->neighbors);
		}

		return $result;
	}
}
```

Запуск тестов. Ожидается: строки `OK   similarity: ...`, `failed: 0`.

- [ ] **Шаг 3. Тесты популярного и ранжирования (падают)**

`tests/cases/PopularTest.php`:

```php
<?php

declare(strict_types=1);

use Dzahdev\Recom\Engine\Popular;

$popular = Popular::compute([
	[1, 5, 3.0],
	[2, 5, 1.0],
	[3, 6, 2.0],
	[1, 5, 4.0],
	[4, 0, 10.0],
], 2);

check($popular[0] === [4 => 10.0, 1 => 7.0], 'popular: whole catalog top 2 with summed scores');
check($popular[5] === [1 => 7.0, 2 => 1.0], 'popular: section 5');
check($popular[6] === [3 => 2.0], 'popular: section 6');
check(!isset($popular[-1]) && count($popular) === 3, 'popular: product without section only in catalog list');
check(Popular::compute([], 10) === [], 'popular: no signals');
```

`tests/cases/PersonalRankerTest.php`:

```php
<?php

declare(strict_types=1);

use Dzahdev\Recom\Engine\PersonalRanker;

$scores = [1 => 1.0, 2 => 0.0, 3 => 0.5];
$score = static fn(int $itemId): ?float => $scores[$itemId] ?? null;
$ranked = (new PersonalRanker(5))->rank($score, [2, 3, 1, 3, 99], [1 => true]);
check($ranked === [3 => 0.5, 2 => 0.0], 'ranker: excludes seen, dedups, skips unknown, sorts by score');
check((new PersonalRanker(1))->rank($score, [2, 3], []) === [3 => 0.5], 'ranker: limit');
```

Запуск тестов. Ожидается: `Class "Dzahdev\Recom\Engine\Popular" not found`.

- [ ] **Шаг 4. Реализация Popular и PersonalRanker**

`lib/Engine/Popular.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

/**
 * Популярное: сумма оценок сигналов, отдельный рейтинг для всего каталога (раздел 0) и для каждого
 * раздела.
 */
final class Popular
{
	/**
	 * @param iterable<array{0: int, 1: int, 2: float}> $rows (ID товара, ID раздела, оценка)
	 * @return array<int, array<int, float>> ID раздела => [ID товара => оценка]
	 */
	public static function compute(iterable $rows, int $limit): array
	{
		$bySection = [];
		foreach ($rows as [$itemId, $sectionId, $score])
		{
			$bySection[0][$itemId] = ($bySection[0][$itemId] ?? 0.0) + $score;
			if ($sectionId > 0)
			{
				$bySection[$sectionId][$itemId] = ($bySection[$sectionId][$itemId] ?? 0.0) + $score;
			}
		}

		return array_map(static fn(array $scores): array => TopK::select($scores, $limit), $bySection);
	}
}
```

`lib/Engine/PersonalRanker.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

/**
 * Персональная выдача по набору кандидатов (соседи товаров пользователя и популярное) с оценкой
 * модели ALS или FM. Перебор всего каталога для каждого пользователя заменен кандидатами, чтобы
 * подготовка подборок росла линейно с числом пользователей.
 */
final class PersonalRanker
{
	public function __construct(private readonly int $limit)
	{
	}

	/**
	 * @param callable(int): ?float $score оценка товара для пользователя; null - товар модели неизвестен
	 * @param list<int> $candidateIds ID товаров, повторы допускаются
	 * @param array<int, true> $exclude ID товаров, которые не выдаются
	 * @return array<int, float> ID товара => оценка, по убыванию
	 */
	public function rank(callable $score, array $candidateIds, array $exclude): array
	{
		$scores = [];
		$unknown = [];
		foreach ($candidateIds as $itemId)
		{
			if (isset($exclude[$itemId]) || isset($scores[$itemId]) || isset($unknown[$itemId]))
			{
				continue;
			}
			$value = $score($itemId);
			if ($value === null)
			{
				$unknown[$itemId] = true;
				continue;
			}
			$scores[$itemId] = $value;
		}

		return TopK::select($scores, $this->limit);
	}
}
```

Запуск тестов. Ожидается: `failed: 0`.

- [ ] **Шаг 5. Замер сходства**

В `tests/bench.php` добавить импорт `use Dzahdev\Recom\Engine\ItemSimilarity;` и перед `printf`:

```php
$start = microtime(true);
(new ItemSimilarity(20))->compute($dataset);
$similarityTime = microtime(true) - $start;
```

и строку вывода:

```php
printf("similarity %.1fs\n", $similarityTime);
```

Запуск `bench.php`, вывод записать в артефакт этапа. Цель: не дольше 60 с; иначе - показать
пользователю до этапа 6.

- [ ] **Шаг 6. Коммит**

```bash
cd /home/dzrise/projects/bitrix-modules/www/local/modules/dzahdev.recom
git add lib/Engine tests
git -c user.name="Denis Zakharov" -c user.email=dzahdev@gmail.com commit -m "Add item similarity, popular items and personal ranker."
```

**Критерии приемки:** тесты проходят, включая порядок соседей, сжатие и ручной расчет; замер
записан; инвариант 4 соблюден.
