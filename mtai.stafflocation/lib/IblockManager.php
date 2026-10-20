<?php

namespace Mtai\Stafflocation;

use Bitrix\Main\SiteTable;
use CIBlock;
use CIBlockType;
use RuntimeException;

/**
 * Инфоблок этажей: тип mtai_stafflocation, инфоблок «Этажи».
 *
 * Элемент — этаж (NAME, SORT, карта в DETAIL_PICTURE). Права расширенные:
 * администраторы (группа 1) — полный доступ, все пользователи (группа 2) — чтение.
 */
class IblockManager
{
	public const IBLOCK_TYPE = 'mtai_stafflocation';

	public const IBLOCK_CODE = 'mtai_staff_floors';

	public const PUBLIC_PATH = '/company/location/';

	public static function getIblockId(): int
	{
		$row = CIBlock::GetList(
			[],
			[
				'TYPE' => self::IBLOCK_TYPE,
				'CODE' => self::IBLOCK_CODE,
				'CHECK_PERMISSIONS' => 'N',
			]
		)->Fetch();

		return $row ? (int)$row['ID'] : 0;
	}

	/**
	 * @return int ID инфоблока этажей
	 * @throws RuntimeException
	 */
	public static function installInfoblock(): int
	{
		$iblockId = self::getIblockId();
		if ($iblockId > 0)
		{
			return $iblockId;
		}

		$rsType = CIBlockType::GetList([], ['=ID' => self::IBLOCK_TYPE]);
		if (!$rsType->Fetch())
		{
			$obType = new CIBlockType();
			$ok = $obType->Add([
				'ID' => self::IBLOCK_TYPE,
				'SECTIONS' => 'N',
				'SORT' => 97,
				'LANG' => [
					'ru' => [
						'NAME' => 'Расположение сотрудников',
						'ELEMENT_NAME' => 'Этаж',
					],
					'en' => [
						'NAME' => 'Employee location',
						'ELEMENT_NAME' => 'Floor',
					],
				],
			]);
			if (!$ok)
			{
				throw new RuntimeException('iblock type: ' . $obType->LAST_ERROR);
			}
		}

		$siteIds = [];
		$rsSites = SiteTable::getList(['select' => ['LID'], 'filter' => ['=ACTIVE' => 'Y']]);
		while ($site = $rsSites->fetch())
		{
			$siteIds[] = $site['LID'];
		}
		if (!$siteIds)
		{
			$siteIds = ['s1'];
		}

		$obIblock = new CIBlock();
		$iblockId = (int)$obIblock->Add([
			'ACTIVE' => 'Y',
			'NAME' => 'Этажи',
			'CODE' => self::IBLOCK_CODE,
			'IBLOCK_TYPE_ID' => self::IBLOCK_TYPE,
			'SITE_ID' => $siteIds,
			'SORT' => 100,
			'RIGHTS_MODE' => 'E',
			'GROUP_ID' => [1 => 'X', 2 => 'R'],
			'WORKFLOW' => 'N',
			'LIST_PAGE_URL' => '#SITE_DIR#company/location/',
			'DETAIL_PAGE_URL' => '#SITE_DIR#company/location/',
			'INDEX_ELEMENT' => 'N',
			'INDEX_SECTION' => 'N',
			'FIELDS' => [
				'DETAIL_PICTURE' => ['IS_REQUIRED' => 'Y'],
			],
		]);
		if ($iblockId <= 0)
		{
			throw new RuntimeException('iblock: ' . $obIblock->LAST_ERROR);
		}

		return $iblockId;
	}

	public static function uninstallInfoblock(): void
	{
		$iblockId = self::getIblockId();
		if ($iblockId > 0)
		{
			\CIBlockElement::DeleteAll($iblockId);
			CIBlock::Delete($iblockId);
		}

		$rsType = CIBlockType::GetList([], ['=ID' => self::IBLOCK_TYPE]);
		if ($rsType->Fetch())
		{
			CIBlockType::Delete(self::IBLOCK_TYPE);
		}
	}
}
