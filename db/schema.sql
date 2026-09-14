-- =============================================================================
--  Train schedule database schema
--  -------------------------------------------------------------------------
--  Key design goal:
--      "Does train X run on date D?" must be answerable by MySQL alone,
--      without any string parsing in PHP.
--
--  That is why the running rule is NOT stored as a free-text string like
--  "Пн, Ср" but is decomposed into structured, indexable columns:
--
--      schedule_rules.date_from / date_to   -> period of validity
--      schedule_rules.dow_mask              -> bit mask of week days (Mon..Sun)
--      schedule_rules.week_parity           -> any / even / odd (ISO week number)
--      schedule_rules.applies_to_holidays   -> default / always / never
--      schedule_rules.include_holidays_as   -> which weekday a holiday plays
--
--      schedule_exceptions(rule_id,date,type)-> explicit include / exclude dates
--      holidays(date,is_day_off,pre_holiday)-> holidays & pre-holidays
--
--  All the evaluation logic lives in the stored FUNCTION
--  `train_runs_on_date(train_id, date)` (see bottom of this file).
-- =============================================================================

-- NOTE: this script does NOT contain CREATE DATABASE / USE.
-- Select the target database when running it, e.g.:
--     mysql -u root -e "CREATE DATABASE belta_trains CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
--     mysql -u root belta_trains < db/schema.sql
-- In Docker the database is created by the MySQL image and selected with
-- the mysql client's --database flag, so DB_NAME can be anything.

-- Re-runnable script -----------------------------------------------------------
DROP FUNCTION  IF EXISTS train_runs_on_date;
DROP TABLE IF EXISTS schedule_exceptions;
DROP TABLE IF EXISTS schedule_rules;
DROP TABLE IF EXISTS holidays;
DROP TABLE IF EXISTS trains;
DROP TABLE IF EXISTS stations;

