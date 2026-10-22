# Этап 1. Каркас модуля, таблицы, установка

**Результат:** модуль `dzahdev.recom` ставится и удаляется из админки, при установке создает 6 таблиц
с индексами, при удалении удаляет их и настройки. Приватный репозиторий
`dzahdev/bitrix_recom_module` создан, ветка `main` отправлена.

**Файлы:**
- Создать: `install/version.php`, `install/index.php`, `install/step.php`, `install/unstep.php`
- Создать: `lang/ru/install/index.php`, `lang/ru/install/step.php`, `lang/ru/install/unstep.php`
- Создать: `include.php`, `default_option.php`, `lib/Config.php`
- Создать: `lib/Orm/EventTable.php`, `FactorTable.php`, `UserRecTable.php`, `ItemSimTable.php`,
  `PopularTable.php`, `TrainLogTable.php`

**Интерфейсы:**
- Производит: `\Dzahdev\Recom\Config` (`MODULE_ID`, `string()`, `int()`, `float()`, `set()`,
  `positiveTypes()`, `weight()`); ORM-классы `\Dzahdev\Recom\Orm\*Table`; константы
  `FactorTable::USER = 'U'`, `FactorTable::ITEM = 'I'`; в установщике методы `getEventHandlers()`,
  `getAgents()`, `getFileDirs()` возвращают пустые списки и дополняются на этапах 5, 6, 8, 9.

---

- [ ] **Шаг 1. Версия и пустой include.php**

`install/version.php`:

```php
<?php

$arModuleVersion = [
	'VERSION' => '0.1.0',
	'VERSION_DATE' => '2026-10-23 00:00:00',
];
```

`include.php`:

```php
<?php

// Классы модуля загружает автозагрузчик D7 из каталога lib.
```

- [ ] **Шаг 2. Настройки по умолчанию и класс Config**

`default_option.php`:

```php
<?php

$dzahdev_recom_default_option = [
	'positive_types' => 'view,cart,delay,buy',
	'weight_view' => '1',
	'weight_delay' => '2',
	'weight_cart' => '3',
	'weight_buy' => '5',
	'als_factors' => '16',
	'als_reg' => '0.06',
	'als_alpha' => '0.001',
	'als_epochs_full' => '20',
	'als_epochs_incremental' => '3',
	'fm_enabled' => 'Y',
	'fm_factors' => '8',
	'fm_learning_rate' => '0.05',
	'fm_reg' => '0.0001',
	'fm_epochs' => '30',
	'fm_negatives' => '2',
	'min_user_events' => '3',
	'rec_limit' => '50',
	'similar_limit' => '20',
	'popular_limit' => '100',
	'popular_window_days' => '30',
	'active_user_days' => '30',
	'event_ttl_days' => '180',
	'eval_users' => '300',
	'max_user_items_similarity' => '500',
	'train_incremental_interval' => '3600',
	'train_full_interval' => '86400',
	'full_train_requested' => 'N',
	'last_full_train_at' => '0',
];
```

`lib/Config.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom;

use Bitrix\Main\Config\Option;

final class Config
{
	public const MODULE_ID = 'dzahdev.recom';

	/**
	 * @throws \LogicException если настройка не задана и нет значения в default_option.php
	 */
	public static function string(string $name): string
	{
		$value = Option::get(self::MODULE_ID, $name);
		if ($value === '')
		{
			throw new \LogicException("Option {$name} of module " . self::MODULE_ID . ' is not defined');
		}

		return $value;
	}

	public static function int(string $name): int
	{
		return (int)self::string($name);
	}

	public static function float(string $name): float
	{
		return (float)self::string($name);
	}

	public static function set(string $name, string $value): void
	{
		Option::set(self::MODULE_ID, $name, $value);
	}

	/**
	 * @return list<string> типы событий, которые считаются положительным сигналом
	 */
	public static function positiveTypes(): array
	{
		return array_values(array_filter(array_map('trim', explode(',', self::string('positive_types')))));
	}

	public static function weight(string $type): float
	{
		return self::float('weight_' . $type);
	}
}
```

- [ ] **Шаг 3. ORM-таблицы**

`lib/Orm/EventTable.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

class EventTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_dzahdev_recom_event';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new IntegerField('FUSER_ID'))->configureRequired(),
			(new IntegerField('PRODUCT_ID'))->configureRequired(),
			(new StringField('TYPE'))->configureRequired()->configureSize(16),
			(new StringField('SITE_ID'))->configureRequired()->configureSize(2),
			(new DatetimeField('CREATED_AT'))->configureRequired(),
		];
	}
}
```

