<?php
/**
 * Trains CRUD + schedule rules CRUD.
 *
 *  ?action=list            - list (default)
 *  ?action=new             - create train
 *  ?action=edit&id=N       - edit train + manage its rules
 *  ?action=rule_new&train_id=N
 *  ?action=rule_edit&rule_id=N
 *  POST actions: save_train, delete_train, save_rule, delete_rule
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use App\Weekday;

$repo   = trainRepo();
$action = $_GET['action'] ?? 'list';

// ---------------------------------------------------------------------------
// Collect form data for a rule from the request
// ---------------------------------------------------------------------------
function rule_input_from_request(): array
{
    $weekdays = [];
    foreach ((array) ($_POST['weekdays'] ?? []) as $day) {
        $weekdays[] = (int) $day;
    }

    $exclude = preg_split('/[\s,;]+/', (string) ($_POST['exclude_dates'] ?? '')) ?: [];
    $include = preg_split('/[\s,;]+/', (string) ($_POST['include_dates'] ?? '')) ?: [];

    $exclude = array_values(array_filter(array_map(static function (string $d): ?string {
        $d = trim($d);
        return $d !== '' ? valid_date($d) : null;
    }, $exclude)));

    $include = array_values(array_filter(array_map(static function (string $d): ?string {
        $d = trim($d);
        return $d !== '' ? valid_date($d) : null;
    }, $include)));

    return [
        'description'           => trim((string) ($_POST['description'] ?? '')),
        'date_from'             => valid_date((string) ($_POST['date_from'] ?? '')) ?? '',
        'date_to'               => valid_date((string) ($_POST['date_to'] ?? '')) ?? '',
        'weekdays'              => $weekdays,
        'week_parity'           => in_array($_POST['week_parity'] ?? 'any', ['any', 'even', 'odd'], true)
                                    ? $_POST['week_parity'] : 'any',
        'applies_to_holidays'   => in_array($_POST['applies_to_holidays'] ?? 'default', ['default', 'always', 'never'], true)
                                    ? $_POST['applies_to_holidays'] : 'default',
        'holiday_weekday'       => $_POST['holiday_weekday'] ?? '',
        'pre_holiday_as_friday' => isset($_POST['pre_holiday_as_friday']),
        'exclude_dates'         => $exclude,
        'include_dates'         => $include,
    ];
}

// ---------------------------------------------------------------------------
// POST handling
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['form_action'] ?? '';

    if ($postAction === 'save_train') {
        $id     = (int) ($_POST['id'] ?? 0);
        $number = trim((string) ($_POST['number'] ?? ''));
        $origin = trim((string) ($_POST['origin'] ?? ''));
        $dest   = trim((string) ($_POST['destination'] ?? ''));
        $time   = trim((string) ($_POST['departure_time'] ?? ''));

        $errors = [];
        if ($number === '') { $errors[] = 'Укажите номер поезда.'; }
        if ($origin === '') { $errors[] = 'Укажите станцию отправления.'; }
        if ($dest === '')   { $errors[] = 'Укажите станцию назначения.'; }
        if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $time)) { $errors[] = 'Время в формате ЧЧ:М.'; }
        if (mb_strlen($time) === 5) { $time .= ':00'; }

        if ($errors !== []) {
            foreach ($errors as $error) { flash('error', $error); }
            redirect('trains.php?action=' . ($id > 0 ? 'edit&id=' . $id : 'new'));
        }

        $data = [
            'number'         => $number,
            'name'           => trim((string) ($_POST['name'] ?? '')),
            'origin'         => $origin,
            'destination'    => $dest,
            'departure_time' => $time,
        ];

        try {
            if ($id > 0) {
                $repo->updateTrain($id, $data);
                flash('success', "Поезд №{$number} обновлён.");
                redirect('trains.php?action=edit&id=' . $id);
            }

            $newId = $repo->createTrain($data);
            flash('success', "Поезд №{$number} добавлен. Теперь задайте правило расписания.");
            redirect('trains.php?action=edit&id=' . $newId);
        } catch (\PDOException $e) {
            flash('error', str_contains($e->getMessage(), 'uq_trains_number')
                ? "Поезд с номером №{$number} уже существует."
                : 'Ошибка сохранения: ' . $e->getMessage());
            redirect('trains.php?action=' . ($id > 0 ? 'edit&id=' . $id : 'new'));
        }
    }

    if ($postAction === 'delete_train') {
        $id = (int) ($_POST['id'] ?? 0);
        $repo->deleteTrain($id);
        flash('success', 'Поезд удалён вместе со своими правилами.');
        redirect('trains.php');
    }

    if ($postAction === 'save_rule') {
        $trainId = (int) ($_POST['train_id'] ?? 0);
        $ruleId  = (int) ($_POST['id'] ?? 0);
        $data    = rule_input_from_request();

        if (!empty($_POST['date_from']) && !empty($_POST['date_to'])
            && $_POST['date_from'] > $_POST['date_to']) {
            flash('error', 'Дата начала периода больше даты окончания.');
            redirect('trains.php?action=' . ($ruleId > 0 ? 'rule_edit&rule_id=' . $ruleId : 'rule_new&train_id=' . $trainId));
        }

        try {
            if ($ruleId > 0) {
                $data['train_id'] = $trainId;
                $repo->updateRule($ruleId, $data);
                flash('success', 'Правило обновлено.');
                redirect('trains.php?action=edit&id=' . $trainId);
            }

            $repo->createRule($trainId, $data);
            flash('success', 'Правило добавлено.');
            redirect('trains.php?action=edit&id=' . $trainId);
        } catch (\PDOException $e) {
            flash('error', 'Ошибка сохранения правила: ' . $e->getMessage());
            redirect('trains.php?action=edit&id=' . $trainId);
        }
    }

    if ($postAction === 'delete_rule') {
        $ruleId  = (int) ($_POST['id'] ?? 0);
        $trainId = (int) ($_POST['train_id'] ?? 0);
        $repo->deleteRule($ruleId);
        flash('success', 'Правило удалено.');
        redirect('trains.php?action=edit&id=' . $trainId);
    }
}

// ---------------------------------------------------------------------------
// Views
// ---------------------------------------------------------------------------

/** Renders a weekday checkbox row. */
function render_weekday_picker(array $selected): void
{
    ?>
    <div class="dows">
        <?php foreach (Weekday::NAMES as $index => $name): ?>
            <label>
                <input type="checkbox" name="weekdays[]" value="<?= $index ?>"
                    <?= in_array($index, $selected, true) ? 'checked' : '' ?>>
                <?= e(Weekday::SHORT[$index]) ?>
                <span class="meta"><?= e($name) ?></span>
            </label>
        <?php endforeach; ?>
    </div>
    <?php
}

