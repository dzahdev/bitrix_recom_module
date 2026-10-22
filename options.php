<?php

/**
 * @global CMain $APPLICATION
 */

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Dzahdev\Recom\Config;
use Dzahdev\Recom\Orm\EventTable;
use Dzahdev\Recom\Orm\TrainLogTable;
use Dzahdev\Recom\Tracking\EventType;

$moduleId = 'dzahdev.recom';

if ($APPLICATION->GetGroupRight($moduleId) < 'W')
{
	$APPLICATION->AuthForm(Loc::getMessage('DZAHDEV_RECOM_OPT_ACCESS_DENIED'));
}

Loader::includeModule($moduleId);
Loc::loadMessages(__FILE__);

$numericOptions = [
	'als_factors', 'als_reg', 'als_alpha', 'als_epochs_full', 'als_epochs_incremental',
	'fm_factors', 'fm_learning_rate', 'fm_reg', 'fm_epochs', 'fm_negatives',
	'min_user_events', 'rec_limit', 'similar_limit', 'popular_limit', 'popular_window_days',
	'active_user_days', 'event_ttl_days', 'eval_users', 'max_user_items_similarity', 'train_full_interval',
];

$request = Application::getInstance()->getContext()->getRequest();
$errors = [];
$result = 'saved';

if ($request->isPost() && check_bitrix_sessid())
{
	if ($request->getPost('train_now') !== null)
	{
		Config::set('full_train_requested', 'Y');
		$result = 'train_requested';
	}
	else
	{
		$types = array_values(array_intersect(EventType::ALL, (array)$request->getPost('positive_types')));
		if ($types === [])
		{
			$errors[] = Loc::getMessage('DZAHDEV_RECOM_OPT_ERROR_TYPES');
		}

		$values = [];
		foreach (array_merge($numericOptions, array_map(static fn(string $t): string => 'weight_' . $t, EventType::ALL)) as $name)
		{
			$value = trim((string)$request->getPost($name));
			if (!is_numeric($value) || (float)$value <= 0)
			{
				$errors[] = Loc::getMessage('DZAHDEV_RECOM_OPT_ERROR_NUMBER', ['#NAME#' => Loc::getMessage('DZAHDEV_RECOM_OPT_' . strtoupper($name))]);
				continue;
			}
			$values[$name] = $value;
		}

		if ($errors === [])
		{
			Config::set('positive_types', implode(',', $types));
			Config::set('fm_enabled', $request->getPost('fm_enabled') === 'Y' ? 'Y' : 'N');
			foreach ($values as $name => $value)
			{
				Config::set($name, $value);
			}
		}
	}

	if ($errors === [])
	{
		LocalRedirect($APPLICATION->GetCurPage() . '?mid=' . urlencode($moduleId) . '&lang=' . LANGUAGE_ID . '&result=' . $result);
	}
}

$stats = EventTable::getRow([
	'select' => ['EVENTS', 'USERS', 'ITEMS'],
	'runtime' => [
		(new ExpressionField('EVENTS', 'COUNT(*)'))->configureValueType(IntegerField::class),
		(new ExpressionField('USERS', 'COUNT(DISTINCT %s)', ['FUSER_ID']))->configureValueType(IntegerField::class),
		(new ExpressionField('ITEMS', 'COUNT(DISTINCT %s)', ['PRODUCT_ID']))->configureValueType(IntegerField::class),
	],
]);
$lastRun = TrainLogTable::getRow(['order' => ['ID' => 'DESC']]);
$cronAgents = defined('BX_CRONTAB_SUPPORT') && BX_CRONTAB_SUPPORT === true;

$tabControl = new CAdminTabControl('dzahdev_recom_options', [
	['DIV' => 'settings', 'TAB' => Loc::getMessage('DZAHDEV_RECOM_OPT_TAB_SETTINGS'), 'TITLE' => Loc::getMessage('DZAHDEV_RECOM_OPT_TAB_SETTINGS')],
	['DIV' => 'stats', 'TAB' => Loc::getMessage('DZAHDEV_RECOM_OPT_TAB_STATS'), 'TITLE' => Loc::getMessage('DZAHDEV_RECOM_OPT_TAB_STATS')],
]);

if ($errors !== [])
{
	CAdminMessage::ShowMessage(implode('<br>', array_map('htmlspecialcharsbx', $errors)));
}
elseif ($request->getQuery('result') === 'saved')
{
	CAdminMessage::ShowNote(Loc::getMessage('DZAHDEV_RECOM_OPT_SAVED'));
}
elseif ($request->getQuery('result') === 'train_requested')
{
	CAdminMessage::ShowNote(Loc::getMessage('DZAHDEV_RECOM_OPT_TRAIN_REQUESTED'));
}

if (!$cronAgents)
{
	CAdminMessage::ShowMessage([
		'TYPE' => 'ERROR',
		'MESSAGE' => Loc::getMessage('DZAHDEV_RECOM_OPT_NO_CRON'),
		'DETAILS' => Loc::getMessage('DZAHDEV_RECOM_OPT_NO_CRON_DETAILS'),
		'HTML' => true,
	]);
}

