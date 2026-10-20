<?php

namespace Mtai\Stafflocation;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SiteTable;

/**
 * Общий пункт левого меню портала (left_menu_items_to_all_<SITE_ID>).
 * Читается ядром через COption::GetOptionString без явного сайта,
 * поэтому значение пишется в строку сайта по умолчанию, а код сайта
 * входит в имя опции — так же пункт ищется при построении меню.
 */
class Menu
{
	public const ITEM_ID = 'mtai_stafflocation';

	public static function install(): void
	{
		foreach (self::siteIds() as $siteId)
		{
			$items = self::read($siteId);
			foreach ($items as $item)
			{
				if (($item['ID'] ?? '') === self::ITEM_ID)
				{
					continue 2;
				}
			}

			$items[] = [
				'TEXT' => (string)Loc::getMessage('MTAI_SL_MENU_TEXT'),
				'LINK' => IblockManager::PUBLIC_PATH,
				'ID' => self::ITEM_ID,
				'NEW_PAGE' => 'N',
			];
			self::write($siteId, $items);
		}
	}

	public static function uninstall(): void
	{
		foreach (self::siteIds() as $siteId)
		{
			$items = self::read($siteId);
			$next = [];
			foreach ($items as $item)
			{
				if (($item['ID'] ?? '') !== self::ITEM_ID)
				{
					$next[] = $item;
				}
			}
			if (count($next) === count($items))
			{
				continue;
			}
			if (!$next)
			{
				Option::delete('intranet', ['name' => self::optionName($siteId)]);
				continue;
			}
			self::write($siteId, $next);
		}
	}

	/**
	 * @return string[]
	 */
	private static function siteIds(): array
	{
		$ids = [];
		$rs = SiteTable::getList(['select' => ['LID'], 'filter' => ['=ACTIVE' => 'Y']]);
		while ($site = $rs->fetch())
		{
			$ids[] = (string)$site['LID'];
		}

		return $ids ?: ['s1'];
	}

	private static function optionName(string $siteId): string
	{
		return 'left_menu_items_to_all_' . $siteId;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function read(string $siteId): array
	{
		$raw = (string)Option::get('intranet', self::optionName($siteId), '', false);
		if ($raw === '')
		{
			return [];
		}

		$items = unserialize($raw, ['allowed_classes' => false]);

		return is_array($items) ? array_values($items) : [];
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 */
	private static function write(string $siteId, array $items): void
	{
		Option::set('intranet', self::optionName($siteId), serialize($items), false);
	}
}