`lib/Orm/FactorTable.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Векторы факторов ALS. VECTOR - base64 от float32 little-endian (VectorCodec::packVector).
 */
class FactorTable extends DataManager
{
	public const USER = 'U';
	public const ITEM = 'I';

	public static function getTableName(): string
	{
		return 'b_dzahdev_recom_factor';
	}

	public static function getMap(): array
	{
		return [
			(new StringField('ENTITY_TYPE'))->configurePrimary()->configureSize(1),
			(new IntegerField('ENTITY_ID'))->configurePrimary(),
			(new TextField('VECTOR'))->configureRequired(),
		];
	}
}
```

`lib/Orm/UserRecTable.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Готовые персональные подборки. ITEMS - base64 от VectorCodec::packScores.
 */
class UserRecTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_dzahdev_recom_user_rec';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('FUSER_ID'))->configurePrimary(),
			(new TextField('ITEMS'))->configureRequired(),
			(new StringField('SOURCE'))->configureRequired()->configureSize(16),
			(new DatetimeField('UPDATED_AT'))->configureRequired(),
		];
	}
}
```

`lib/Orm/ItemSimTable.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Похожие товары. ITEMS - base64 от VectorCodec::packScores.
 */
class ItemSimTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_dzahdev_recom_item_sim';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('PRODUCT_ID'))->configurePrimary(),
			(new TextField('ITEMS'))->configureRequired(),
		];
	}
}
```

`lib/Orm/PopularTable.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Популярные товары. SECTION_ID = 0 - весь каталог. ITEMS - base64 от VectorCodec::packScores.
 */
class PopularTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_dzahdev_recom_popular';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('SECTION_ID'))->configurePrimary(),
			(new TextField('ITEMS'))->configureRequired(),
		];
	}
}
```

`lib/Orm/TrainLogTable.php`:

```php
<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\FloatField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

class TrainLogTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_dzahdev_recom_train_log';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new StringField('MODE'))->configureRequired()->configureSize(16),
			(new DatetimeField('STARTED_AT'))->configureRequired(),
			(new FloatField('DURATION_SEC'))->configureRequired(),
			(new IntegerField('USERS_COUNT'))->configureRequired(),
			(new IntegerField('ITEMS_COUNT'))->configureRequired(),
			(new IntegerField('PAIRS_COUNT'))->configureRequired(),
			(new FloatField('NDCG'))->configureNullable(),
			(new FloatField('NDCG_FM'))->configureNullable(),
			(new TextField('ERROR'))->configureNullable(),
		];
	}
}
```

- [ ] **Шаг 4. Установщик**

`install/index.php`:

