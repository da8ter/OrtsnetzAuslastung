<?php

declare(strict_types=1);

/**
 * Reine Bausteine fuer ortsnetz-auslastung.de (ohne Symcon, einzeln testbar):
 * Messung pruefen und zur Nutzlast fuer POST /v1/measurements bauen, Intervallstatistik
 * (min/max/avg) fuehren und die Antwort normalisieren. Wertebereiche laut API.md des Projekts.
 */
final class OnaPayload
{
    public const API_URL = 'https://www.ortsnetz-auslastung.de/v1/measurements';
    public const INTERVAL = 300;

    /** Ampel: unbekannt, gruen, gelb, rot */
    public const LIGHT = ['green' => 0, 'yellow' => 1, 'red' => 2];
    public const STORAGE = ['none' => 0, 'charge' => 1, 'discharge' => 2];
    public const UNKNOWN = -1;

    /** Spannung laut API: groesser 0 und hoechstens 500 V. */
    public static function ValidVoltage(?float $v): bool
    {
        return $v !== null && is_finite($v) && $v > 0 && $v <= 500;
    }

    public static function ValidFrequency(?float $f): bool
    {
        return $f !== null && is_finite($f) && $f >= 45 && $f <= 55;
    }

    /** Koordinate auf die gewaehlte Genauigkeit runden (2 ≈ 1 km, 3 ≈ 100 m, 4 ≈ 10 m). */
    public static function Coordinate(float $value, int $digits): float
    {
        return round($value, max(2, min(6, $digits)));
    }

    public static function ValidLocation(float $lat, float $lon): bool
    {
        return is_finite($lat) && is_finite($lon) && $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180
            && !($lat == 0.0 && $lon == 0.0);
    }

    // ------------------------------------------------------------ Intervallstatistik

    /** @param array<string, array{0:float,1:float,2:float,3:int}> $stats */
    public static function AddSample(array $stats, string $key, float $value): array
    {
        if (!is_finite($value)) {
            return $stats;
        }
        [$min, $max, $sum, $n] = $stats[$key] ?? [$value, $value, 0.0, 0];
        $stats[$key] = [min($min, $value), max($max, $value), $sum + $value, $n + 1];
        return $stats;
    }

    /** @return array{min:float,max:float,avg:float}|null */
    public static function Summary(array $stats, string $key, callable $valid): ?array
    {
        $s = $stats[$key] ?? null;
        if (!is_array($s) || ($s[3] ?? 0) < 1) {
            return null;
        }
        $r = ['min' => round((float)$s[0], 3), 'max' => round((float)$s[1], 3), 'avg' => round((float)$s[2] / (int)$s[3], 3)];
        // avg darf durch Rundung nicht aus [min, max] fallen (die API prueft min <= avg <= max)
        $r['avg'] = max($r['min'], min($r['max'], $r['avg']));
        return $valid($r['min']) && $valid($r['max']) ? $r : null;
    }

    // ------------------------------------------------------------ Nutzlast

    /**
     * @param array{
     *   time:int, latitude:float, longitude:float, digits:int, version:string,
     *   phases:array<int, array{configured:bool, value:?float}>,
     *   frequency:?float, stats:array, minmax:bool,
     *   currents:array<int, ?float>, grid_power_w:?float, battery_soc:?float,
     *   pv_forecast_kwh:?float, plant_capacity_kwp:float,
     *   static:?array{plant_capacity_kwp:float, battery_capacity:float, smartmeter_model:string}
     * } $in
     * @return array{ok:bool, error:string, payload:array, notes:list<string>}
     */
    public static function Build(array $in): array
    {
        $notes = [];
        $fail = static fn (string $e): array => ['ok' => false, 'error' => $e, 'payload' => [], 'notes' => $notes];

        if (!self::ValidLocation((float)$in['latitude'], (float)$in['longitude'])) {
            return $fail('No valid location');
        }
        $p = [
            'observed_at' => gmdate('Y-m-d\TH:i:s\Z', (int)$in['time']),
            'latitude'    => self::Coordinate((float)$in['latitude'], (int)$in['digits']),
            'longitude'   => self::Coordinate((float)$in['longitude'], (int)$in['digits']),
        ];

        $measured = 0;
        foreach ([1, 2, 3] as $i) {
            $phase = $in['phases'][$i] ?? ['configured' => false, 'value' => null];
            if (!$phase['configured']) {
                $p['l' . $i . '_v'] = -1;
                continue;
            }
            // Eine eingerichtete Phase mit unplausiblem Wert wird nicht als "nicht gemessen" gemeldet: dann gar nicht senden
            if (!self::ValidVoltage($phase['value'])) {
                return $fail('Invalid voltage on L' . $i);
            }
            $p['l' . $i . '_v'] = round((float)$phase['value'], 2);
            $measured++;
        }
        if ($measured === 0) {
            return $fail('No voltage variable configured');
        }

        if (self::ValidFrequency($in['frequency'])) {
            $p['grid_frequency_hz'] = round((float)$in['frequency'], 3);
        } elseif ($in['frequency'] !== null) {
            $notes[] = 'Frequency out of range, omitted';
        }

        if ($in['minmax']) {
            foreach ([1, 2, 3] as $i) {
                if (!($in['phases'][$i]['configured'] ?? false)) {
                    continue;
                }
                $s = self::Summary($in['stats'], 'l' . $i, [self::class, 'ValidVoltage']);
                if ($s !== null) {
                    $p['l' . $i . '_v_min'] = $s['min'];
                    $p['l' . $i . '_v_max'] = $s['max'];
                    $p['l' . $i . '_v_avg'] = $s['avg'];
                }
            }
            if (isset($p['grid_frequency_hz'])) {
                $s = self::Summary($in['stats'], 'f', [self::class, 'ValidFrequency']);
                if ($s !== null) {
                    $p['grid_frequency_hz_min'] = $s['min'];
                    $p['grid_frequency_hz_max'] = $s['max'];
                    $p['grid_frequency_hz_avg'] = $s['avg'];
                }
            }
        }

        // Strom: Effektivwert, nie negativ. Vorzeichen eines Zaehlers zeigt nur die Richtung.
        foreach ([1, 2, 3] as $i) {
            $a = $in['currents'][$i] ?? null;
            if ($a !== null && is_finite($a)) {
                $p['l' . $i . '_a'] = round(abs($a), 3);
            }
        }
        if ($in['grid_power_w'] !== null && is_finite($in['grid_power_w'])) {
            $p['grid_power_w'] = round((float)$in['grid_power_w'], 1);
        }
        $soc = $in['battery_soc'];
        if ($soc !== null && is_finite($soc)) {
            if ($soc >= 0 && $soc <= 100) {
                $p['battery_soc_percent'] = round($soc, 1);
            } else {
                $notes[] = 'Battery SOC out of range, omitted';
            }
        }

        $kwp = (float)$in['plant_capacity_kwp'];
        $forecast = $in['pv_forecast_kwh'];
        if ($forecast !== null && is_finite($forecast)) {
            // Ohne bekannte Anlagengroesse oder ueber 10 kWh je kWp antwortet die API mit 422
            if ($kwp <= 0) {
                $notes[] = 'PV forecast needs the plant capacity, omitted';
            } elseif ($forecast < 0 || $forecast > 100000 || $forecast > 10 * $kwp) {
                $notes[] = 'PV forecast out of range, omitted';
            } else {
                $p['pv_forecast_kwh'] = round($forecast, 2);
            }
        }

        $static = $in['static'];
        if ($static !== null) {
            if ($static['plant_capacity_kwp'] > 0 && $static['plant_capacity_kwp'] <= 1000) {
                $p['plant_capacity_kwp'] = round($static['plant_capacity_kwp'], 3);
            }
            if ($static['battery_capacity'] > 0) {
                $p['battery_capacity'] = round($static['battery_capacity'], 3);
            }
            $model = trim($static['smartmeter_model']);
            if ($model !== '') {
                $p['smartmeter_model'] = mb_substr($model, 0, 120);
            }
        }
        $p['integration_version'] = substr($in['version'], 0, 32);

        return ['ok' => true, 'error' => '', 'payload' => $p, 'notes' => $notes];
    }

