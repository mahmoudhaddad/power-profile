<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Computes hourly solar generation profiles.
 *
 * Primary data source: NASA POWER satellite-derived GHI (±3% accuracy).
 * Fallback data source: static PSH lookup table (±10-15% accuracy).
 *
 * The NASA POWER API is free, requires no key, and covers all coordinates
 * on Earth. Results are cached for 30 days since historical irradiance data
 * never changes.
 *
 * Static fallback algorithm:
 * 1. Spencer's equation → solar declination for mid-month day-of-year
 * 2. Hour-angle formula → local sunrise / sunset times (solar noon = 12:00)
 * 3. PSH lookup table (derived from NASA POWER / NREL TMY data) provides the
 *    total daily irradiance at the collector plane for each lat band and month.
 *    Southern-hemisphere months are season-flipped automatically.
 * 4. A sinusoidal bell curve distributes the PSH between sunrise and sunset.
 *    Its peak is set so the area under the curve equals capacity × PSH × PR.
 */
class SolarIrradianceService
{
    // ── Solar capacity estimation constants (shared with controllers) ────────
    // 17% roof coverage × 1 000 W/m² STC × 0.75 PR (rough sizing, not hourly calc).
    // The 0.75 PR here is intentionally lower than PERFORMANCE_RATIO (0.80) below,
    // which is the real PR used inside hourly profile computations.
    public const ROOF_COVERAGE_RATIO      = 0.17;    // usable roof fraction for PV panels
    public const STC_IRRADIANCE_W         = 1000.0;  // W/m² at Standard Test Conditions
    public const CAPACITY_ESTIMATE_PR     = 0.75;    // conservative PR for capacity sizing

    // ── NASA POWER API constants ──────────────────────────────────────────────
    private const NASA_API_BASE_URL  = 'https://power.larc.nasa.gov/api/temporal/hourly/point';
    private const NASA_TIMEOUT_SEC   = 10;
    private const NASA_NULL_VALUE    = -999;
    private const CACHE_DAYS         = 30;
    private const STC_IRRADIANCE     = self::STC_IRRADIANCE_W; // internal alias
    private const REPRESENTATIVE_DAY = 15;       // 15th of month as statistical midpoint

    /** Estimate installed PV capacity (W) from roof area using standard IEC sizing parameters. */
    public static function estimateCapacityW(float $areaM2): float
    {
        return $areaM2 * self::ROOF_COVERAGE_RATIO * self::STC_IRRADIANCE_W * self::CAPACITY_ESTIMATE_PR;
    }

    /** Tracks which data source was used in the last getHourlyOutputWatts() call. */
    private string $dataSource = 'static_lookup';

    public function getDataSource(): string
    {
        return $this->dataSource;
    }
    // Monthly average Peak Sun Hours (kWh/m²/day) by absolute-latitude band and month.
    // Rows: 0°, 10°, 20°, 30°, 40°, 50°, 60°
    // Cols: Jan, Feb, Mar, Apr, May, Jun, Jul, Aug, Sep, Oct, Nov, Dec
    // Derived from NASA POWER ALLSKY_SFC_SW_DWN monthly averages, TMY 1991-2020.
    private const PSH_TABLE = [
         0 => [5.5, 5.5, 5.5, 5.5, 5.5, 5.5, 5.5, 5.5, 5.5, 5.5, 5.5, 5.5],
        10 => [4.9, 5.3, 5.7, 6.1, 6.2, 6.1, 6.1, 6.1, 5.7, 5.3, 4.9, 4.7],
        20 => [4.1, 4.8, 5.7, 6.5, 6.9, 7.0, 6.9, 6.6, 5.8, 4.9, 4.0, 3.7],
        30 => [3.2, 4.1, 5.4, 6.6, 7.3, 7.7, 7.5, 6.9, 5.8, 4.6, 3.2, 2.8],
        40 => [2.0, 3.1, 4.8, 6.3, 7.5, 8.0, 7.7, 6.7, 5.3, 3.8, 2.2, 1.7],
        50 => [0.8, 1.9, 3.8, 5.7, 7.3, 8.0, 7.5, 6.1, 4.3, 2.7, 1.0, 0.5],
        60 => [0.0, 0.9, 2.7, 5.0, 7.0, 8.0, 7.2, 5.2, 3.1, 1.4, 0.1, 0.0],
    ];

    // Typical performance ratio: accounts for inverter losses, wiring, temperature de-rating, soiling.
    private const PERFORMANCE_RATIO = 0.80;

