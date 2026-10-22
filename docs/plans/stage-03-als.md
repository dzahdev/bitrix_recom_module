# Этап 3. ALS для неявных сигналов и NDCG

**Результат:** ALS для неявных сигналов (eALS, He et al., SIGIR 2016) и NDCG, тесты на синтетике со скрытой
структурой, замер скорости обучения на 100 000 событий.

**Файлы:**
- Создать: `lib/Engine/Gaussian.php`, `lib/Engine/Als.php`, `lib/Engine/AlsModel.php`, `lib/Engine/Ndcg.php`
- Создать: `tests/bench.php`
- Тесты: `tests/cases/AlsTest.php`, `tests/cases/NdcgTest.php`

**Интерфейсы:**
- Потребляет: `Dataset`, `TopK` (этап 2).
- Производит:
  - `new Als(int $factors, float $reg, float $alpha, float $initStdDev, ?int $seed = null)`;
    `Als::fit(Dataset $dataset, int $epochs, array $warmUsers = [], array $warmItems = []): AlsModel`,
    где `$warmUsers`/`$warmItems` - `array<int ID, list<float>>`.
  - `AlsModel::dataset(): Dataset`, `userVector(int $u): list<float>`, `itemVector(int $i): list<float>`,
    `userFactorsById(): array<int, list<float>>`, `itemFactorsById(): array<int, list<float>>`,
    `AlsModel::dot(array $a, array $b): float`.
  - `Ndcg::score(list<int> $targets, list<int> $ranked): float`,
    `Ndcg::evaluate(Dataset $dataset, callable $score, array<int,int> $holdout, int $k): ?float`, где
    `$score(int $userIndex, int $itemIndex): float` - оценка любой модели (ALS здесь, FM на этапе 7).
  - `Gaussian::sample(): float` - стандартное нормальное распределение на `mt_rand` (ALS и FM).

**Формулы (eALS с одинаковым весом α неотмеченных пар).** Для вектора пользователя `p_u` при матрице Грама
товаров `S = Σ_i q_i q_iᵀ`, по каждому фактору `f`:

- `r̂ᶠ_ui = r̂_ui - p_uf·q_if` для `i ∈ R(u)`;
- `a = Σ_i∈R(u) (1 - (1-α)·r̂ᶠ_ui)·q_if`, `c = Σ_i∈R(u) (1-α)·q_if²`, `b = Σ_k≠f α·p_uk·S_kf`;
- `p_uf = (a - b) / (c + α·S_ff + λ)`, затем `r̂_ui = r̂ᶠ_ui + p_uf·q_if`.

Шаг по товарам симметричен с `S = Σ_u p_u p_uᵀ`. Параметры по умолчанию: `α = 0.001`, `λ = 0.06`,
`initStdDev = 0.1`, 16 факторов.

---

- [ ] **Шаг 1. Тесты NDCG (падают)**

`tests/cases/NdcgTest.php`:

```php
<?php

declare(strict_types=1);

use Dzahdev\Recom\Engine\Ndcg;

// Одна цель на второй позиции: DCG = 1/log2(3), IDCG = 1/log2(2) = 1.
near(Ndcg::score([5], [3, 5, 7]), 0.630929754, 1e-9, 'ndcg: target at position 2');
near(Ndcg::score([5], [5, 3, 7]), 1.0, 1e-12, 'ndcg: target at position 1');
near(Ndcg::score([5], [1, 2, 3]), 0.0, 1e-12, 'ndcg: target missing');
near(Ndcg::score([5], []), 0.0, 1e-12, 'ndcg: empty ranking scores zero');

$thrown = false;
try
{
	Ndcg::score([], [1, 2]);
}
catch (\InvalidArgumentException)
{
	$thrown = true;
}
check($thrown, 'ndcg: no targets throws');
```

Запуск тестов. Ожидается: `Class "Dzahdev\Recom\Engine\Ndcg" not found`.

- [ ] **Шаг 2. AlsModel и Ndcg**