    /** Was in einer Messung nur bei Einrichtung, Aenderung und Neustart mitgeht. */
    public static function StaticHash(array $static, float $lat, float $lon): string
    {
        return md5(json_encode([$static, $lat, $lon]));
    }

    // ------------------------------------------------------------ Antwort

    /**
     * Antwort (202) in feste Werte: Ampel je Phase und gesamt, Speicherempfehlung, Redispatch.
     * Fehlende Felder (aeltere Server) und null werden UNKNOWN (-1), Zeitstempel 0.
     *
     * @return array{overall:int, l1:int, l2:int, l3:int, storage:int, redispatch:int, units:int, measures:int, updated:int}
     */
    public static function ParseResponse(mixed $json): array
    {
        $json = is_array($json) ? $json : [];
        $status = is_array($json['status'] ?? null) ? $json['status'] : [];
        $light = static fn (mixed $v): int => is_string($v) ? (self::LIGHT[$v] ?? self::UNKNOWN) : self::UNKNOWN;
        $r = is_array($json['redispatch'] ?? null) ? $json['redispatch'] : [];
        $count = static fn (mixed $v): int => is_int($v) && $v >= 0 ? $v : self::UNKNOWN;
        $updated = is_string($r['data_updated_at'] ?? null) ? strtotime($r['data_updated_at']) : false;
        return [
            'overall'    => $light($status['overall'] ?? null),
            'l1'         => $light($status['l1'] ?? null),
            'l2'         => $light($status['l2'] ?? null),
            'l3'         => $light($status['l3'] ?? null),
            'storage'    => is_string($json['storage_recommendation'] ?? null) ? (self::STORAGE[$json['storage_recommendation']] ?? self::UNKNOWN) : self::UNKNOWN,
            'redispatch' => is_bool($r['active'] ?? null) ? (int)$r['active'] : self::UNKNOWN,
            'units'      => $count($r['affected_units'] ?? null),
            'measures'   => $count($r['active_measures'] ?? null),
            'updated'    => $updated === false ? 0 : $updated,
        ];
    }

    /** Kurzer Fehlertext aus einer 422-Antwort (FastAPI-artig "detail" oder "error"/"message"). */
    public static function ErrorDetail(string $body): string
    {
        $json = json_decode($body, true);
        if (is_array($json)) {
            $d = $json['detail'] ?? $json['error'] ?? $json['message'] ?? null;
            if (is_string($d)) {
                return mb_substr($d, 0, 300);
            }
            if (is_array($d)) {
                $parts = [];
                foreach ($d as $e) {
                    if (is_array($e)) {
                        $loc = is_array($e['loc'] ?? null) ? implode('.', array_map('strval', $e['loc'])) : '';
                        $parts[] = trim($loc . ': ' . (string)($e['msg'] ?? ''), ': ');
                    } elseif (is_string($e)) {
                        $parts[] = $e;
                    }
                }
                return mb_substr(implode('; ', $parts), 0, 300);
            }
        }
        return mb_substr(trim(strip_tags($body)), 0, 300);
    }
}
