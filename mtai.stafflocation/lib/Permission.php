<?php

namespace Mtai\Stafflocation;

use CIBlockRights;

/**
 * Права на инфоблок этажей. В расширенном режиме (RIGHTS_MODE=E)
 * проверять нужно через CIBlockRights, не через CIBlock::GetPermission().
 */
class Permission
{
	/** @var array<string, array<string, string>> */
	private static array $operations = [];

	/**
	 * @return array<string, string>
	 */
	private static function operations(int $iblockId): array
	{
		$key = (string)$iblockId;
		if (!isset(self::$operations[$key]))
		{
			self::$operations[$key] = CIBlockRights::UserHasRightTo(
				$iblockId,
				$iblockId,
				'element_read',
				CIBlockRights::RETURN_OPERATIONS
			) ?: [];
		}

		return self::$operations[$key];
	}

	public static function canRead(int $iblockId): bool
	{
		return isset(self::operations($iblockId)['element_read']);
	}

	/** Редактирование этажей и пинов — право изменять элементы инфоблока. */
	public static function canEdit(int $iblockId): bool
	{
		return isset(self::operations($iblockId)['element_edit']);
	}

	public static function has(int $iblockId, string $operation): bool
	{
		return isset(self::operations($iblockId)[$operation]);
	}
}
