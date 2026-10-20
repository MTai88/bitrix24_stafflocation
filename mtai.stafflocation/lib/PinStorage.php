<?php

namespace Mtai\Stafflocation;

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;
use CFile;
use CUser;
use CUserTypeEntity;
use RuntimeException;

/**
 * Хайлоадблок пинов. Координаты — проценты от левого края и верха карты,
 * поэтому зум и сдвиг карты их не меняют.
 *
 * Один сотрудник — одна точка на портале: сохранение на другой этаж
 * переносит пин.
 */
class PinStorage
{
	public const HL_NAME = 'MtaiStaffLocationPin';

	public const TABLE_NAME = 'mtai_staff_location_pin';

	/** Штатная заглушка сотрудника (селектор пользователей Битрикс24). */
	public const DEFAULT_AVATAR = '/bitrix/js/socialnetwork/entity-selector/src/images/default-user.svg';

	public static function getHlId(): int
	{
		if (!Loader::includeModule('highloadblock'))
		{
			return 0;
		}

		$row = HighloadBlockTable::getList([
			'filter' => ['=NAME' => self::HL_NAME],
			'select' => ['ID'],
		])->fetch();

		return $row ? (int)$row['ID'] : 0;
	}

	/**
	 * @return int ID хайлоадблока
	 * @throws RuntimeException
	 */
	public static function install(): int
	{
		if (!Loader::includeModule('highloadblock'))
		{
			throw new RuntimeException('highloadblock module is not installed');
		}

		$hlId = self::getHlId();
		if ($hlId > 0)
		{
			self::ensureFields($hlId);

			return $hlId;
		}

		$result = HighloadBlockTable::add([
			'NAME' => self::HL_NAME,
			'TABLE_NAME' => self::TABLE_NAME,
		]);
		if (!$result->isSuccess())
		{
			throw new RuntimeException('highloadblock: ' . implode('; ', $result->getErrorMessages()));
		}

		$hlId = (int)$result->getId();
		self::ensureFields($hlId);
		self::ensureIndexes();

		return $hlId;
	}

	public static function uninstall(): void
	{
		if (!Loader::includeModule('highloadblock'))
		{
			return;
		}

		$hlId = self::getHlId();
		if ($hlId <= 0)
		{
			return;
		}

		$entityId = 'HLBLOCK_' . $hlId;
		$rs = CUserTypeEntity::GetList([], ['ENTITY_ID' => $entityId]);
		$userType = new CUserTypeEntity();
		while ($field = $rs->Fetch())
		{
			$userType->Delete((int)$field['ID']);
		}

		HighloadBlockTable::delete($hlId);
	}

