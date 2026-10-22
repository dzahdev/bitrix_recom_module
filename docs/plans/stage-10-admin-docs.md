# Этап 10. Настройки, статистика, документация, итоговая проверка

**Результат:** страница настроек модуля с вкладками "Настройки" и "Статистика", кнопка
"Обучить сейчас", предупреждение о работе агентов на хитах; README; сквозная проверка на стенде;
ветка `main` на GitHub.

**Файлы:**
- Создать: `options.php`, `lang/ru/options.php`, `README.md`
- Изменить: `install/version.php` (версия `1.0.0`)

**Интерфейсы:**
- Потребляет: `Config`, `EventType`, `EventTable`, `TrainLogTable`.
- Производит: страница `/bitrix/admin/settings.php?mid=dzahdev.recom&lang=ru`.

---

- [ ] **Шаг 1. Страница настроек**

`options.php`:

```php
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
```

`lang/ru/options.php`:

```php
<?php

$MESS['DZAHDEV_RECOM_OPT_ACCESS_DENIED'] = 'Нет доступа к настройкам модуля';
$MESS['DZAHDEV_RECOM_OPT_TAB_SETTINGS'] = 'Настройки';
$MESS['DZAHDEV_RECOM_OPT_TAB_STATS'] = 'Статистика';
$MESS['DZAHDEV_RECOM_OPT_SAVED'] = 'Настройки сохранены';
$MESS['DZAHDEV_RECOM_OPT_TRAIN_REQUESTED'] = 'Полное обучение запрошено: его выполнит ближайший запуск агента на cron или консольного скрипта';
$MESS['DZAHDEV_RECOM_OPT_ERROR_TYPES'] = 'Отметьте хотя бы один тип события как положительный сигнал';
$MESS['DZAHDEV_RECOM_OPT_ERROR_NUMBER'] = 'Поле "#NAME#" должно быть положительным числом';
$MESS['DZAHDEV_RECOM_OPT_NO_CRON'] = 'Агенты сайта выполняются на хитах: обучение агентом не запускается';
$MESS['DZAHDEV_RECOM_OPT_NO_CRON_DETAILS'] = 'Настройте выполнение агентов на cron (константа BX_CRONTAB_SUPPORT) или запускайте обучение из консоли:<br><code>php local/modules/dzahdev.recom/tools/train.php</code> - дообучение,<br><code>php local/modules/dzahdev.recom/tools/train.php --full</code> - полное обучение (из корня сайта).';
$MESS['DZAHDEV_RECOM_OPT_HEADING_EVENTS'] = 'События';
$MESS['DZAHDEV_RECOM_OPT_HEADING_MODEL'] = 'Модели и расписание';
$MESS['DZAHDEV_RECOM_OPT_POSITIVE'] = 'положительный сигнал';
$MESS['DZAHDEV_RECOM_OPT_WEIGHT'] = 'вес';
$MESS['DZAHDEV_RECOM_OPT_TYPE_VIEW'] = 'Просмотр товара';
$MESS['DZAHDEV_RECOM_OPT_TYPE_CART'] = 'Добавление в корзину';
$MESS['DZAHDEV_RECOM_OPT_TYPE_DELAY'] = 'Отложенный товар';
$MESS['DZAHDEV_RECOM_OPT_TYPE_BUY'] = 'Покупка';
$MESS['DZAHDEV_RECOM_OPT_WEIGHT_VIEW'] = 'Вес просмотра';
$MESS['DZAHDEV_RECOM_OPT_WEIGHT_CART'] = 'Вес добавления в корзину';
$MESS['DZAHDEV_RECOM_OPT_WEIGHT_DELAY'] = 'Вес отложенного товара';
$MESS['DZAHDEV_RECOM_OPT_WEIGHT_BUY'] = 'Вес покупки';
$MESS['DZAHDEV_RECOM_OPT_ALS_FACTORS'] = 'ALS: число факторов';
$MESS['DZAHDEV_RECOM_OPT_ALS_REG'] = 'ALS: регуляризация (reg)';
$MESS['DZAHDEV_RECOM_OPT_ALS_ALPHA'] = 'ALS: вес непросмотренных пар (alpha)';
$MESS['DZAHDEV_RECOM_OPT_ALS_EPOCHS_FULL'] = 'ALS: эпох полного обучения';
$MESS['DZAHDEV_RECOM_OPT_ALS_EPOCHS_INCREMENTAL'] = 'ALS: эпох дообучения';
$MESS['DZAHDEV_RECOM_OPT_FM_ENABLED'] = 'Ранжировать персональные подборки FM (иначе ALS)';
$MESS['DZAHDEV_RECOM_OPT_FM_FACTORS'] = 'FM: число факторов';
$MESS['DZAHDEV_RECOM_OPT_FM_LEARNING_RATE'] = 'FM: шаг обучения';
$MESS['DZAHDEV_RECOM_OPT_FM_REG'] = 'FM: регуляризация';
$MESS['DZAHDEV_RECOM_OPT_FM_EPOCHS'] = 'FM: эпох обучения';
$MESS['DZAHDEV_RECOM_OPT_FM_NEGATIVES'] = 'FM: отрицательных примеров на положительный';
$MESS['DZAHDEV_RECOM_OPT_MIN_USER_EVENTS'] = 'Минимум товаров в истории для персональной подборки';
$MESS['DZAHDEV_RECOM_OPT_REC_LIMIT'] = 'Длина персональной подборки';
$MESS['DZAHDEV_RECOM_OPT_SIMILAR_LIMIT'] = 'Число похожих товаров на товар';
$MESS['DZAHDEV_RECOM_OPT_POPULAR_LIMIT'] = 'Длина списка популярного';
$MESS['DZAHDEV_RECOM_OPT_POPULAR_WINDOW_DAYS'] = 'Окно популярного, дней';
$MESS['DZAHDEV_RECOM_OPT_ACTIVE_USER_DAYS'] = 'Подборки для покупателей, активных за последние N дней';
$MESS['DZAHDEV_RECOM_OPT_EVENT_TTL_DAYS'] = 'Срок хранения событий, дней';
$MESS['DZAHDEV_RECOM_OPT_EVAL_USERS'] = 'Покупателей в оценке NDCG@10';
$MESS['DZAHDEV_RECOM_OPT_MAX_USER_ITEMS_SIMILARITY'] = 'Максимум товаров в истории для учета в похожих';
$MESS['DZAHDEV_RECOM_OPT_TRAIN_FULL_INTERVAL'] = 'Полное обучение не реже, секунд';
$MESS['DZAHDEV_RECOM_OPT_STAT_EVENTS'] = 'Событий';
$MESS['DZAHDEV_RECOM_OPT_STAT_USERS'] = 'Покупателей';
$MESS['DZAHDEV_RECOM_OPT_STAT_ITEMS'] = 'Товаров';
$MESS['DZAHDEV_RECOM_OPT_HEADING_LAST_RUN'] = 'Последнее обучение';
$MESS['DZAHDEV_RECOM_OPT_NO_RUNS'] = 'Обучение еще не запускалось';
$MESS['DZAHDEV_RECOM_OPT_RUN_STARTED'] = 'Начало (режим)';
$MESS['DZAHDEV_RECOM_OPT_RUN_DURATION'] = 'Длительность, с';
$MESS['DZAHDEV_RECOM_OPT_RUN_SIZE'] = 'Покупателей / товаров / пар';
$MESS['DZAHDEV_RECOM_OPT_NOT_EVALUATED'] = 'не считался (дообучение, FM выключена или нет данных)';
$MESS['DZAHDEV_RECOM_OPT_RUN_ERROR'] = 'Ошибка';
$MESS['DZAHDEV_RECOM_OPT_FULL_REQUESTED'] = 'Полное обучение запрошено';
$MESS['DZAHDEV_RECOM_OPT_YES'] = 'да, выполнит ближайший запуск агента или консольной команды';
$MESS['DZAHDEV_RECOM_OPT_NO'] = 'нет';
$MESS['DZAHDEV_RECOM_OPT_SAVE'] = 'Сохранить';
$MESS['DZAHDEV_RECOM_OPT_TRAIN_NOW'] = 'Обучить сейчас';
```