    /**
     * Returns a 24-element array (index = hour 0–23) of hourly solar output in watts.
     *
     * @param float $lat         Latitude in degrees (−90 to +90)
     * @param float $lng         Longitude in degrees (unused in generation model; kept for future API integration)
     * @param int   $month       Month number 1–12
     * @param float $capacityW   Installed system capacity in W (or VA — treated as W; PF ≈ 1 for inverters)
     */
    public function hourlyProfile(float $lat, float $lng, int $month, float $capacityW): array
    {
        if ($capacityW <= 0) return array_fill(0, 24, 0.0);

        ['sunrise' => $rise, 'sunset' => $set] = $this->sunriseSunset($lat, $month);

        if ($rise === null) return array_fill(0, 24, 0.0); // polar night
        if ($rise === 0.0 && $set === 24.0) {
            // Midnight sun: distribute flat from 0 to 24
            $peakW = $capacityW * self::PERFORMANCE_RATIO;
            return array_fill(0, 24, round($peakW * (M_PI / 4), 2)); // mean of sinusoid over full day
        }

        $daylightH = $set - $rise;
        $psh       = $this->interpolatePsh($lat, $month);

        // Peak output: set so that integral of sin(π·t/D) dt from 0 to D = D·2/π equals capacity·PSH·PR
        // → peakW = capacity · PSH · PR · π / (2 · daylightH)
        $peakW = $capacityW * $psh * self::PERFORMANCE_RATIO * M_PI / (2.0 * $daylightH);

        $profile = [];
        for ($h = 0; $h < 24; $h++) {
            $mid = $h + 0.5; // sample at midpoint of each hour
            if ($mid <= $rise || $mid >= $set) {
                $profile[$h] = 0.0;
            } else {
                $t            = ($mid - $rise) / $daylightH; // 0..1
                $profile[$h]  = round($peakW * sin(M_PI * $t), 2);
            }
        }

        return $profile;
    }

    /**
     * Returns sunrise and sunset as decimal hours (e.g. 6.5 = 06:30).
     * Solar noon is assumed at 12:00 local clock time.
     *
     * @return array{sunrise: float|null, sunset: float|null, polar_night?: bool, midnight_sun?: bool}
     */
    public function sunriseSunset(float $lat, int $month): array
    {
        $doy  = $this->midMonthDoy($month);
        $decl = 23.45 * sin(deg2rad(360.0 / 365.0 * ($doy - 81))); // solar declination °

        $cosHa = -tan(deg2rad($lat)) * tan(deg2rad($decl));

        if ($cosHa > 1.0) {
            return ['sunrise' => null, 'sunset' => null, 'polar_night' => true];
        }
        if ($cosHa < -1.0) {
            return ['sunrise' => 0.0, 'sunset' => 24.0, 'midnight_sun' => true];
        }

        $haD = rad2deg(acos($cosHa)); // hour angle in degrees
        return [
            'sunrise' => round(12.0 - $haD / 15.0, 2),
            'sunset'  => round(12.0 + $haD / 15.0, 2),
        ];
    }

    /**
     * Returns the interpolated peak-sun-hours for a given latitude and month.
     */
    public function peakSunHours(float $lat, int $month): float
    {
        return $this->interpolatePsh($lat, $month);
    }

    // ── private helpers ───────────────────────────────────────────────────────

