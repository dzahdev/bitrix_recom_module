# Этап 9. Рекомендации в письмах

**Результат:** компонент `dzahdev:recom.list.mail` вставляется в HTML-шаблон почтового события
Битрикса и выводит персональные, похожие или популярные товары в верстке стандартного
`bitrix:catalog.show.products.mail`. Проверено письмом в Mailpit стенда.

**Файлы:**
- Создать: `lib/Recommend/FuserLocator.php`, `lib/Recommend/AvailableFilter.php`
- Создать: `install/components/dzahdev/recom.list.mail/.description.php`, `.parameters.php`,
  `class.php`, `templates/.default/template.php`, `lang/ru/.description.php`, `lang/ru/.parameters.php`
- Изменить: `install/index.php` (`getFileDirs()`)

**Интерфейсы:**
- Потребляет: `Recommender` (этап 8).
- Производит:
  - `FuserLocator::byUserId(int $userId): ?int` - FUSER_ID пользователя без создания записи;
  - `AvailableFilter::filter(list<int> $ids, int $limit): list<int>` - первые `$limit` активных и
    доступных к покупке товаров в исходном порядке;
  - компонент `dzahdev:recom.list.mail`, параметры `MODE`, `USER_ID`, `PRODUCT_ID`, `SECTION_ID`,
    `COUNT`.

**Как это работает в письме (сверено с ядром 26.150):**
- `EventMessageTable::replaceTemplateToPhp()` превращает макрос `#USER_ID#` шаблона в
  `$arParams["USER_ID"]`, поэтому строка `"{#USER_ID#}"` в параметрах компонента подставляет поле
  письма (PHP-интерполяция в двойных кавычках).
- `EventMessageThemeCompiler::includeComponent()` выставляет компоненту сайт, язык и шаблон
  письма (`setSiteId`, `setLanguageId`, `setSiteTemplateId`).
- `bitrix:sale.bigdata.personal.mail` устроен так же: считает ID товаров и передает их в
  `bitrix:catalog.show.products.mail` параметром `LIST_ITEM_ID`.

---

- [ ] **Шаг 1. Поиск покупателя и фильтр доступности**

`lib/Recommend/FuserLocator.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Recommend;

use Bitrix\Sale\Internals\FuserTable;

final class FuserLocator
{
	/**
	 * Только чтение: Fuser::getIdByUserId() при отсутствии записи создает новую корзину покупателя,
	 * а отправка письма не должна менять данные магазина.
	 *
	 * @return int|null null - у пользователя нет корзины покупателя, значит нет и истории событий
	 */
	public static function byUserId(int $userId): ?int
	{
		$row = FuserTable::getRow([
			'select' => ['ID'],
			'filter' => ['=USER_ID' => $userId],
			'order' => ['ID' => 'DESC'],
		]);

		return $row === null ? null : (int)$row['ID'];
	}
}
```

`lib/Recommend/AvailableFilter.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Recommend;

use Bitrix\Catalog\ProductTable;

final class AvailableFilter
{
	/**
	 * В письме нет catalog.section, который сам отбросил бы неактивные и недоступные товары,
	 * поэтому список фильтруется здесь.
	 *
	 * @param list<int> $ids по убыванию оценки
	 * @return list<int> первые $limit активных и доступных к покупке товаров в исходном порядке
	 */
	public static function filter(array $ids, int $limit): array
	{
		if ($ids === [])
		{
			return [];
		}

		$rows = ProductTable::getList([
			'select' => ['ID'],
			'filter' => [
				'@ID' => $ids,
				'=AVAILABLE' => 'Y',
				'=IBLOCK_ELEMENT.ACTIVE' => 'Y',
			],
		]);
		$available = [];
		while ($row = $rows->fetch())
		{
			$available[(int)$row['ID']] = true;
		}

		$result = [];
		foreach ($ids as $id)
		{
			if (isset($available[$id]))
			{
				$result[] = $id;
				if (count($result) >= $limit)
				{
					break;
				}
			}
		}

		return $result;
	}
}
```

- [ ] **Шаг 2. Компонент для писем**

`install/components/dzahdev/recom.list.mail/.description.php`:

```php
<?php

use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

$arComponentDescription = [
	'NAME' => Loc::getMessage('DZAHDEV_RECOM_MAIL_NAME'),
	'DESCRIPTION' => Loc::getMessage('DZAHDEV_RECOM_MAIL_DESCRIPTION'),
	'PATH' => [
		'ID' => 'dzahdev',
		'NAME' => 'dzahdev',
	],
];
```

