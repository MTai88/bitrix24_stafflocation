<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

/**
 * Компонент mtai:stafflocation.app — страница /company/location/.
 *
 * Готовит конфиг Vue-приложения (права, ajax, глубокая ссылка ?user=)
 * и подключает штатный мини-профиль сотрудника.
 */

use Bitrix\Main\Application;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;
use Mtai\Stafflocation\IblockManager;
use Mtai\Stafflocation\Permission;
use Mtai\Stafflocation\PinStorage;

$arResult = [
	'CONFIG' => [],
	'CSS' => [],
	'JS_URL' => '',
	'VENDOR_URL' => '',
];

if (
	!Loader::includeModule('iblock')
	|| !Loader::includeModule('highloadblock')
	|| !Loader::includeModule('mtai.stafflocation')
)
{
	ShowError('Модуль mtai.stafflocation не установлен');
	return;
}

$iblockId = IblockManager::getIblockId();
if ($iblockId <= 0)
{
	ShowError('Инфоблок этажей не найден');
	return;
}

if (!Permission::canRead($iblockId))
{
	ShowError('Нет доступа к карте расположения');
	return;
}

$focus = null;
$focusUserId = (int)Context::getCurrent()->getRequest()->get('user');
if ($focusUserId > 0)
{
	$pin = PinStorage::findByUser($focusUserId);
	if ($pin)
	{
		$focus = [
			'userId' => $focusUserId,
			'floorId' => (int)$pin['UF_FLOOR_ID'],
			'left' => (float)$pin['UF_POS_LEFT'],
			'top' => (float)$pin['UF_POS_TOP'],
		];
	}
}

$canEdit = Permission::canEdit($iblockId);

$arResult['CONFIG'] = [
	'sessid' => bitrix_sessid(),
	'ajaxUrl' => '/bitrix/services/main/ajax.php',
	'canEdit' => $canEdit,
	'defaultAvatar' => PinStorage::DEFAULT_AVATAR,
	'focusUserId' => $focus ? $focusUserId : 0,
	'focus' => $focus,
	'actions' => [
		'floorList' => 'mtai:stafflocation.floor.list',
		'floorSave' => 'mtai:stafflocation.floor.save',
		'floorDelete' => 'mtai:stafflocation.floor.delete',
		'pinList' => 'mtai:stafflocation.pin.list',
		'pinFind' => 'mtai:stafflocation.pin.find',
		'pinSave' => 'mtai:stafflocation.pin.save',
		'pinDelete' => 'mtai:stafflocation.pin.delete',
	],
];

Extension::load('intranet.user.mini-profile');
Extension::load('ui.entity-selector');

$distDir = '/bitrix/js/mtai.stafflocation/dist';
$manifestPath = Application::getDocumentRoot() . $distDir . '/manifest.json';
if (is_file($manifestPath))
{
	$manifest = json_decode((string)file_get_contents($manifestPath), true);
	if (is_array($manifest))
	{
		foreach ($manifest as $key => $path)
		{
			if (preg_match('/\.css$/', (string)$key))
			{
				$arResult['CSS'][] = $distDir . '/' . $path;
			}
		}
		$arResult['JS_URL'] = !empty($manifest['main.js']) ? $distDir . '/' . $manifest['main.js'] : '';
		$arResult['VENDOR_URL'] = !empty($manifest['vendor.js']) ? $distDir . '/' . $manifest['vendor.js'] : '';
	}
}

$this->IncludeComponentTemplate();
