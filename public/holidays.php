<?php
/**
 * Holidays CRUD.
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use App\Weekday;

$repo   = holidayRepo();
$action = $_GET['action'] ?? 'list';

function holiday_input(): array
{
    return [
        'holiday_date'     => valid_date((string) ($_POST['holiday_date'] ?? '')) ?? '',
        'name'             => trim((string) ($_POST['name'] ?? '')),
        'is_day_off'       => isset($_POST['is_day_off']),
        'pre_holiday_date' => valid_date((string) ($_POST['pre_holiday_date'] ?? '')) ?? '',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['form_action'] ?? '';

    if ($postAction === 'save_holiday') {
        $id   = (int) ($_POST['id'] ?? 0);
        $data = holiday_input();

        if ($data['holiday_date'] === '') {
            flash('error', 'Укажите дату праздника.');
            redirect('holidays.php?action=' . ($id > 0 ? 'edit&id=' . $id : 'new'));
        }

        try {
            if ($id > 0) {
                $repo->update($id, $data);
                flash('success', 'Праздник обновлён.');
            } else {
                $repo->create($data);
                flash('success', 'Праздник добавлен.');
            }
        } catch (\PDOException $e) {
            flash('error', str_contains($e->getMessage(), 'uq_holidays_date')
                ? 'Праздник на эту дату уже существует.'
                : 'Ошибка сохранения: ' . $e->getMessage());
        }

        redirect('holidays.php');
    }

    if ($postAction === 'delete_holiday') {
        $repo->delete((int) ($_POST['id'] ?? 0));
        flash('success', 'Праздник удалён.');
        redirect('holidays.php');
    }
}

if ($action === 'new' || $action === 'edit') {
    $holiday = null;
    if ($action === 'edit') {
        $holiday = $repo->find((int) ($_GET['id'] ?? 0));
        if ($holiday === null) {
            flash('error', 'Запись не найдена.');
            redirect('holidays.php');
        }
    }

    render_header($holiday ? 'Изменить праздник' : 'Новый праздник', 'holidays');
    ?>
    <div class="panel">
        <form method="post" action="holidays.php">
            <input type="hidden" name="form_action" value="save_holiday">
            <?php if ($holiday): ?>
                <input type="hidden" name="id" value="<?= (int) $holiday['id'] ?>">
            <?php endif; ?>
            <div class="form-grid">
                <div class="field">
                    <label>Дата праздника *</label>
                    <input type="date" name="holiday_date" required
                           value="<?= e($holiday['holiday_date'] ?? '') ?>">
                </div>
                <div class="field">
                    <label>Название</label>
                    <input type="text" name="name" value="<?= e($holiday['name'] ?? '') ?>"
                           placeholder="День Победы">
                </div>
                <div class="field">
                    <label>Предпраздничный день (дата)</label>
                    <input type="date" name="pre_holiday_date"
                           value="<?= e($holiday['pre_holiday_date'] ?? '') ?>">
                    <div class="hint">Пусто — определяется автоматически как рабочий день перед праздником.</div>
                </div>
                <div class="field">
                    <label>Тип</label>
                    <label style="font-weight:500">
                        <input type="checkbox" name="is_day_off" value="1"
                            <?= !$holiday || (int) $holiday['is_day_off'] === 1 ? 'checked' : '' ?>>
                        выходной день (приравнивается к выходному)
                    </label>
                </div>
            </div>
            <div class="actions" style="margin-top:14px">
                <button class="btn" type="submit">Сохранить</button>
                <a class="btn btn--ghost" href="holidays.php">Отмена</a>
            </div>
        </form>
    </div>
    <?php
    render_footer();
    exit;
}

$holidays = $repo->all();

render_header('Праздники', 'holidays');
?>
<div class="panel">
    <div class="panel__head">
        <h2>Праздничные дни (<?= count($holidays) ?>)</h2>
        <a class="btn" href="holidays.php?action=new">+ Добавить праздник</a>
    </div>
    <p class="meta">
        Праздник приравнивается к выходному дню, если в правиле поезда не указано иное
        (<code>applies_to_holidays</code>). День перед праздником (рабочий) приравнивается к пятнице.
    </p>

    <?php if ($holidays === []): ?>
        <div class="empty">Праздники не заданы.</div>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th>Дата</th><th>День недели</th><th>Название</th><th>Тип</th>
                <th>Предпраздничный</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($holidays as $holiday): ?>
                <tr>
                    <td class="num"><b><?= e(date('d.m.Y', strtotime((string) $holiday['holiday_date']))) ?></b></td>
                    <td><?= e(Weekday::NAMES[(int) date('N', strtotime((string) $holiday['holiday_date'])) - 1]) ?></td>
                    <td><?= e($holiday['name'] ?? '') ?></td>
                    <td>
                        <?php if ((int) $holiday['is_day_off'] === 1): ?>
                            <span class="badge badge--off">выходной</span>
                        <?php else: ?>
                            <span class="badge badge--info">рабочий</span>
                        <?php endif; ?>
                    </td>
                    <td class="num">
                        <?= $holiday['pre_holiday_date']
                            ? e(date('d.m.Y', strtotime((string) $holiday['pre_holiday_date'])))
                            : '<span class="meta">авто</span>' ?>
                    </td>
                    <td class="num">
                        <div class="actions">
                            <a class="btn btn--ghost btn--sm" href="holidays.php?action=edit&id=<?= (int) $holiday['id'] ?>">Изменить</a>
                            <form method="post" action="holidays.php" style="display:inline"
                                  onsubmit="return confirm('Удалить праздник?')">
                                <input type="hidden" name="form_action" value="delete_holiday">
                                <input type="hidden" name="id" value="<?= (int) $holiday['id'] ?>">
                                <button class="btn btn--danger btn--sm" type="submit">Удалить</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