`install/components/dzahdev/recom.list.mail/.parameters.php`:

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
			'NAME' => Loc::getMessage('DZAHDEV_RECOM_MAIL_MODE'),
			'TYPE' => 'LIST',
			'VALUES' => [
				'personal' => Loc::getMessage('DZAHDEV_RECOM_MAIL_MODE_PERSONAL'),
				'similar' => Loc::getMessage('DZAHDEV_RECOM_MAIL_MODE_SIMILAR'),
				'popular' => Loc::getMessage('DZAHDEV_RECOM_MAIL_MODE_POPULAR'),
			],
			'DEFAULT' => 'personal',
		],
		'USER_ID' => [
			'PARENT' => 'BASE',
			'NAME' => Loc::getMessage('DZAHDEV_RECOM_MAIL_USER_ID'),
			'TYPE' => 'STRING',
			'DEFAULT' => '{#USER_ID#}',
		],
		'PRODUCT_ID' => [
			'PARENT' => 'BASE',
			'NAME' => Loc::getMessage('DZAHDEV_RECOM_MAIL_PRODUCT_ID'),
			'TYPE' => 'STRING',
		],
		'SECTION_ID' => [
			'PARENT' => 'BASE',
			'NAME' => Loc::getMessage('DZAHDEV_RECOM_MAIL_SECTION_ID'),
			'TYPE' => 'STRING',
			'DEFAULT' => '0',
		],
		'COUNT' => [
			'PARENT' => 'BASE',
			'NAME' => Loc::getMessage('DZAHDEV_RECOM_MAIL_COUNT'),
			'TYPE' => 'STRING',
			'DEFAULT' => '4',
		],
	],
];
```

`install/components/dzahdev/recom.list.mail/lang/ru/.description.php`:

```php
<?php

$MESS['DZAHDEV_RECOM_MAIL_NAME'] = 'Рекомендации товаров в письме';
$MESS['DZAHDEV_RECOM_MAIL_DESCRIPTION'] = 'Персональные, похожие или популярные товары для HTML-шаблона почтового события';
```

`install/components/dzahdev/recom.list.mail/lang/ru/.parameters.php`:

```php
<?php

$MESS['DZAHDEV_RECOM_MAIL_MODE'] = 'Режим';
$MESS['DZAHDEV_RECOM_MAIL_MODE_PERSONAL'] = 'Персональные для получателя';
$MESS['DZAHDEV_RECOM_MAIL_MODE_SIMILAR'] = 'Похожие на товар';
$MESS['DZAHDEV_RECOM_MAIL_MODE_POPULAR'] = 'Популярные';
$MESS['DZAHDEV_RECOM_MAIL_USER_ID'] = 'ID пользователя-получателя (макрос поля письма, например {#USER_ID#})';
$MESS['DZAHDEV_RECOM_MAIL_PRODUCT_ID'] = 'ID товара для режима "Похожие" (например {#PRODUCT_ID#})';
$MESS['DZAHDEV_RECOM_MAIL_SECTION_ID'] = 'ID раздела для режима "Популярные", 0 - весь каталог';
$MESS['DZAHDEV_RECOM_MAIL_COUNT'] = 'Количество товаров';
```

`install/components/dzahdev/recom.list.mail/class.php`:

```php
<?php

