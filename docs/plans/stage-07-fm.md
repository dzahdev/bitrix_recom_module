# Этап 7. Ранжирование FM (классическая factorization machine, Rendle 2010)

**Результат:** минимальная FM без собственных изменений модели: уравнение второго порядка,
вычисление попарных взаимодействий за `O(k·n)`, SGD с L2-регуляризацией и логистической потерей
(S. Rendle, "Factorization Machines", ICDM 2010, разделы III-A, III-B, III-C). Признаки: покупатель,
товар, раздел товара. При `fm_enabled = Y` FM ранжирует кандидатов персональной подборки; при полном
обучении в журнал пишутся NDCG@10 для ALS и для FM.

**Файлы:**
- Создать: `lib/Engine/FeatureIndex.php`, `lib/Engine/FmModel.php`, `lib/Engine/Fm.php`,
  `lib/Engine/FmSamples.php`
- Тесты: `tests/cases/FmTest.php`
- Изменить: `tests/bench.php` (замер FM), `lib/Training/Trainer.php` (обучение FM и выбор оценки)

**Интерфейсы:**
- Потребляет: `Dataset`, `Gaussian`, `TopK`, `Ndcg` (этапы 2-3), `Trainer`, `DatasetLoader::itemSections`
  (этап 6), настройки `fm_*` (этап 1).
- Производит:
  - `FeatureIndex::id(string $name): int` (создает индекс), `find(string $name): ?int`, `count(): int`.
  - `new FmModel(float $bias, list<float> $weights, list<list<float>> $factors)`;
    `predict(list<int> $features): float` - значение `y(x)` до сигмоиды.
  - `new Fm(int $factors, float $learningRate, float $reg, float $initStdDev, ?int $seed = null)`;
    `fit(list<array{0: list<int>, 1: int}> $samples, int $featureCount, int $epochs): FmModel`.
  - `FmSamples::build(Dataset $dataset, array<int,int> $itemSections, int $negatives, FeatureIndex $index): list<array{0: list<int>, 1: int}>`,
    `FmSamples::features(FeatureIndex $index, int $userId, int $itemId, int $sectionId): list<int>`.
  - В журнале обучения `NDCG_FM`; в `b_dzahdev_recom_user_rec.SOURCE` - `fm` или `als`.

**Формулы для бинарных признаков `xᵢ ∈ {0, 1}`** (`A` - активные признаки примера):

- `y = w₀ + Σ_{i∈A} wᵢ + ½·Σ_f [(Σ_{i∈A} v_if)² - Σ_{i∈A} v_if²]` (лемма 3.1 статьи);
- логистическая потеря, `g = σ(y) - метка`;
- `w₀ ← w₀ - η·g`, `wᵢ ← wᵢ - η·(g + λ·wᵢ)`, `v_if ← v_if - η·(g·(Σ_{j∈A} v_jf - v_if) + λ·v_if)`;
  суммы `Σ_{j∈A} v_jf` берутся до обновления примера.

---

- [ ] **Шаг 1. Тесты (падают)**

`tests/cases/FmTest.php`:

```php
<?php

declare(strict_types=1);

use Dzahdev\Recom\Engine\Dataset;
use Dzahdev\Recom\Engine\FeatureIndex;
use Dzahdev\Recom\Engine\Fm;
use Dzahdev\Recom\Engine\FmModel;
use Dzahdev\Recom\Engine\FmSamples;
use Dzahdev\Recom\Engine\Ndcg;
use Dzahdev\Recom\Engine\TopK;

// Формула на заданных весах: w0 = 0.5, w = [1, 2, 3], v0 = (1, 0), v1 = (0, 1), v2 = (1, 1).
$model = new FmModel(0.5, [1.0, 2.0, 3.0], [[1.0, 0.0], [0.0, 1.0], [1.0, 1.0]]);
near($model->predict([0, 2]), 0.5 + 1.0 + 3.0 + 1.0, 1e-12, 'fm: two features, one pair');
near($model->predict([0, 1, 2]), 0.5 + 6.0 + 0.0 + 1.0 + 1.0, 1e-12, 'fm: three features, three pairs');
near($model->predict([]), 0.5, 1e-12, 'fm: no features gives bias');

$index = new FeatureIndex();
check($index->id('u1') === 0 && $index->id('i5') === 1 && $index->id('u1') === 0, 'fm: feature index is stable');
check($index->find('s9') === null && $index->count() === 2, 'fm: find does not create');

// Синтетика с разделами: товары 1-10 в разделе 1, 11-20 в разделе 2;
// покупатели 1-20 выбирают 6 товаров раздела 1, покупатели 21-40 - 6 товаров раздела 2.
$sections = [];
for ($item = 1; $item <= 21; $item++)
{
	$sections[$item] = ($item <= 10 || $item === 21) ? 1 : 2;
}
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

$index = new FeatureIndex();
mt_srand(3);
$samples = FmSamples::build($dataset, $sections, 2, $index);
check(count($samples) === $dataset->countPairs() * 3, 'fm: one positive and two negatives per pair');
check(count(array_filter($samples, static fn(array $s): bool => $s[1] === 1)) === $dataset->countPairs(), 'fm: positives count');

$fm = (new Fm(4, 0.05, 0.0001, 0.01, 42))->fit($samples, $index->count(), 30);
$score = static fn(int $userId, int $itemId): float
	=> $fm->predict(FmSamples::features($index, $userId, $itemId, $sections[$itemId]));

// Верхние 4 невыбранных товара - из раздела покупателя.
$inCluster = 0;
$total = 0;
for ($u = 0; $u < $dataset->countUsers(); $u++)
{
	$userId = $dataset->userId($u);
	$seen = array_flip(array_map([$dataset, 'itemId'], $dataset->userItems($u)));
	$scores = [];
	for ($item = 1; $item <= 20; $item++)
	{
		if (!isset($seen[$item]))
		{
			$scores[$item] = $score($userId, $item);
		}
	}
	foreach (array_keys(TopK::select($scores, 4)) as $itemId)
	{
		$inCluster += (($userId <= 20) === ($itemId <= 10)) ? 1 : 0;
		$total++;
	}
}
check($inCluster / $total >= 0.9, sprintf('fm: %.0f%% of top-4 inside user section', 100 * $inCluster / $total));

// Новый товар 21 в разделе 1 без истории: признак товара неизвестен, оценка идет через раздел.
$sectionOneUsers = 0.0;
$sectionTwoUsers = 0.0;
for ($user = 1; $user <= 40; $user++)
{
	if ($user <= 20)
	{
		$sectionOneUsers += $score($user, 21);
	}
	else
	{
		$sectionTwoUsers += $score($user, 21);
	}
}
check($sectionOneUsers / 20 > $sectionTwoUsers / 20, 'fm: cold item scores higher for users of its section');

// Отложенная выборка: NDCG@4 выше случайной выдачи (около 0.2).
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
$train = Dataset::fromPairs($trainPairs);
$trainIndex = new FeatureIndex();
mt_srand(3);
$trainFm = (new Fm(4, 0.05, 0.0001, 0.01, 42))->fit(FmSamples::build($train, $sections, 2, $trainIndex), $trainIndex->count(), 30);
$ndcg = Ndcg::evaluate(
	$train,
	static fn(int $u, int $i): float => $trainFm->predict(
		FmSamples::features($trainIndex, $train->userId($u), $train->itemId($i), $sections[$train->itemId($i)])
	),
	$holdout,
	4
);
check($ndcg !== null && $ndcg > 0.35, sprintf('fm: holdout NDCG@4 = %.3f above random', $ndcg ?? -1));
```

Запуск тестов. Ожидается: `Class "Dzahdev\Recom\Engine\FmModel" not found`.

- [ ] **Шаг 2. Модель и индекс признаков**

`lib/Engine/FeatureIndex.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

/**
 * Отображение имен признаков ("u15", "i204", "s7") в индексы 0..n-1 для FM.
 */
final class FeatureIndex
{
	/** @var array<string, int> */
	private array $index = [];

	public function id(string $name): int
	{
		return $this->index[$name] ??= count($this->index);
	}

	public function find(string $name): ?int
	{
		return $this->index[$name] ?? null;
	}

	public function count(): int
	{
		return count($this->index);
	}
}
```