`lib/Engine/AlsModel.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

/**
 * Обученные векторы ALS. Внутри индексы Dataset, наружу - внешние ID.
 */
final class AlsModel
{
	/**
	 * @param array<int, list<float>> $userFactors индекс пользователя => вектор
	 * @param array<int, list<float>> $itemFactors индекс товара => вектор
	 */
	public function __construct(
		private readonly Dataset $dataset,
		private readonly array $userFactors,
		private readonly array $itemFactors,
	)
	{
	}

	public function dataset(): Dataset
	{
		return $this->dataset;
	}

	/** @return list<float> */
	public function userVector(int $u): array
	{
		return $this->userFactors[$u];
	}

	/** @return list<float> */
	public function itemVector(int $i): array
	{
		return $this->itemFactors[$i];
	}

	/** @return array<int, list<float>> ID пользователя => вектор */
	public function userFactorsById(): array
	{
		$result = [];
		foreach ($this->userFactors as $u => $vector)
		{
			$result[$this->dataset->userId($u)] = $vector;
		}

		return $result;
	}

	/** @return array<int, list<float>> ID товара => вектор */
	public function itemFactorsById(): array
	{
		$result = [];
		foreach ($this->itemFactors as $i => $vector)
		{
			$result[$this->dataset->itemId($i)] = $vector;
		}

		return $result;
	}

	/**
	 * @param list<float> $a
	 * @param list<float> $b
	 */
	public static function dot(array $a, array $b): float
	{
		$sum = 0.0;
		foreach ($a as $f => $value)
		{
			$sum += $value * $b[$f];
		}

		return $sum;
	}
}
```

`lib/Engine/Ndcg.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

final class Ndcg
{
	/**
	 * NDCG (K. Jarvelin, J. Kekalainen, Cumulated Gain-Based Evaluation of IR Techniques, ACM TOIS 2002)
	 * для бинарной релевантности. Пустая выдача дает 0: пользователь ничего не получил.
	 *
	 * @param list<int> $targets товары, которые пользователь выбрал в отложенной выборке
	 * @param list<int> $ranked выдача по убыванию оценки
	 * @throws \InvalidArgumentException если целей нет
	 */
	public static function score(array $targets, array $ranked): float
	{
		if ($targets === [])
		{
			throw new \InvalidArgumentException('NDCG needs at least one target item');
		}
		if ($ranked === [])
		{
			return 0.0;
		}

		$targetSet = array_flip($targets);
		$idcg = 0.0;
		for ($i = 0, $n = min(count($targets), count($ranked)); $i < $n; $i++)
		{
			$idcg += 1.0 / log($i + 2.0, 2);
		}

		$dcg = 0.0;
		foreach (array_values($ranked) as $i => $itemId)
		{
			if (isset($targetSet[$itemId]))
			{
				$dcg += 1.0 / log($i + 2.0, 2);
			}
		}

		return $dcg / $idcg;
	}

	/**
	 * Средний NDCG@k по отложенной выборке: для каждого пользователя ранжируются все товары,
	 * которых нет в его обучающей истории.
	 *
	 * @param callable(int, int): float $score оценка модели по индексам пользователя и товара в $dataset
	 * @param array<int, int> $holdout ID пользователя => отложенный ID товара
	 * @return float|null null, если ни одну пару нельзя оценить
	 */
	public static function evaluate(Dataset $dataset, callable $score, array $holdout, int $k): ?float
	{
		$sum = 0.0;
		$count = 0;
		foreach ($holdout as $userId => $itemId)
		{
			$u = $dataset->userIndexOf($userId);
			$target = $dataset->itemIndexOf($itemId);
			if ($u === null || $target === null)
			{
				// Товар встретился только в отложенной выборке: модель о нем не знает, оценивать нечем.
				continue;
			}

			$seen = array_flip($dataset->userItems($u));
			$scores = [];
			for ($i = 0, $n = $dataset->countItems(); $i < $n; $i++)
			{
				if (!isset($seen[$i]))
				{
					$scores[$i] = $score($u, $i);
				}
			}

			$sum += self::score([$target], array_keys(TopK::select($scores, $k)));
			$count++;
		}

		return $count > 0 ? $sum / $count : null;
	}
}
```

