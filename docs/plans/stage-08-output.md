# Этап 8. Выдача: цепочка рекомендаций, компонент, контроллер

**Результат:** компонент `dzahdev:recom.list` выводит персональные, похожие и популярные товары
через `bitrix:catalog.section` в оформлении магазина; действие контроллера `getList` отдает те же
списки в JSON.

**Файлы:**
- Создать: `lib/Recommend/Recommender.php`
- Создать: `install/components/dzahdev/recom.list/.description.php`, `.parameters.php`, `class.php`,
  `templates/.default/template.php`, `lang/ru/.description.php`, `lang/ru/.parameters.php`
- Изменить: `lib/Controller/Recommend.php` (действие `getList`), `install/index.php` (`getFileDirs()`)

**Интерфейсы:**
- Потребляет: `ModelStore`, `DatasetLoader` (этап 6), `ProductResolver`, `EventType` (этап 5), `Config`.
- Производит:
  - `Recommender::create(): Recommender`;
    `personal(?int $fuserId, string $siteId, int $limit): array{ids: list<int>, source: string}`,
    `similar(int $productId, int $limit): array{ids: list<int>, source: string}`,
    `popular(int $sectionId, int $limit): array{ids: list<int>, source: string}`;
    источники: `personal` (готовая подборка FM или ALS), `similar`, `popular`.
  - Компонент `dzahdev:recom.list`, параметры: `MODE` (`personal|similar|popular`), `PRODUCT_ID`,
    `SECTION_ID`, `COUNT`, `IBLOCK_ID`, `PRICE_CODE`, `SECTION_TEMPLATE`.
  - Действие `dzahdev:recom.Recommend.getList` с параметрами `mode`, `productId`, `sectionId`, `count`.

**Цепочка `personal` (спецификация 5.5):** готовая подборка (FM или ALS) -> похожие на последние товары
покупателя -> популярное. Из выдачи всегда исключаются купленные товары и позиции текущей корзины
(включая отложенные); на шаге 2 - и сами последние товары.

---

- [ ] **Шаг 1. Цепочка выдачи**

`lib/Recommend/Recommender.php`:

```php
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
```

- [ ] **Шаг 2. Действие контроллера getList**

В `lib/Controller/Recommend.php` добавить импорты `use Bitrix\Main\ArgumentException;`,
`use Dzahdev\Recom\Recommend\Recommender;`, в `configureActions()` строку
`'getList' => ['-prefilters' => [Authentication::class]],` и метод:

```php
	/**
	 * @return array{ids: list<int>, source: string}
	 * @throws ArgumentException для неизвестного режима или режима similar без товара
	 */
	public function getListAction(string $mode, int $productId = 0, int $sectionId = 0, int $count = 10): array
	{
		$recommender = Recommender::create();

		return match ($mode) {
			'personal' => $recommender->personal(Fuser::getId(true), $this->siteId(), $count),
			'similar' => $productId > 0
				? $recommender->similar($productId, $count)
				: throw new ArgumentException('productId is required for mode similar', 'productId'),
			'popular' => $recommender->popular($sectionId, $count),
			default => throw new ArgumentException("Unknown mode {$mode}", 'mode'),
		};
	}
```

- [ ] **Шаг 3. Компонент**

`install/components/dzahdev/recom.list/.description.php`:

```php
<?php

use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

$arComponentDescription = [
	'NAME' => Loc::getMessage('DZAHDEV_RECOM_LIST_NAME'),
	'DESCRIPTION' => Loc::getMessage('DZAHDEV_RECOM_LIST_DESCRIPTION'),
	'PATH' => [
		'ID' => 'dzahdev',
		'NAME' => 'dzahdev',
	],
];
```

`install/components/dzahdev/recom.list/.parameters.php`:

