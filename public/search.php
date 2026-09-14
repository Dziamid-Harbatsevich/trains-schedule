<?php
/**
 * Search page.
 *
 * Three scenarios, all executed by MySQL via train_runs_on_date():
 *   1. specific train on a date      (number + date)
 *   2. a route on a date             (origin + destination + date)
 *   3. every train on a date         (date only)
 *
 * The PHP code only binds parameters and renders results — it never parses
 * a schedule string.
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use App\Weekday;

$repo   = searchRepo();
$trains = trainRepo();

$mode       = $_GET['mode'] ?? 'all';
$date       = valid_date((string) ($_GET['date'] ?? '')) ?? date('Y-m-d');
$number     = trim((string) ($_GET['number'] ?? ''));
$originId   = (int) ($_GET['origin'] ?? 0);
$destId     = (int) ($_GET['destination'] ?? 0);
$trainId    = (int) ($_GET['train_id'] ?? 0);
$submitted  = isset($_GET['search']);

$stations   = $repo->allStations();
$dayInfo    = $repo->dayInfo($date);
$results    = [];
$specific   = null;
$diagnostics = [];

if ($submitted) {
    if ($mode === 'train' && $number !== '') {
        $specific = $repo->findTrainOnDate($number, $date);
        if ($specific !== null && (int) $specific['runs'] === 1) {
            $results = [$specific];
        }
        $diagnostics = $specific !== null
            ? $repo->ruleDiagnostics((int) $specific['id'], $date)
            : [];
    } elseif ($mode === 'route' && $originId > 0 && $destId > 0) {
        $results = $repo->findRouteOnDate($originId, $destId, $date);
    } else {
        $results = $repo->findAllOnDate($date);
    }
}

render_header('Поиск поездов на дату', 'search');
?>

<div class="panel">
    <h2>Параметры поиска</h2>
    <form method="get" action="search.php">
        <input type="hidden" name="search" value="1">
        <div class="form-grid">
            <div class="field">
                <label>Режим поиска</label>
                <select name="mode" onchange="this.form.submit()">
                    <option value="all"   <?= $mode === 'all'   ? 'selected' : '' ?>>Любой поезд на дату</option>
                    <option value="train" <?= $mode === 'train' ? 'selected' : '' ?>>Конкретный поезд на дату</option>
                    <option value="route" <?= $mode === 'route' ? 'selected' : '' ?>>Маршрут на дату</option>
                </select>
            </div>

            <div class="field">
                <label>Дата *</label>
                <input type="date" name="date" required value="<?= e($date) ?>">
            </div>

            <?php if ($mode === 'train'): ?>
                <div class="field">
                    <label>Номер поезда</label>
                    <input type="text" name="number" value="<?= e($number) ?>" placeholder="45" list="train-numbers">
                    <datalist id="train-numbers">
                        <?php foreach ($trains->allTrains() as $train): ?>
                            <option value="<?= e($train['number']) ?>">
                                №<?= e($train['number']) ?> <?= e($train['origin']) ?> → <?= e($train['destination']) ?>
                            </option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
            <?php endif; ?>

            <?php if ($mode === 'route'): ?>
                <div class="field">
                    <label>Станция отправления</label>
                    <select name="origin">
                        <option value="0">— выберите —</option>
                        <?php foreach ($stations as $station): ?>
                            <option value="<?= (int) $station['id'] ?>" <?= $originId === (int) $station['id'] ? 'selected' : '' ?>>
                                <?= e($station['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Станция назначения</label>
                    <select name="destination">
                        <option value="0">— выберите —</option>
                        <?php foreach ($stations as $station): ?>
                            <option value="<?= (int) $station['id'] ?>" <?= $destId === (int) $station['id'] ? 'selected' : '' ?>>
                                <?= e($station['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>

        <div class="actions" style="margin-top:14px">
            <button class="btn" type="submit">Найти</button>
            <a class="btn btn--ghost" href="search.php">Сбросить</a>
        </div>
    </form>
</div>

<?php if ($date !== ''): ?>
    <div class="panel">
        <h2>Информация о дне <?= e(date('d.m.Y', strtotime($date))) ?></h2>
        <div class="result-head">
            <span class="badge badge--info"><?= e(Weekday::NAMES[(int) date('N', strtotime($date)) - 1]) ?></span>
            <span class="badge <?= (int) $dayInfo['is_holiday'] === 1 ? 'badge--warn' : 'badge--off' ?>">
                <?= (int) $dayInfo['is_holiday'] === 1 ? 'праздник (выходной)' : 'не праздник' ?>
            </span>
            <span class="badge <?= (int) $dayInfo['is_pre_holiday'] === 1 ? 'badge--warn' : 'badge--off' ?>">
                <?= (int) $dayInfo['is_pre_holiday'] === 1 ? 'предпраздничный (как пятница)' : 'не предпраздничный' ?>
            </span>
            <span class="badge badge--info">ISO неделя <?= (int) $dayInfo['iso_week'] ?>
                (<?= (int) $dayInfo['is_even_week'] === 1 ? 'чётная' : 'нечётная' ?>)</span>
        </div>
        <p class="meta">Все признаки вычислены в MySQL (функции <code>holiday_is_day_off()</code>,
            <code>is_pre_holiday()</code>, <code>WEEKOFYEAR()</code>).</p>
    </div>
<?php endif; ?>

<?php if ($submitted): ?>
    <div class="panel">
        <div class="panel__head">
            <h2>Результат: найдено <?= count($results) ?></h2>
        </div>

        <?php if ($results === []): ?>
            <div class="empty">
                <?php if ($mode === 'train' && $specific !== null): ?>
                    Поезд №<?= e($number) ?> <b>не ходит</b> <?= e(date('d.m.Y', strtotime($date))) ?>.
                <?php elseif ($mode === 'train'): ?>
                    Поезд №<?= e($number) ?> не найден.
                <?php else: ?>
                    На <?= e(date('d.m.Y', strtotime($date))) ?> поездов по заданным условиям нет.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>№</th><th>Маршрут</th><th>Отправление</th><th>Дата</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $row): ?>
                    <tr>
                        <td class="num"><b>№<?= e($row['number']) ?></b></td>
                        <td><?= e($row['origin']) ?> → <?= e($row['destination']) ?></td>
                        <td class="num"><?= e(substr((string) $row['departure_time'], 0, 5)) ?></td>
                        <td class="num"><?= e(date('d.m.Y', strtotime((string) $row['run_date']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($mode === 'train' && $specific !== null): ?>
            <h3>Разбор по правилам (почему ходит / не ходит)</h3>
            <table>
                <thead>
                <tr>
                    <th>Правило</th><th>Дни недели</th><th>Чётность</th>
                    <th>Период</th><th>Эффективный день</th><th>Сработало</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($diagnostics as $row): ?>
                    <tr>
                        <td><?= e($row['description'] ?: '#' . $row['rule_id']) ?></td>
                        <td><?= e(Weekday::describe($row['dow_mask'] !== null ? (int) $row['dow_mask'] : null)) ?></td>
                        <td><?= e($row['week_parity']) ?></td>
                        <td class="meta">
                            <?= $row['date_from'] ? e(date('d.m.Y', strtotime((string) $row['date_from']))) : '—' ?>
                            …
                            <?= $row['date_to'] ? e(date('d.m.Y', strtotime((string) $row['date_to']))) : '—' ?>
                        </td>
                        <td><?= e(Weekday::NAMES[(int) $row['effective_weekday']]) ?></td>
                        <td>
                            <span class="badge <?= (int) $row['rule_runs'] === 1 ? 'badge--ok' : 'badge--off' ?>">
                                <?= (int) $row['rule_runs'] === 1 ? 'да' : 'нет' ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="panel">
    <h2>Примеры из задания</h2>
    <ul class="plain">
        <li><a href="search.php?search=1&mode=train&number=45&date=2022-04-01">Поезд №45 на 01.04.2022</a> (пятница — ходит)</li>
        <li><a href="search.php?search=1&mode=train&number=45&date=2022-04-03">Поезд №45 на 03.04.2022</a> (воскресенье, нечётная неделя — не ходит)</li>
        <li><a href="search.php?search=1&mode=train&number=45&date=2022-04-10">Поезд №45 на 10.04.2022</a> (воскресенье, чётная неделя — ходит)</li>
        <li><a href="search.php?search=1&mode=train&number=39&date=2022-03-26">Поезд №39 на 26.03.2022</a> (суббота, но исключена — не ходит)</li>
        <li><a href="search.php?search=1&mode=train&number=39&date=2022-04-02">Поезд №39 на 02.04.2022</a> (суббота — ходит)</li>
        <li><a href="search.php?search=1&mode=train&number=22&date=2022-05-09">Поезд №22 на 09.05.2022</a> (праздник — не ходит, т.к. рабочие дни)</li>
        <li><a href="search.php?search=1&mode=train&number=45&date=2022-05-06">Поезд №45 на 06.05.2022</a> (предпраздничный, как пятница — ходит)</li>
        <li><a href="search.php?search=1&mode=all&date=2022-09-03">Все поезда на 03.09.2022</a></li>
    </ul>
</div>

<?php render_footer(); ?>