/** Renders the rule form (create or edit). */
function render_rule_form(int $trainId, ?array $rule): void
{
    $isEdit = $rule !== null;
    $selected = [];
    if ($isEdit) {
        $selected = Weekday::fromMask($rule['dow_mask'] !== null ? (int) $rule['dow_mask'] : null);
    }

    $excludeText = '';
    $includeText = '';
    if ($isEdit) {
        $ex = [];
        $inc = [];
        foreach ($rule['exceptions'] as $exception) {
            if ($exception['type'] === 'exclude') {
                $ex[] = $exception['exception_date'];
            } else {
                $inc[] = $exception['exception_date'];
            }
        }
        $excludeText = implode(', ', $ex);
        $includeText = implode(', ', $inc);
    }

    $actionUrl = $isEdit
        ? 'trains.php?action=rule_edit&rule_id=' . (int) $rule['id']
        : 'trains.php?action=rule_new&train_id=' . $trainId;
    ?>
    <form method="post" action="<?= e($actionUrl) ?>">
        <input type="hidden" name="form_action" value="save_rule">
        <input type="hidden" name="train_id" value="<?= $trainId ?>">
        <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?= (int) $rule['id'] ?>">
        <?php endif; ?>

        <div class="form-grid">
            <div class="field">
                <label>Комментарий (для людей, в поиске не участвует)</label>
                <input type="text" name="description" value="<?= e($rule['description'] ?? '') ?>"
                       placeholder="напр. Понедельник, пятница, чётные воскресенья">
            </div>
            <div class="field">
                <label>Действует с</label>
                <input type="date" name="date_from" value="<?= e($rule['date_from'] ?? '') ?>">
                <div class="hint">Пусто — без ограничения</div>
            </div>
            <div class="field">
                <label>Действует по</label>
                <input type="date" name="date_to" value="<?= e($rule['date_to'] ?? '') ?>">
                <div class="hint">Пусто — без ограничения</div>
            </div>

            <div class="field field--wide">
                <label>Дни недели (битовая маска dow_mask)</label>
                <?php render_weekday_picker($selected); ?>
                <div class="hint">Ничего не отмечено — правило не ограничено днём недели (например, только по датам).</div>
            </div>

            <div class="field">
                <label>Чётность недели (ISO)</label>
                <select name="week_parity">
                    <?php foreach (['any' => 'Любая', 'even' => 'Чётные недели', 'odd' => 'Нечётные недели'] as $value => $label): ?>
                        <option value="<?= $value ?>" <?= ($rule['week_parity'] ?? 'any') === $value ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Праздники</label>
                <select name="applies_to_holidays">
                    <?php
                    $holidayOptions = [
                        'default' => 'Праздник = выходной (по умолчанию)',
                        'always'  => 'Ходит и в праздники',
                        'never'   => 'Не ходит в праздники',
                    ];
                    foreach ($holidayOptions as $value => $label): ?>
                        <option value="<?= $value ?>" <?= ($rule['applies_to_holidays'] ?? 'default') === $value ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Праздник считается днём недели</label>
                <select name="holiday_weekday">
                    <option value="">По умолчанию (Воскресенье)</option>
                    <?php foreach (Weekday::NAMES as $index => $name): ?>
                        <option value="<?= $index ?>" <?= (string) ($rule['holiday_weekday'] ?? '') === (string) $index ? 'selected' : '' ?>>
                            <?= e($name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Предпраздничный день = пятница</label>
                <label style="font-weight:500">
                    <input type="checkbox" name="pre_holiday_as_friday" value="1"
                        <?= !isset($rule) || (int) ($rule['pre_holiday_as_friday'] ?? 1) === 1 ? 'checked' : '' ?>>
                    да
                </label>
            </div>

            <div class="field field--wide">
                <label>Исключения — даты «кроме» (через запятую)</label>
                <input type="text" name="exclude_dates" value="<?= e($excludeText) ?>"
                       placeholder="2022-03-26, 2022-04-16">
            </div>
            <div class="field field--wide">
                <label>Дополнительные даты — «ходит» (через запятую)</label>
                <input type="text" name="include_dates" value="<?= e($includeText) ?>"
                       placeholder="2022-05-02">
            </div>
        </div>

        <div class="actions" style="margin-top:14px">
            <button class="btn" type="submit"><?= $isEdit ? 'Сохранить правило' : 'Добавить правило' ?></button>
            <a class="btn btn--ghost" href="trains.php?action=edit&id=<?= $trainId ?>">Отмена</a>
        </div>
    </form>
    <?php
}

// ---------------------------------------------------------------------------
// Routing to views
// ---------------------------------------------------------------------------

if ($action === 'new') {
    render_header('Новый поезд', 'trains');
    ?>
    <div class="panel">
        <h2>Добавить поезд</h2>
        <form method="post" action="trains.php">
            <input type="hidden" name="form_action" value="save_train">
            <div class="form-grid">
                <div class="field">
                    <label>Номер поезда *</label>
                    <input type="text" name="number" required placeholder="45">
                </div>
                <div class="field">
                    <label>Название маршрута</label>
                    <input type="text" name="name" placeholder="Минск-Брест">
                </div>
                <div class="field">
                    <label>Станция отправления *</label>
                    <input type="text" name="origin" required list="stations" placeholder="Минск">
                </div>
                <div class="field">
                    <label>Станция назначения *</label>
                    <input type="text" name="destination" required list="stations" placeholder="Брест">
                </div>
                <div class="field">
                    <label>Время отправления *</label>
                    <input type="time" name="departure_time" required value="09:00">
                </div>
            </div>
            <datalist id="stations">
                <?php foreach ($repo->allStations() as $station): ?>
                    <option value="<?= e($station['name']) ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <div class="actions" style="margin-top:14px">
                <button class="btn" type="submit">Сохранить</button>
                <a class="btn btn--ghost" href="trains.php">Отмена</a>
            </div>
        </form>
    </div>
    <?php
    render_footer();
    exit;
}

if ($action === 'rule_new' || $action === 'rule_edit') {
    $trainId = (int) ($_GET['train_id'] ?? 0);
    $rule    = null;

    if ($action === 'rule_edit') {
        $rule    = $repo->findRule((int) ($_GET['rule_id'] ?? 0));
        $trainId = $rule !== null ? (int) $rule['train_id'] : 0;
    }

    $train = $repo->findTrain($trainId);
    if ($train === null) {
        flash('error', 'Поезд не найден.');
        redirect('trains.php');
    }

    render_header(($rule ? 'Изменить правило' : 'Новое правило') . ' — поезд №' . $train['number'], 'trains');
    ?>
    <div class="panel">
        <p class="meta">Поезд <b>№<?= e($train['number']) ?></b>
            <?= e($train['origin']) ?> → <?= e($train['destination']) ?></p>
        <?php render_rule_form($trainId, $rule); ?>
    </div>
    <?php
    render_footer();
    exit;
}

if ($action === 'edit') {
    $id    = (int) ($_GET['id'] ?? 0);
    $train = $repo->findTrain($id);

    if ($train === null) {
        flash('error', 'Поезд не найден.');
        redirect('trains.php');
    }

    $rules = $repo->rulesForTrain($id);

    render_header('Поезд №' . $train['number'], 'trains');
    ?>
    <div class="panel">
        <div class="panel__head">
            <h2>Карточка поезда</h2>
            <a class="btn btn--ghost" href="trains.php">← К списку</a>
        </div>
        <form method="post" action="trains.php">
            <input type="hidden" name="form_action" value="save_train">
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="form-grid">
                <div class="field">
                    <label>Номер поезда *</label>
                    <input type="text" name="number" required value="<?= e($train['number']) ?>">
                </div>
                <div class="field">
                    <label>Название маршрута</label>
                    <input type="text" name="name" value="<?= e($train['name']) ?>">
                </div>
                <div class="field">
                    <label>Станция отправления *</label>
                    <input type="text" name="origin" required value="<?= e($train['origin']) ?>" list="stations">
                </div>
                <div class="field">
                    <label>Станция назначения *</label>
                    <input type="text" name="destination" required value="<?= e($train['destination']) ?>" list="stations">
                </div>
                <div class="field">
                    <label>Время отправления *</label>
                    <input type="time" name="departure_time" required value="<?= e(substr((string) $train['departure_time'], 0, 5)) ?>">
                </div>
            </div>
            <datalist id="stations">
                <?php foreach ($repo->allStations() as $station): ?>
                    <option value="<?= e($station['name']) ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <div class="actions" style="margin-top:14px">
                <button class="btn" type="submit">Сохранить поезд</button>
                <a class="btn btn--ghost" href="search.php?train_id=<?= $id ?>">Проверить на дату</a>
            </div>
        </form>
        <form method="post" action="trains.php" style="margin-top:14px"
              onsubmit="return confirm('Удалить поезд №<?= e($train['number']) ?> и все его правила?')">
            <input type="hidden" name="form_action" value="delete_train">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn--danger btn--sm" type="submit">Удалить поезд</button>
        </form>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h2>Правила расписания (<?= count($rules) ?>)</h2>
            <a class="btn" href="trains.php?action=rule_new&train_id=<?= $id ?>">+ Добавить правило</a>
        </div>

        <?php if ($rules === []): ?>
            <div class="empty">У поезда нет правил — он не будет найден ни на одну дату.</div>
        <?php else: ?>
            <p class="meta">Правила объединяются логическим ИЛИ: поезд ходит, если сработало хотя бы одно правило.</p>
            <?php foreach ($rules as $rule): ?>
                <div class="rule">
                    <div class="rule__head">
                        <span class="rule__title"><?= e($rule['description'] ?: 'Правило #' . $rule['id']) ?></span>
                        <div class="actions">
                            <a class="btn btn--ghost btn--sm"
                               href="trains.php?action=rule_edit&rule_id=<?= (int) $rule['id'] ?>">Изменить</a>
                            <form method="post" action="trains.php"
                                  onsubmit="return confirm('Удалить правило?')" style="display:inline">
                                <input type="hidden" name="form_action" value="delete_rule">
                                <input type="hidden" name="id" value="<?= (int) $rule['id'] ?>">
                                <input type="hidden" name="train_id" value="<?= $id ?>">
                                <button class="btn btn--danger btn--sm" type="submit">Удалить</button>
                            </form>
                        </div>
                    </div>
                    <div class="meta">
                        <b>Дни недели:</b> <?= e(Weekday::describe($rule['dow_mask'] !== null ? (int) $rule['dow_mask'] : null)) ?>
                        &nbsp;·&nbsp;
                        <b>Период:</b>
                        <?= $rule['date_from'] ? e(date('d.m.Y', strtotime((string) $rule['date_from']))) : '—' ?>
                        …
                        <?= $rule['date_to'] ? e(date('d.m.Y', strtotime((string) $rule['date_to']))) : '—' ?>
                        &nbsp;·&nbsp;
                        <b>Чётность:</b> <?= e($rule['week_parity']) ?>
                        &nbsp;·&nbsp;
                        <b>Праздники:</b> <?= e($rule['applies_to_holidays']) ?>
                        &nbsp;·&nbsp;
                        <b>dow_mask:</b> <?= $rule['dow_mask'] !== null ? (int) $rule['dow_mask'] : 'NULL' ?>
                    </div>
                    <?php if ($rule['exceptions'] !== []): ?>
                        <h3>Исключения</h3>
                        <ul class="plain">
                            <?php foreach ($rule['exceptions'] as $exception): ?>
                                <li>
                                    <span class="badge <?= $exception['type'] === 'exclude' ? 'badge--off' : 'badge--ok' ?>">
                                        <?= $exception['type'] === 'exclude' ? 'кроме' : 'доп.' ?>
                                    </span>
                                    <?= e(date('d.m.Y', strtotime((string) $exception['exception_date']))) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
    render_footer();
    exit;
}

// --- default: list ----------------------------------------------------------
$trains = $repo->allTrains();

render_header('Расписания', 'trains');
?>
<div class="panel">
    <div class="panel__head">
        <h2>Поезда и расписания</h2>
        <a class="btn" href="trains.php?action=new">+ Добавить поезд</a>
    </div>
    <?php if ($trains === []): ?>
        <div class="empty">Поездов пока нет.</div>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th>№</th><th>Маршрут</th><th>Время</th><th>Правил</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($trains as $train): ?>
                <tr>
                    <td class="num"><b>№<?= e($train['number']) ?></b></td>
                    <td><?= e($train['origin']) ?> → <?= e($train['destination']) ?></td>
                    <td class="num"><?= e(substr((string) $train['departure_time'], 0, 5)) ?></td>
                    <td class="num">
                        <span class="badge <?= (int) $train['rules_count'] > 0 ? 'badge--ok' : 'badge--warn' ?>">
                            <?= (int) $train['rules_count'] ?>
                        </span>
                    </td>
                    <td class="num">
                        <a class="btn btn--ghost btn--sm" href="trains.php?action=edit&id=<?= (int) $train['id'] ?>">
                            Правила и карточка
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