```php
<?php

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\IO\Directory;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Type\DateTime;
use Dzahdev\Recom\Orm\EventTable;
use Dzahdev\Recom\Orm\FactorTable;
use Dzahdev\Recom\Orm\ItemSimTable;
use Dzahdev\Recom\Orm\PopularTable;
use Dzahdev\Recom\Orm\TrainLogTable;
use Dzahdev\Recom\Orm\UserRecTable;

Loc::loadMessages(__FILE__);

class dzahdev_recom extends CModule
{
	public $MODULE_ID = 'dzahdev.recom';
	public $MODULE_VERSION;
	public $MODULE_VERSION_DATE;
	public $MODULE_NAME;
	public $MODULE_DESCRIPTION;
	public $MODULE_GROUP_RIGHTS = 'Y';
	public $PARTNER_NAME = 'Dzahdev';
	public $PARTNER_URI = 'https://github.com/dzahdev';

	public function __construct()
	{
		$arModuleVersion = [];
		include __DIR__ . '/version.php';

		$this->MODULE_VERSION = $arModuleVersion['VERSION'];
		$this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
		$this->MODULE_NAME = Loc::getMessage('DZAHDEV_RECOM_MODULE_NAME');
		$this->MODULE_DESCRIPTION = Loc::getMessage('DZAHDEV_RECOM_MODULE_DESCRIPTION');
	}

	public function DoInstall(): bool
	{
		global $APPLICATION;

		if (!$this->checkRequirements())
		{
			$APPLICATION->ThrowException(Loc::getMessage('DZAHDEV_RECOM_INSTALL_REQUIREMENTS'));
			return false;
		}

		try
		{
			$this->InstallDB();
			$this->InstallEvents();
			$this->InstallAgents();
			$this->InstallFiles();
		}
		catch (\Throwable $e)
		{
			// Откат регистрации, чтобы модуль не числился установленным наполовину. Таблицы остаются.
			$this->UnInstallAgents();
			$this->UnInstallEvents();
			ModuleManager::unRegisterModule($this->MODULE_ID);
			$APPLICATION->ThrowException($e->getMessage());
			return false;
		}

		$APPLICATION->IncludeAdminFile(Loc::getMessage('DZAHDEV_RECOM_INSTALL_TITLE'), __DIR__ . '/step.php');

		return true;
	}

	public function DoUninstall(): bool
	{
		global $APPLICATION;

		$this->UnInstallAgents();
		$this->UnInstallEvents();
		$this->UnInstallFiles();
		$this->UnInstallDB();

		$APPLICATION->IncludeAdminFile(Loc::getMessage('DZAHDEV_RECOM_UNINSTALL_TITLE'), __DIR__ . '/unstep.php');

		return true;
	}

	private function checkRequirements(): bool
	{
		return version_compare(PHP_VERSION, '8.1.0') >= 0
			&& ModuleManager::isModuleInstalled('iblock')
			&& ModuleManager::isModuleInstalled('catalog')
			&& ModuleManager::isModuleInstalled('sale');
	}

	/**
	 * @return list<class-string<\Bitrix\Main\ORM\Data\DataManager>>
	 */
	private function getTables(): array
	{
		return [
			EventTable::class,
			FactorTable::class,
			UserRecTable::class,
			ItemSimTable::class,
			PopularTable::class,
			TrainLogTable::class,
		];
	}

	public function InstallDB(): bool
	{
		ModuleManager::registerModule($this->MODULE_ID);
		Loader::includeModule($this->MODULE_ID);

		$connection = Application::getConnection();
		foreach ($this->getTables() as $table)
		{
			if (!$connection->isTableExists($table::getTableName()))
			{
				$table::getEntity()->createDbTable();
			}
		}

		$indexes = [
			'ix_dzahdev_recom_event_fuser' => ['FUSER_ID', 'CREATED_AT'],
			'ix_dzahdev_recom_event_created' => ['CREATED_AT'],
			'ix_dzahdev_recom_event_product' => ['PRODUCT_ID'],
		];
		foreach ($indexes as $name => $columns)
		{
			if (!$connection->isIndexExists(EventTable::getTableName(), $columns))
			{
				$connection->createIndex(EventTable::getTableName(), $name, $columns);
			}
		}

		return true;
	}

	public function UnInstallDB(): bool
	{
		Loader::includeModule($this->MODULE_ID);

		$connection = Application::getConnection();
		foreach ($this->getTables() as $table)
		{
			if ($connection->isTableExists($table::getTableName()))
			{
				$connection->dropTable($table::getTableName());
			}
		}

		Option::delete($this->MODULE_ID);
		ModuleManager::unRegisterModule($this->MODULE_ID);

		return true;
	}

	/**
	 * @return list<array{0: string, 1: string, 2: string, 3: string}> модуль, событие, класс, метод
	 */
	private function getEventHandlers(): array
	{
		return [];
	}

	public function InstallEvents(): bool
	{
		$eventManager = EventManager::getInstance();
		foreach ($this->getEventHandlers() as [$fromModule, $event, $class, $method])
		{
			$eventManager->registerEventHandler($fromModule, $event, $this->MODULE_ID, $class, $method);
		}

		return true;
	}

	public function UnInstallEvents(): bool
	{
		$eventManager = EventManager::getInstance();
		foreach ($this->getEventHandlers() as [$fromModule, $event, $class, $method])
		{
			$eventManager->unRegisterEventHandler($fromModule, $event, $this->MODULE_ID, $class, $method);
		}

		return true;
	}

	/**
	 * @return list<array{0: string, 1: int}> имя агента, интервал в секундах
	 */
	private function getAgents(): array
	{
		return [];
	}

	public function InstallAgents(): bool
	{
		$start = DateTime::createFromTimestamp(time() + 300)->toString();
		foreach ($this->getAgents() as [$name, $interval])
		{
			\CAgent::AddAgent($name, $this->MODULE_ID, 'N', $interval, '', 'Y', $start);
		}

		return true;
	}

	public function UnInstallAgents(): bool
	{
		\CAgent::RemoveModuleAgents($this->MODULE_ID);

		return true;
	}

	/**
	 * @return array<string, list<string>> каталог внутри install/ => пути от корня сайта, удаляемые при деинсталляции
	 */
	private function getFileDirs(): array
	{
		return [];
	}

	public function InstallFiles(): bool
	{
		$root = Application::getDocumentRoot();
		foreach (array_keys($this->getFileDirs()) as $dir)
		{
			if (!CopyDirFiles(__DIR__ . '/' . $dir, $root . '/bitrix/' . $dir, true, true))
			{
				throw new \RuntimeException("Cannot copy install/{$dir} to /bitrix/{$dir}");
			}
		}

		return true;
	}

	public function UnInstallFiles(): bool
	{
		$root = Application::getDocumentRoot();
		foreach ($this->getFileDirs() as $paths)
		{
			foreach ($paths as $path)
			{
				Directory::deleteDirectory($root . $path);

				// Каталог вендора (/bitrix/components/dzahdev) удаляется, только если в нем не осталось
				// файлов других модулей вендора.
				$vendorDir = new Directory(dirname($root . $path));
				if ($vendorDir->isExists() && $vendorDir->getChildren() === [])
				{
					$vendorDir->delete();
				}
			}
		}

		return true;
	}
}
```