Запуск тестов. Ожидается: строки `OK   ndcg: ...`, `failed: 0`.

- [ ] **Шаг 3. Тесты ALS (падают)**

`tests/cases/AlsTest.php`:

```php
<?php

declare(strict_types=1);

use Dzahdev\Recom\Engine\Als;
use Dzahdev\Recom\Engine\AlsModel;
use Dzahdev\Recom\Engine\Dataset;
use Dzahdev\Recom\Engine\TopK;

// Два кластера: пользователи 1-20 выбирают 6 из товаров 1-10, пользователи 21-40 - 6 из товаров 11-20.
mt_srand(7);
$pairs = [];
for ($user = 1; $user <= 40; $user++)
{
	$base = $user <= 20 ? 1 : 11;
	$items = range($base, $base + 9);
	shuffle($items);
	foreach (array_slice($items, 0, 6) as $item)
	{
		$pairs[] = [$user, $item];
	}
}
$dataset = Dataset::fromPairs($pairs);
$model = (new Als(8, 0.06, 0.001, 0.1, 42))->fit($dataset, 20);

// У каждого пользователя 4 невыбранных товара своего кластера: верхние 4 должны быть из него.
$inCluster = 0;
$total = 0;
for ($u = 0; $u < $dataset->countUsers(); $u++)
{
	$userId = $dataset->userId($u);
	$seen = array_flip($dataset->userItems($u));
	$scores = [];
	for ($i = 0; $i < $dataset->countItems(); $i++)
	{
		if (!isset($seen[$i]))
		{
			$scores[$dataset->itemId($i)] = AlsModel::dot($model->userVector($u), $model->itemVector($i));
		}
	}
	foreach (array_keys(TopK::select($scores, 4)) as $itemId)
	{
		$sameCluster = ($userId <= 20) === ($itemId <= 10);
		$inCluster += $sameCluster ? 1 : 0;
		$total++;
	}
}
check($inCluster / $total >= 0.9, sprintf('als: %.0f%% of top-4 inside user cluster', 100 * $inCluster / $total));

// Детерминированность при одинаковом seed.
$again = (new Als(8, 0.06, 0.001, 0.1, 42))->fit($dataset, 20);
check($again->userFactorsById() === $model->userFactorsById(), 'als: same seed gives same factors');

// Теплый старт без эпох возвращает переданные векторы; вектор другой размерности заменяется случайным.
$warmUsers = $model->userFactorsById();
$warmItems = $model->itemFactorsById();
$warmItems[1] = [1.0, 2.0];
$warm = (new Als(8, 0.06, 0.001, 0.1, 1))->fit($dataset, 0, $warmUsers, $warmItems);
check($warm->userFactorsById() === $warmUsers, 'als: warm start keeps user vectors');
check(count($warm->itemFactorsById()[1]) === 8 && $warm->itemFactorsById()[1] !== [1.0, 2.0], 'als: warm vector of wrong size is reinitialized');

// Метрика на отложенной выборке выше случайной выдачи (у случайной NDCG@4 около 0.2).
$holdout = [];
$trainPairs = [];
foreach ($pairs as [$user, $item])
{
	if (!isset($holdout[$user]))
	{
		$holdout[$user] = $item;
		continue;
	}
	$trainPairs[] = [$user, $item];
}
$trainModel = (new Als(8, 0.06, 0.001, 0.1, 42))->fit(Dataset::fromPairs($trainPairs), 20);
$ndcg = \Dzahdev\Recom\Engine\Ndcg::evaluate(
	$trainModel->dataset(),
	static fn(int $u, int $i): float => AlsModel::dot($trainModel->userVector($u), $trainModel->itemVector($i)),
	$holdout,
	4
);
check($ndcg !== null && $ndcg > 0.35, sprintf('als: holdout NDCG@4 = %.3f above random', $ndcg ?? -1));
```

Запуск тестов. Ожидается: `Class "Dzahdev\Recom\Engine\Als" not found`.

- [ ] **Шаг 4. Реализация Als**