    private function midMonthDoy(int $month): int
    {
        // Cumulative days before each month (non-leap year)
        static $cum = [0, 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        return $cum[$month] + 15;
    }

    private function interpolatePsh(float $lat, int $month): float
    {
        $mi     = $month - 1; // 0-indexed column
        $absLat = min(abs($lat), 60.0);

        // Southern hemisphere: flip season by 6 months
        if ($lat < 0) {
            $mi = ($mi + 6) % 12;
        }

        $lower = (int) (floor($absLat / 10.0) * 10);
        $upper = min($lower + 10, 60);

        $pshL = self::PSH_TABLE[$lower][$mi];
        $pshU = self::PSH_TABLE[$upper][$mi];

        if ($lower === $upper) return $pshL;

        $frac = ($absLat - $lower) / 10.0;
        return $pshL + $frac * ($pshU - $pshL);
    }

    // ── NASA POWER integration ────────────────────────────────────────────────

    /**
     * Returns 24-element float array (index = hour 0-23) of GHI in W/m²
     * for the 15th of the given month at the given coordinates.
     *
     * Returns [] on any failure so the caller can fall back to the static table.
     * Results are cached for 30 days — historical satellite data never changes.
     */
    private function fetchNasaHourlyGhi(?float $lat, ?float $lng, int $month, int $day = self::REPRESENTATIVE_DAY): array
    {
        // Cannot fetch without a valid location
        if ($lat === null || $lng === null) {
            return [];
        }

        // Round to 4 decimal places (~11 m precision) to keep cache keys stable
        $latR = round($lat, 4);
        $lngR = round($lng, 4);

        // Cache key includes day so each calendar date has its own entry
        $cacheKey = "nasa_ghi_{$latR}_{$lngR}_{$month}_{$day}";

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // NASA POWER ALLSKY_SFC_SW_DWN is a historical dataset; use 2023 as the
        // representative year (confirmed available). Using the current year
        // returns -999 null values for future/recent dates.
        $year = 2023;
        $mon  = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
        $dayS = str_pad((string) $day,   2, '0', STR_PAD_LEFT);
        $date = $year . $mon . $dayS;

        try {
            $response = Http::timeout(self::NASA_TIMEOUT_SEC)->get(self::NASA_API_BASE_URL, [
                'parameters'    => 'ALLSKY_SFC_SW_DWN',
                'community'     => 'RE',
                'longitude'     => $lngR,
                'latitude'      => $latR,
                'start'         => $date,
                'end'           => $date,
                'format'        => 'JSON',
                'time-standard' => 'LST',
            ]);

            if (! $response->successful()) {
                Log::warning('NASA POWER API returned non-200, falling back to static PSH table', [
                    'status' => $response->status(),
                    'lat'    => $latR,
                    'lng'    => $lngR,
                    'month'  => $month,
                ]);
                return [];
            }

            $data = $response->json();
            $raw  = $data['properties']['parameter']['ALLSKY_SFC_SW_DWN'] ?? [];

            if (empty($raw)) {
                Log::warning('NASA POWER API returned empty data, falling back to static PSH table', [
                    'lat' => $latR, 'lng' => $lngR, 'month' => $month,
                ]);
                return [];
            }

            $hourly = array_fill(0, 24, 0.0);
            foreach ($raw as $key => $value) {
                // Keys are 'YYYYMMDDHH' — extract the last two digits as hour
                $hour = (int) substr((string) $key, 8, 2);
                if ($hour >= 0 && $hour <= 23) {
                    // Replace NASA null indicator (-999) with 0
                    $hourly[$hour] = max(0.0, (float) $value === (float) self::NASA_NULL_VALUE ? 0.0 : (float) $value);
                }
            }

            // If all values are zero for a non-polar location it means NASA
            // returned -999 nulls (future/unavailable date). Fall back to the
            // static PSH table instead of caching bad zeros.
            $isPolarNight = abs($latR) >= 60 && in_array($month, [11, 12, 1, 2]);
            if (max($hourly) <= 0 && ! $isPolarNight) {
                Log::warning('NASA POWER returned all-zero GHI for non-polar location, using static fallback', [
                    'lat' => $latR, 'lng' => $lngR, 'month' => $month,
                ]);
                return [];
            }

            Cache::put($cacheKey, $hourly, now()->addDays(self::CACHE_DAYS));
            return $hourly;

        } catch (\Throwable $e) {
            Log::warning('NASA POWER fallback triggered', [
                'reason' => $e->getMessage(),
                'lat'    => $latR,
                'lng'    => $lngR,
                'month'  => $month,
            ]);
            return [];
        }
    }

    /**
     * Returns a 24-element array (index = hour 0-23) of hourly solar output in watts.
     *
     * Tries NASA POWER satellite data first; falls back to the static PSH table
     * if the API is unavailable. Check getDataSource() after calling to know
     * which path was taken.
     *
     * @param float|null $lat               Latitude (null → skip NASA, use static)
     * @param float|null $lng               Longitude (null → skip NASA, use static)
     * @param int        $month             Month number 1-12
     * @param float      $panelCapacityKw   Nameplate system capacity in kW
     * @param float      $performanceRatio  Accounts for inverter, wiring, and soiling losses
     */
    public function getHourlyOutputWatts(
        ?float $lat,
        ?float $lng,
        int    $month,
        float  $panelCapacityKw,
        float  $performanceRatio = self::PERFORMANCE_RATIO,
        int    $day              = self::REPRESENTATIVE_DAY
    ): array {
        if ($panelCapacityKw <= 0) {
            $this->dataSource = 'static_lookup';
            return array_fill(0, 24, 0.0);
        }

        $ghi = $this->fetchNasaHourlyGhi($lat, $lng, $month, $day);

        // ── NASA path ───────────────────────────────────────────────────────
        if (count($ghi) === 24) {
            $this->dataSource = 'nasa_power';
            $output = [];
            for ($h = 0; $h < 24; $h++) {
                // Linear scale: at STC (1000 W/m²) the panel runs at nameplate × PR
                $output[$h] = round(
                    ($ghi[$h] / self::STC_IRRADIANCE) * ($panelCapacityKw * 1000) * $performanceRatio,
                    2
                );
            }
            return $output;
        }

        // ── Static fallback ─────────────────────────────────────────────────
        // hourlyProfile() uses the same PERFORMANCE_RATIO constant (0.80).
        // If a custom ratio was passed we scale proportionally.
        $this->dataSource = 'static_lookup';
        $static = $this->hourlyProfile($lat ?? 0.0, $lng ?? 0.0, $month, $panelCapacityKw * 1000);

        if ($performanceRatio !== self::PERFORMANCE_RATIO && self::PERFORMANCE_RATIO > 0) {
            $scale = $performanceRatio / self::PERFORMANCE_RATIO;
            $static = array_map(fn($w) => round($w * $scale, 2), $static);
        }

        return $static;
    }
}
