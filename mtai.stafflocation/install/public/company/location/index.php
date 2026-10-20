<?php

/**
 * Публичная страница «Расположение сотрудников»: /company/location/
 * Создаётся установщиком модуля mtai.stafflocation.
 */

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php';

global $APPLICATION, $USER;

if (!$USER->IsAuthorized())
{
	LocalRedirect('/auth/?backurl=' . urlencode($APPLICATION->GetCurPageParam('', ['backurl'])));
}

$APPLICATION->SetTitle('Расположение сотрудников');
$APPLICATION->IncludeComponent(
	'mtai:stafflocation.app',
	'',
	[],
	false
);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