`install/step.php`:

```php
<?php

use Bitrix\Main\Localization\Loc;

if (!check_bitrix_sessid())
{
	return;
}

Loc::loadMessages(__FILE__);

CAdminMessage::ShowNote(Loc::getMessage('DZAHDEV_RECOM_INSTALL_DONE'));
```

`install/unstep.php`:

```php
<?php

use Bitrix\Main\Localization\Loc;

if (!check_bitrix_sessid())
{
	return;
}

Loc::loadMessages(__FILE__);

CAdminMessage::ShowNote(Loc::getMessage('DZAHDEV_RECOM_UNINSTALL_DONE'));
```

`lang/ru/install/index.php`:

```php
<?php

$MESS['DZAHDEV_RECOM_MODULE_NAME'] = 'Персональные рекомендации';
$MESS['DZAHDEV_RECOM_MODULE_DESCRIPTION'] = 'Персональные рекомендации, похожие и популярные товары. Модели обучаются внутри модуля на действиях покупателей.';
$MESS['DZAHDEV_RECOM_INSTALL_REQUIREMENTS'] = 'Нужны PHP 8.1 или новее и установленные модули iblock, catalog и sale.';
$MESS['DZAHDEV_RECOM_INSTALL_TITLE'] = 'Установка модуля "Персональные рекомендации"';
$MESS['DZAHDEV_RECOM_UNINSTALL_TITLE'] = 'Удаление модуля "Персональные рекомендации"';
```

`lang/ru/install/step.php`:

```php
<?php

$MESS['DZAHDEV_RECOM_INSTALL_DONE'] = 'Модуль "Персональные рекомендации" установлен. Для обучения моделей настройте выполнение агентов на cron или запускайте из корня сайта php local/modules/dzahdev.recom/tools/train.php (полное обучение - с ключом --full).';
```

`lang/ru/install/unstep.php`:

```php
<?php

$MESS['DZAHDEV_RECOM_UNINSTALL_DONE'] = 'Модуль "Персональные рекомендации" удален вместе с событиями и обученными моделями.';
```

- [ ] **Шаг 5. Синтаксис**

```bash
cd /home/dzrise/projects/bitrix-modules/www/local/modules/dzahdev.recom
for f in $(git ls-files -o -m --exclude-standard '*.php') ; do docker exec bitrix_php php -l /var/www/bitrix/local/modules/dzahdev.recom/$f | grep -v '^No syntax'; done
```

Ожидается: пустой вывод.

- [ ] **Шаг 6. Установка на стенде**

В браузере (админ уже авторизован): `http://localhost:8088/bitrix/admin/partner_modules.php?lang=ru`,
модуль "Персональные рекомендации (dzahdev.recom)" - "Установить".

Ожидается: сообщение "Модуль ... установлен". Проверка:

```bash
docker exec bitrix_mysql mysql -ubitrix -pbitrix bitrix -N -e "
select ID from b_module where ID='dzahdev.recom';
show tables like 'b_dzahdev_recom_%';
show index from b_dzahdev_recom_event where Key_name like 'ix_%';" 2>/dev/null
```

Ожидается: `dzahdev.recom`, 6 таблиц, индексы `ix_dzahdev_recom_event_fuser` (2 строки),
`ix_dzahdev_recom_event_created`, `ix_dzahdev_recom_event_product`.

- [ ] **Шаг 7. Удаление и повторная установка**

В списке решений - "Удалить", затем снова "Установить". После удаления запрос из шага 6 не
возвращает ни модуля, ни таблиц; после повторной установки - снова все 6 таблиц.

- [ ] **Шаг 8. Репозиторий и коммит**

```bash
cd /home/dzrise/projects/bitrix-modules/www/local/modules/dzahdev.recom
git add -A
git -c user.name="Denis Zakharov" -c user.email=dzahdev@gmail.com commit -m "Add module scaffold, installer and ORM tables."
gh repo create dzahdev/bitrix_recom_module --private --description "Bitrix module dzahdev.recom: personal product recommendations"
git remote add origin git@github-as-dzahdev:dzahdev/bitrix_recom_module.git
git push -u origin main
```

**Критерии приемки:** шаги 5-7 дали ожидаемый результат; репозиторий приватный, `main` на GitHub
совпадает с локальным; инварианты 4 и 5 соблюдены (D7 везде, кроме `\CAgent`, `\CModule`,
`CopyDirFiles` и `CAdminMessage` в шаблонах установщика).