`lib/Engine/Als.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

/**
 * Матричная факторизация для неявных сигналов, покоординатный ALS с кешем матрицы Грама (eALS:
 * X. He, H. Zhang, M.-Y. Kan, T.-S. Chua, Fast Matrix Factorization for Online Recommendation with
 * Implicit Feedback, SIGIR 2016; модель неявных сигналов - Y. Hu, Y. Koren, C. Volinsky, ICDM 2008).
 * Положительная пара имеет цель 1 и вес 1, все остальные пары - цель 0 и одинаковый вес alpha.
 */
final class Als
{
	public function __construct(
		private readonly int $factors,
		private readonly float $reg,
		private readonly float $alpha,
		private readonly float $initStdDev,
		private readonly ?int $seed = null,
	)
	{
	}

	/**
	 * @param array<int, list<float>> $warmUsers ID пользователя => вектор прошлого обучения
	 * @param array<int, list<float>> $warmItems ID товара => вектор прошлого обучения
	 */
	public function fit(Dataset $dataset, int $epochs, array $warmUsers = [], array $warmItems = []): AlsModel
	{
		if ($this->seed !== null)
		{
			mt_srand($this->seed);
		}

		$p = [];
		foreach ($dataset->userIds() as $u => $userId)
		{
			$p[$u] = $this->initVector($warmUsers[$userId] ?? null);
		}
		$q = [];
		foreach ($dataset->itemIds() as $i => $itemId)
		{
			$q[$i] = $this->initVector($warmItems[$itemId] ?? null);
		}

		$userCount = $dataset->countUsers();
		$itemCount = $dataset->countItems();
		for ($epoch = 0; $epoch < $epochs; $epoch++)
		{
			$s = $this->gram($q);
			for ($u = 0; $u < $userCount; $u++)
			{
				$p[$u] = $this->solve($p[$u], $dataset->userItems($u), $q, $s);
			}

			$s = $this->gram($p);
			for ($i = 0; $i < $itemCount; $i++)
			{
				$q[$i] = $this->solve($q[$i], $dataset->itemUsers($i), $p, $s);
			}
		}

		return new AlsModel($dataset, $p, $q);
	}

	/**
	 * Обновление одного вектора по всем факторам.
	 *
	 * @param list<float> $x вектор пользователя (или товара)
	 * @param list<int> $observed индексы товаров (или пользователей) с положительным сигналом
	 * @param array<int, list<float>> $other векторы противоположной стороны
	 * @param list<list<float>> $s матрица Грама противоположной стороны
	 * @return list<float>
	 */
	private function solve(array $x, array $observed, array $other, array $s): array
	{
		$k = $this->factors;
		$alpha = $this->alpha;

		$prediction = [];
		foreach ($observed as $j)
		{
			$prediction[$j] = AlsModel::dot($x, $other[$j]);
		}

		for ($f = 0; $f < $k; $f++)
		{
			$a = 0.0;
			$c = 0.0;
			$residual = [];
			foreach ($observed as $j)
			{
				$yf = $other[$j][$f];
				$r = $prediction[$j] - $x[$f] * $yf;
				$residual[$j] = $r;
				$a += (1.0 - (1.0 - $alpha) * $r) * $yf;
				$c += (1.0 - $alpha) * $yf * $yf;
			}

			$b = 0.0;
			for ($m = 0; $m < $k; $m++)
			{
				if ($m !== $f)
				{
					$b += $alpha * $x[$m] * $s[$m][$f];
				}
			}

			$x[$f] = ($a - $b) / ($c + $alpha * $s[$f][$f] + $this->reg);

			foreach ($observed as $j)
			{
				$prediction[$j] = $residual[$j] + $x[$f] * $other[$j][$f];
			}
		}

		return $x;
	}

	/**
	 * @param array<int, list<float>> $vectors
	 * @return list<list<float>> S = Σ v·vᵀ
	 */
	private function gram(array $vectors): array
	{
		$k = $this->factors;
		$s = array_fill(0, $k, array_fill(0, $k, 0.0));
		foreach ($vectors as $v)
		{
			for ($a = 0; $a < $k; $a++)
			{
				$va = $v[$a];
				for ($b = $a; $b < $k; $b++)
				{
					$s[$a][$b] += $va * $v[$b];
				}
			}
		}
		for ($a = 0; $a < $k; $a++)
		{
			for ($b = 0; $b < $a; $b++)
			{
				$s[$a][$b] = $s[$b][$a];
			}
		}

		return $s;
	}

	/**
	 * Вектор прошлого обучения берется как есть. Если число факторов в настройках изменилось,
	 * старый вектор несовместим и заменяется случайным: это смена модели, а не сбой.
	 *
	 * @param list<float>|null $warm
	 * @return list<float>
	 */
	private function initVector(?array $warm): array
	{
		if ($warm !== null && count($warm) === $this->factors)
		{
			return $warm;
		}

		$vector = [];
		for ($f = 0; $f < $this->factors; $f++)
		{
			$vector[] = Gaussian::sample() * $this->initStdDev;
		}

		return $vector;
	}
}
```

