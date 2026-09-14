<?php
/**
 * Search queries.
 *
 * IMPORTANT: none of these methods parse schedule strings. The whole
 * "does the train run on this date?" decision is made by MySQL inside
 * train_runs_on_date() / rule_matches_date() (see db/schema.sql).
 */

declare(strict_types=1);

namespace App;

use PDO;

final class SearchRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    /**
     * Scenario 1: a specific train on a specific date
     *             (e.g. "поезд №45 01.04.2022").
     *
     * @return array<string,mixed>|null
     */
    public function findTrainOnDate(string $number, string $date): ?array
    {
        $sql = 'SELECT t.id, t.number, t.name, t.departure_time,
                       os.name AS origin, ds.name AS destination,
                       :date AS run_date,
                       train_runs_on_date(t.id, :date2) AS runs
                  FROM trains t
                  JOIN stations os ON os.id = t.origin_station_id
                  JOIN stations ds ON ds.id = t.destination_station_id
                 WHERE t.number = :number';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':date' => $date, ':date2' => $date, ':number' => $number]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Scenario 2: a route (origin -> destination) on a date
     *             (e.g. "Минск-Гомель 09.05.2022").
     *
     * @return array<int,array<string,mixed>>
     */
    public function findRouteOnDate(int $originId, int $destinationId, string $date): array
    {
        $sql = 'SELECT t.id, t.number, t.name, t.departure_time,
                       os.name AS origin, ds.name AS destination,
                       :date AS run_date
                  FROM trains t
                  JOIN stations os ON os.id = t.origin_station_id
                  JOIN stations ds ON ds.id = t.destination_station_id
                 WHERE t.origin_station_id = :origin
                   AND t.destination_station_id = :destination
                   AND train_runs_on_date(t.id, :date2) = 1
              ORDER BY t.departure_time, t.number + 0';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':origin'      => $originId,
            ':destination' => $destinationId,
            ':date'        => $date,
            ':date2'       => $date,
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Scenario 3: every train running on a date.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findAllOnDate(string $date): array
    {
        $sql = 'SELECT t.id, t.number, t.name, t.departure_time,
                       os.name AS origin, ds.name AS destination,
                       :date AS run_date
                  FROM trains t
                  JOIN stations os ON os.id = t.origin_station_id
                  JOIN stations ds ON ds.id = t.destination_station_id
                 WHERE train_runs_on_date(t.id, :date2) = 1
              ORDER BY t.departure_time, t.number + 0';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':date' => $date, ':date2' => $date]);

        return $stmt->fetchAll();
    }

    /**
     * Rule-level breakdown for one train on one date (used by the UI
     * to show *why* a train does or does not run).
     *
     * @return array<int,array<string,mixed>>
     */
    public function ruleDiagnostics(int $trainId, string $date): array
    {
        $sql = 'SELECT r.id AS rule_id, r.description, r.dow_mask, r.week_parity,
                       r.date_from, r.date_to, r.applies_to_holidays,
                       effective_weekday(:date1, r.holiday_weekday, r.pre_holiday_as_friday) AS effective_weekday,
                       rule_matches_date(r.id, :date2) AS rule_runs
                  FROM schedule_rules r
                 WHERE r.train_id = :train_id
              ORDER BY r.id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':train_id' => $trainId,
            ':date1'    => $date,
            ':date2'    => $date,
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Day information: weekend/day-off/pre-holiday flags for a date,
     * computed entirely in SQL.
     *
     * @return array<string,mixed>
     */
    public function dayInfo(string $date): array
    {
        $sql = 'SELECT :date AS d,
                       DAYNAME(:date2) AS day_name,
                       HOLIDAY_IS_DAY_OFF(:date3) AS is_holiday,
                       IS_PRE_HOLIDAY(:date4)     AS is_pre_holiday,
                       (WEEKOFYEAR(:date5) % 2 = 0) AS is_even_week,
                       WEEKOFYEAR(:date6) AS iso_week
                  FROM DUAL';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':date'  => $date, ':date2' => $date, ':date3' => $date,
            ':date4' => $date, ':date5' => $date, ':date6' => $date,
        ]);

        return $stmt->fetch() ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public function allStations(): array
    {
        return $this->pdo->query('SELECT id, name FROM stations ORDER BY name')->fetchAll();
    }
}