```php
<?php

use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

$arComponentParameters = [
	'PARAMETERS' => [
		'MODE' => [
			'PARENT' => 'BASE',
			'NAME' => Loc::getMessage('DZAHDEV_RECOM_LIST_MODE'),
			'TYPE' => 'LIST',
			'VALUES' => [
				'personal' => Loc::getMessage('DZAHDEV_RECOM_LIST_MODE_PERSONAL'),
				'similar' => Loc::getMessage('DZAHDEV_RECOM_LIST_MODE_SIMILAR'),
				'popular' => Loc::getMessage('DZAHDEV_RECOM_LIST_MODE_POPULAR'),
			],
			'DEFAULT' => 'personal',
		],
		'IBLOCK_ID' => [
			'PARENT' => 'BASE',
			'NAME' => Loc::getMessage('DZAHDEV_RECOM_LIST_IBLOCK_ID'),
			'TYPE' => 'STRING',
		],
		'PRODUCT_ID' => [
			'PARENT' => 'BASE',
			'NAME' => Loc::getMessage('DZAHDEV_RECOM_LIST_PRODUCT_ID'),
			'TYPE' => 'STRING',
		],
		'SECTION_ID' => [
			'PARENT' => 'BASE',
			'NAME' => Loc::getMessage('DZAHDEV_RECOM_LIST_SECTION_ID'),
			'TYPE' => 'STRING',
			'DEFAULT' => '0',
		],
		'COUNT' => [
			'PARENT' => 'BASE',
			'NAME' => Loc::getMessage('DZAHDEV_RECOM_LIST_COUNT'),
			'TYPE' => 'STRING',
			'DEFAULT' => '8',
		],
		'PRICE_CODE' => [
			'PARENT' => 'BASE',
			'NAME' => Loc::getMessage('DZAHDEV_RECOM_LIST_PRICE_CODE'),
			'TYPE' => 'STRING',
			'MULTIPLE' => 'Y',
			'DEFAULT' => ['BASE'],
		],
		'SECTION_TEMPLATE' => [
			'PARENT' => 'VISUAL',
			'NAME' => Loc::getMessage('DZAHDEV_RECOM_LIST_SECTION_TEMPLATE'),
			'TYPE' => 'STRING',
			'DEFAULT' => '.default',
		],
	],
];
```

`install/components/dzahdev/recom.list/lang/ru/.description.php`:

```php
<?php

$MESS['DZAHDEV_RECOM_LIST_NAME'] = 'Рекомендации товаров';
$MESS['DZAHDEV_RECOM_LIST_DESCRIPTION'] = 'Персональные рекомендации, похожие или популярные товары';
```

`install/components/dzahdev/recom.list/lang/ru/.parameters.php`:

```php
<?php

$MESS['DZAHDEV_RECOM_LIST_MODE'] = 'Режим';
$MESS['DZAHDEV_RECOM_LIST_MODE_PERSONAL'] = 'Персональные';
$MESS['DZAHDEV_RECOM_LIST_MODE_SIMILAR'] = 'Похожие на товар';
$MESS['DZAHDEV_RECOM_LIST_MODE_POPULAR'] = 'Популярные';
$MESS['DZAHDEV_RECOM_LIST_IBLOCK_ID'] = 'ID инфоблока каталога';
$MESS['DZAHDEV_RECOM_LIST_PRODUCT_ID'] = 'ID товара (для режима "Похожие")';
$MESS['DZAHDEV_RECOM_LIST_SECTION_ID'] = 'ID раздела (для режима "Популярные", 0 - весь каталог)';
$MESS['DZAHDEV_RECOM_LIST_COUNT'] = 'Количество товаров';
$MESS['DZAHDEV_RECOM_LIST_PRICE_CODE'] = 'Типы цен';
$MESS['DZAHDEV_RECOM_LIST_SECTION_TEMPLATE'] = 'Шаблон компонента catalog.section';
```

`install/components/dzahdev/recom.list/class.php`:

