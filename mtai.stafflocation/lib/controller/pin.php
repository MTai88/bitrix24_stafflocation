<?php

namespace Mtai\Stafflocation\Controller;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Error;
use Bitrix\Main\UserTable;
use CIBlockElement;
use Mtai\Stafflocation\PinStorage;
use RuntimeException;

/**
 * Пины сотрудников на карте этажа.
 *
 *   mtai:stafflocation.pin.list
 *   mtai:stafflocation.pin.find
 *   mtai:stafflocation.pin.save
 *   mtai:stafflocation.pin.delete
 */
class Pin extends Base
{
	public function configureActions(): array
	{
		return [
			'list' => ['prefilters' => [new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_GET])]],
			'find' => ['prefilters' => [new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_GET])]],
			'save' => ['prefilters' => $this->postFilters()],
			'delete' => ['prefilters' => $this->postFilters()],
		];
	}

	/**
	 * @return array{employee: array<string, mixed>|null}
	 */
	public function findAction(int $userId): array
	{
		if ($this->requireIblockId() <= 0)
		{
			return [];
		}

		return ['employee' => PinStorage::locate($userId)];
	}

	/**
	 * @return array{pins: array<int, array<string, mixed>>}
	 */
	public function listAction(int $floorId): array
	{
		$iblockId = $this->requireIblockId();
		if ($iblockId <= 0)
		{
			return [];
		}
		if (!$this->ownFloor($iblockId, $floorId))
		{
			$this->addError(new Error('Этаж не найден'));

			return [];
		}

		return ['pins' => PinStorage::listByFloor($floorId)];
	}

	/**
	 * @return array{pin: array<string, mixed>}
	 */
	public function saveAction(int $floorId, int $userId, float $left, float $top): array
	{
		$iblockId = $this->requireIblockId();
		if ($iblockId <= 0 || !$this->requireEdit($iblockId))
		{
			return [];
		}
		if (!$this->ownFloor($iblockId, $floorId))
		{
			$this->addError(new Error('Этаж не найден'));

			return [];
		}
		if (!$this->activeUser($userId))
		{
			$this->addError(new Error('Сотрудник не найден'));

			return [];
		}

		try
		{
			$pin = PinStorage::save($floorId, $userId, $left, $top);
		}
		catch (RuntimeException $e)
		{
			$this->addError(new Error($e->getMessage()));

			return [];
		}

		if (!$pin)
		{
			$this->addError(new Error('Не удалось сохранить расположение'));

			return [];
		}

		return ['pin' => $pin];
	}

	/**
	 * @return array{userId: int}
	 */
	public function deleteAction(int $floorId, int $userId): array
	{
		$iblockId = $this->requireIblockId();
		if ($iblockId <= 0 || !$this->requireEdit($iblockId))
		{
			return [];
		}
		if (!$this->ownFloor($iblockId, $floorId))
		{
			$this->addError(new Error('Этаж не найден'));

			return [];
		}

		PinStorage::delete($floorId, $userId);

		return ['userId' => $userId];
	}

	private function ownFloor(int $iblockId, int $floorId): bool
	{
		if ($floorId <= 0)
		{
			return false;
		}

		$row = CIBlockElement::GetList(
			[],
			['ID' => $floorId, 'IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'],
			false,
			false,
			['ID']
		)->Fetch();

		return (bool)$row;
	}

	private function activeUser(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		$row = UserTable::getList([
			'filter' => ['=ID' => $userId, '=ACTIVE' => 'Y'],
			'select' => ['ID'],
			'limit' => 1,
		])->fetch();

		return (bool)$row;
	}
}
