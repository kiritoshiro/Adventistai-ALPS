<?php
namespace App;

use DateTimeImmutable;
use DateTimeZone;

final class SabbathTimer
{
    private const TIMEZONE = 'Europe/Vilnius';
    private const FRIDAY_REVEAL_HOUR = 6;

    /**
     * Standard apparent sunset: Sun's centre 50 arc minutes below the horizon.
     * 16' solar radius + about 34' atmospheric refraction = 90°50' zenith.
     */
    private const SUNSET_ZENITH = 90.833333;

    public static function cities(): array
    {
        return [
            'vilnius' => ['name' => 'Vilnius', 'lat' => 54.6872, 'lon' => 25.2797],
            'kaunas' => ['name' => 'Kaunas', 'lat' => 54.8985, 'lon' => 23.9036],
            'klaipeda' => ['name' => 'Klaipėda', 'lat' => 55.7033, 'lon' => 21.1443],
            'siauliai' => ['name' => 'Šiauliai', 'lat' => 55.9349, 'lon' => 23.3137],
            'panevezys' => ['name' => 'Panevėžys', 'lat' => 55.7348, 'lon' => 24.3575],
            'alytus' => ['name' => 'Alytus', 'lat' => 54.3964, 'lon' => 24.0414],
            'marijampole' => ['name' => 'Marijampolė', 'lat' => 54.5599, 'lon' => 23.3541],
            'mazeikiai' => ['name' => 'Mažeikiai', 'lat' => 56.3092, 'lon' => 22.3417],
            'jonava' => ['name' => 'Jonava', 'lat' => 55.0727, 'lon' => 24.2803],
            'utena' => ['name' => 'Utena', 'lat' => 55.4976, 'lon' => 25.5992],
            'kedainiai' => ['name' => 'Kėdainiai', 'lat' => 55.2878, 'lon' => 23.9728],
            'taurage' => ['name' => 'Tauragė', 'lat' => 55.2522, 'lon' => 22.2897],
            'telsiai' => ['name' => 'Telšiai', 'lat' => 55.9814, 'lon' => 22.2472],
            'ukmerge' => ['name' => 'Ukmergė', 'lat' => 55.2494, 'lon' => 24.7636],
            'palanga' => ['name' => 'Palanga', 'lat' => 55.9175, 'lon' => 21.0686],
            'druskininkai' => ['name' => 'Druskininkai', 'lat' => 54.0157, 'lon' => 23.9870],
            'plunge' => ['name' => 'Plungė', 'lat' => 55.9114, 'lon' => 21.8442],
            'kretinga' => ['name' => 'Kretinga', 'lat' => 55.8888, 'lon' => 21.2445],
            'visaginas' => ['name' => 'Visaginas', 'lat' => 55.5968, 'lon' => 26.4398],
            'nida' => ['name' => 'Nida', 'lat' => 55.3039, 'lon' => 21.0067],
        ];
    }

    /**
     * Sabbath verses shown only between Friday sunset and Saturday sunset.
     * More verses can be added here later without changing the front-end markup.
     */
    public static function verses(): array
    {
        return [
            [
                'text' => 'Atmink ir švęsk šabo dieną.',
                'reference' => 'Išėjimo knyga 20, 8',
            ],
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
            'fridayRevealHour' => self::FRIDAY_REVEAL_HOUR,
            'verses' => self::verses(),
            'cities' => $cities,
        ];
    }

    /**
     * Calculate apparent sunset using the standard 90°50' zenith.
     * This keeps the Sabbath boundary tied to the real sunset for each city/date
     * without relying on server php.ini solar settings or deprecated date_sunset().
     */
    private static function sunset(DateTimeImmutable $date, float $latitude, float $longitude): ?DateTimeImmutable
    {
        $dayOfYear = ((int) $date->format('z')) + 1;
        $longitudeHour = $longitude / 15.0;

        // Approximate time for sunset at the location.
        $t = $dayOfYear + ((18.0 - $longitudeHour) / 24.0);

        // Sun's mean anomaly and true longitude.
        $meanAnomaly = (0.9856 * $t) - 3.289;
        $trueLongitude = $meanAnomaly
            + (1.916 * sin(deg2rad($meanAnomaly)))
            + (0.020 * sin(deg2rad(2.0 * $meanAnomaly)))
            + 282.634;
        $trueLongitude = self::normalizeDegrees($trueLongitude);

        // Right ascension, corrected into the same quadrant as true longitude.
        $rightAscension = rad2deg(atan(0.91764 * tan(deg2rad($trueLongitude))));
        $rightAscension = self::normalizeDegrees($rightAscension);
        $longitudeQuadrant = floor($trueLongitude / 90.0) * 90.0;
        $rightAscensionQuadrant = floor($rightAscension / 90.0) * 90.0;
        $rightAscension += $longitudeQuadrant - $rightAscensionQuadrant;
        $rightAscension /= 15.0;

        // Solar declination.
        $sinDeclination = 0.39782 * sin(deg2rad($trueLongitude));
        $cosDeclination = cos(asin($sinDeclination));

        // Local hour angle for standard apparent sunset (90°50').
        $cosHourAngle = (
            cos(deg2rad(self::SUNSET_ZENITH))
            - ($sinDeclination * sin(deg2rad($latitude)))
        ) / ($cosDeclination * cos(deg2rad($latitude)));

        if ($cosHourAngle < -1.0 || $cosHourAngle > 1.0) {
            return null;
        }

        $hourAngle = rad2deg(acos($cosHourAngle)) / 15.0;
        $localMeanTime = $hourAngle + $rightAscension - (0.06571 * $t) - 6.622;
        $utcHours = $localMeanTime - $longitudeHour;

        // The sunrise/sunset algorithm expresses UT modulo 24 hours.
        $utcHours = fmod($utcHours, 24.0);
        if ($utcHours < 0.0) {
            $utcHours += 24.0;
        }

        $seconds = (int) round($utcHours * 3600.0);
        if ($seconds >= 86400) {
            $seconds = 0;
        }

        $utcMidnight = new DateTimeImmutable(
            $date->format('Y-m-d') . ' 00:00:00',
            new DateTimeZone('UTC')
        );
        $timestamp = $utcMidnight->getTimestamp() + $seconds;

        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone(self::TIMEZONE));
    }

    private static function normalizeDegrees(float $degrees): float
    {
        $degrees = fmod($degrees, 360.0);
        return $degrees < 0.0 ? $degrees + 360.0 : $degrees;
    }

    private static function event(string $type, DateTimeImmutable $sunset): array
    {
        $event = [
            'type' => $type,
            'timestamp' => $sunset->getTimestamp(),
            'iso' => $sunset->format(DATE_ATOM),
            'date' => $sunset->format('Y-m-d'),
            'time' => $sunset->format('H:i'),
        ];

        if ($type === 'start') {
            $reveal = $sunset->setTime(self::FRIDAY_REVEAL_HOUR, 0, 0);
            $event['revealTimestamp'] = $reveal->getTimestamp();
            $event['revealIso'] = $reveal->format(DATE_ATOM);
        }

        return $event;
    }
}
