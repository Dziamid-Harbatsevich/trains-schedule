<?php
/**
 * Trains, their schedule rules and per-rule exceptions.
 */

declare(strict_types=1);

namespace App;

use PDO;

final class TrainRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    // -- stations -------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function allStations(): array
    {
        return $this->pdo
            ->query('SELECT id, name FROM stations ORDER BY name')
            ->fetchAll();
    }

    /** Creates a station if it does not exist yet and returns its id. */
    public function findOrCreateStation(string $name): int
    {
        $name = trim($name);
        $stmt = $this->pdo->prepare('SELECT id FROM stations WHERE name = ?');
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            return (int) $id;
        }

        $stmt = $this->pdo->prepare('INSERT INTO stations (name) VALUES (?)');
        $stmt->execute([$name]);

        return (int) $this->pdo->lastInsertId();
    }

    // -- trains ---------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function allTrains(): array
    {
        $sql = 'SELECT t.id, t.number, t.name, t.departure_time,
                       os.name AS origin, ds.name AS destination,
                       os.id   AS origin_id, ds.id AS destination_id,
                       (SELECT COUNT(*) FROM schedule_rules r WHERE r.train_id = t.id) AS rules_count
                  FROM trains t
                  JOIN stations os ON os.id = t.origin_station_id
                  JOIN stations ds ON ds.id = t.destination_station_id
              ORDER BY t.number + 0, t.number';

        return $this->pdo->query($sql)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function findTrain(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, os.name AS origin, ds.name AS destination
               FROM trains t
               JOIN stations os ON os.id = t.origin_station_id
               JOIN stations ds ON ds.id = t.destination_station_id
              WHERE t.id = ?'
        );
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * @param array{number:string,name:?string,origin:string,destination:string,departure_time:string} $data
     */
    public function createTrain(array $data): int
    {
        $originId      = $this->findOrCreateStation($data['origin']);
        $destinationId = $this->findOrCreateStation($data['destination']);

        $stmt = $this->pdo->prepare(
            'INSERT INTO trains (number, name, origin_station_id, destination_station_id, departure_time)
             VALUES (:number, :name, :origin, :destination, :time)'
        );
        $stmt->execute([
            ':number'      => $data['number'],
            ':name'        => $data['name'] !== null && $data['name'] !== '' ? $data['name'] : null,
            ':origin'      => $originId,
            ':destination' => $destinationId,
            ':time'        => $data['departure_time'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateTrain(int $id, array $data): void
    {
        $originId      = $this->findOrCreateStation($data['origin']);
        $destinationId = $this->findOrCreateStation($data['destination']);

        $stmt = $this->pdo->prepare(
            'UPDATE trains
                SET number = :number,
                    name = :name,
                    origin_station_id = :origin,
                    destination_station_id = :destination,
                    departure_time = :time
              WHERE id = :id'
        );
        $stmt->execute([
            ':id'          => $id,
            ':number'      => $data['number'],
            ':name'        => $data['name'] !== null && $data['name'] !== '' ? $data['name'] : null,
            ':origin'      => $originId,
            ':destination' => $destinationId,
            ':time'        => $data['departure_time'],
        ]);
    }

    public function deleteTrain(int $id): void
    {
        // schedule_rules / schedule_exceptions are removed by ON DELETE CASCADE.
        $stmt = $this->pdo->prepare('DELETE FROM trains WHERE id = ?');
        $stmt->execute([$id]);
    }

    // -- schedule rules -------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function rulesForTrain(int $trainId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM schedule_rules WHERE train_id = ? ORDER BY id'
        );
        $stmt->execute([$trainId]);
        $rules = $stmt->fetchAll();

        foreach ($rules as &$rule) {
            $rule['exceptions'] = $this->exceptionsForRule((int) $rule['id']);
        }

        return $rules;
    }

    /** @return array<string,mixed>|null */
    public function findRule(int $ruleId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM schedule_rules WHERE id = ?');
        $stmt->execute([$ruleId]);
        $rule = $stmt->fetch();

        if ($rule === false) {
            return null;
        }
        $rule['exceptions'] = $this->exceptionsForRule($ruleId);

        return $rule;
    }

    /**
     * @param list<int> $weekdays  indexes 0..6 (Mon..Sun)
     * @param list<string> $excludeDates
     * @param list<string> $includeDates
     */
    public function createRule(int $trainId, array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO schedule_rules
                 (train_id, description, date_from, date_to, dow_mask, week_parity,
                  applies_to_holidays, holiday_weekday, pre_holiday_as_friday)
             VALUES
                 (:train_id, :description, :date_from, :date_to, :dow_mask, :week_parity,
                  :applies_to_holidays, :holiday_weekday, :pre_holiday_as_friday)'
        );
        $stmt->execute($this->ruleParams($trainId, $data));
        $ruleId = (int) $this->pdo->lastInsertId();

        $this->saveExceptions($ruleId, $data['exclude_dates'] ?? [], 'exclude');
        $this->saveExceptions($ruleId, $data['include_dates'] ?? [], 'include');

        return $ruleId;
    }

    public function updateRule(int $ruleId, array $data): void
    {
        $params = $this->ruleParams((int) $data['train_id'], $data);
        $params[':id'] = $ruleId;

        $stmt = $this->pdo->prepare(
            'UPDATE schedule_rules
                SET train_id = :train_id,
                    description = :description,
                    date_from = :date_from,
                    date_to = :date_to,
                    dow_mask = :dow_mask,
                    week_parity = :week_parity,
                    applies_to_holidays = :applies_to_holidays,
                    holiday_weekday = :holiday_weekday,
                    pre_holiday_as_friday = :pre_holiday_as_friday
              WHERE id = :id'
        );
        $stmt->execute($params);

        $this->pdo->prepare('DELETE FROM schedule_exceptions WHERE schedule_rule_id = ?')
            ->execute([$ruleId]);

        $this->saveExceptions($ruleId, $data['exclude_dates'] ?? [], 'exclude');
        $this->saveExceptions($ruleId, $data['include_dates'] ?? [], 'include');
    }

    public function deleteRule(int $ruleId): void
    {
        $this->pdo->prepare('DELETE FROM schedule_rules WHERE id = ?')->execute([$ruleId]);
    }

    // -- helpers --------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function exceptionsForRule(int $ruleId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, exception_date, type, reason
               FROM schedule_exceptions
              WHERE schedule_rule_id = ?
           ORDER BY exception_date'
        );
        $stmt->execute([$ruleId]);

        return $stmt->fetchAll();
    }

    private function ruleParams(int $trainId, array $data): array
    {
        $mask = Weekday::toMask($data['weekdays'] ?? []);

        return [
            ':train_id'             => $trainId,
            ':description'          => $data['description'] !== '' ? $data['description'] : null,
            ':date_from'            => $data['date_from'] !== '' ? $data['date_from'] : null,
            ':date_to'              => $data['date_to'] !== '' ? $data['date_to'] : null,
            ':dow_mask'             => $mask > 0 ? $mask : null,
            ':week_parity'          => $data['week_parity'] ?? 'any',
            ':applies_to_holidays'  => $data['applies_to_holidays'] ?? 'default',
            ':holiday_weekday'      => isset($data['holiday_weekday']) && $data['holiday_weekday'] !== ''
                                        ? (int) $data['holiday_weekday'] : null,
            ':pre_holiday_as_friday' => isset($data['pre_holiday_as_friday']) ? 1 : 0,
        ];
    }

    /** @param list<string> $dates */
    private function saveExceptions(int $ruleId, array $dates, string $type): void
    {
        $dates = array_values(array_unique(array_filter(array_map('trim', $dates))));
        if ($dates === []) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO schedule_exceptions (schedule_rule_id, exception_date, type)
             VALUES (?, ?, ?)'
        );
        foreach ($dates as $date) {
            $stmt->execute([$ruleId, $date, $type]);
        }
    }
}