Переключатель `train_incremental_interval` на странице нет: он задает интервал агента при установке
и меняется в списке агентов.

- [ ] **Шаг 2. Проверка страницы**

`http://localhost:8088/bitrix/admin/settings.php?mid=dzahdev.recom&lang=ru`:

1. Вкладка "Статистика": числа событий, покупателей, товаров совпадают с
   `select count(*), count(distinct FUSER_ID), count(distinct PRODUCT_ID) from b_dzahdev_recom_event`;
   последний прогон совпадает с последней строкой `b_dzahdev_recom_train_log`, включая NDCG@10 ALS
   и FM.
2. На стенде агенты на хитах: видно предупреждение с командами.
3. Снять все положительные типы и сохранить - ошибка "Отметьте хотя бы один тип...", настройки не
   изменились. Ввести `abc` в число факторов - ошибка с названием поля.
4. Изменить вес покупки на `6`, сохранить - "Настройки сохранены", значение `6` после перезагрузки.
   Снять флажок FM, сохранить, выполнить `dzahdev.recom:train --full` - в статистике
   "NDCG@10 FM: не считался", подборки с `SOURCE = als`; вернуть флажок.
5. "Обучить сейчас" - на вкладке "Статистика" строка "Полное обучение запрошено: да".
   После `docker exec -w /var/www/bitrix bitrix_php php local/modules/dzahdev.recom/tools/train.php`
   строка остается "да": дообучение флаг не сбрасывает. После того же вызова с `--full` - "нет".