`lib/Engine/FmModel.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

/**
 * Обученная factorization machine второго порядка (S. Rendle, Factorization Machines, ICDM 2010).
 */
final class FmModel
{
	/**
	 * @param list<float> $weights вес признака по индексу
	 * @param list<list<float>> $factors вектор признака по индексу
	 */
	public function __construct(
		private readonly float $bias,
		private readonly array $weights,
		private readonly array $factors,
	)
	{
	}

	/**
	 * y = w0 + Σ w_i + ½·Σ_f [(Σ v_if)² - Σ v_if²] по активным бинарным признакам (лемма 3.1).
	 *
	 * @param list<int> $features индексы активных признаков
	 */
	public function predict(array $features): float
	{
		$y = $this->bias;
		foreach ($features as $j)
		{
			$y += $this->weights[$j];
		}

		$pairs = 0.0;
		$factorCount = $features === [] ? 0 : count($this->factors[$features[0]]);
		for ($f = 0; $f < $factorCount; $f++)
		{
			$sum = 0.0;
			$squares = 0.0;
			foreach ($features as $j)
			{
				$value = $this->factors[$j][$f];
				$sum += $value;
				$squares += $value * $value;
			}
			$pairs += $sum * $sum - $squares;
		}

		return $y + 0.5 * $pairs;
	}
}
```

- [ ] **Шаг 3. Обучение и подготовка примеров**

`lib/Engine/Fm.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

/**
 * Обучение factorization machine второго порядка стохастическим градиентным спуском с
 * L2-регуляризацией и логистической потерей (S. Rendle, Factorization Machines, ICDM 2010,
 * разделы III-B и III-C). Модель без изменений относительно статьи; признаки бинарные.
 */
final class Fm
{
	public function __construct(
		private readonly int $factors,
		private readonly float $learningRate,
		private readonly float $reg,
		private readonly float $initStdDev,
		private readonly ?int $seed = null,
	)
	{
	}

	/**
	 * @param list<array{0: list<int>, 1: int}> $samples (индексы активных признаков, метка 0 или 1)
	 */
	public function fit(array $samples, int $featureCount, int $epochs): FmModel
	{
		if ($this->seed !== null)
		{
			mt_srand($this->seed);
		}

		$k = $this->factors;
		$eta = $this->learningRate;
		$reg = $this->reg;

		$bias = 0.0;
		$weights = array_fill(0, $featureCount, 0.0);
		$factors = [];
		for ($j = 0; $j < $featureCount; $j++)
		{
			for ($f = 0; $f < $k; $f++)
			{
				$factors[$j][$f] = Gaussian::sample() * $this->initStdDev;
			}
		}

		$order = array_keys($samples);
		for ($epoch = 0; $epoch < $epochs; $epoch++)
		{
			shuffle($order);
			foreach ($order as $s)
			{
				[$features, $label] = $samples[$s];

				$y = $bias;
				$sums = array_fill(0, $k, 0.0);
				foreach ($features as $j)
				{
					$y += $weights[$j];
					for ($f = 0; $f < $k; $f++)
					{
						$sums[$f] += $factors[$j][$f];
					}
				}
				$pairs = 0.0;
				for ($f = 0; $f < $k; $f++)
				{
					$squares = 0.0;
					foreach ($features as $j)
					{
						$squares += $factors[$j][$f] * $factors[$j][$f];
					}
					$pairs += $sums[$f] * $sums[$f] - $squares;
				}
				$y += 0.5 * $pairs;

				$g = self::sigmoid($y) - $label;
				$bias -= $eta * $g;
				foreach ($features as $j)
				{
					$weights[$j] -= $eta * ($g + $reg * $weights[$j]);
					for ($f = 0; $f < $k; $f++)
					{
						$vf = $factors[$j][$f];
						$factors[$j][$f] = $vf - $eta * ($g * ($sums[$f] - $vf) + $reg * $vf);
					}
				}
			}
		}

		return new FmModel($bias, $weights, $factors);
	}

	/**
	 * Сигмоида без переполнения exp() на больших |y|.
	 */
	private static function sigmoid(float $y): float
	{
		if ($y >= 0.0)
		{
			return 1.0 / (1.0 + exp(-$y));
		}
		$e = exp($y);

		return $e / (1.0 + $e);
	}
}
```