use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Dzahdev\Recom\Recommend\AvailableFilter;
use Dzahdev\Recom\Recommend\FuserLocator;
use Dzahdev\Recom\Recommend\Recommender;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class DzahdevRecomListMailComponent extends CBitrixComponent
{
	private const MODES = ['personal', 'similar', 'popular'];

	/**
	 * Запас списка до фильтра активности и доступности.
	 */
	private const RESERVE_FACTOR = 3;

	public function onPrepareComponentParams($arParams): array
	{
		$mode = (string)($arParams['MODE'] ?? '');
		if (!in_array($mode, self::MODES, true))
		{
			throw new SystemException("Unknown MODE '{$mode}' of dzahdev:recom.list.mail");
		}

		$arParams['MODE'] = $mode;
		// Письмо гостю или событие без поля USER_ID дает пустую строку: получатель без истории.
		$arParams['USER_ID'] = (int)($arParams['USER_ID'] ?? 0);
		$arParams['PRODUCT_ID'] = (int)($arParams['PRODUCT_ID'] ?? 0);
		$arParams['SECTION_ID'] = (int)($arParams['SECTION_ID'] ?? 0);
		$arParams['COUNT'] = (int)($arParams['COUNT'] ?? 4);

		if ($arParams['COUNT'] <= 0)
		{
			throw new SystemException('COUNT of dzahdev:recom.list.mail must be positive');
		}
		if ($mode === 'similar' && $arParams['PRODUCT_ID'] <= 0)
		{
			throw new SystemException('PRODUCT_ID of dzahdev:recom.list.mail is required for MODE=similar');
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

		$siteId = (string)$this->getSiteId();
		if ($siteId === '')
		{
			throw new SystemException('Site of the mail message is not defined');
		}

		$recommender = Recommender::create();
		$limit = $this->arParams['COUNT'] * self::RESERVE_FACTOR;
		$result = match ($this->arParams['MODE']) {
			'personal' => $recommender->personal(
				$this->arParams['USER_ID'] > 0 ? FuserLocator::byUserId($this->arParams['USER_ID']) : null,
				$siteId,
				$limit
			),
			'similar' => $recommender->similar($this->arParams['PRODUCT_ID'], $limit),
			'popular' => $recommender->popular($this->arParams['SECTION_ID'], $limit),
		};

		$this->arResult = [
			'IDS' => AvailableFilter::filter($result['ids'], $this->arParams['COUNT']),
			'SOURCE' => $result['source'],
		];
		$this->includeComponentTemplate();
	}
}
```

`install/components/dzahdev/recom.list.mail/templates/.default/template.php`:

```php
<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

/**
 * @var array $arResult
 * @var CBitrixComponentTemplate $this
 */

if ($arResult['IDS'] === [])
{
	return;
}

\Bitrix\Main\Mail\EventMessageThemeCompiler::includeComponent(
	'bitrix:catalog.show.products.mail',
	'.default',
	['LIST_ITEM_ID' => $arResult['IDS']],
	false
);
```

В `install/index.php` в `getFileDirs()` добавить путь компонента:

```php
			'components' => [
				'/bitrix/components/dzahdev/recom.list',
				'/bitrix/components/dzahdev/recom.list.mail',
			],
```

- [ ] **Шаг 3. Синтаксис и переустановка**

Синтаксис - как на этапе 5, шаг 6. Переустановить модуль, повторить синтетику и обучение
(этап 6, шаги 7-8; с FM - этап 7). Проверка:

```bash
ls /home/dzrise/projects/bitrix-modules/www/bitrix/components/dzahdev/recom.list.mail/templates/.default
```

- [ ] **Шаг 4. Тестовое почтовое событие на стенде**

Скрипт стенда `/home/dzrise/projects/bitrix-modules/www/recom-mail-test.php` (в репозиторий модуля не
входит): создает тип события и HTML-шаблон с компонентом, если их еще нет, и отправляет письмо.
Запуск из консоли через `www/dev-cli.php` (ядро с правами без авторизации в админке):

```php
<?php

/**
 * Скрипт стенда (не входит в модуль): тестовое почтовое событие с компонентом dzahdev:recom.list.mail.
 * Запуск: docker exec -w /var/www/bitrix bitrix_php php dev-cli.php '$user = 2; $product = 11; include "recom-mail-test.php";'
 * $user - ID пользователя-получателя, $product - ID товара для блока "Похожие".
 */

use Bitrix\Main\Mail\Event;
use Bitrix\Main\Mail\Internal\EventMessageSiteTable;
use Bitrix\Main\Mail\Internal\EventMessageTable;
use Bitrix\Main\Mail\Internal\EventTypeTable;

if (PHP_SAPI !== 'cli')
{
	http_response_code(404);
	exit;
}

const TEST_EVENT = 'DZAHDEV_RECOM_TEST';

if (EventTypeTable::getRow(['filter' => ['=EVENT_NAME' => TEST_EVENT, '=LID' => 'ru']]) === null)
{
	$added = EventTypeTable::add([
		'LID' => 'ru',
		'EVENT_NAME' => TEST_EVENT,
		'NAME' => 'Проверка рекомендаций в письме',
		'DESCRIPTION' => "#EMAIL# - адрес\n#USER_ID# - ID пользователя\n#PRODUCT_ID# - ID товара",
		'EVENT_TYPE' => 'email',
	]);
	if (!$added->isSuccess())
	{
		throw new RuntimeException(implode('; ', $added->getErrorMessages()));
	}

	$message = <<<'HTML'
<h2>Персональные рекомендации</h2>
<?EventMessageThemeCompiler::includeComponent("dzahdev:recom.list.mail", "", Array("MODE" => "personal", "USER_ID" => "{#USER_ID#}", "COUNT" => "4"))?>
<h2>Похожие на товар</h2>
<?EventMessageThemeCompiler::includeComponent("dzahdev:recom.list.mail", "", Array("MODE" => "similar", "PRODUCT_ID" => "{#PRODUCT_ID#}", "COUNT" => "4"))?>
<h2>Популярные</h2>
<?EventMessageThemeCompiler::includeComponent("dzahdev:recom.list.mail", "", Array("MODE" => "popular", "COUNT" => "4"))?>
HTML;

	$added = EventMessageTable::add([
		'EVENT_NAME' => TEST_EVENT,
		'LID' => 's1',
		'ACTIVE' => 'Y',
		'EMAIL_FROM' => '#DEFAULT_EMAIL_FROM#',
		'EMAIL_TO' => '#EMAIL#',
		'SUBJECT' => 'Проверка рекомендаций',
		'MESSAGE' => $message,
		'BODY_TYPE' => 'html',
		'SITE_TEMPLATE_ID' => 'mail_user',
		'LANGUAGE_ID' => 'ru',
	]);
	if (!$added->isSuccess())
	{
		throw new RuntimeException(implode('; ', $added->getErrorMessages()));
	}
	EventMessageSiteTable::add(['EVENT_MESSAGE_ID' => $added->getId(), 'SITE_ID' => 's1']);
	echo "template created\n";
}

$status = Event::sendImmediate([
	'EVENT_NAME' => TEST_EVENT,
	'LID' => 's1',
	'C_FIELDS' => [
		'EMAIL' => 'recom-test@bitrix.local',
		'USER_ID' => (int)($user ?? 0),
		'PRODUCT_ID' => (int)($product ?? 0),
	],
]);

echo 'send status: ' . $status . "\n";
```

```bash
docker exec -w /var/www/bitrix bitrix_php php dev-cli.php '$user = <ID пользователя с подборкой>; $product = <ID товара с похожими из этапа 8>; include "recom-mail-test.php";'
```

Ожидается `send status: Y`. Если у пользователя с `USER_ID` нет истории, добавить ему события
(как в этапе 6, шаг 7) и выполнить `tools/train.php --full`.

- [ ] **Шаг 5. Проверка письма в Mailpit**

```bash
python3 -I -c '
import json, urllib.request as u
msgs = json.load(u.urlopen("http://localhost:8025/api/v1/messages?limit=1"))["messages"]
m = msgs[0]; print(m["Subject"], m["To"])
html = u.urlopen("http://localhost:8025/view/" + m["ID"] + ".html").read().decode()
print("links:", html.count("http://localhost:8088/catalog/"), "headings:", html.count("<h2>"))
'
```

Ожидается: тема "Проверка рекомендаций", получатель `recom-test@bitrix.local`, абсолютные ссылки
на товары каталога, три заголовка. Глазами - `http://localhost:8025`: три блока карточек
(картинка, название, цена). Если адрес сайта в ссылках не `localhost:8088`, задать его в настройках
сайта `s1` ("Настройки - Сайты - URL сервера") - это поле, из которого ядро строит ссылки писем.

Дополнительно:
- `$user = 999999` (пользователь без корзины) - блок персональных выводит популярное;
- `$product = 0` - блок "Похожие" получает `PRODUCT_ID = 0`, компонент бросает
  `PRODUCT_ID of dzahdev:recom.list.mail is required for MODE=similar`, письмо не уходит (исключение
  на странице скрипта или статус не `Y`, новых писем в Mailpit нет). Шаблон, собранный без нужного
  поля, виден сразу, а не уходит клиенту с пустым блоком.

- [ ] **Шаг 6. Коммит**

```bash
cd /home/dzrise/projects/bitrix-modules/www/local/modules/dzahdev.recom
git add -A
git -c user.name="Denis Zakharov" -c user.email=dzahdev@gmail.com commit -m "Add mail component for recommendations in mail event templates."
git push
```

**Критерии приемки:** письмо в Mailpit содержит три блока товаров с абсолютными ссылками;
`FuserLocator` не создает записей в `b_sale_fuser`
(`select count(*) from b_sale_fuser` до и после шага 5 с `$user = 999999` совпадает); инварианты 3
и 5 соблюдены.
