<?php
/**
 * Home page: list of trains with route, departure time and a summary
 * of their schedule rules.
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use App\Weekday;

$repo   = trainRepo();
$trains = $repo->allTrains();

// Load rules for every train so we can describe the schedule on the page.
$rulesByTrain = [];
foreach ($trains as $train) {
    $rulesByTrain[(int) $train['id']] = $repo->rulesForTrain((int) $train['id']);
}

/**
 * Short human readable text of one rule (only for display; the search
 * itself never uses this text).
 */
function rule_text(array $rule): string
{
    $parts = [];

    if ($rule['date_from'] || $rule['date_to']) {
        $from = $rule['date_from'] ? date('d.m.Y', strtotime((string) $rule['date_from'])) : '…';
        $to   = $rule['date_to']   ? date('d.m.Y', strtotime((string) $rule['date_to']))   : '…';
        $parts[] = "период $from – $to";
    }

    $parts[] = Weekday::describe($rule['dow_mask'] !== null ? (int) $rule['dow_mask'] : null);

    if ($rule['week_parity'] === 'even') {
        $parts[] = 'чётные недели';
    } elseif ($rule['week_parity'] === 'odd') {
        $parts[] = 'нечётные недели';
    }

    if ($rule['applies_to_holidays'] === 'never') {
        $parts[] = 'не ходит в праздники';
    } elseif ($rule['applies_to_holidays'] === 'always') {
        $parts[] = 'ходит и в праздники';
    }

    $exceptions = $rule['exceptions'] ?? [];
    if ($exceptions !== []) {
        $ex = [];
        foreach ($exceptions as $exception) {
            $mark = $exception['type'] === 'exclude' ? 'кроме ' : 'доп. ';
            $ex[] = $mark . date('d.m.Y', strtotime((string) $exception['exception_date']));
        }
        $parts[] = implode(', ', $ex);
    }

    return implode(', ', $parts);
}

render_header('Поезда', 'index');
?>

<div class="panel">
    <div class="panel__head">
        <h2>Список поездов</h2>
        <div class="actions">
            <a class="btn" href="trains.php?action=new">+ Добавить поезд</a>
            <a class="btn btn--ghost" href="search.php">Найти поезд на дату</a>
        </div>
    </div>

    <?php if ($trains === []): ?>
        <div class="empty">Поездов пока нет. Добавьте первый поезд.</div>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th>№</th>
                <th>Название / маршрут</th>
                <th>Отправление</th>
                <th>Расписание</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($trains as $train): ?>
                <tr>
                    <td class="num"><b>№<?= e($train['number']) ?></b></td>
                    <td>
                        <div><b><?= e($train['name'] ?: $train['origin'] . ' — ' . $train['destination']) ?></b></div>
                        <div class="meta"><?= e($train['origin']) ?> → <?= e($train['destination']) ?></div>
                    </td>
                    <td class="num"><?= e(substr((string) $train['departure_time'], 0, 5)) ?></td>
                    <td>
                        <?php $rules = $rulesByTrain[(int) $train['id']] ?? []; ?>
                        <?php if ($rules === []): ?>
                            <span class="badge badge--warn">правила не заданы</span>
                        <?php else: ?>
                            <ul class="plain">
                                <?php foreach ($rules as $rule): ?>
                                    <li><?= e(rule_text($rule)) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </td>
                    <td class="num">
                        <div class="actions">
                            <a class="btn btn--ghost btn--sm" href="trains.php?action=edit&id=<?= (int) $train['id'] ?>">Изменить</a>
                            <a class="btn btn--ghost btn--sm" href="search.php?train_id=<?= (int) $train['id'] ?>&date=<?= date('Y-m-d') ?>">Проверить</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>Как это работает</h2>
    <p class="meta">
        Правило курсирования хранится не строкой, а набором структурированных полей
        (<code>date_from</code>, <code>date_to</code>, <code>dow_mask</code>,
        <code>week_parity</code>, <code>applies_to_holidays</code>) и таблицей
        <code>schedule_exceptions</code>. Ответ на вопрос «ходит ли поезд в дату X»
        полностью вычисляется в MySQL функцией
        <code>train_runs_on_date(train_id, date)</code> — без парсинга строк в PHP.
    </p>
</div>

<?php render_footer(); ?>
