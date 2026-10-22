# Этап 5. Сбор событий

**Результат:** на стенде просмотр товара, добавление в корзину, перенос в отложенные и оформление
заказа пишут строки в `b_dzahdev_recom_event`. Торговые предложения сводятся к товару. Есть
JS-метод `DzahdevRecom.trackView(productId)` для шаблонов без стандартного учета просмотров.

**Файлы:**
- Создать: `lib/Catalog/ProductResolver.php`, `lib/Tracking/EventType.php`,
  `lib/Tracking/EventRecorder.php`, `lib/Tracking/Handler.php`, `lib/Controller/Recommend.php`
- Создать: `lib/Modules.php`, `.settings.php`, `install/js/dzahdev/recom/config.php`, `install/js/dzahdev/recom/script.js`
- Изменить: `install/index.php` (`getEventHandlers()`, `getFileDirs()`)

**Интерфейсы:**
- Потребляет: `Config`, `EventTable` (этап 1).
- Производит:
  - `EventType::VIEW|CART|DELAY|BUY`, `EventType::ALL`.
  - `ProductResolver::resolve(int $elementId): ?int` - ID товара; `null` для элемента вне торгового
    каталога; `\RuntimeException` для предложения без привязки к товару.
  - `EventRecorder::create(): EventRecorder`,
    `record(int $fuserId, int $elementId, string $type, string $siteId): bool` (false - не товар каталога).
  - Контроллер `\Dzahdev\Recom\Controller\Recommend`, действие `trackView(int $productId)`,
    вызов из JS `BX.ajax.runAction('dzahdev:recom.Recommend.trackView', {data: {productId}})`.

**Источники событий** (сверено с ядром 26.150):
- `catalog`, `\Bitrix\Catalog\CatalogViewedProduct::OnAfterAdd` и `::OnAfterUpdate` - ORM-события
  `CatalogViewedProductTable::refresh()`, параметры `fields`, `primary`, `id`.
- `sale`, `OnSaleBasketItemSaved` - параметры `ENTITY` (BasketItem), `IS_NEW`, `VALUES` (старые
  значения измененных полей).
- `sale`, `OnSaleOrderSaved` - параметры `ENTITY` (Order), `IS_NEW`.

---

- [ ] **Шаг 1. Типы событий и приведение к товару**

`lib/Tracking/EventType.php`:

```php
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
```

`lib/Catalog/ProductResolver.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Catalog;

use Bitrix\Catalog\CatalogIblockTable;
use Bitrix\Iblock\ElementPropertyTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\IblockTable;
use Bitrix\Main\Application;

/**
 * Приводит элемент торгового каталога к товару: предложение - к родительскому товару.
 */
final class ProductResolver
{
	/** @var array<int, array{productIblock: int, skuProperty: int, version: int}|null> */
	private array $iblocks = [];

	/**
	 * @return int|null ID товара; null, если элемент не входит в торговый каталог
	 * @throws \RuntimeException если у предложения нет привязки к товару
	 */
	public function resolve(int $elementId): ?int
	{
		$element = ElementTable::getRow([
			'select' => ['IBLOCK_ID'],
			'filter' => ['=ID' => $elementId],
		]);
		if ($element === null)
		{
			return null;
		}

		$iblockId = (int)$element['IBLOCK_ID'];
		$catalog = $this->catalogIblock($iblockId);
		if ($catalog === null)
		{
			return null;
		}
		if ($catalog['productIblock'] === 0)
		{
			return $elementId;
		}

		$parentId = $this->parentId($elementId, $iblockId, $catalog);
		if ($parentId <= 0)
		{
			throw new \RuntimeException("Offer {$elementId} has no link to a parent product");
		}

		return $parentId;
	}

	/**
	 * @return array{productIblock: int, skuProperty: int, version: int}|null
	 */
	private function catalogIblock(int $iblockId): ?array
	{
		if (array_key_exists($iblockId, $this->iblocks))
		{
			return $this->iblocks[$iblockId];
		}

		$catalog = CatalogIblockTable::getRow([
			'select' => ['PRODUCT_IBLOCK_ID', 'SKU_PROPERTY_ID'],
			'filter' => ['=IBLOCK_ID' => $iblockId],
		]);
		if ($catalog === null)
		{
			return $this->iblocks[$iblockId] = null;
		}

		$iblock = IblockTable::getRow([
			'select' => ['VERSION'],
			'filter' => ['=ID' => $iblockId],
		]);

		return $this->iblocks[$iblockId] = [
			'productIblock' => (int)$catalog['PRODUCT_IBLOCK_ID'],
			'skuProperty' => (int)$catalog['SKU_PROPERTY_ID'],
			'version' => (int)$iblock['VERSION'],
		];
	}

	/**
	 * @param array{productIblock: int, skuProperty: int, version: int} $catalog
	 */
	private function parentId(int $elementId, int $iblockId, array $catalog): int
	{
		if ($catalog['version'] === 1)
		{
			$row = ElementPropertyTable::getRow([
				'select' => ['VALUE'],
				'filter' => [
					'=IBLOCK_ELEMENT_ID' => $elementId,
					'=IBLOCK_PROPERTY_ID' => $catalog['skuProperty'],
				],
			]);

			return $row === null ? 0 : (int)$row['VALUE'];
		}

		// Инфоблок с хранением свойств в отдельной таблице (версия 2): ORM-сущности у нее нет.
		$value = Application::getConnection()->queryScalar(sprintf(
			'SELECT PROPERTY_%d FROM b_iblock_element_prop_s%d WHERE IBLOCK_ELEMENT_ID = %d',
			$catalog['skuProperty'],
			$iblockId,
			$elementId
		));

		return (int)$value;
	}
}
```

