-- =============================================================================
--  Seed data: the task example schedule + holiday calendar
--  Select the target database when running the script:
--      mysql -u root belta_trains < db/seed.sql
-- =============================================================================

-- Make the seed re-runnable ------------------------------------------------
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE schedule_exceptions;
TRUNCATE TABLE schedule_rules;
TRUNCATE TABLE holidays;
TRUNCATE TABLE trains;
TRUNCATE TABLE stations;
SET FOREIGN_KEY_CHECKS = 1;

-- Reference data: stations
INSERT INTO stations (id, name) VALUES
    (1, 'Минск'),
    (2, 'Москва'),
    (3, 'Варшава'),
    (4, 'Брест'),
    (5, 'Гомель'),
    (6, 'Прага');

-- Trains
--  | №  | route          | schedule                                     | time  |
--  | 21 | Минск-Москва   | every day                                    | 09:00 |
--  | 22 | Минск-Варшава  | working days                                 | 10:00 |
--  | 45 | Минск-Брест    | Monday, Friday, even Sundays                 | 11:00 |
--  | 67 | Минск-Гомель   | 01.06.2022-31.08.2022 Wed, Thu               | 12:00 |
--  | 35 | Минск-Гомель   | 01.09.2022-31.12.2022 weekends               | 12:00 |
--  | 39 | Минск-Прага    | Saturday except 26.03.2022 and 16.04.2022    | 13:00 |
INSERT INTO trains (id, number, name, origin_station_id, destination_station_id, departure_time) VALUES
    (1, '21', 'Минск-Москва',  1, 2, '09:00:00'),
    (2, '22', 'Минск-Варшава', 1, 3, '10:00:00'),
    (3, '45', 'Минск-Брест',   1, 4, '11:00:00'),
    (4, '67', 'Минск-Гомель',  1, 5, '12:00:00'),
    (5, '35', 'Минск-Гомель',  1, 5, '12:00:00'),
    (6, '39', 'Минск-Прага',   1, 6, '13:00:00');

-- Schedule rules
--  dow_mask bits: bit0=Mon, bit1=Tue, bit2=Wed, bit3=Thu, bit4=Fri, bit5=Sat, bit6=Sun
--      every day    = 127
--      working days =   31  (Mon..Fri)
--      weekends     =   96  (Sat, Sun)

-- №21 Минск-Москва - все дни недели
INSERT INTO schedule_rules
    (id, train_id, description, date_from, date_to, dow_mask, week_parity, applies_to_holidays)
VALUES
    (1, 1, 'Все дни недели', NULL, NULL, 127, 'any', 'default');

-- №22 Минск-Варшава - рабочие дни
INSERT INTO schedule_rules
    (id, train_id, description, date_from, date_to, dow_mask, week_parity, applies_to_holidays)
VALUES
    (2, 2, 'Рабочие дни (Пн-Пт)', NULL, NULL, 31, 'any', 'default');

-- №45 Минск-Брест - понедельник, пятница, чётные воскресенья
--   <=> weekdays Mon|Fri always, plus Sundays only on even ISO weeks.
--   Implemented as TWO rules OR-combined (this is why N rules per train matter).
INSERT INTO schedule_rules
    (id, train_id, description, date_from, date_to, dow_mask, week_parity, applies_to_holidays)
VALUES
    (3, 3, 'Понедельник и пятница',            NULL, NULL, 17,  'any',  'default'),
    (4, 3, 'Воскресенья по чётным неделям',    NULL, NULL, 64,  'even', 'default');

-- №67 Минск-Гомель - 01.06.2022-31.08.2022, среда и четверг
INSERT INTO schedule_rules
    (id, train_id, description, date_from, date_to, dow_mask, week_parity, applies_to_holidays)
VALUES
    (5, 4, '01.06.2022-31.08.2022: среда, четверг', '2022-06-01', '2022-08-31', 12, 'any', 'default');

-- №35 Минск-Гомель - 01.09.2022-31.12.2022, выходные
INSERT INTO schedule_rules
    (id, train_id, description, date_from, date_to, dow_mask, week_parity, applies_to_holidays)
VALUES
    (6, 5, '01.09.2022-31.12.2022: выходные (Сб, Вс)', '2022-09-01', '2022-12-31', 96, 'any', 'default');

-- №39 Минск-Прага - суббота, кроме 26.03.2022 и 16.04.2022
INSERT INTO schedule_rules
    (id, train_id, description, date_from, date_to, dow_mask, week_parity, applies_to_holidays)
VALUES
    (7, 6, 'Суббота, кроме 26.03.2022 и 16.04.2022', NULL, NULL, 32, 'any', 'default');

INSERT INTO schedule_exceptions (schedule_rule_id, exception_date, type, reason) VALUES
    (7, '2022-03-26', 'exclude', 'Исключение из расписания'),
    (7, '2022-04-16', 'exclude', 'Исключение из расписания');

-- Holidays
-- is_day_off = 1 -> treated as weekend (day off)
INSERT INTO holidays (holiday_date, name, is_day_off, pre_holiday_date) VALUES
    ('2022-01-01', 'Новый год',                    1, NULL),
    ('2022-03-08', 'День женщин',                  1, '2022-03-07'),  -- explicit pre-holiday (Mon)
    ('2022-05-09', 'День Победы',                  1, NULL),          -- auto: 06.05 (Fri) is pre-holiday
    ('2022-07-03', 'День Независимости',           1, NULL),          -- auto: 01.07 (Fri) is pre-holiday
    ('2022-11-07', 'День Октябрьской революции',   1, NULL),          -- auto: 04.11 (Fri) is pre-holiday
    ('2022-12-25', 'Рождество (католическое)',     1, NULL),
    ('2023-01-01', 'Новый год',                    1, NULL),
    ('2023-01-02', 'Новый год (2-й день)',         1, NULL);
