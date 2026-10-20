<?php

/**
 * Ajax-контроллеры модуля.
 *
 * lib/controller/<name>.php доступны как
 *   /bitrix/services/main/ajax.php?action=mtai:stafflocation.<name>.<action>
 * например mtai:stafflocation.floor.list
 */

return [
	'controllers' => [
		'value' => [
			'defaultNamespace' => '\\Mtai\\Stafflocation\\Controller',
		],
		'readonly' => true,
	],
];