$positiveTypes = Config::positiveTypes();
?>
<form method="post" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>?mid=<?= urlencode($moduleId) ?>&amp;lang=<?= LANGUAGE_ID ?>">
<?= bitrix_sessid_post() ?>
<?php
$tabControl->Begin();
$tabControl->BeginNextTab();
?>
	<tr class="heading"><td colspan="2"><?= Loc::getMessage('DZAHDEV_RECOM_OPT_HEADING_EVENTS') ?></td></tr>
	<?php foreach (EventType::ALL as $type): ?>
	<tr>
		<td width="40%"><?= Loc::getMessage('DZAHDEV_RECOM_OPT_TYPE_' . strtoupper($type)) ?></td>
		<td width="60%">
			<label><input type="checkbox" name="positive_types[]" value="<?= $type ?>"<?= in_array($type, $positiveTypes, true) ? ' checked' : '' ?>> <?= Loc::getMessage('DZAHDEV_RECOM_OPT_POSITIVE') ?></label>
			&nbsp; <?= Loc::getMessage('DZAHDEV_RECOM_OPT_WEIGHT') ?>
			<input type="text" size="5" name="weight_<?= $type ?>" value="<?= htmlspecialcharsbx(Config::string('weight_' . $type)) ?>">
		</td>
	</tr>
	<?php endforeach; ?>
	<tr class="heading"><td colspan="2"><?= Loc::getMessage('DZAHDEV_RECOM_OPT_HEADING_MODEL') ?></td></tr>
	<tr>
		<td width="40%"><?= Loc::getMessage('DZAHDEV_RECOM_OPT_FM_ENABLED') ?></td>
		<td width="60%"><input type="checkbox" name="fm_enabled" value="Y"<?= Config::string('fm_enabled') === 'Y' ? ' checked' : '' ?>></td>
	</tr>
	<?php foreach ($numericOptions as $name): ?>
	<tr>
		<td width="40%"><?= Loc::getMessage('DZAHDEV_RECOM_OPT_' . strtoupper($name)) ?></td>
		<td width="60%"><input type="text" size="10" name="<?= $name ?>" value="<?= htmlspecialcharsbx(Config::string($name)) ?>"></td>
	</tr>
	<?php endforeach; ?>
<?php $tabControl->BeginNextTab(); ?>
	<tr><td width="40%"><?= Loc::getMessage('DZAHDEV_RECOM_OPT_STAT_EVENTS') ?></td><td><?= (int)$stats['EVENTS'] ?></td></tr>
	<tr><td><?= Loc::getMessage('DZAHDEV_RECOM_OPT_STAT_USERS') ?></td><td><?= (int)$stats['USERS'] ?></td></tr>
	<tr><td><?= Loc::getMessage('DZAHDEV_RECOM_OPT_STAT_ITEMS') ?></td><td><?= (int)$stats['ITEMS'] ?></td></tr>
	<tr class="heading"><td colspan="2"><?= Loc::getMessage('DZAHDEV_RECOM_OPT_HEADING_LAST_RUN') ?></td></tr>
	<?php if ($lastRun === null): ?>
	<tr><td colspan="2"><?= Loc::getMessage('DZAHDEV_RECOM_OPT_NO_RUNS') ?></td></tr>
	<?php else: ?>
	<tr><td><?= Loc::getMessage('DZAHDEV_RECOM_OPT_RUN_STARTED') ?></td><td><?= htmlspecialcharsbx((string)$lastRun['STARTED_AT']) ?> (<?= htmlspecialcharsbx($lastRun['MODE']) ?>)</td></tr>
	<tr><td><?= Loc::getMessage('DZAHDEV_RECOM_OPT_RUN_DURATION') ?></td><td><?= round((float)$lastRun['DURATION_SEC'], 1) ?></td></tr>
	<tr><td><?= Loc::getMessage('DZAHDEV_RECOM_OPT_RUN_SIZE') ?></td><td><?= (int)$lastRun['USERS_COUNT'] ?> / <?= (int)$lastRun['ITEMS_COUNT'] ?> / <?= (int)$lastRun['PAIRS_COUNT'] ?></td></tr>
	<tr><td>NDCG@10 ALS</td><td><?= $lastRun['NDCG'] === null ? Loc::getMessage('DZAHDEV_RECOM_OPT_NOT_EVALUATED') : sprintf('%.4f', $lastRun['NDCG']) ?></td></tr>
	<tr><td>NDCG@10 FM</td><td><?= $lastRun['NDCG_FM'] === null ? Loc::getMessage('DZAHDEV_RECOM_OPT_NOT_EVALUATED') : sprintf('%.4f', $lastRun['NDCG_FM']) ?></td></tr>
	<tr><td><?= Loc::getMessage('DZAHDEV_RECOM_OPT_RUN_ERROR') ?></td><td><?= $lastRun['ERROR'] === null ? '-' : htmlspecialcharsbx($lastRun['ERROR']) ?></td></tr>
	<?php endif; ?>
	<tr><td><?= Loc::getMessage('DZAHDEV_RECOM_OPT_FULL_REQUESTED') ?></td><td><?= Config::string('full_train_requested') === 'Y' ? Loc::getMessage('DZAHDEV_RECOM_OPT_YES') : Loc::getMessage('DZAHDEV_RECOM_OPT_NO') ?></td></tr>
<?php
$tabControl->Buttons();
?>
	<input type="submit" name="save" value="<?= Loc::getMessage('DZAHDEV_RECOM_OPT_SAVE') ?>" class="adm-btn-save">
	<input type="submit" name="train_now" value="<?= Loc::getMessage('DZAHDEV_RECOM_OPT_TRAIN_NOW') ?>">
<?php
$tabControl->End();
?>
</form>
