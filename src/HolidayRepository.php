<?php
/**
 * Holiday calendar CRUD.
 */

declare(strict_types=1);

namespace App;

use PDO;

final class HolidayRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->pdo
            ->query('SELECT id, holiday_date, name, is_day_off, pre_holiday_date
                       FROM holidays
                   ORDER BY holiday_date')
            ->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM holidays WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO holidays (holiday_date, name, is_day_off, pre_holiday_date)
             VALUES (:date, :name, :is_day_off, :pre_holiday_date)'
        );
        $stmt->execute($this->params($data));

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $params = $this->params($data);
        $params[':id'] = $id;

        $stmt = $this->pdo->prepare(
            'UPDATE holidays
                SET holiday_date = :date,
                    name = :name,
                    is_day_off = :is_day_off,
                    pre_holiday_date = :pre_holiday_date
              WHERE id = :id'
        );
        $stmt->execute($params);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM holidays WHERE id = ?')->execute([$id]);
    }

    private function params(array $data): array
    {
        return [
            ':date'             => $data['holiday_date'],
            ':name'             => $data['name'] !== '' ? $data['name'] : null,
            ':is_day_off'       => !empty($data['is_day_off']) ? 1 : 0,
            ':pre_holiday_date' => !empty($data['pre_holiday_date']) ? $data['pre_holiday_date'] : null,
        ];
    }
}
