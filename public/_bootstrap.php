<?php
/**
 * Shared bootstrap: autoloading, sessions, small helpers.
 */

declare(strict_types=1);

session_start();

require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/Weekday.php';
require dirname(__DIR__) . '/src/TrainRepository.php';
require dirname(__DIR__) . '/src/HolidayRepository.php';
require dirname(__DIR__) . '/src/SearchRepository.php';

use App\TrainRepository;
use App\HolidayRepository;
use App\SearchRepository;
use App\Weekday;

/** Escapes a value for HTML output. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Redirects and stops execution. */
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** Stores a one-shot flash message. */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** @return array<int,array{type:string,message:string}> */
function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return $messages;
}

/** Validates an ISO date (Y-m-d) and returns it, or null. */
function valid_date(?string $date): ?string
{
    if ($date === null || $date === '') {
        return null;
    }
    $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);

    return ($dt !== false && $dt->format('Y-m-d') === $date) ? $date : null;
}

function trainRepo(): TrainRepository
{
    return new TrainRepository();
}

function holidayRepo(): HolidayRepository
{
    return new HolidayRepository();
}

function searchRepo(): SearchRepository
{
    return new SearchRepository();
}

/** Renders the shared page header. */
function render_header(string $title, string $active = ''): void
{
    $nav = [
        'index'    => ['index.php',    'Поезда'],
        'trains'   => ['trains.php',   'Расписания'],
        'holidays' => ['holidays.php', 'Праздники'],
        'search'   => ['search.php',   'Поиск'],
    ];
    ?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> — Расписание поездов</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <div class="topbar__inner">
        <a class="brand" href="index.php">🚆 Расписание поездов</a>
        <nav class="nav">
            <?php foreach ($nav as $key => [$href, $label]): ?>
                <a href="<?= e($href) ?>" class="<?= $active === $key ? 'is-active' : '' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
</header>
<main class="container">
    <h1><?= e($title) ?></h1>
    <?php foreach (take_flashes() as $flash): ?>
        <div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach;
}

/** Renders the shared page footer. */
function render_footer(): void
{
    ?>
</main>
<footer class="footer">
    <p>Тестовое задание: PHP + MySQL, поиск по дате средствами SQL.
       Логика курсирования реализована хранимой функцией <code>train_runs_on_date()</code>.</p>
</footer>
</body>
</html><?php
}