`lib/Engine/Gaussian.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

final class Gaussian
{
	/**
	 * Стандартное нормальное распределение, преобразование Бокса - Мюллера на mt_rand:
	 * mt_srand() в моделях делает результат воспроизводимым.
	 */
	public static function sample(): float
	{
		$u1 = (mt_rand() + 1) / (mt_getrandmax() + 1);
		$u2 = mt_rand() / mt_getrandmax();

		return sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
	}
}
```

Запуск тестов. Ожидается: `failed: 0`. Если тест кластеров или NDCG не проходит, сверить
`solve()` с формулами в шапке этапа;
пороги теста не снижать.

- [ ] **Шаг 5. Замер скорости**

`tests/bench.php`:

```php
<?php

declare(strict_types=1);

/**
 * Замер обучения на синтетике: 5 000 пользователей, 1 000 товаров, 100 000 событий.
 * Запуск: docker exec bitrix_php php /var/www/bitrix/local/modules/dzahdev.recom/tests/bench.php
 */

require __DIR__ . '/autoload.php';

use Dzahdev\Recom\Engine\Als;
use Dzahdev\Recom\Engine\Dataset;

mt_srand(1);
$pairs = [];
for ($e = 0; $e < 100000; $e++)
{
	// Квадрат равномерной величины дает перекос популярности к товарам с малыми номерами.
	$pairs[] = [mt_rand(1, 5000), (int)floor(1000 * (mt_rand() / mt_getrandmax()) ** 2) + 1];
}

$start = microtime(true);
$dataset = Dataset::fromPairs($pairs);
$datasetTime = microtime(true) - $start;

$start = microtime(true);
(new Als(16, 0.06, 0.001, 0.1, 1))->fit($dataset, 20);
$alsTime = microtime(true) - $start;

printf("pairs=%d users=%d items=%d\n", $dataset->countPairs(), $dataset->countUsers(), $dataset->countItems());
printf("dataset %.1fs, als 20 epochs %.1fs, peak memory %.0f MB\n", $datasetTime, $alsTime, memory_get_peak_usage(true) / 1048576);
```

Запуск:

```bash
docker exec bitrix_php php /var/www/bitrix/local/modules/dzahdev.recom/tests/bench.php
```

Записать вывод в артефакт этапа. Цель: ALS 20 эпох не дольше 120 с и пиковая память не больше
512 МБ (`memory_limit` стенда). Если цель не достигнута - остановиться и показать цифры
пользователю до этапа 4: варианты - меньше эпох полного обучения, 8 факторов, отбор активных
пользователей.

- [ ] **Шаг 6. Коммит**

```bash
cd /home/dzrise/projects/bitrix-modules/www/local/modules/dzahdev.recom
git add lib/Engine tests
git -c user.name="Denis Zakharov" -c user.email=dzahdev@gmail.com commit -m "Add ALS for implicit feedback and NDCG."
```

**Критерии приемки:** тесты проходят без снижения порогов; замер записан и укладывается в цель
либо согласован с пользователем; инвариант 4 соблюден.
