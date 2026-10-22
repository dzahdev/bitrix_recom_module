# dzahdev.recom - план реализации

> **Для исполнителя:** этапы выполняются по порядку, каждый этап - отдельный файл в этом каталоге.
> Шаги отмечаются чекбоксами (`- [ ]`). После каждого этапа - артефакт этапа в
> `~/.claude/plans/bitrix-modules/dzahdev-recom/NN-этап-<описание>.md` (статус, что сделано,
> проверка инвариантов, след проверок, контекст для следующего этапа).

**Цель:** модуль 1С-Битрикс `dzahdev.recom`: сбор событий покупателей, обучение моделей рекомендаций
на чистом PHP внутри модуля, вывод персональных, похожих и популярных товаров.

**Архитектура:** чистая математика (`lib/Engine`) не зависит от Битрикса и покрыта тестами без ядра.
Слой Битрикса (`lib/Orm`, `lib/Tracking`, `lib/Training`, `lib/Recommend`, компонент, контроллер,
агенты, консольная команда) пишет события, обучает модели в CLI или агенте на cron и отдает готовые
подборки из таблиц.

**Стек:** PHP 8.1+, ядро 1С-Битрикс D7 (main, iblock, catalog, sale) версии 26.x, MySQL 8.

**Спецификация:** [`../specs/2026-10-22-recom-design.md`](../specs/2026-10-22-recom-design.md).

## Общие ограничения

- PHP 8.1+, в файлах `lib/` и `tests/` - `declare(strict_types=1);`, отступы табуляцией (стиль
  `dzah.hotclient`).
- D7 по таблице раздела 3а спецификации. Старый API только в исключениях спецификации: `\CAgent`;
  разметка и служебные вызовы страниц админки (`\CAdminTabControl`, `\CAdminMessage`,
  `$APPLICATION->GetGroupRight`, `$APPLICATION->AuthForm`, `LocalRedirect`, `check_bitrix_sessid`,
  `bitrix_sessid_post`, `htmlspecialcharsbx`); класс установщика `\CModule` и копирование файлов
  установщика `CopyDirFiles`; базовый класс компонента `\CBitrixComponent`.
- Ошибка не превращается в значение по умолчанию. Исключения пробрасываются; перехват допускается
  только на границах, которые обязаны продолжить работу магазина: обработчики событий (журнал
  `EventLogger` и продолжение работы ядра), агент обучения (ошибку уже записал `Trainer`),
  установщик (откат и сообщение в админке).
- Тексты и комментарии без буквы "е с точками" и длинных тире; только дефис `-`.
- Коммиты от имени Denis Zakharov <dzahdev@gmail.com>, без упоминаний ИИ.
- Путь модуля на стенде: `/home/dzrise/projects/bitrix-modules/www/local/modules/dzahdev.recom`,
  внутри контейнера `bitrix_php`: `/var/www/bitrix/local/modules/dzahdev.recom`.
- Тесты: `docker exec bitrix_php php /var/www/bitrix/local/modules/dzahdev.recom/tests/run.php`.

## Архитектурные инварианты

Проверяются в критериях приемки каждого этапа; нарушение блокирует завершение этапа.

1. Обработчики событий только пишут строку в `b_dzahdev_recom_event`; расчетов моделей и внешних
   вызовов на хите нет.
2. Обучение выполняется только в CLI или в агенте при `BX_CRONTAB === true`.
3. Выдача на странице читает только готовые таблицы (подборки, похожие, популярное) и данные
   текущего покупателя (его покупки и корзина); модели на хите не считаются.
4. `lib/Engine` не обращается к классам Битрикса.
5. Данные читаются и пишутся через ORM D7 и `Bitrix\Main\DB\Connection`; старый API - только из
   списка исключений.
6. Сбой учета события не ломает просмотр, корзину и оформление заказа; сбой обучения виден в
   журнале обучения и журнале событий, выдача использует последнюю успешную модель.

## Структура файлов

```
dzahdev.recom/
  .gitignore
  .settings.php                    контроллеры и консольная команда (этапы 5, 6)
  default_option.php               значения настроек по умолчанию
  include.php
  options.php                      страница настроек и статистики (этап 10)
  README.md                        (этап 10)
  docs/specs, docs/plans
  install/
    index.php, version.php, step.php, unstep.php
    js/dzahdev/recom/              config.php, script.js (этап 5)
    components/dzahdev/recom.list/ компонент вывода (этап 8)
    components/dzahdev/recom.list.mail/ компонент для писем (этап 9)
  lang/ru/install/                 index.php, step.php, unstep.php
  lang/ru/options.php              (этап 10)
  lib/
    Config.php                     доступ к настройкам
    Orm/                           EventTable, FactorTable, UserRecTable, ItemSimTable,
                                   PopularTable, TrainLogTable
    Engine/                        Dataset, VectorCodec, TopK, Gaussian, Als, AlsModel, Ndcg,
                                   ItemSimilarity, Popular, PersonalRanker,
                                   FeatureIndex, Fm, FmModel, FmSamples (этап 7)
    Catalog/ProductResolver.php    предложение -> товар
    Tracking/                      EventType, EventRecorder, Handler
    Training/                      DatasetLoader, ModelStore, Trainer, TrainResult
    Recommend/                     Recommender (цепочка выдачи), FuserLocator, AvailableFilter
    Controller/Recommend.php       trackView, getList
    Cli/TrainCommand.php           dzahdev.recom:train
    Agent/                         TrainAgent, CleanupAgent
  tests/
    autoload.php, run.php, bench.php
    cases/                         по файлу тестов на компонент Engine
```

## Этапы

| N | Файл | Результат | Зависит от |
|---|---|---|---|
| 1 | [stage-01-scaffold.md](stage-01-scaffold.md) | модуль ставится и удаляется, создает и удаляет 6 таблиц, репозиторий на GitHub | - |
| 2 | [stage-02-engine-basics.md](stage-02-engine-basics.md) | Dataset, VectorCodec, TopK, раннер тестов | 1 |
| 3 | [stage-03-als.md](stage-03-als.md) | ALS, NDCG, замер скорости | 2 |
| 4 | [stage-04-similarity-popular.md](stage-04-similarity-popular.md) | похожие товары, популярное, ранжирование кандидатов | 2, 3 |
| 5 | [stage-05-tracking.md](stage-05-tracking.md) | события просмотра, корзины, отложенных, покупки пишутся на стенде | 1 |
| 6 | [stage-06-training.md](stage-06-training.md) | консольная команда и агент обучают модели и заполняют таблицы | 3, 4, 5 |
| 7 | [stage-07-fm.md](stage-07-fm.md) | классическая FM (Rendle 2010) ранжирует персональные подборки, NDCG@10 ALS и FM в журнале | 6 |
| 8 | [stage-08-output.md](stage-08-output.md) | компонент и контроллер выводят рекомендации на стенде | 7 |
| 9 | [stage-09-mail.md](stage-09-mail.md) | компонент для шаблонов почтовых событий, письмо проверено в Mailpit | 8 |
| 10 | [stage-10-admin-docs.md](stage-10-admin-docs.md) | страница настроек и статистики, README, сквозная проверка, push | 9 |

После этапа 10 - фаза ревью кода (кросс-ревью Codex по правилам пользователя) и итоговый отчет.