`lib/Engine/FmSamples.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Engine;

/**
 * Признаки FM для пары "покупатель - товар": u<ID покупателя>, i<ID товара>, s<ID раздела>.
 */
final class FmSamples
{
	/**
	 * Обучающие примеры: каждая положительная пара с меткой 1 и $negatives случайных товаров без
	 * сигнала от этого покупателя с меткой 0. Без записи показов это единственный источник класса 0.
	 *
	 * @param array<int, int> $itemSections ID товара => ID раздела; 0 или отсутствие - без раздела
	 * @return list<array{0: list<int>, 1: int}>
	 */
	public static function build(Dataset $dataset, array $itemSections, int $negatives, FeatureIndex $index): array
	{
		$itemCount = $dataset->countItems();
		$samples = [];
		for ($u = 0, $userCount = $dataset->countUsers(); $u < $userCount; $u++)
		{
			$userId = $dataset->userId($u);
			$items = $dataset->userItems($u);
			$seen = array_flip($items);
			// Покупатель со всеми товарами каталога: товаров без сигнала нет, отрицательных примеров тоже.
			$canSample = count($items) < $itemCount;

			foreach ($items as $i)
			{
				$samples[] = [self::encode($index, $userId, $dataset->itemId($i), $itemSections), 1];
				if (!$canSample)
				{
					continue;
				}
				for ($n = 0; $n < $negatives; $n++)
				{
					do
					{
						$j = mt_rand(0, $itemCount - 1);
					}
					while (isset($seen[$j]));
					$samples[] = [self::encode($index, $userId, $dataset->itemId($j), $itemSections), 0];
				}
			}
		}

		return $samples;
	}

	/**
	 * Признаки для оценки обученной моделью. Признак, которого не было в обучении, пропускается:
	 * новый товар оценивается по покупателю и разделу.
	 *
	 * @param int $sectionId 0 - товар без раздела
	 * @return list<int>
	 */
	public static function features(FeatureIndex $index, int $userId, int $itemId, int $sectionId): array
	{
		$names = ['u' . $userId, 'i' . $itemId];
		if ($sectionId > 0)
		{
			$names[] = 's' . $sectionId;
		}

		$features = [];
		foreach ($names as $name)
		{
			$id = $index->find($name);
			if ($id !== null)
			{
				$features[] = $id;
			}
		}

		return $features;
	}

	/**
	 * @param array<int, int> $itemSections
	 * @return list<int>
	 */
	private static function encode(FeatureIndex $index, int $userId, int $itemId, array $itemSections): array
	{
		$features = [$index->id('u' . $userId), $index->id('i' . $itemId)];
		$sectionId = $itemSections[$itemId] ?? 0;
		if ($sectionId > 0)
		{
			$features[] = $index->id('s' . $sectionId);
		}

		return $features;
	}
}
```

Запуск тестов. Ожидается: строки `OK   fm: ...`, `failed: 0`. Если не проходит тест кластеров или
холодного товара - сверить `fit()` с формулами в шапке этапа (порядок: суммы до обновления, знак
градиента `σ(y) - метка`); пороги и параметры теста не менять.

- [ ] **Шаг 4. Замер**

В `tests/bench.php` добавить импорты `use Dzahdev\Recom\Engine\FeatureIndex;`,
`use Dzahdev\Recom\Engine\Fm;`, `use Dzahdev\Recom\Engine\FmSamples;` и перед выводом:

```php
// Синтетические разделы: 20 разделов по номеру товара.
$sections = [];
foreach ($dataset->itemIds() as $itemId)
{
	$sections[$itemId] = $itemId % 20 + 1;
}
$start = microtime(true);
$index = new FeatureIndex();
$samples = FmSamples::build($dataset, $sections, 2, $index);
(new Fm(8, 0.05, 0.0001, 0.01, 1))->fit($samples, $index->count(), 30);
$fmTime = microtime(true) - $start;
```

и строку вывода `printf("fm 30 epochs, %d samples %.1fs\n", count($samples), $fmTime);`.

Запуск `bench.php`, вывод - в артефакт этапа. Цель: FM не дольше 120 с и общая пиковая память не
больше 512 МБ. Если цель не достигнута - остановиться и показать цифры пользователю: варианты -
меньше эпох (`fm_epochs`), один отрицательный пример (`fm_negatives = 1`), 4 фактора.

- [ ] **Шаг 5. FM в обучении**

