<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

/**
 * @var array $arResult
 */

$configJson = json_encode(
	$arResult['CONFIG'],
	JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
);
?>
<div id="mtai-stafflocation-app" class="mtai-stafflocation" data-config="<?= htmlspecialcharsbx($configJson) ?>"></div>

<?php if ($arResult['JS_URL'] !== ''): ?>
	<?php foreach ($arResult['CSS'] as $cssUrl): ?>
		<link rel="stylesheet" href="<?= htmlspecialcharsbx($cssUrl) ?>">
	<?php endforeach; ?>

	<?php if ($arResult['VENDOR_URL'] !== ''): ?>
		<link rel="modulepreload" href="<?= htmlspecialcharsbx($arResult['VENDOR_URL']) ?>">
	<?php endif; ?>

	<script type="module" src="<?= htmlspecialcharsbx($arResult['JS_URL']) ?>"></script>
<?php else: ?>
	<div style="padding: 16px; color: #f00;">
		Сборка карты расположения не найдена (bitrix/js/mtai.stafflocation/dist). Выполните
		<code>npm ci &amp;&amp; npm run build</code> в install/client модуля
		и переустановите модуль.
	</div>
<?php endif; ?>