-- -----------------------------------------------------------------------------
-- 1. stations - dictionary of stations
-- -----------------------------------------------------------------------------
CREATE TABLE stations (
    id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stations_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. trains - train card: number, route (origin -> destination), departure time
-- -----------------------------------------------------------------------------
CREATE TABLE trains (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    number               VARCHAR(16)  NOT NULL COMMENT 'Train number, e.g. "21", "45"',
    name                 VARCHAR(160) NULL     COMMENT 'Display name, e.g. "Минск-Москва"',
    origin_station_id      INT UNSIGNED NOT NULL,
    destination_station_id INT UNSIGNED NOT NULL,
    departure_time       TIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_trains_number (number),
    KEY idx_trains_route (origin_station_id, destination_station_id),
    CONSTRAINT fk_trains_origin
        FOREIGN KEY (origin_station_id)      REFERENCES stations (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_trains_destination
        FOREIGN KEY (destination_station_id) REFERENCES stations (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. holidays - holiday calendar
--    is_day_off = 1 -> holiday is treated as a day off (weekend)
--    is_day_off = 0 -> "working holiday" (NOT a day off), kept for completeness
--    pre_holiday_date -> optional explicit pre-holiday date.
--                       If NULL the pre-holiday is derived automatically
--                       as "the working day right before a day off".
-- -----------------------------------------------------------------------------
CREATE TABLE holidays (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    holiday_date DATE NOT NULL,
    name         VARCHAR(160) NULL,
    is_day_off   TINYINT(1) NOT NULL DEFAULT 1,
    pre_holiday_date DATE NULL DEFAULT NULL
                 COMMENT 'Optional explicit pre-holiday date; NULL = auto (day before a day off)',
    PRIMARY KEY (id),
    UNIQUE KEY uq_holidays_date (holiday_date),
    KEY idx_holidays_pre (pre_holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. schedule_rules - one structured rule of a train.
--    A train may have several rules; their results are OR-combined.
--
--    dow_mask bits (bit 0 .. bit 6):
--        bit 0 = Monday, bit 1 = Tuesday, ... bit 6 = Sunday
--        value 0 (or NULL) = "no weekday restriction" (rule driven by dates only)
--        Examples:  all days    = 127
--                   working days= 0b0011111 = 31
--                   weekend    = 0b1100000 = 96
-- -----------------------------------------------------------------------------
CREATE TABLE schedule_rules (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    train_id    INT UNSIGNED NOT NULL,
    description VARCHAR(190) NULL COMMENT 'Human readable comment (not used in search)',

    date_from   DATE NULL COMMENT 'NULL = no lower bound',
    date_to     DATE NULL COMMENT 'NULL = no upper bound',

    dow_mask    TINYINT UNSIGNED NULL DEFAULT NULL
                COMMENT 'Mon..Sun bitmask (bit0=Mon .. bit6=Sun); NULL/0 = any day',

    week_parity ENUM('any','even','odd') NOT NULL DEFAULT 'any'
                COMMENT 'even/odd week -> ISO week number parity',

    applies_to_holidays ENUM('default','always','never') NOT NULL DEFAULT 'default'
                COMMENT 'default: holiday acts as day off; always: ignore holiday; never: no run on holiday',

    holiday_weekday TINYINT UNSIGNED NULL DEFAULT NULL
                COMMENT '0..6 (Mon..Sun) - which weekday a holiday is treated as; NULL = Sunday(6)',

    pre_holiday_as_friday TINYINT(1) NOT NULL DEFAULT 1
                COMMENT '1 = pre-holiday is treated as Friday',

    PRIMARY KEY (id),
    KEY idx_rules_train (train_id),
    KEY idx_rules_dates (date_from, date_to),
    CONSTRAINT fk_rules_train
        FOREIGN KEY (train_id) REFERENCES trains (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. schedule_exceptions - explicit single dates
--    type='exclude' -> train never runs on this date (e.g. "sat except 26.03")
--    type='include' -> train is forced to run on this date
-- -----------------------------------------------------------------------------
CREATE TABLE schedule_exceptions (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_rule_id INT UNSIGNED NOT NULL,
    exception_date   DATE NOT NULL,
    type             ENUM('exclude','include') NOT NULL DEFAULT 'exclude',
    reason           VARCHAR(160) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_exception_rule_date (schedule_rule_id, exception_date, type),
    KEY idx_exception_date (exception_date),
    CONSTRAINT fk_exception_rule
        FOREIGN KEY (schedule_rule_id) REFERENCES schedule_rules (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
--  Helper functions
-- =============================================================================

DROP FUNCTION IF EXISTS holiday_is_day_off;
DROP FUNCTION IF EXISTS is_pre_holiday;
DROP FUNCTION IF EXISTS weekday_index;         -- 0=Mon .. 6=Sun
DROP FUNCTION IF EXISTS effective_weekday;     -- weekday after holiday/pre-holiday shift
DROP FUNCTION IF EXISTS rule_matches_date;

DELIMITER $$

-- Returns 1 when :d is a holiday that must be treated as a day off.
-- A date is a "day off" holiday when it exists in holidays and is_day_off = 1.
CREATE FUNCTION holiday_is_day_off(d DATE)
RETURNS TINYINT
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v TINYINT DEFAULT 0;
    SELECT is_day_off INTO v
      FROM holidays
     WHERE holiday_date = d
     LIMIT 1;
    RETURN IFNULL(v, 0);
END$$

-- Returns 1 when :d is a pre-holiday (pre-holiday == treated as Friday).
--   * explicit flag holidays.pre_holiday = 1 wins;
--   * otherwise auto: :d is the working day right before a day-off holiday.
CREATE FUNCTION is_pre_holiday(d DATE)
RETURNS TINYINT
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_explicit DATE DEFAULT NULL;
    DECLARE v_next     DATE;

    -- explicit pre-holiday date stored in the calendar
    SELECT pre_holiday_date INTO v_explicit
      FROM holidays
     WHERE holiday_date = d
     LIMIT 1;

    IF v_explicit IS NOT NULL AND v_explicit = d THEN
        RETURN 1;
    END IF;

    -- explicit pre-holiday date declared on ANOTHER holiday row
    SET v_explicit = NULL;
    SELECT pre_holiday_date INTO v_explicit
      FROM holidays
     WHERE pre_holiday_date = d
     LIMIT 1;

    IF v_explicit IS NOT NULL THEN
        RETURN 1;
    END IF;

    -- the day itself must be a holiday / day off
    IF holiday_is_day_off(d) = 1 THEN
        RETURN 0;
    END IF;

    -- only working days (Mon..Fri) can be pre-holidays
    IF WEEKDAY(d) > 4 THEN
        RETURN 0;
    END IF;

    -- auto: walk forward over the coming weekend to the next working day;
    -- if that day is a day-off holiday, :d is a pre-holiday.
    --   e.g. Fri 06.05.2022 -> next working day is Mon 09.05.2022 (holiday) -> pre-holiday
    --        Thu 05.05.2022 -> next day is Fri 06.05.2022 (working)            -> not pre-holiday
    SET v_next = DATE_ADD(d, INTERVAL 1 DAY);
    WHILE WEEKDAY(v_next) > 4 DO
        SET v_next = DATE_ADD(v_next, INTERVAL 1 DAY);
    END WHILE;

    IF holiday_is_day_off(v_next) = 1 THEN
        RETURN 1;
    END IF;

    RETURN 0;
END$$

-- Normalised weekday index: 0=Mon .. 6=Sun (ISO style)
CREATE FUNCTION weekday_index(d DATE)
RETURNS TINYINT
DETERMINISTIC
NO SQL
BEGIN
    RETURN WEEKDAY(d);   -- MySQL WEEKDAY(): 0=Monday .. 6=Sunday
END$$

-- Effective weekday of a date, after applying holiday / pre-holiday shifts:
--   * holiday (day off)      -> the weekday configured in the rule, default Sunday(6)
--   * pre-holiday            -> Friday(4)
--   * otherwise              -> real weekday
CREATE FUNCTION effective_weekday(d DATE, p_holiday_weekday TINYINT, p_pre_holiday_as_friday TINYINT)
RETURNS TINYINT
DETERMINISTIC
READS SQL DATA
BEGIN
    IF holiday_is_day_off(d) = 1 THEN
        RETURN IFNULL(p_holiday_weekday, 6);      -- default: holiday == Sunday
    END IF;

    IF p_pre_holiday_as_friday = 1 AND is_pre_holiday(d) = 1 THEN
        RETURN 4;                                  -- pre-holiday == Friday
    END IF;

    RETURN weekday_index(d);
END$$

-- Evaluates a SINGLE schedule rule against a date. Purely scalar/table lookups,
-- no string parsing. Returns 1 when the rule matches the date.
CREATE FUNCTION rule_matches_date(
        p_rule_id               INT UNSIGNED,
        d                       DATE
) RETURNS TINYINT
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_date_from   DATE;
    DECLARE v_date_to     DATE;
    DECLARE v_dow_mask    TINYINT UNSIGNED;
    DECLARE v_parity      ENUM('any','even','odd');
    DECLARE v_applies     ENUM('default','always','never');
    DECLARE v_hol_wd      TINYINT UNSIGNED;
    DECLARE v_pre_friday  TINYINT;
    DECLARE v_eff_wd      TINYINT;
    DECLARE v_is_holiday  TINYINT;
    DECLARE v_is_pre      TINYINT;

    SELECT date_from, date_to, dow_mask, week_parity, applies_to_holidays,
           holiday_weekday, pre_holiday_as_friday
      INTO v_date_from, v_date_to, v_dow_mask, v_parity, v_applies,
           v_hol_wd, v_pre_friday
      FROM schedule_rules
     WHERE id = p_rule_id;

    IF v_dow_mask IS NULL AND v_date_from IS NULL AND v_date_to IS NULL
       AND v_applies IS NULL THEN
        RETURN 0;                                  -- rule does not exist
    END IF;

    SET v_is_holiday = holiday_is_day_off(d);
    SET v_is_pre     = is_pre_holiday(d);

    -- --- 1. holiday handling ------------------------------------------------
    IF v_is_holiday = 1 THEN
        IF v_applies = 'never' THEN
            RETURN 0;                              -- explicitly forbidden
        END IF;
        -- 'default' and 'always': holiday participates as its configured
        -- weekday (default Sunday). 'always' simply keeps the holiday as a
        -- day off as well; the difference vs 'default' is documented in README:
        -- 'always' means "also run if the rule targets holidays explicitly".
    END IF;

    -- --- 2. date range ------------------------------------------------------
    IF v_date_from IS NOT NULL AND d < v_date_from THEN
        RETURN 0;
    END IF;
    IF v_date_to IS NOT NULL AND d > v_date_to THEN
        RETURN 0;
    END IF;

    -- --- 3. weekday mask ----------------------------------------------------
    -- Effective weekday considers holiday / pre-holiday shifts.
    -- pre_holiday_as_friday = 0 disables the "pre-holiday == Friday" shift.
    SET v_eff_wd = effective_weekday(d, v_hol_wd, v_pre_friday);

    IF v_dow_mask IS NOT NULL AND v_dow_mask > 0 THEN
        -- bit v_eff_wd must be set:  DOWN_SET = (mask >> wd) & 1
        IF ((v_dow_mask >> v_eff_wd) & 1) = 0 THEN
            RETURN 0;
        END IF;
    END IF;

    -- --- 4. week parity (ISO week number) -----------------------------------
    IF v_parity = 'even' AND (WEEKOFYEAR(d) % 2) <> 0 THEN
        RETURN 0;
    END IF;
    IF v_parity = 'odd'  AND (WEEKOFYEAR(d) % 2) <> 1 THEN
        RETURN 0;
    END IF;

    RETURN 1;
END$$

-- =============================================================================
--  Main entry point: "does train :p_train_id run on date :d ?"
--    1. explicit excludes / includes win over everything;
--    2. the date is checked against every rule (OR);
--    3. result = 1 (runs) / 0 (does not run).
-- =============================================================================
CREATE FUNCTION train_runs_on_date(p_train_id INT UNSIGNED, d DATE)
RETURNS TINYINT
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_has_include TINYINT DEFAULT 0;
    DECLARE v_has_exclude TINYINT DEFAULT 0;
    DECLARE v_rule_match  TINYINT DEFAULT 0;

    -- 1a. explicit include date -> always runs
    SELECT COUNT(*) INTO v_has_include
      FROM schedule_exceptions e
      JOIN schedule_rules r ON r.id = e.schedule_rule_id
     WHERE r.train_id = p_train_id
       AND e.type = 'include'
       AND e.exception_date = d;

    IF v_has_include > 0 THEN
        RETURN 1;
    END IF;

    -- 1b. explicit exclude date -> never runs
    SELECT COUNT(*) INTO v_has_exclude
      FROM schedule_exceptions e
      JOIN schedule_rules r ON r.id = e.schedule_rule_id
     WHERE r.train_id = p_train_id
       AND e.type = 'exclude'
       AND e.exception_date = d;

    IF v_has_exclude > 0 THEN
        RETURN 0;
    END IF;

    -- 2. OR over all rules of the train
    SELECT COUNT(*) INTO v_rule_match
      FROM schedule_rules r
     WHERE r.train_id = p_train_id
       AND rule_matches_date(r.id, d) = 1;

    RETURN IF(v_rule_match > 0, 1, 0);
END$$

DELIMITER ;