```php
<?php

use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Bitrix\Sale\Fuser;
use Dzahdev\Recom\Recommend\Recommender;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class DzahdevRecomListComponent extends CBitrixComponent
{
	private const MODES = ['personal', 'similar', 'popular'];

	/**
	 * Запас списка: catalog.section отбросит неактивные и недоступные к покупке товары.
	 */
	private const RESERVE_FACTOR = 3;

	public function onPrepareComponentParams($arParams): array
	{
		$mode = (string)($arParams['MODE'] ?? '');
		if (!in_array($mode, self::MODES, true))
		{
			throw new SystemException("Unknown MODE '{$mode}' of dzahdev:recom.list");
		}

		$arParams['MODE'] = $mode;
		$arParams['IBLOCK_ID'] = (int)($arParams['IBLOCK_ID'] ?? 0);
		$arParams['PRODUCT_ID'] = (int)($arParams['PRODUCT_ID'] ?? 0);
		$arParams['SECTION_ID'] = (int)($arParams['SECTION_ID'] ?? 0);
		$arParams['COUNT'] = (int)($arParams['COUNT'] ?? 8);
		$arParams['PRICE_CODE'] = array_values(array_filter((array)($arParams['PRICE_CODE'] ?? ['BASE'])));
		$arParams['SECTION_TEMPLATE'] = (string)($arParams['SECTION_TEMPLATE'] ?? '.default');

		if ($arParams['IBLOCK_ID'] <= 0)
		{
			throw new SystemException('IBLOCK_ID of dzahdev:recom.list is required');
		}
		if ($arParams['COUNT'] <= 0)
		{
			throw new SystemException('COUNT of dzahdev:recom.list must be positive');
		}
		if ($mode === 'similar' && $arParams['PRODUCT_ID'] <= 0)
		{
			throw new SystemException('PRODUCT_ID of dzahdev:recom.list is required for MODE=similar');
		}

		return $arParams;
	}

	public function executeComponent(): void
	{
		foreach (['dzahdev.recom', 'sale', 'catalog'] as $module)
		{
			if (!Loader::includeModule($module))
			{
				throw new SystemException("Module {$module} is not installed");
			}
		}

		$recommender = Recommender::create();
		$limit = $this->arParams['COUNT'] * self::RESERVE_FACTOR;
		$result = match ($this->arParams['MODE']) {
			'personal' => $recommender->personal(Fuser::getId(true), SITE_ID, $limit),
			'similar' => $recommender->similar($this->arParams['PRODUCT_ID'], $limit),
			'popular' => $recommender->popular($this->arParams['SECTION_ID'], $limit),
		};

		$this->arResult = [
			'IDS' => $result['ids'],
			'SOURCE' => $result['source'],
		];
		$this->includeComponentTemplate();
	}
}
```

`install/components/dzahdev/recom.list/templates/.default/template.php`:

```php
<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

/**
 * @var array $arParams
 * @var array $arResult
 * @var CMain $APPLICATION
 * @var CBitrixComponent $component
 * @var CBitrixComponentTemplate $this
 */

if ($arResult['IDS'] === [])
{
	return;
}

$filterName = 'dzahdevRecomFilter' . $this->randString();
$GLOBALS[$filterName] = ['=ID' => $arResult['IDS']];
?>
<div class="dzahdev-recom" data-source="<?= htmlspecialcharsbx($arResult['SOURCE']) ?>">
<?php
$APPLICATION->IncludeComponent(
	'bitrix:catalog.section',
	$arParams['SECTION_TEMPLATE'],
	[
		'IBLOCK_ID' => $arParams['IBLOCK_ID'],
		'FILTER_NAME' => $filterName,
		'SECTION_ID' => '',
		'SECTION_CODE' => '',
		'SHOW_ALL_WO_SECTION' => 'Y',
		'INCLUDE_SUBSECTIONS' => 'Y',
		'PAGE_ELEMENT_COUNT' => $arParams['COUNT'],
		'CUSTOM_ELEMENT_SORT' => ['ID' => $arResult['IDS']],
		'HIDE_NOT_AVAILABLE' => 'Y',
		'PRICE_CODE' => $arParams['PRICE_CODE'],
		'DISPLAY_TOP_PAGER' => 'N',
		'DISPLAY_BOTTOM_PAGER' => 'N',
		'SET_TITLE' => 'N',
		'SET_BROWSER_TITLE' => 'N',
		'SET_META_KEYWORDS' => 'N',
		'SET_META_DESCRIPTION' => 'N',
		'ADD_SECTIONS_CHAIN' => 'N',
		'SET_STATUS_404' => 'N',
		'CACHE_TYPE' => 'N',
		'BASKET_URL' => '/personal/cart/',
		'ACTION_VARIABLE' => 'action',
		'PRODUCT_ID_VARIABLE' => 'id',
		'ADD_TO_BASKET_ACTION' => 'ADD',
		'PRODUCT_DISPLAY_MODE' => 'Y',
		'COMPATIBLE_MODE' => 'N',
	],
	$component,
	['HIDE_ICONS' => 'Y']
);
?>
</div>
```

