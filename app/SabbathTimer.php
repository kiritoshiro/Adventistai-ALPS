<?php
namespace App;

final class SabbathTimer
{
    private const TIMEZONE = 'Europe/Vilnius';
    private const FRIDAY_REVEAL_HOUR = 6;

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
     * Whether the front page shows the timer: Appearance → ALPS Theme
     * Settings → Sabbath Timer can turn it off.
     */
    public static function enabled(): bool
    {
        return !self::option('sabbath_timer_hide');
    }

    /**
     * Sabbath verses shown only between Friday sunset and Saturday sunset,
     * from the theme settings; the script picks one at random per visit.
     * Without any, the default verse.
     */
    public static function verses(): array
    {
        $verses = [];
        $rows = self::option('sabbath_timer_verses');
        foreach (is_array($rows) ? $rows : [] as $row) {
            // The script shows them as plain text.
            $text = trim(wp_strip_all_tags((string) ($row['sabbath_verse_text'] ?? '')));
            if ($text === '') {
                continue;
            }
            $verses[] = [
                'text' => $text,
                'reference' => trim(wp_strip_all_tags((string) ($row['sabbath_verse_reference'] ?? ''))),
            ];
        }

        return $verses ?: [
            [
                'text' => 'Atmink ir švęsk šabo dieną.',
                'reference' => 'Išėjimo knyga 20, 8',
            ],
        ];
    }

    /** A theme setting; none outside WordPress (tests/sabbath/run.php). */
    private static function option(string $name)
    {
        return defined('ABSPATH') && class_exists(Fields\Store::class) ? Fields\Store::option($name) : null;
    }

    /**
     * What the front page embeds for assets/js/sabbath-timer.js: the cities
     * with their coordinates. The script calculates each city's sunsets
     * itself, so the page no longer carries weeks of times for 20 cities.
     */
    public static function data(): array
    {
        $cities = [];
        foreach (self::cities() as $key => $city) {
            $cities[$key] = ['name' => $city['name'], 'lat' => $city['lat'], 'lon' => $city['lon']];
        }

        return [
            'timezone' => self::TIMEZONE,
            'defaultCity' => 'vilnius',
            'fridayRevealHour' => self::FRIDAY_REVEAL_HOUR,
            'verses' => self::verses(),
            'cities' => $cities,
        ];
    }
}