- [ ] **Шаг 2. Запись события**

`lib/Tracking/EventRecorder.php`:

```php
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
```

- [ ] **Шаг 3. Обработчики событий**

`lib/Tracking/Handler.php`:

```php
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
```

- [ ] **Шаг 4. Контроллер и JS-расширение**

`.settings.php`:

```php
<?php

return [
	'controllers' => [
		'value' => [
			'defaultNamespace' => '\\Dzahdev\\Recom\\Controller',
		],
		'readonly' => true,
	],
];
```

`lib/Modules.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom;

use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;

final class Modules
{
	/**
	 * Подключает модули ядра, без которых код модуля не работает. Вне страницы сайта (консоль,
	 * агент, AJAX-контроллер) ядро их само не подключает.
	 *
	 * @throws SystemException если модуль не установлен
	 */
	public static function require(string ...$moduleIds): void
	{
		foreach ($moduleIds as $moduleId)
		{
			if (!Loader::includeModule($moduleId))
			{
				throw new SystemException("Module {$moduleId} is not installed");
			}
		}
	}
}
```

`lib/Controller/Recommend.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Controller;

use Bitrix\Main\Application;
use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\Controller;
use Bitrix\Sale\Fuser;
use Dzahdev\Recom\Modules;
use Dzahdev\Recom\Tracking\EventRecorder;
use Dzahdev\Recom\Tracking\EventType;

class Recommend extends Controller
{
	/**
	 * В AJAX-запросе ядро не подключает модули магазина: учет событий и выдача без них не работают.
	 */
	protected function init(): void
	{
		parent::init();

		Modules::require('iblock', 'catalog', 'sale');
	}

	public function configureActions(): array
	{
		// Гости тоже покупатели: действия доступны без авторизации, проверка sessid остается.
		return [
			'trackView' => ['-prefilters' => [Authentication::class]],
		];
	}

	/**
	 * @return array{recorded: bool} false - элемент вне торгового каталога
	 */
	public function trackViewAction(int $productId): array
	{
		return [
			'recorded' => EventRecorder::create()->record((int)Fuser::getId(), $productId, EventType::VIEW, $this->siteId()),
		];
	}

	private function siteId(): string
	{
		$siteId = Application::getInstance()->getContext()->getSite();
		if ($siteId === null || $siteId === '')
		{
			throw new \RuntimeException('Site of the request is not defined');
		}

		return $siteId;
	}
}
```

`install/js/dzahdev/recom/config.php`:

```php
<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

return [
	'js' => './script.js',
	'rel' => ['main.core'],
];
```

`install/js/dzahdev/recom/script.js`:

```js
(function () {
	'use strict';

	window.DzahdevRecom = window.DzahdevRecom || {
		/**
		 * Учет просмотра товара для шаблонов, которые не вызывают стандартный учет просмотров каталога.
		 * @param {number} productId ID товара или торгового предложения
		 * @returns {Promise}
		 */
		trackView: function (productId) {
			return BX.ajax.runAction('dzahdev:recom.Recommend.trackView', {
				data: { productId: productId }
			});
		}
	};
})();
```

