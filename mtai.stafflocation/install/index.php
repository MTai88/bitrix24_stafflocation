<?php

/**
 * Установщик модуля mtai.stafflocation.
 *
 * Создаёт инфоблок этажей, хайлоадблок координат, страницу /company/location/,
 * компонент mtai:stafflocation.app, бандл Vue и пункт левого меню.
 * Снимает всё это при удалении, вместе с этажами и пинами.
 */

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Mtai\Stafflocation\IblockManager;
use Mtai\Stafflocation\Menu;
use Mtai\Stafflocation\PinStorage;

require_once __DIR__ . '/../lib/IblockManager.php';
require_once __DIR__ . '/../lib/PinStorage.php';
require_once __DIR__ . '/../lib/Menu.php';

Loc::loadMessages(__FILE__);

class mtai_stafflocation extends CModule
{
	public $MODULE_ID = 'mtai.stafflocation';
	public $MODULE_VERSION;
	public $MODULE_VERSION_DATE;
	public $MODULE_NAME;
	public $MODULE_DESCRIPTION;
	public $MODULE_GROUP_RIGHTS = 'N';

	public function __construct()
	{
		$arModuleVersion = [];
		include __DIR__ . '/version.php';

		$this->MODULE_VERSION = (string)($arModuleVersion['VERSION'] ?? '');
		$this->MODULE_VERSION_DATE = (string)($arModuleVersion['VERSION_DATE'] ?? '');

		$this->MODULE_NAME = Loc::getMessage('MTAI_SL_MODULE_NAME');
		$this->MODULE_DESCRIPTION = Loc::getMessage('MTAI_SL_MODULE_DESCRIPTION');
		$this->PARTNER_NAME = Loc::getMessage('MTAI_SL_PARTNER_NAME');
		$this->PARTNER_URI = Loc::getMessage('MTAI_SL_PARTNER_URI');
	}

	public function InstallDB(): bool
	{
		ModuleManager::registerModule($this->MODULE_ID);
		IblockManager::installInfoblock();
		PinStorage::install();

		return true;
	}

	public function UnInstallDB(): bool
	{
		PinStorage::uninstall();
		IblockManager::uninstallInfoblock();
		ModuleManager::unRegisterModule($this->MODULE_ID);

		return true;
	}

	public function InstallFiles(): bool
	{
		global $APPLICATION;

		$documentRoot = Application::getDocumentRoot();

		CopyDirFiles(__DIR__ . '/public', $documentRoot, true, true);

		$componentsDir = $documentRoot . '/local/components';
		CheckDirPath($componentsDir . '/mtai/');
		CopyDirFiles(__DIR__ . '/components', $componentsDir, true, true);

		if (is_dir(__DIR__ . '/client/dist'))
		{
			CheckDirPath($documentRoot . '/bitrix/js/' . $this->MODULE_ID . '/dist/');
			CopyDirFiles(
				__DIR__ . '/client/dist',
				$documentRoot . '/bitrix/js/' . $this->MODULE_ID . '/dist',
				true,
				true
			);
		}

		$APPLICATION->SetFileAccessPermission(IblockManager::PUBLIC_PATH, [2 => 'R']);

		return true;
	}

	public function UnInstallFiles(): bool
	{
		global $APPLICATION;

		DeleteDirFilesEx(IblockManager::PUBLIC_PATH);
		DeleteDirFilesEx('/local/components/mtai/stafflocation.app/');
		DeleteDirFilesEx('/bitrix/js/' . $this->MODULE_ID . '/dist/');
		$APPLICATION->SetFileAccessPermission(IblockManager::PUBLIC_PATH, []);

		return true;
	}

	public function DoInstall(): bool
	{
		global $APPLICATION;

		foreach ([
			'iblock' => 'MTAI_SL_INSTALL_ERROR_IBLOCK',
			'highloadblock' => 'MTAI_SL_INSTALL_ERROR_HL',
			'intranet' => 'MTAI_SL_INSTALL_ERROR_INTRANET',
			'ui' => 'MTAI_SL_INSTALL_ERROR_UI',
		] as $module => $message)
		{
			if (!Loader::includeModule($module))
			{
				$APPLICATION->ThrowException(Loc::getMessage($message));

				return false;
			}
		}

		try
		{
			$this->InstallDB();
			$this->InstallFiles();
			Menu::install();
		}
		catch (\Throwable $e)
		{
			$this->UnInstallFiles();
			Menu::uninstall();
			if (ModuleManager::isModuleInstalled($this->MODULE_ID))
			{
				$this->UnInstallDB();
			}
			$APPLICATION->ThrowException($e->getMessage());

			return false;
		}

		return true;
	}

	public function DoUninstall(): bool
	{
		Menu::uninstall();
		$this->UnInstallFiles();
		$this->UnInstallDB();

		return true;
	}
}
