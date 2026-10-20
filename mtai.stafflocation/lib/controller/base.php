<?php

namespace Mtai\Stafflocation\Controller;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Mtai\Stafflocation\IblockManager;
use Mtai\Stafflocation\Permission;

abstract class Base extends Controller
{
	protected function requireIblockId(): int
	{
		if (!Loader::includeModule('iblock') || !Loader::includeModule('mtai.stafflocation'))
		{
			$this->addError(new Error('Модуль не установлен'));

			return 0;
		}

		if ((int)CurrentUser::get()->getId() <= 0)
		{
			$this->addError(new Error('Требуется авторизация'));

			return 0;
		}

		$iblockId = IblockManager::getIblockId();
		if ($iblockId <= 0)
		{
			$this->addError(new Error('Инфоблок этажей не найден'));

			return 0;
		}

		if (!Permission::canRead($iblockId))
		{
			$this->addError(new Error('Нет доступа к карте этажей'));

			return 0;
		}

		return $iblockId;
	}

	protected function requireEdit(int $iblockId): bool
	{
		if (Permission::canEdit($iblockId))
		{
			return true;
		}

		$this->addError(new Error('Недостаточно прав для редактирования'));

		return false;
	}

	/**
	 * @return ActionFilter\Base[]
	 */
	protected function postFilters(): array
	{
		return [
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			new ActionFilter\Csrf(),
		];
	}
}
