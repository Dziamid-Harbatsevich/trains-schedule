# Расписание поездов — PHP + MySQL

Тестовое задание: БД для хранения расписания поездов, простой интерфейс на PHP
для ввода/редактирования расписания и поиск поездов на любую дату
**средствами MySQL** (без строкового поиска и парсинга в PHP).

## Содержание
- [Быстрый старт через Docker](#быстрый-старт-через-docker)
- [Как запустить](#как-запустить)
- [Структура проекта](#структура-проекта)
- [Модель данных](#модель-данных)
- [Как реализован поиск на дату](#как-реализован-поиск-на-дату)
- [Праздники и предпраздничные дни](#праздники-и-предпраздничные-дни)
- [Интерфейс](#интерфейс)
- [Проверка из задания](#проверка-из-задания)
- [SQL-запросы поиска](#sql-запросы-поиска)

## Быстрый старт через Docker
Самый простой способ развернуть проект на любой машине — Docker. Нужны только
Docker и Docker Compose (плагин `docker compose`). Ни PHP, ни MySQL локально
устанавливать не требуется.

```bash
# (необязательно) свои порт/пароли
cp .env.example .env
# собрать и запустить приложение + базу
docker compose up -d --build
# открыть http://localhost:8080
```

При первом запуске контейнер приложения сам:

1. дожидается готовности MySQL (healthcheck `mysqladmin ping`);
2. создаёт таблицы и хранимые функции (`db/schema.sql`);
3. заливает демо-данные из задания (`db/seed.sql`).

Повторный запуск импорт не повторяет — данные сохраняются в томе `db_data`.

### Полезные команды
```bash
docker compose logs -f app        # логи приложения
docker compose logs -f db         # логи базы
docker compose down               # остановить (данные сохраняются)
docker compose down -v            # остановить и удалить БД
FORCE_DB_INIT=1 docker compose up -d   # перезалить схему и демо-данные
docker compose exec db \
    mysql -ubelta -pbelta belta_trains -e "SELECT train_runs_on_date(3,'2022-04-01');"
```

### Если сайт не открывается
`HTTP 000` / «нет ответа» означает, что контейнер `app` не смог стартовать и
перезапускается по кругу. Посмотрите логи и статус:

```bash
docker compose ps                     # STATUS: healthy / restarting / exited
docker compose logs --tail=100 app    # настоящая причина — здесь
docker compose logs --tail=50 db
```

Типичные причины:

| Симптом в логах | Причина | Что делать |
|---|---|---|
| `TLS/SSL error: self-signed certificate` | MySQL 8 требует TLS с самоподписанным сертом | уже решено: `DB_SSL_MODE=disabled` |
| `MySQL at db:3306 did not answer` | app не может достучаться до БД | `docker compose logs db`, дождаться healthy |
| `no 'mysql' client found` | битый образ | `docker compose build --no-cache app` |
| `Loading db/schema.sql` + ошибка SQL | ошибка в схеме | текст ошибки в логах, править `db/schema.sql` |
| сайт открылся, но пустая страница | PHP-ошибка | `docker compose logs app` |

Полная пересборка с нуля (удаляет данные БД):

```bash
docker compose down -v
docker compose up -d --build
```

### Параметры окружения
| Переменная             | По умолчанию   | Назначение                             |
|------------------------|----------------|----------------------------------------|
| `APP_PORT`             | `8080`         | порт на хосте для веб-интерфейса       |
| `DB_NAME`              | `belta_trains` | имя базы данных                        |
| `DB_USER` / `DB_PASSWORD` | `belta`     | пользователь приложения                |
| `MYSQL_ROOT_PASSWORD`  | `rootpass`     | root-пароль MySQL (только для контейнера БД) |
| `FORCE_DB_INIT`        | `0`            | `1` — перезалить схему и демо-данные    |
| `DB_SSL_MODE`          | `disabled`     | TLS к MySQL: `disabled` / `required-no-verify` |

### Развёртывание без Docker (bare-metal)

Требования: PHP ≥ 8.0 с расширением `pdo_mysql`, MySQL / MariaDB ≥ 5.7 / 10.3.

```bash
# 1. Создать БД, таблицы, функции и демо-данные
./db/install.sh
# при необходимости с другими параметрами:
#   DB_HOST=127.0.0.1 DB_PORT=3306 DB_USER=root DB_PASSWORD=secret ./db/install.sh
# 2. Прописать доступы к БД (или задать переменные окружения)
#    config.php — host / port / database / user / password
# 3. Запустить встроенный сервер PHP
php -S 127.0.0.1:8000 -t public
# 4. Открыть http://127.0.0.1:8000/
```

PHP-файлы также можно положить в DocumentRoot Apache/Nginx, указав корень на
папку `public/`.

## Структура проекта

```
belta/
├── README.md                  — этот файл
├── ER_DIAGRAM.md              — ER-диаграмма (Mermaid) + пояснения
├── config.php                 — параметры подключения к БД
├── Dockerfile                 — образ приложения (PHP 8.2 + Apache + pdo_mysql)
├── docker-compose.yml         — приложение + MySQL
├── .env.example               — шаблон переменных окружения для Docker
├── db/
│   ├── schema.sql             — DDL: таблицы, индексы, хранимые функции
│   ├── seed.sql               — демо-данные (пример из задания + праздники)
│   └── install.sh             — установка схемы и данных (bare-metal)
├── docker/
│   └── entrypoint.sh          — ожидание БД и автоимпорт схемы в контейнере
├── public/
│   ├── _bootstrap.php         — общие функции, подключение репозиториев
│   ├── index.php              — список поездов
│   ├── trains.php             — CRUD поездов и их правил расписания
│   ├── holidays.php           — CRUD праздников
│   ├── search.php             — поиск на дату (3 сценария)
│   └── assets/style.css
└── src/
    ├── Database.php           — PDO-подключение
    ├── Weekday.php            — работа с битовой маской дней недели
    ├── TrainRepository.php    — поезда, правила, исключения
    ├── HolidayRepository.php  — праздники
    └── SearchRepository.php   — запросы поиска (используют SQL-функции)
```

Технологии: чистый PHP (без фреймворка и composer), PDO MySQL, минимум CSS.

## Модель данных

Пять таблиц (подробности и ER-диаграмма — в [ER_DIAGRAM.md](ER_DIAGRAM.md)):

| Таблица               | Назначение                                                        |
|-----------------------|-------------------------------------------------------------------|
| `stations`            | справочник станций                                                |
| `trains`              | поезд: номер, маршрут (станции отправления/назначения), время     |
| `schedule_rules`      | правило курсирования (структурированные поля, не строка)          |
| `schedule_exceptions` | точечные даты: `exclude` («кроме») / `include` («дополнительно»)  |
| `holidays`            | праздничные и предпраздничные дни                                 |

Ключевая идея: **правило курсирования не хранится текстом**. Оно разложено на
поля, по которым MySQL сравнивает с датой напрямую:

| Поле                   | Смысл                                                             |
|------------------------|-------------------------------------------------------------------|
| `date_from` / `date_to`| период действия (NULL — без ограничения)                          |
| `dow_mask`             | битовая маска Пн..Вс (1..127, `NULL` — любой день)                |
| `week_parity`          | `any` / `even` / `odd` — чётность ISO-номера недели               |
| `applies_to_holidays`  | `default` / `always` / `never` — как правило относится к празднику |
| `holiday_weekday`      | каким днём недели считать праздник (по умолчанию — воскресенье)   |
| `pre_holiday_as_friday`| считать предпраздничный день пятницей                             |

## Как реализован поиск на дату

Вся логика — в хранимых функциях MySQL (определены в `db/schema.sql`):

```
train_runs_on_date(train_id, date)            ← главная точка входа
  ├── schedule_exceptions (include/exclude)   ← приоритетнее всего
  └── OR по всем правилам поезда
        └── rule_matches_date(rule_id, date)
              ├── holiday_is_day_off(date)    → праздник = выходной
              ├── is_pre_holiday(date)        → предпраздничный = пятница
              ├── effective_weekday(...)      → эффективный день недели
              ├── период date_from .. date_to
              ├── бит дня недели в dow_mask
              └── week_parity по WEEKOFYEAR()
```

PHP только подставляет параметры и печатает результат:

```php
$stmt = $pdo->prepare(
    'SELECT t.*, os.name AS origin, ds.name AS destination
       FROM trains t
       JOIN stations os ON os.id = t.origin_station_id
       JOIN stations ds ON ds.id = t.destination_station_id
      WHERE train_runs_on_date(t.id, :date) = 1'
);
$stmt->execute([':date' => $date]);
```

Строки вида `"Пн, Ср"` не парсятся нигде.

## Праздники и предпраздничные дни

- **Праздник = выходной.** Таблица `holidays` (`is_day_off = 1`). Поведение
  правила регулирует `applies_to_holidays`: `default` — праздник трактуется как
  выходной; `never` — поезд не ходит в праздник; `always` — ходит.
- **Предпраздничный день = пятница.** День перед праздником приравнивается к
  пятнице. Задаётся явно (`holidays.pre_holiday_date`) либо вычисляется
  функцией `is_pre_holiday()`: это рабочий день, перед которым (с пропуском
  выходных) идёт праздник.

## Интерфейс

| Страница        | Возможности                                                     |
|-----------------|-----------------------------------------------------------------|
| `index.php`     | список поездов с описанием их расписаний                        |
| `trains.php`    | CRUD поездов; CRUD правил расписания (дни недели, период, чётность, праздники, исключения) |
| `holidays.php`  | CRUD праздников                                                 |
| `search.php`    | поиск: конкретный поезд / маршрут / любой поезд на дату + разбор по правилам |

## Проверка из задания

Примеры из задания, проверенные через `search.php` (все результаты совпадают с
ожидаемыми):

| Запрос                                                     | Ожидание            | Результат |
|------------------------------------------------------------|---------------------|-----------|
| №45 Минск-Брест на **01.04.2022** (пятница)                | ходит               | 1 ✅       |
| №45 на **03.04.2022** (воскресенье, нечётная ISO-неделя)   | не ходит            | 0 ✅       |
| №45 на **10.04.2022** (воскресенье, чётная неделя)         | ходит               | 1 ✅       |
| №39 Минск-Прага на **26.03.2022** (суббота, но исключена)  | не ходит            | 0 ✅       |
| №39 на **02.04.2022** (суббота)                            | ходит               | 1 ✅       |
| №22 Минск-Варшава на **09.05.2022** (праздник)             | не ходит (рабочие дни) | 0 ✅    |
| №45 на **06.05.2022** (предпраздничный, как пятница)       | ходит               | 1 ✅       |
| Минск→Гомель на **03.09.2022** (суббота)                   | ходит №35           | ✅         |

## SQL-запросы поиска

**Конкретный поезд на дату:**

```sql
SELECT t.id, t.number, os.name AS origin, ds.name AS destination, t.departure_time,
       train_runs_on_date(t.id, :date) AS runs
  FROM trains t
  JOIN stations os ON os.id = t.origin_station_id
  JOIN stations ds ON ds.id = t.destination_station_id
 WHERE t.number = :number;
```

**Маршрут на дату:**

```sql
SELECT t.number, os.name AS origin, ds.name AS destination, t.departure_time
  FROM trains t
  JOIN stations os ON os.id = t.origin_station_id
  JOIN stations ds ON ds.id = t.destination_station_id
 WHERE t.origin_station_id = :origin
   AND t.destination_station_id = :destination
   AND train_runs_on_date(t.id, :date) = 1
 ORDER BY t.departure_time;
```

**Любой поезд на дату:**

```sql
SELECT t.number, os.name AS origin, ds.name AS destination, t.departure_time
  FROM trains t
  JOIN stations os ON os.id = t.origin_station_id
  JOIN stations ds ON ds.id = t.destination_station_id
 WHERE train_runs_on_date(t.id, :date) = 1
 ORDER BY t.departure_time;
```

## Проверка схемы вручную

```sql
USE belta_trains;

-- Ходит ли поезд №45 (id=3) 01.04.2022?
SELECT train_runs_on_date(3, '2022-04-01');   -- 1

-- Признаки дня
SELECT HOLIDAY_IS_DAY_OFF('2022-05-09'),      -- 1 (праздник)
       IS_PRE_HOLIDAY('2022-05-06');          -- 1 (предпраздничный)
```