Агент на cron при поднятом флаге запускает полное обучение (`TrainAgent`), поэтому на рабочем
сайте кнопка срабатывает без консоли.

- [ ] **Шаг 3. README и версия**

`install/version.php`: `'VERSION' => '1.0.0'`, `'VERSION_DATE'` - дата завершения этапа.

`README.md` - разделы:

1. **Назначение** - сбор событий, обучение ALS и классической FM (Rendle 2010), похожие и популярные
   товары внутри модуля; таблица моделей со ссылками на статьи, где опубликованы формулы.
2. **Требования** - PHP 8.1+, модули iblock, catalog, sale; агенты на cron
   (`BX_CRONTAB_SUPPORT`) либо регулярный запуск консольной команды.
3. **Установка** - скопировать в `/local/modules/dzahdev.recom/`, установить в "Решениях";
   удаление удаляет события и модели.
4. **Сбор событий** - таблица источников из этапа 5; JS-метод `DzahdevRecom.trackView(productId)` с
   примером `BX.loadExt('dzahdev.recom')`.
5. **Обучение** - `php local/modules/dzahdev.recom/tools/train.php [--full]` из корня сайта (код выхода 0/1),
   Symfony-команда `dzahdev.recom:train` для сайтов с composer, агент, флаг "Обучить сейчас",
   журнал обучения, NDCG@10.
6. **Вывод** - пример вызова компонента для каждого режима (как на тестовой странице этапа 8),
   описание параметров; действие `dzahdev:recom.Recommend.getList`.
7. **Письма** - вставка `dzahdev:recom.list.mail` в HTML-шаблон почтового события (пример из
   этапа 9: вызов `EventMessageThemeCompiler::includeComponent` с `"USER_ID" => "{#USER_ID#}"`);
   параметры; поведение для получателя без истории; поле "URL сервера" сайта для абсолютных ссылок.
8. **Настройки** - перечень настроек со значениями по умолчанию из `default_option.php`.
9. **Тесты** - `tests/run.php`, `tests/bench.php` и цифры замера из этапов 3, 4 и 7.

Текст - технический регистр по правилам пользователя: методы, поля, коды, числа вместо бытовых
оборотов.

- [ ] **Шаг 4. Сквозная проверка**

1. `tests/run.php` - `failed: 0`.
2. Переустановить модуль, повторить синтетику (этап 6, шаг 7), обучение `--full`, проверку выдачи
   (этап 8, шаг 6).
3. Оформить заказ на товар из персонального блока; после `dzahdev.recom:train` товар не попадает
   в персональный блок этого покупателя - ни на странице, ни в письме
   (`/recom-mail-test.php`, этап 9).
4. Журнал событий: `select SEVERITY, AUDIT_TYPE_ID, DESCRIPTION from b_event_log where MODULE_ID='dzahdev.recom' order by ID desc limit 10`
   - только ожидаемые предупреждения `Training skipped` с этапов 6 и 10, ошибок нет.

- [ ] **Шаг 5. Коммит и push**

```bash
cd /home/dzrise/projects/bitrix-modules/www/local/modules/dzahdev.recom
git add -A
git -c user.name="Denis Zakharov" -c user.email=dzahdev@gmail.com commit -m "Add settings page, statistics and README; release 1.0.0."
git push
```

**Критерии приемки:** шаги 2 и 4 дали ожидаемый результат; все 6 инвариантов перепроверены по
итоговому коду; `git status` чистый, `main` на GitHub совпадает с локальным.

После этапа - кросс-ревью кода (Codex, по правилам пользователя) и итоговый отчет: что построено,
решения, измененные файлы, технический долг.