	/**
	 * @return class-string<\Bitrix\Main\ORM\Data\DataManager>|null
	 */
	public static function dataClass(): ?string
	{
		$hlId = self::getHlId();
		if ($hlId <= 0)
		{
			return null;
		}

		$row = HighloadBlockTable::getById($hlId)->fetch();
		if (!$row)
		{
			return null;
		}

		$entity = HighloadBlockTable::compileEntity($row);

		return $entity->getDataClass();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function findByUser(int $userId): ?array
	{
		$dataClass = self::dataClass();
		if ($dataClass === null || $userId <= 0)
		{
			return null;
		}

		$row = $dataClass::getList([
			'filter' => ['=UF_USER_ID' => $userId],
			'limit' => 1,
		])->fetch();

		return $row ?: null;
	}

	/**
	 * Карточка пина сотрудника, если он стоит на какой-либо карте.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function locate(int $userId): ?array
	{
		$row = self::findByUser($userId);
		if ($row === null)
		{
			return null;
		}

		$cards = self::withUsers([$row]);
		if (!$cards)
		{
			return null;
		}

		$floorId = (int)$row['UF_FLOOR_ID'];
		$names = self::floorNames([$floorId]);
		$card = $cards[0];
		$card['floorId'] = $floorId;
		$card['floorName'] = $names[$floorId] ?? '';

		return $card;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function listByFloor(int $floorId): array
	{
		$dataClass = self::dataClass();
		if ($dataClass === null || $floorId <= 0)
		{
			return [];
		}

		$rows = [];
		$rs = $dataClass::getList([
			'filter' => ['=UF_FLOOR_ID' => $floorId],
			'order' => ['ID' => 'ASC'],
		]);
		while ($row = $rs->fetch())
		{
			$rows[] = $row;
		}

		return self::withUsers($rows);
	}

	/**
	 * Создаёт или переносит пин сотрудника. Возвращает карточку пина.
	 *
	 * @return array<string, mixed>
	 * @throws RuntimeException
	 */
	public static function save(int $floorId, int $userId, float $left, float $top): array
	{
		$dataClass = self::dataClass();
		if ($dataClass === null)
		{
			throw new RuntimeException('Хайлоадблок пинов не найден');
		}

		$left = self::clampPercent($left);
		$top = self::clampPercent($top);

		$existingId = 0;
		$rs = $dataClass::getList([
			'filter' => ['=UF_USER_ID' => $userId],
		]);
		while ($row = $rs->fetch())
		{
			if ((int)$row['UF_FLOOR_ID'] === $floorId && $existingId === 0)
			{
				$existingId = (int)$row['ID'];
				continue;
			}
			$dataClass::delete((int)$row['ID']);
		}

		$fields = [
			'UF_FLOOR_ID' => $floorId,
			'UF_USER_ID' => $userId,
			'UF_POS_LEFT' => $left,
			'UF_POS_TOP' => $top,
		];

		if ($existingId > 0)
		{
			$result = $dataClass::update($existingId, $fields);
			$id = $existingId;
		}
		else
		{
			$result = $dataClass::add($fields);
			$id = (int)$result->getId();
		}

		if (!$result->isSuccess())
		{
			throw new RuntimeException(implode('; ', $result->getErrorMessages()));
		}

		$cards = self::withUsers([[
			'ID' => $id,
			'UF_FLOOR_ID' => $floorId,
			'UF_USER_ID' => $userId,
			'UF_POS_LEFT' => $left,
			'UF_POS_TOP' => $top,
		]]);

		return $cards[0] ?? [];
	}

	public static function delete(int $floorId, int $userId): void
	{
		$dataClass = self::dataClass();
		if ($dataClass === null)
		{
			return;
		}

		$rs = $dataClass::getList([
			'filter' => [
				'=UF_FLOOR_ID' => $floorId,
				'=UF_USER_ID' => $userId,
			],
		]);
		while ($row = $rs->fetch())
		{
			$dataClass::delete((int)$row['ID']);
		}
	}

	public static function deleteByFloor(int $floorId): void
	{
		$dataClass = self::dataClass();
		if ($dataClass === null || $floorId <= 0)
		{
			return;
		}

		$rs = $dataClass::getList([
			'filter' => ['=UF_FLOOR_ID' => $floorId],
			'select' => ['ID'],
		]);
		while ($row = $rs->fetch())
		{
			$dataClass::delete((int)$row['ID']);
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, array<string, mixed>>
	 */
	private static function withUsers(array $rows): array
	{
		if (!$rows)
		{
			return [];
		}

		$userIds = [];
		foreach ($rows as $row)
		{
			$userIds[] = (int)$row['UF_USER_ID'];
		}

		$users = [];
		$rs = UserTable::getList([
			'filter' => ['@ID' => $userIds],
			'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'WORK_POSITION', 'PERSONAL_PHOTO'],
		]);
		while ($user = $rs->fetch())
		{
			$users[(int)$user['ID']] = $user;
		}

		$cards = [];
		foreach ($rows as $row)
		{
			$userId = (int)$row['UF_USER_ID'];
			$user = $users[$userId] ?? null;
			if ($user === null)
			{
				continue;
			}

			$cards[] = [
				'id' => (int)$row['ID'],
				'userId' => $userId,
				'name' => (string)CUser::FormatName('#NAME# #LAST_NAME#', $user, true, false),
				'position' => (string)$user['WORK_POSITION'],
				'avatar' => self::avatarUrl((int)$user['PERSONAL_PHOTO']),
				'left' => (float)$row['UF_POS_LEFT'],
				'top' => (float)$row['UF_POS_TOP'],
			];
		}

		return $cards;
	}

	public static function avatarUrl(int $photoId): string
	{
		if ($photoId <= 0)
		{
			return self::DEFAULT_AVATAR;
		}

		$resized = CFile::ResizeImageGet(
			$photoId,
			['width' => 96, 'height' => 96],
			BX_RESIZE_IMAGE_EXACT,
			true
		);
		if (is_array($resized) && !empty($resized['src']))
		{
			return (string)$resized['src'];
		}

		$path = (string)CFile::GetPath($photoId);

		return $path !== '' ? $path : self::DEFAULT_AVATAR;
	}

	/**
	 * @param array<int, int> $floorIds
	 * @return array<int, string>
	 */
	private static function floorNames(array $floorIds): array
	{
		$floorIds = array_values(array_unique(array_filter($floorIds)));
		if (!$floorIds || !Loader::includeModule('iblock'))
		{
			return [];
		}

		$names = [];
		$rs = \CIBlockElement::GetList(
			[],
			['ID' => $floorIds, 'CHECK_PERMISSIONS' => 'N'],
			false,
			false,
			['ID', 'NAME']
		);
		while ($row = $rs->Fetch())
		{
			$names[(int)$row['ID']] = (string)$row['NAME'];
		}

		return $names;
	}

	private static function clampPercent(float $value): float
	{
		if ($value < 0)
		{
			return 0.0;
		}
		if ($value > 100)
		{
			return 100.0;
		}

		return round($value, 4);
	}

	private static function ensureFields(int $hlId): void
	{
		$entityId = 'HLBLOCK_' . $hlId;
		$fields = [
			['UF_FLOOR_ID', 'integer', 100, 'Этаж', 'Floor'],
			['UF_USER_ID', 'integer', 200, 'Сотрудник', 'Employee'],
			['UF_POS_LEFT', 'double', 300, 'Слева, %', 'Left, %'],
			['UF_POS_TOP', 'double', 400, 'Сверху, %', 'Top, %'],
		];

		$userType = new CUserTypeEntity();
		foreach ($fields as [$name, $type, $sort, $ru, $en])
		{
			$exists = CUserTypeEntity::GetList([], [
				'ENTITY_ID' => $entityId,
				'FIELD_NAME' => $name,
			])->Fetch();
			if ($exists)
			{
				continue;
			}

			$label = ['ru' => $ru, 'en' => $en];
			$settings = $type === 'double'
				? ['PRECISION' => 4, 'SIZE' => 20, 'MIN_VALUE' => 0, 'MAX_VALUE' => 100, 'DEFAULT_VALUE' => 0]
				: ['SIZE' => 20, 'MIN_VALUE' => 0, 'MAX_VALUE' => 0, 'DEFAULT_VALUE' => 0];

			$fieldId = $userType->Add([
				'ENTITY_ID' => $entityId,
				'FIELD_NAME' => $name,
				'USER_TYPE_ID' => $type,
				'XML_ID' => $name,
				'SORT' => $sort,
				'MULTIPLE' => 'N',
				'MANDATORY' => 'Y',
				'SHOW_FILTER' => 'I',
				'SHOW_IN_LIST' => 'Y',
				'EDIT_IN_LIST' => 'Y',
				'IS_SEARCHABLE' => 'N',
				'SETTINGS' => $settings,
				'EDIT_FORM_LABEL' => $label,
				'LIST_COLUMN_LABEL' => $label,
				'LIST_FILTER_LABEL' => $label,
			]);
			if (!$fieldId)
			{
				global $APPLICATION;
				$exception = $APPLICATION instanceof \CMain ? $APPLICATION->GetException() : null;
				$message = $exception ? $exception->GetString() : $name;
				throw new RuntimeException('user field ' . $message);
			}
		}
	}

	private static function ensureIndexes(): void
	{
		$connection = \Bitrix\Main\Application::getConnection();
		$connection->queryExecute(
			'ALTER TABLE ' . self::TABLE_NAME
			. ' ADD INDEX IX_MTAI_SL_USER (UF_USER_ID), ADD INDEX IX_MTAI_SL_FLOOR (UF_FLOOR_ID)'
		);
	}
}
