<?php

namespace Mtai\Stafflocation\Controller;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Error;
use CIBlockElement;
use CFile;
use Mtai\Stafflocation\Permission;
use Mtai\Stafflocation\PinStorage;

/**
 * Этажи (элементы инфоблока) и карта в DETAIL_PICTURE.
 *
 *   mtai:stafflocation.floor.list
 *   mtai:stafflocation.floor.save
 *   mtai:stafflocation.floor.delete
 */
class Floor extends Base
{
	private const FILE_FIELD = 'map';

	private const MAX_SIZE = 26214400;

	/** @var array<string, string> */
	private const ALLOWED_TYPES = [
		'jpg' => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png' => 'image/png',
		'gif' => 'image/gif',
		'webp' => 'image/webp',
	];

	public function configureActions(): array
	{
		return [
			'list' => ['prefilters' => [new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_GET])]],
			'save' => ['prefilters' => $this->postFilters()],
			'delete' => ['prefilters' => $this->postFilters()],
		];
	}

	/**
	 * @return array{floors: array<int, array<string, mixed>>, canEdit: bool}
	 */
	public function listAction(): array
	{
		$iblockId = $this->requireIblockId();
		if ($iblockId <= 0)
		{
			return [];
		}

		$floors = [];
		$rs = CIBlockElement::GetList(
			['SORT' => 'ASC', 'ID' => 'ASC'],
			[
				'IBLOCK_ID' => $iblockId,
				'ACTIVE' => 'Y',
				'CHECK_PERMISSIONS' => 'N',
			],
			false,
			false,
			['ID', 'NAME', 'SORT', 'DETAIL_PICTURE']
		);
		while ($row = $rs->Fetch())
		{
			$floors[] = self::format($row);
		}

		return [
			'floors' => $floors,
			'canEdit' => Permission::canEdit($iblockId),
		];
	}

	/**
	 * @return array{floor: array<string, mixed>}
	 */
	public function saveAction(int $id = 0, string $name = '', int $sort = 500): array
	{
		$iblockId = $this->requireIblockId();
		if ($iblockId <= 0 || !$this->requireEdit($iblockId))
		{
			return [];
		}

		$name = trim($name);
		if ($name === '')
		{
			$this->addError(new Error('Укажите название этажа'));

			return [];
		}
		if (mb_strlen($name) > 255)
		{
			$name = mb_substr($name, 0, 255);
		}
		if ($sort <= 0)
		{
			$sort = 500;
		}

		$file = $this->imageFile($id <= 0);
		if ($this->getErrors())
		{
			return [];
		}

		$element = new CIBlockElement();
		$fields = [
			'NAME' => $name,
			'SORT' => $sort,
			'ACTIVE' => 'Y',
		];
		if ($file !== null)
		{
			$file['MODULE_ID'] = 'iblock';
			$fields['DETAIL_PICTURE'] = $file;
		}

		if ($id > 0)
		{
			if (!$this->ownElement($iblockId, $id))
			{
				$this->addError(new Error('Этаж не найден'));

				return [];
			}
			if (!$element->Update($id, $fields))
			{
				$this->addError(new Error($element->LAST_ERROR ?: 'Не удалось сохранить этаж'));

				return [];
			}
		}
		else
		{
			if (!Permission::has($iblockId, 'section_element_bind') && !Permission::canEdit($iblockId))
			{
				$this->addError(new Error('Недостаточно прав для добавления этажа'));

				return [];
			}
			$fields['IBLOCK_ID'] = $iblockId;
			$id = (int)$element->Add($fields);
			if ($id <= 0)
			{
				$this->addError(new Error($element->LAST_ERROR ?: 'Не удалось создать этаж'));

				return [];
			}
		}

		$row = CIBlockElement::GetList(
			[],
			['ID' => $id, 'IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'],
			false,
			false,
			['ID', 'NAME', 'SORT', 'DETAIL_PICTURE']
		)->Fetch();

		return ['floor' => $row ? self::format($row) : ['id' => $id, 'name' => $name, 'sort' => $sort, 'mapUrl' => '']];
	}

	/**
	 * @return array{id: int}
	 */
	public function deleteAction(int $id): array
	{
		$iblockId = $this->requireIblockId();
		if ($iblockId <= 0 || !$this->requireEdit($iblockId))
		{
			return [];
		}
		if (!$this->ownElement($iblockId, $id))
		{
			$this->addError(new Error('Этаж не найден'));

			return [];
		}
		if (!Permission::has($iblockId, 'element_delete'))
		{
			$this->addError(new Error('Недостаточно прав для удаления этажа'));

			return [];
		}

		if (!CIBlockElement::Delete($id))
		{
			$this->addError(new Error('Не удалось удалить этаж'));

			return [];
		}

		PinStorage::deleteByFloor($id);

		return ['id' => $id];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{id: int, name: string, sort: int, mapUrl: string}
	 */
	private static function format(array $row): array
	{
		$pictureId = (int)($row['DETAIL_PICTURE'] ?? 0);

		return [
			'id' => (int)$row['ID'],
			'name' => (string)$row['NAME'],
			'sort' => (int)$row['SORT'],
			'mapUrl' => $pictureId > 0 ? (string)CFile::GetPath($pictureId) : '',
		];
	}

	private function ownElement(int $iblockId, int $id): bool
	{
		if ($id <= 0)
		{
			return false;
		}

		$row = CIBlockElement::GetList(
			[],
			['ID' => $id, 'IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'],
			false,
			false,
			['ID']
		)->Fetch();

		return (bool)$row;
	}

	/**
	 * @return array<string, mixed>|null файл для инфоблока или null, если файл не передавали
	 */
	private function imageFile(bool $required): ?array
	{
		$file = $this->request->getFileList()->get(self::FILE_FIELD);
		if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)
		{
			if ($required)
			{
				$this->addError(new Error('Загрузите карту этажа'));
			}

			return null;
		}

		if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? '')))
		{
			$this->addError(new Error('Файл карты не получен'));

			return null;
		}

		if ((int)$file['size'] <= 0 || (int)$file['size'] > self::MAX_SIZE)
		{
			$this->addError(new Error('Размер карты больше допустимого (25 МБ)'));

			return null;
		}

		$extension = mb_strtolower((string)pathinfo((string)$file['name'], PATHINFO_EXTENSION));
		if ($extension === '' || !isset(self::ALLOWED_TYPES[$extension]))
		{
			$this->addError(new Error('Карта должна быть изображением: jpg, png, gif или webp'));

			return null;
		}

		$info = @getimagesize((string)$file['tmp_name']);
		if ($info === false)
		{
			$this->addError(new Error('Файл не является изображением'));

			return null;
		}

		return $file;
	}
}
