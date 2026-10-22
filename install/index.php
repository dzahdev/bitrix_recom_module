<?php

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\IO\Directory;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Type\DateTime;
use Dzahdev\Recom\Config;
use Dzahdev\Recom\Orm\EventTable;
use Dzahdev\Recom\Orm\FactorTable;
use Dzahdev\Recom\Orm\ItemSimTable;
use Dzahdev\Recom\Orm\PopularTable;
use Dzahdev\Recom\Orm\TrainLogTable;
use Dzahdev\Recom\Orm\UserRecTable;
use Dzahdev\Recom\Tracking\Handler;

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
		return [
			['catalog', '\Bitrix\Catalog\CatalogViewedProduct::OnAfterAdd', Handler::class, 'onViewedProductAdd'],
			['catalog', '\Bitrix\Catalog\CatalogViewedProduct::OnAfterUpdate', Handler::class, 'onViewedProductUpdate'],
			['sale', 'OnSaleBasketItemSaved', Handler::class, 'onBasketItemSaved'],
			['sale', 'OnSaleOrderSaved', Handler::class, 'onOrderSaved'],
		];
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
		return [
			['\Dzahdev\Recom\Agent\TrainAgent::run();', Config::int('train_incremental_interval')],
			['\Dzahdev\Recom\Agent\CleanupAgent::run();', 86400],
		];
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
		return [
			'js' => ['/bitrix/js/dzahdev/recom'],
			'components' => [
				'/bitrix/components/dzahdev/recom.list',
				'/bitrix/components/dzahdev/recom.list.mail',
			],
		];
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
