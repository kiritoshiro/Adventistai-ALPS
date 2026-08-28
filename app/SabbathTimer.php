<?php
namespace App;

use DateTimeImmutable;
use DateTimeZone;

final class SabbathTimer
{
    private const TIMEZONE = 'Europe/Vilnius';

    public static function cities(): array
    {
        return [
            'vilnius' => ['name' => 'Vilnius', 'lat' => 54.6872, 'lon' => 25.2797],
            'kaunas' => ['name' => 'Kaunas', 'lat' => 54.8985, 'lon' => 23.9036],
            'klaipeda' => ['name' => 'Klaipėda', 'lat' => 55.7033, 'lon' => 21.1443],
            'siauliai' => ['name' => 'Šiauliai', 'lat' => 55.9349, 'lon' => 23.3137],
            'panevezys' => ['name' => 'Panevėžys', 'lat' => 55.7348, 'lon' => 24.3575],
        ];
    }

    public static function data(?DateTimeImmutable $now = null): array
    {
        $timezone = new DateTimeZone(self::TIMEZONE);
        $now = $now ? $now->setTimezone($timezone) : new DateTimeImmutable('now', $timezone);

        $dayOfWeek = (int) $now->format('N');
        $weekMonday = $now->modify('-' . ($dayOfWeek - 1) . ' days')->setTime(12, 0, 0);
        $cities = [];

        foreach (self::cities() as $key => $city) {
            $events = [];
            for ($week = -1; $week <= 5; $week++) {
                $weekOffset = $week * 7;
                $friday = $weekMonday->modify(($weekOffset + 4) . ' days');
                $saturday = $weekMonday->modify(($weekOffset + 5) . ' days');

                $start = self::sunset($friday, $city['lat'], $city['lon']);
                $end = self::sunset($saturday, $city['lat'], $city['lon']);

                if ($start) {
                    $events[] = self::event('start', $start);
                }
                if ($end) {
                    $events[] = self::event('end', $end);
                }
            }

            usort($events, static fn(array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);
            $cities[$key] = [
                'name' => $city['name'],
                'events' => $events,
            ];
        }

        return [
            'timezone' => self::TIMEZONE,
            'generatedAt' => $now->format(DATE_ATOM),
            'defaultCity' => 'vilnius',
            'cities' => $cities,
        ];
    }

    private static function sunset(DateTimeImmutable $date, float $latitude, float $longitude): ?DateTimeImmutable
    {
        $timezone = new DateTimeZone(self::TIMEZONE);
        $noon = new DateTimeImmutable($date->format('Y-m-d') . ' 12:00:00', $timezone);
        $sun = date_sun_info($noon->getTimestamp(), $latitude, $longitude);
        $timestamp = $sun['sunset'] ?? false;

        if (!is_int($timestamp)) {
            return null;
        }

        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
    }

    private static function event(string $type, DateTimeImmutable $sunset): array
    {
        return [
            'type' => $type,
            'timestamp' => $sunset->getTimestamp(),
            'iso' => $sunset->format(DATE_ATOM),
            'date' => $sunset->format('Y-m-d'),
            'time' => $sunset->format('H:i'),
        ];
    }
}