В `install/index.php` заменить `getFileDirs()`:

```php
	private function getFileDirs(): array
	{
		return [
			'js' => ['/bitrix/js/dzahdev/recom'],
			'components' => ['/bitrix/components/dzahdev/recom.list'],
		];
	}
```

- [ ] **Шаг 4. Синтаксис, переустановка, повторное обучение**

Синтаксис - как на этапе 5, шаг 6. Переустановить модуль (компонент копируется при установке;
таблицы пересоздаются пустыми), затем повторить шаги 7 и 8 этапа 6 (синтетика и
`dzahdev.recom:train --full`). Проверка копирования:

```bash
ls /home/dzrise/projects/bitrix-modules/www/bitrix/components/dzahdev/recom.list
```

- [ ] **Шаг 5. Тестовая страница стенда**

Файл стенда `/home/dzrise/projects/bitrix-modules/www/recom-test.php` (в репозиторий модуля не
входит, `www/` целиком в `.gitignore` проекта стенда):

```php
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php';

$APPLICATION->SetTitle('Проверка рекомендаций');
$productId = (int)($_GET['product'] ?? 0);
?>
<h2>Персональные</h2>
<?php $APPLICATION->IncludeComponent('dzahdev:recom.list', '', ['MODE' => 'personal', 'IBLOCK_ID' => 2, 'COUNT' => 8]); ?>
<?php if ($productId > 0): ?>
<h2>Похожие на товар <?= $productId ?></h2>
<?php $APPLICATION->IncludeComponent('dzahdev:recom.list', '', ['MODE' => 'similar', 'IBLOCK_ID' => 2, 'PRODUCT_ID' => $productId, 'COUNT' => 8]); ?>
<?php endif; ?>
<h2>Популярные</h2>
<?php $APPLICATION->IncludeComponent('dzahdev:recom.list', '', ['MODE' => 'popular', 'IBLOCK_ID' => 2, 'COUNT' => 8]); ?>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
```

- [ ] **Шаг 6. Проверка выдачи**

1. Узнать FUSER_ID текущего браузера и сделать его покупателем с историей: в консоли браузера на
   странице сайта выполнить `BX.loadExt('dzahdev.recom').then(() => DzahdevRecom.trackView(<ID>))`
   для 4 товаров одного раздела (ID из `select ID, IBLOCK_SECTION_ID from b_iblock_element where IBLOCK_ID=2`),
   затем `dzahdev.recom:train --full`.
2. Открыть `http://localhost:8088/recom-test.php?product=<ID одного из этих товаров>`.

Ожидается:
- блок "Персональные" с `data-source="personal"`, просмотренные товары в нем отсутствуют;
- блок "Похожие" не пустой и не содержит сам товар;
- блок "Популярные" с `data-source="popular"`;
- карточки выглядят как в каталоге магазина, кнопка "В корзину" работает; после добавления товара
  в корзину он пропадает из персонального блока при обновлении страницы.

3. Новый посетитель (окно инкогнито): блок "Персональные" с `data-source="popular"`.
4. JSON: в консоли браузера
   `BX.ajax.runAction('dzahdev:recom.Recommend.getList', {data: {mode: 'popular', count: 5}}).then(r => console.log(r.data))`
   - ответ `{ids: [...5 ID], source: 'popular'}`; с `mode: 'unknown'` - ответ с ошибкой
   `Unknown mode unknown`.

Источник блоков смотреть через `document.querySelectorAll('.dzahdev-recom')` и атрибут
`data-source`.

- [ ] **Шаг 7. Коммит**

```bash
cd /home/dzrise/projects/bitrix-modules/www/local/modules/dzahdev.recom
git add -A
git -c user.name="Denis Zakharov" -c user.email=dzahdev@gmail.com commit -m "Add recommendation chain, list component and getList action."
git push
```

**Критерии приемки:** шаг 6 дал ожидаемые источники и исключения; страница не считает модели
(инвариант 3: `Recommender` читает только `ModelStore`, события покупателя и корзину);
инварианты 5 и 6 соблюдены.
