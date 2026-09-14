<?php
/**
 * Bit-mask helpers for week days.
 *
 * dow_mask bit layout (matches the SQL functions in db/schema.sql):
 *      bit 0 = Monday
 *      bit 1 = Tuesday
 *      bit 2 = Wednesday
 *      bit 3 = Thursday
 *      bit 4 = Friday
 *      bit 5 = Saturday
 *      bit 6 = Sunday
 */

declare(strict_types=1);

namespace App;

final class Weekday
{
    /** Human readable names, index 0..6 = Mon..Sun. */
    public const NAMES = [
        0 => 'Понедельник',
        1 => 'Вторник',
        2 => 'Среда',
        3 => 'Четверг',
        4 => 'Пятница',
        5 => 'Суббота',
        6 => 'Воскресенье',
    ];

    public const SHORT = [
        0 => 'Пн',
        1 => 'Вт',
        2 => 'Ср',
        3 => 'Чт',
        4 => 'Пт',
        5 => 'Сб',
        6 => 'Вс',
    ];

    /** Mask with bit $index set. */
    public static function bit(int $index): int
    {
        return 1 << $index;
    }

    /**
     * Turns a list of week-day indexes into a bit mask.
     *
     * @param array<int,int|string> $days
     */
    public static function toMask(array $days): int
    {
        $mask = 0;
        foreach ($days as $day) {
            $day = (int) $day;
            if ($day >= 0 && $day <= 6) {
                $mask |= self::bit($day);
            }
        }
        return $mask;
    }

    /**
     * Turns a bit mask into a list of week-day indexes.
     *
     * @return array<int,int>
     */
    public static function fromMask(?int $mask): array
    {
        if ($mask === null || $mask <= 0) {
            return [];
        }
        $days = [];
        for ($i = 0; $i <= 6; $i++) {
            if (($mask >> $i) & 1) {
                $days[] = $i;
            }
        }
        return $days;
    }

    /** Readable description of a mask, e.g. "Пн, Ср, Пт". */
    public static function describe(?int $mask): string
    {
        $days = self::fromMask($mask);
        if ($days === []) {
            return 'любой день недели';
        }
        if (count($days) === 7) {
            return 'все дни недели';
        }
        if ($days === [0, 1, 2, 3, 4]) {
            return 'рабочие дни (Пн–Пт)';
        }
        if ($days === [5, 6]) {
            return 'выходные (Сб, Вс)';
        }

        return implode(', ', array_map(
            static fn (int $d): string => self::SHORT[$d],
            $days
        ));
    }
}