- [ ] **Шаг 5. Регистрация в установщике**

В `install/index.php` добавить `use Dzahdev\Recom\Tracking\Handler;` и заменить тела методов:

```php
	private function getEventHandlers(): array
	{
		return [
			['catalog', '\Bitrix\Catalog\CatalogViewedProduct::OnAfterAdd', Handler::class, 'onViewedProductAdd'],
			['catalog', '\Bitrix\Catalog\CatalogViewedProduct::OnAfterUpdate', Handler::class, 'onViewedProductUpdate'],
			['sale', 'OnSaleBasketItemSaved', Handler::class, 'onBasketItemSaved'],
			['sale', 'OnSaleOrderSaved', Handler::class, 'onOrderSaved'],
		];
	}
```

```php
	private function getFileDirs(): array
	{
		return [
			'js' => ['/bitrix/js/dzahdev/recom'],
		];
	}
```

- [ ] **Шаг 6. Синтаксис и переустановка**

```bash
cd /home/dzrise/projects/bitrix-modules/www/local/modules/dzahdev.recom
for f in $(git ls-files -o -m --exclude-standard '*.php') ; do docker exec bitrix_php php -l /var/www/bitrix/local/modules/dzahdev.recom/$f | grep -v '^No syntax'; done
```

Ожидается: пустой вывод. Затем в админке удалить и установить модуль заново (обработчики
регистрируются при установке). Проверка регистрации:

```bash
docker exec bitrix_mysql mysql -ubitrix -pbitrix bitrix -N -e "select FROM_MODULE_ID, MESSAGE_ID, TO_METHOD from b_module_to_module where TO_MODULE_ID='dzahdev.recom'" 2>/dev/null
ls /home/dzrise/projects/bitrix-modules/www/bitrix/js/dzahdev/recom
```

Ожидается: 4 обработчика; файлы `config.php`, `script.js`.

- [ ] **Шаг 7. Проверка на стенде**

В браузере: открыть карточку любого товара каталога (`/catalog/...`), добавить товар в корзину, в
корзине (`/personal/cart/`) отложить другой товар, оформить заказ на оставшийся товар (оплата
наличными, самовывоз; тестовый заказ на локальном стенде). Затем:

```bash
docker exec bitrix_mysql mysql -ubitrix -pbitrix bitrix -e "select FUSER_ID, PRODUCT_ID, TYPE, SITE_ID, CREATED_AT from b_dzahdev_recom_event order by ID" 2>/dev/null
docker exec bitrix_mysql mysql -ubitrix -pbitrix bitrix -e "select SEVERITY, DESCRIPTION from b_event_log where MODULE_ID='dzahdev.recom' order by ID desc limit 5" 2>/dev/null
```

Ожидается: строки `view`, `cart`, `delay`, `buy`; у предложений `PRODUCT_ID` - ID родительского
товара (инфоблок 2, а не 3); в журнале событий нет ошибок модуля.

Если строк `view` нет - проверить, что карточка вызывает учет просмотров
(`/bitrix/components/bitrix/catalog.element/ajax.php` в сетевых запросах) и что имя события
совпадает с `$entity->getNamespace() . $entity->getName() . '::OnAfterAdd'`
(`main/lib/orm/event.php`, строка 30).

- [ ] **Шаг 8. Проверка JS-метода**

На любой странице сайта в консоли браузера:

```js
BX.loadExt('dzahdev.recom').then(() => DzahdevRecom.trackView(<ID товара>)).then(r => console.log(r.data));
```

Ожидается: `{recorded: true}` и новая строка `view` в таблице событий. Если ответ - ошибка
"controller not found", сверить имя действия с правилом `vendor:module.controller.action` для
`defaultNamespace` и исправить строку в `script.js`.

- [ ] **Шаг 9. Коммит**

```bash
cd /home/dzrise/projects/bitrix-modules/www/local/modules/dzahdev.recom
git add -A
git -c user.name="Denis Zakharov" -c user.email=dzahdev@gmail.com commit -m "Track product views, basket and order events."
git push
```

**Критерии приемки:** шаги 6-8 дали ожидаемые строки; оформление заказа и корзина работают как
раньше; инварианты 1, 5, 6 проверены по коду обработчиков (только запись строки, ORM D7, ошибки в
журнал событий без выброса в ядро).