В `lib/Training/Trainer.php`:

1. Импорты: `use Dzahdev\Recom\Engine\FeatureIndex;`, `use Dzahdev\Recom\Engine\Fm;`,
   `use Dzahdev\Recom\Engine\FmSamples;`; константа `private const SOURCE_FM = 'fm';`.
2. В `train()` заменить строку

```php
		$recs = $this->personalRecs($dataset, $alsScore, $sims, $popular[0] ?? [], $data['holdout']);
```

на

```php
		$score = $alsScore;
		$source = self::SOURCE_ALS;
		$ndcgFm = null;
		if (Config::string('fm_enabled') === 'Y')
		{
			$sections = $this->loader->itemSections($dataset->itemIds());
			$index = new FeatureIndex();
			$samples = FmSamples::build($dataset, $sections, Config::int('fm_negatives'), $index);
			$fm = (new Fm(Config::int('fm_factors'), Config::float('fm_learning_rate'), Config::float('fm_reg'), 0.01))
				->fit($samples, $index->count(), Config::int('fm_epochs'));
			// Товар без раздела или удаленный из каталога оценивается без признака раздела.
			$score = static fn(int $u, int $i): float => $fm->predict(FmSamples::features(
				$index,
				$dataset->userId($u),
				$dataset->itemId($i),
				$sections[$dataset->itemId($i)] ?? 0
			));
			$ndcgFm = $full ? Ndcg::evaluate($dataset, $score, $holdout, 10) : null;
			$source = self::SOURCE_FM;
		}

		$recs = $this->personalRecs($dataset, $score, $sims, $popular[0] ?? [], $data['holdout']);
```

3. Заменить `$this->store->replaceUserRecs($recs, self::SOURCE_ALS);` на
   `$this->store->replaceUserRecs($recs, $source);`.
4. В `return new TrainResult(...)` последний аргумент `null` заменить на `$ndcgFm`.

- [ ] **Шаг 6. Проверка на стенде**

Синтаксис - как на этапе 5, шаг 6. Переустановить модуль (в журнале обучения появляется колонка
`NDCG_FM`, в настройках - значения `fm_*` по умолчанию), повторить синтетику (этап 6, шаг 7) и:

```bash
docker exec -w /var/www/bitrix bitrix_php php local/modules/dzahdev.recom/tools/train.php --full
docker exec bitrix_mysql mysql -ubitrix -pbitrix bitrix -e "
select MODE, DURATION_SEC, NDCG, NDCG_FM, ERROR from b_dzahdev_recom_train_log order by ID desc limit 1;
select SOURCE, count(*) from b_dzahdev_recom_user_rec group by SOURCE;" 2>/dev/null
```

Ожидается: строка `full training: ... NDCG@10 ALS 0.xxxx, FM 0.xxxx`; оба NDCG заполнены, ошибок
нет; подборки с `SOURCE = fm`. Выключить FM и повторить:

```bash
docker exec -w /var/www/bitrix bitrix_php php -r '
$_SERVER["DOCUMENT_ROOT"] = "/var/www/bitrix";
define("NO_KEEP_STATISTIC", true); define("NOT_CHECK_PERMISSIONS", true);
require "bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Config\Option::set("dzahdev.recom", "fm_enabled", "N");'
docker exec -w /var/www/bitrix bitrix_php php local/modules/dzahdev.recom/tools/train.php --full
```

Ожидается: `FM not evaluated`, подборки с `SOURCE = als`. Вернуть `fm_enabled = Y` той же командой.
Оба значения NDCG@10 записать в артефакт этапа.

- [ ] **Шаг 7. Коммит**

```bash
cd /home/dzrise/projects/bitrix-modules/www/local/modules/dzahdev.recom
git add -A
git -c user.name="Denis Zakharov" -c user.email=dzahdev@gmail.com commit -m "Add classic factorization machine ranking of personal recommendations."
git push
```

**Критерии приемки:** тесты FM проходят без изменения порогов; формулы `Fm` и `FmModel` совпадают с
шапкой этапа (без дополнительных членов и эвристик); замер записан и укладывается в цель либо
согласован; на стенде переключение `fm_enabled` меняет источник подборок; инварианты 2-4 соблюдены
(FM считается только при обучении, `lib/Engine` без Битрикса).
