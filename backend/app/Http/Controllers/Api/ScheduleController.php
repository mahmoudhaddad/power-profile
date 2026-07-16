<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\DiversityFactorService;
use App\Services\ElectricalDesignService;
use App\Services\LoadSheddingService;
use App\Services\SocketDemandService;
use App\Services\SolarIrradianceService;
use App\Services\SourceDispatchService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ScheduleController extends Controller
{
    private const DEFAULT_WORK_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

    // IEC 60364-8-1 project-level diversity factor (building → project).
    // Floor/room-level DFs are now per-building-type via DiversityFactorService.
    private const DF_PROJECT = 0.7;

    // Socket circuit capacity cap: 16 A breaker × 230 V × 0.80 loading factor = 2 944 W.
    // Matches ElectricalDesignService::packSocketCircuits() sizing (Section 8.5.7).
    // Controlled socket demand is split into sub-slots of at most this size so that
    // LoadSheddingService can restore individual circuits instead of an all-or-nothing block.
    private const SOCKET_CIRCUIT_CAP_W = 2944.0;

    // Fraction of socket-outlet demand that remains energised 24/7, by building type.
    // Represents standby loads (chargers, idle monitors, clock radios, etc.).
    // Real always-on equipment (servers, UPS, network gear) is already modelled as
    // priority=critical RoomComponents and must NOT be double-counted here.
    // The remainder (1 − fraction) is the controlled portion, active only during
    // scheduled occupancy hours (ASHRAE 90.1 §8.4.2).
    //
    // These are reasoned, standards-referenced estimates — NOT a single universal
    // published survey table.  Real-world values vary by building and should be
    // revisited when better locally-monitored sub-metering data becomes available.
    //   ASHRAE RP-1093/1742 → office/general baseline (0.15)
    //   ASHRAE 90.1 §8.4.2  → educational automatic receptacle shutoff mandate (0.07)
    //   NEC 517 / NFPA 99   → hospital essential electrical system 24/7 mandate (0.45)
    //   CIBSE TM22           → residential overnight occupancy profiles (0.25)
    private const SOCKET_UNCONTROLLED_FRACTION_BY_TYPE = [
        'residential_house'      => 0.25, // occupied overnight; CIBSE TM22
        'residential_apartment'  => 0.25, // occupied overnight; CIBSE TM22
        'office'                 => 0.15, // ASHRAE RP-1093 general case
        'educational_school'     => 0.07, // ASHRAE 90.1 §8.4.2 auto-shutoff mandate
        'educational_university' => 0.07, // ASHRAE 90.1 §8.4.2 minimal overnight presence
        'hospital'               => 0.45, // NEC 517 / NFPA 99 essential electrical system
        'retail'                 => 0.10, // closes overnight
        'industrial'             => 0.15, // default; refine with shift-pattern data if available
        'default'                => 0.15, // unknown / null type — conservative middle ground
    ];

    private const MONTH_NAMES = [
        1 => 'January',  2 => 'February',  3 => 'March',    4 => 'April',
        5 => 'May',      6 => 'June',       7 => 'July',     8 => 'August',
        9 => 'September',10 => 'October',  11 => 'November',12 => 'December',
    ];

    public function __construct(
        private SolarIrradianceService  $solarSvc,
        private SourceDispatchService   $dispatchSvc,
        private LoadSheddingService     $sheddingSvc,
        private SocketDemandService     $socketSvc,
        private ElectricalDesignService $designSvc,
    ) {}

    private const ALL_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * GET /api/projects/{project}/schedule
     * Query params:
     *   month = 1-12  (default: current month)
     *   day   = 1-31  (default: 15 — selects the solar irradiance day)
     *
     * Returns per-day load profiles for every day of the week so the frontend
     * can show the exact schedule for any picked calendar date without a round-trip.
     */
    public function project(Request $request, Project $project)
    {
        $this->authorize('view', $project);
        if (! $project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $month  = max(1, min(12, (int) $request->query('month', now()->month)));
        $dayNum = max(1, min(31, (int) $request->query('day', 15)));
        $dayNum = min($dayNum, (int) date('t', mktime(0, 0, 0, $month, 1, 2023)));

        // ── 1. Collect all components with schedule metadata ──────────────────
        $components = $this->collectComponents($project);

        // ── 2. Solar capacity ─────────────────────────────────────────────────
        // Max Available: always area-based (roof estimate) — unchanged.
        // Existing System: sum of named solar systems when defined, otherwise
        //   the legacy single existing_solar_power value.
        $solarSystems = $project->solarSystems()->where('is_active', true)->get();
        $solarMode    = $project->solar_source ?? 'max';

        if ($solarMode === 'existing') {
            if ($solarSystems->isNotEmpty()) {
                $solarCapacityW = $solarSystems->sum('capacity_kw') * 1000.0;
            } else {
                $solarCapacityW = (float) ($project->existing_solar_power ?? 0)
                                + (float) $project->buildings()->sum('existing_solar_power');
            }
        } else {
            // 'max' — roof area estimate, exactly as before
            $totalAreaM2    = (float) $project->buildings()->sum('area');
            $solarCapacityW = SolarIrradianceService::estimateCapacityW($totalAreaM2);
        }

        $lat = $project->location_lat !== null ? (float) $project->location_lat : null;
        $lng = $project->location_lng !== null ? (float) $project->location_lng : null;

        $solarProfile = $this->solarSvc->getHourlyOutputWatts(
            $lat, $lng, $month, $solarCapacityW / 1000.0,
            performanceRatio: 0.80, day: $dayNum
        );

        $sunInfo = $lat !== null ? $this->solarSvc->sunriseSunset($lat, $month)
                                 : ['sunrise' => null, 'sunset' => null];
        $psh     = $lat !== null ? round($this->solarSvc->peakSunHours($lat, $month), 2) : null;

        // ── 3. Source capacities ──────────────────────────────────────────────
        $utilCapVA = (float) $project->utilityLines()->sum('power');
        $genCapVA  = (float) $project->generatorLines()->sum('power');
        $utilCapW  = $utilCapVA * 0.8;
        $genCapW   = $genCapVA  * 0.8;

        // Read per-generator wet-stack floor (Caterpillar: 30 % min to prevent wet-stacking).
        // Uses the first generator line's min_load_pct; falls back to 30 % when null.
        $genFloorLine  = $genCapVA > 0 ? $project->generatorLines()->first() : null;
        $genMinLoadPct = ($genFloorLine && $genFloorLine->min_load_pct !== null)
            ? (float) $genFloorLine->min_load_pct / 100.0
            : 0.30;

        // Guard: return a structured error if the project has no power sources at all
        $hasSolarSystems = $project->solarSystems()->where('is_active', true)->exists();
        $hasBatteries    = $project->batteries()->where('is_active', true)->exists();
        if ($utilCapW <= 0 && $genCapW <= 0 && $solarCapacityW <= 0 && !$hasSolarSystems && !$hasBatteries) {
            return response()->json([
                'error'   => 'no_sources',
                'message' => 'No power sources are configured for this project. '
                           . 'Please add a utility line, generator, or solar system first.',
            ], 422);
        }

        // ── Cost rates (for daily cost display in the UI) ─────────────────────
        $utilLine = $project->utilityLines()->whereNotNull('tariff_per_kwh')->orderBy('id')->first();
        $tariff      = $utilLine ? (float) $utilLine->tariff_per_kwh : null;
        $peakTariff  = ($utilLine && $utilLine->peak_tariff_per_kwh) ? (float) $utilLine->peak_tariff_per_kwh : null;
        $peakStart   = $utilLine ? (int) ($utilLine->peak_hours_start ?? 0) : null;
        $peakEnd     = $utilLine ? (int) ($utilLine->peak_hours_end   ?? 0) : null;

        $genLine = $project->generatorLines()
            ->whereNotNull('fuel_cost_per_liter')->whereNotNull('fuel_consumption_lph')
            ->where('fuel_cost_per_liter', '>', 0)->where('fuel_consumption_lph', '>', 0)
            ->orderBy('id')->first();
        $genCostPerKwh = null;
        if ($genLine && (float) $genLine->power > 0) {
            $genCostPerKwh = round(
                (float) $genLine->fuel_cost_per_liter * (float) $genLine->fuel_consumption_lph
                / ((float) $genLine->power / 1000.0), 4
            );
        }

        // Include raw generator parameters so the frontend can compute affine
        // hourly fuel cost (F(P) = F₀ + (F_rated-F₀)×P/P_rated) instead of
        // a flat kWh rate, which would be inaccurate at part-load conditions.
        $costRates = [
            'tariff_per_kwh'           => $tariff,
            'peak_tariff_per_kwh'      => $peakTariff,
            'peak_hours_start'         => $peakStart,
            'peak_hours_end'           => $peakEnd,
            'generator_cost_per_kwh'   => $genCostPerKwh,          // rated (100 % load) — kept for fallback
            'generator_rated_kw'       => $genLine ? round((float) $genLine->power / 1000.0, 3) : null,
            'generator_rated_lph'      => $genLine ? (float) $genLine->fuel_consumption_lph : null,
            'generator_no_load_lph'    => $genLine ? ($genLine->no_load_fuel_lph
                                              ?? round((float) $genLine->fuel_consumption_lph * 0.30, 4))
                                              : null,
            'fuel_cost_per_liter'      => $genLine ? (float) $genLine->fuel_cost_per_liter : null,
            'currency_symbol'          => $project->currency_symbol ?? '$',
        ];

        // ── Cost-Priority restoration opts ────────────────────────────────────
        // Non-empty only when the project has a generator with fuel data; the shedding
        // service activates its cost/capacity gates when this array is passed in.
        $costOpts = ($genLine !== null) ? [
            'gen_cap_w'     => $genCapW,
            'f_rated_lph'   => (float) $genLine->fuel_consumption_lph,
            'f_no_load_lph' => $genLine->no_load_fuel_lph !== null
                                   ? (float) $genLine->no_load_fuel_lph
                                   : round((float) $genLine->fuel_consumption_lph * 0.30, 4),
            'fuel_price'    => (float) $genLine->fuel_cost_per_liter,
            'solar_w'       => $solarProfile,
            'util_cap_w'    => $utilCapW,
        ] : [];

        // ── 4. Active batteries (fetched once, used in every day simulation) ───
        $batteries = $project->batteries()->where('is_active', true)->get();
        $battPass  = $batteries->isNotEmpty() ? $batteries : null;

        // ── 4b. Shift-cap for shedding pre-pass (solar + grid only) ─────────────
        // shiftCapW prevents shiftable loads from being moved to dark generator/battery-backed
        // hours.  The per-hour unmet deficit that drives shedding now comes from a real
        // SOC-tracked dispatch pre-pass instead of a peak-discharge-power estimate.
        $shiftCapW = array_map(
            fn($solar) => $solar + $utilCapW,   // solar + grid only — no battery, no generator
            $solarProfile
        );

        $solarSystemsArg = $solarSystems->isNotEmpty() ? $solarSystems : null;

        // Socket demand queried once here; reused for all 7 days inside the loop.
        $socketResult = $this->socketSvc->projectResult($project);

        // Real socket-model circuit list — assigns locatable panel IDs to dispatch slots.
        // Queried once (circuits are day-invariant) and passed into every buildSocketSlots call.
        $socketCircuits = $this->designSvc->socketCircuitIndex($project);

        // ── 5. Per-day profiles: one entry per day of the week ────────────────
        // Pipeline (two dispatch passes per mode):
        //   a) Build original load profiles.
        //   b) Raw dispatch pass on the unshed load — energy-aware, SOC-tracked.
        //      Its per-hour unmet array is the REAL deficit signal for shedding.
        //   c) Shedding pass — responds to genuine energy exhaustion, not peak-power estimate.
        //   d) Post-shed dispatch pass — final result shown in the UI.
        // Both raw and post-shed results are returned so the UI can show a Before/After toggle.
        $days = [];
        foreach (self::ALL_DAYS as $dayName) {
            $slotsMax = $this->buildComponentSlots($components, 'max',       $dayName, $month);
            $slotsOpt = $this->buildComponentSlots($components, 'optimized', $dayName, $month);

            $loadMax = $this->buildHourlyW($components, 'max',       $dayName, $month, false);
            $loadOpt = $this->buildHourlyW($components, 'optimized', $dayName, $month, true);

            // Merge socket outlet contributions (NEC 220.14 / ASHRAE 90.1 §8.4.2).
            // Socket slots use the same max/opt split as the load profiles so that
            // the shedding pass sees a consistent view of what is sheddable.
            $socketMax      = $this->buildSocketProfile($socketResult, $project, $dayName, $month, true);
            $socketOpt      = $this->buildSocketProfile($socketResult, $project, $dayName, $month, false);
            $socketSlotsMax = $this->buildSocketSlots($socketResult, $project, $dayName, $month, true,  $socketCircuits);
            $socketSlotsOpt = $this->buildSocketSlots($socketResult, $project, $dayName, $month, false, $socketCircuits);
            for ($h = 0; $h < 24; $h++) {
                $loadMax[$h] = round($loadMax[$h] + $socketMax[$h], 2);
                $loadOpt[$h] = round($loadOpt[$h] + $socketOpt[$h], 2);
            }
            $slotsMax = array_merge($slotsMax, $socketSlotsMax);
            $slotsOpt = array_merge($slotsOpt, $socketSlotsOpt);

            // (b) Raw dispatch — pre-shedding, energy-aware
            $rawDispatchMax = $this->dispatchSvc->dispatch($loadMax, $solarProfile, $utilCapW, $genCapW, $battPass, $solarCapacityW, $solarSystemsArg, $genMinLoadPct);
            $rawDispatchOpt = $this->dispatchSvc->dispatch($loadOpt, $solarProfile, $utilCapW, $genCapW, $battPass, $solarCapacityW, $solarSystemsArg, $genMinLoadPct);

            // (c) Shedding driven by real per-hour unmet from the raw dispatch pass
            $shedMax = $this->sheddingSvc->shed($slotsMax, $rawDispatchMax['unmet'], $shiftCapW);
            $shedOpt = $this->sheddingSvc->shed($slotsOpt, $rawDispatchOpt['unmet'], $shiftCapW);

            // (c') Cost-Priority shed pass — withholds restoration of non-essential loads
            // when restoring would place the generator in its inefficient light-load zone
            // (avg fuel cost/kWh > 1.2× optimal).  The shedding signal is the same real
            // rawUnmetW used by the SP pass: Cost-Priority NEVER proactively sheds
            // currently-served load with zero deficit.  Only restoration is gated.
            if (!empty($costOpts)) {
                $shedCostOpt = $this->sheddingSvc->shed($slotsOpt, $rawDispatchOpt['unmet'], $shiftCapW, $costOpts);
            } else {
                $shedCostOpt = null;
            }

            // (d) Post-shed dispatch — the final, shedding-adjusted result
            $postDispatchMax = $this->dispatchSvc->dispatch($shedMax['adjusted_load_w'], $solarProfile, $utilCapW, $genCapW, $battPass, $solarCapacityW, $solarSystemsArg, $genMinLoadPct);
            $postDispatchOpt = $this->dispatchSvc->dispatch($shedOpt['adjusted_load_w'], $solarProfile, $utilCapW, $genCapW, $battPass, $solarCapacityW, $solarSystemsArg, $genMinLoadPct);

            // (d') Cost-Priority post-shed dispatch
            $postDispatchCostOpt = $shedCostOpt !== null
                ? $this->dispatchSvc->dispatch($shedCostOpt['adjusted_load_w'], $solarProfile, $utilCapW, $genCapW, $battPass, $solarCapacityW, $solarSystemsArg, $genMinLoadPct)
                : null;

            $days[$dayName] = [
                'load_max'                   => $loadMax,
                'load_optimized'             => $loadOpt,
                'load_shed_max'              => $shedMax['adjusted_load_w'],
                'load_shed_optimized'        => $shedOpt['adjusted_load_w'],
                'hourly_kvar'                => $this->buildHourlyKvar($components, $dayName, $month),
                // Raw (pre-shedding) dispatch results
                'dispatch_raw_max'           => $rawDispatchMax,
                'dispatch_raw_optimized'     => $rawDispatchOpt,
                // Post-shedding dispatch results (used for final energy/cost accounting)
                'dispatch_max'               => $postDispatchMax,
                'dispatch_optimized'         => $postDispatchOpt,
                'shedding_max'               => $this->shedSummary($shedMax),
                'shedding_optimized'         => $this->shedSummary($shedOpt),
                // Cost-Priority results (null when no generator fuel data)
                'dispatch_cost_optimized'    => $postDispatchCostOpt,
                'shedding_cost_optimized'    => $shedCostOpt !== null ? $this->shedSummary($shedCostOpt) : null,
            ];
        }

        // ── 6. Battery summary ────────────────────────────────────────────────
        if ($batteries->isNotEmpty()) {
            $totalUsableKwh = $batteries->sum(fn($b) => $b->usable_capacity_kwh);
            $avgAge = $batteries->reduce(
                fn($acc, $b) => $acc + $b->age_years * $b->usable_capacity_kwh,
                0.0
            ) / max(0.001, $totalUsableKwh);

            $batterySummary = [
                'bank_count'        => $batteries->count(),
                'total_nominal_kwh' => round($batteries->sum(fn($b) => $b->nominal_capacity_kwh), 2),
                'total_usable_kwh'  => round($totalUsableKwh, 2),
                'average_age_years' => round($avgAge, 2),
                'chemistries'       => $batteries->pluck('chemistry')->unique()->values()->toArray(),
            ];
        } else {
            $batterySummary = null;
        }

        return response()->json([
            'month'                 => $month,
            'month_name'           => self::MONTH_NAMES[$month],
            'day'                   => $dayNum,
            'location'              => ['lat' => $lat, 'lng' => $lng, 'name' => $project->location_name],
            'sunrise_hour'          => $sunInfo['sunrise'] ?? null,
            'sunset_hour'           => $sunInfo['sunset']  ?? null,
            'peak_sun_hours'        => $psh,
            'solar_capacity_w'      => $solarCapacityW,
            'solar_data_source'     => $this->solarSvc->getDataSource(),
            'utility_capacity_va'   => $utilCapVA,
            'generator_capacity_va' => $genCapVA,
            'solar'                 => $solarProfile,
            'solar_systems'         => $solarSystems->values(),
            'battery_summary'       => $batterySummary,
            'cost_rates'            => $costRates,
            'days'                  => $days,
        ]);
    }

    // ── Component collection (mirrors LoadProfileController logic) ────────────

    private function collectComponents(Project $project): array
    {
        $pDays    = $project->work_days;
        $pSeasons = $project->working_season_intervals;
        $result   = [];

        // Project-own components: no diversity reduction at their own level.
        $this->extractRaw(
            $result,
            $project->components()->with('componentType')->get(),
            'project_id', $pDays, $pSeasons, 1.0, ''
        );

        // Eager load all project data in one query set to prevent N+1 problems.
        // Without this, a project with 10 buildings × 5 floors × 10 rooms
        // would generate 500+ individual database queries per request.
        $buildings = $project->buildings()->with([
            'components.componentType',
            'floors.components.componentType',
            'floors.rooms.components.componentType',
        ])->get();

        foreach ($buildings as $building) {
            $bDays    = $building->work_days    ?? $pDays;
            $bSeasons = $building->working_season_intervals ?? $pSeasons;
            $bDfs     = DiversityFactorService::buildingDfs($building->type ?? null);
            $bLabel   = "Bldg {$building->name}";

            $this->extractRaw($result, $building->components, 'building_id', $bDays, $bSeasons,
                self::DF_PROJECT, $bLabel);

            foreach ($building->getRelation('floors') as $floor) {
                $fDays    = $floor->work_days    ?? $bDays;
                $fSeasons = $floor->working_season_intervals ?? $bSeasons;
                $fLabel   = "Bldg {$building->name}, " . ucfirst($floor->name) . ' floor';

                $this->extractRaw($result, $floor->components, 'floor_id', $fDays, $fSeasons,
                    $bDfs['floor_to_building'] * self::DF_PROJECT, $fLabel);

                foreach ($floor->rooms as $room) {
                    $rDays    = $room->work_days    ?? $fDays;
                    $rSeasons = $room->working_season_intervals ?? $fSeasons;
                    $roomDf   = DiversityFactorService::roomDf($room->type ?? null);
                    $rLabel   = "Bldg {$building->name}, " . ucfirst($floor->name) . " floor, {$room->name}";

                    $this->extractRaw($result, $room->components, 'room_id', $rDays, $rSeasons,
                        $roomDf * $bDfs['room_to_floor'] * $bDfs['floor_to_building'] * self::DF_PROJECT,
                        $rLabel);
                }
            }
        }

        return $result;
    }

    private function extractRaw(array &$out, $components, string $key, ?array $workDays, ?array $seasons, float $df = 1.0, string $location = ''): void
    {
        foreach ($components as $c) {
            $pf       = max(0.01, (float) ($c->power_factor ?? 1));
            $va       = (float) $c->power * (int) $c->quantity;
            $typeName = $c->componentType?->name ?? "Component #{$c->component_type_id}";
            $qty      = (int) $c->quantity;
            $pwStr    = number_format((float) $c->power) . ' W';
            $qtyStr   = $qty > 1 ? "{$qty} × {$pwStr}" : $pwStr;
            $locPart  = $location !== '' ? " — {$location}" : '';
            $out[] = [
                'va'               => $va,
                'peak_w'           => $va * $pf,
                'df'               => $df,
                'pf'               => $pf,
                'intervals'        => $c->usage_time_intervals ?? [['start' => '08:00', 'end' => '18:00']],
                'season'           => $c->usage_season   ?? 'all',
                'day_type'         => $c->usage_day_type ?? 'all',
                'priority'         => $c->priority       ?? 'normal',
                'group_key'        => $c->group_name ? ($key . '|' . $c->{$key} . '|' . $c->group_name) : null,
                'work_days'        => $workDays,
                'seasons'          => $seasons,
                // Demand-side shedding fields
                'load_flexibility' => $c->load_flexibility     ?? 'fixed',
                'curtail_min_pct'  => (int) ($c->curtail_min_pct      ?? 0),
                'earliest_start'   => (int) ($c->earliest_start_hour  ?? 0),
                'latest_end'       => (int) ($c->latest_end_hour      ?? 24),
                'required_run_h'   => (int) ($c->required_run_hours   ?? 0),
                'label'            => "{$typeName}{$locPart} | {$qtyStr}",
            ];
        }
    }

    /**
     * Build per-component slots with pre-computed active_hours[24] arrays.
     * Used by LoadSheddingService to perform component-level shedding/shifting.
     *
     * Applies the same group-max and season/day-type filtering as buildHourlyW()
     * so the shedding pre-pass sees exactly the same set of loads as dispatch.
     */
    private function buildComponentSlots(array $components, string $mode, string $dayName, int $month): array
    {
        // Apply group-max in optimized mode (mirrors buildHourlyW logic)
        if ($mode === 'optimized') {
            $groups    = [];
            $ungrouped = [];
            foreach ($components as $c) {
                if ($c['group_key'] === null) {
                    $ungrouped[] = $c;
                } else {
                    if (!isset($groups[$c['group_key']]) || $c['va'] > $groups[$c['group_key']]['va']) {
                        $groups[$c['group_key']] = $c;
                    }
                }
            }
            $components = array_merge($ungrouped, array_values($groups));
        }

        $applyDiversity = $mode === 'optimized';
        $slots          = [];

        foreach ($components as $c) {
            $isCritical = ($c['priority'] === 'critical');

            // Apply the same season/day-type filters as buildHourlyW
            if (!$isCritical) {
                if (!$this->activeInMonth($c['seasons'], $month))                  continue;
                if (!$this->componentSeasonOk($c['season'], $month))               continue;
                if (!$this->dayTypeOk($c['work_days'], $c['day_type'], $dayName))  continue;
            }

            $effectiveDf = ($applyDiversity && !$isCritical) ? (float) $c['df'] : 1.0;
            $peakW       = $c['peak_w'] * $effectiveDf;
            if ($peakW <= 0) continue;

            // Compute active_hours using the same interval arithmetic as buildHourlyW
            $active = array_fill(0, 24, false);
            if ($isCritical) {
                $active = array_fill(0, 24, true);
            } else {
                foreach ($c['intervals'] as $iv) {
                    $start = $this->dec($iv['start'] ?? '00:00');
                    $end   = $this->dec($iv['end']   ?? '23:59');
                    if ($end <= $start) $end += 24;
                    for ($h = 0; $h < 24; $h++) {
                        $mid = $h + 0.5;
                        if (($mid >= $start && $mid < $end) ||
                            ($end > 24 && ($mid + 24) >= $start && ($mid + 24) < $end)) {
                            $active[$h] = true;
                        }
                    }
                }
            }

            if (!in_array(true, $active, true)) continue;

            $slots[] = [
                'peak_w'          => $peakW,
                'priority'        => $c['priority'],
                'load_flexibility'=> $c['load_flexibility'],
                'active_hours'    => $active,
                'curtail_min_pct' => (float) $c['curtail_min_pct'],
                'earliest_start'  => $c['earliest_start'],
                'latest_end'      => $c['latest_end'],
                'required_run_h'  => $c['required_run_h'],
                'label'           => $c['label'],
            ];
        }

        return $slots;
    }

    // ── 24-hour profile builder ───────────────────────────────────────────────

    private function buildHourlyW(array $components, string $mode, string $dayName, int $month, bool $applyDiversity = false): array
    {
        if ($mode === 'optimized') {
            // Group-max: keep only the highest-VA component per group.
            $groups    = [];
            $ungrouped = [];
            foreach ($components as $c) {
                if ($c['group_key'] === null) {
                    $ungrouped[] = $c;
                } else {
                    if (! isset($groups[$c['group_key']]) || $c['va'] > $groups[$c['group_key']]['va']) {
                        $groups[$c['group_key']] = $c;
                    }
                }
            }
            $components = array_merge($ungrouped, array_values($groups));
        }

        $profile = array_fill(0, 24, 0.0);

        foreach ($components as $c) {
            $isCritical  = ($c['priority'] === 'critical');
            $effectiveDf = ($applyDiversity && ! $isCritical) ? (float) $c['df'] : 1.0;
            $peakW       = $c['peak_w'] * $effectiveDf;

            if ($isCritical) {
                for ($h = 0; $h < 24; $h++) { $profile[$h] += $peakW; }
                continue;
            }

            if (! $this->activeInMonth($c['seasons'], $month))            continue;
            if (! $this->componentSeasonOk($c['season'], $month))         continue;
            if (! $this->dayTypeOk($c['work_days'], $c['day_type'], $dayName)) continue;

            foreach ($c['intervals'] as $iv) {
                $start = $this->dec($iv['start'] ?? '00:00');
                $end   = $this->dec($iv['end']   ?? '23:59');
                if ($end <= $start) $end += 24;

                for ($h = 0; $h < 24; $h++) {
                    $mid = $h + 0.5;
                    if ($mid >= $start && $mid < $end) {
                        $profile[$h] += $peakW;
                    } elseif ($end > 24 && ($mid + 24) >= $start && ($mid + 24) < $end) {
                        $profile[$h] += $peakW;
                    }
                }
            }
        }

        return array_map(fn($v) => round($v, 2), $profile);
    }

    // ── Schedule filter helpers ───────────────────────────────────────────────

    private function activeInMonth(?array $seasonIntervals, int $month): bool
    {
        if (empty($seasonIntervals)) return true;

        $curOrd = $month * 100 + 15; // mid-month ordinal
        foreach ($seasonIntervals as $iv) {
            [$fm, $fd] = array_map('intval', explode('-', $iv['from'] ?? '01-01'));
            [$tm, $td] = array_map('intval', explode('-', $iv['to']   ?? '12-31'));
            $fromOrd = $fm * 100 + $fd;
            $toOrd   = $tm * 100 + $td;

            if ($fromOrd <= $toOrd) {
                if ($curOrd >= $fromOrd && $curOrd <= $toOrd) return true;
            } else {
                if ($curOrd >= $fromOrd || $curOrd <= $toOrd) return true;
            }
        }
        return false;
    }

    private function componentSeasonOk(string $usageSeason, int $month): bool
    {
        if ($usageSeason === 'all') return true;
        $s = match (true) {
            in_array($month, [3, 4, 5])   => 'spring',
            in_array($month, [6, 7, 8])   => 'summer',
            in_array($month, [9, 10, 11]) => 'autumn',
            default                       => 'winter',
        };
        return $usageSeason === $s;
    }

    /**
     * Determine whether a component is active on a specific day of the week.
     *
     * $actualDayName: 'monday' | 'tuesday' | … | 'sunday'
     * $compDayType:   'weekday' | 'weekend' | 'all'   (stored on the component)
     * $workDays:      the entity's configured work days (null → project default Mon-Fri)
     *
     * Logic:
     *  - 'weekday'  → runs only on days that ARE in the entity's work_days
     *  - 'weekend'  → runs only on days that are NOT in the entity's work_days
     *  - 'all'      → follows the entity's work_days (same as 'weekday')
     */
    private function dayTypeOk(?array $workDays, string $compDayType, string $actualDayName): bool
    {
        $effectiveWorkDays = $workDays ?? self::DEFAULT_WORK_DAYS;
        $isWorkday         = in_array($actualDayName, $effectiveWorkDays, true);

        if ($compDayType === 'weekend') return ! $isWorkday;

        // 'weekday', 'workday' (legacy), 'all' → active only on entity's work days
        return $isWorkday;
    }

    private function dec(string $t): float
    {
        [$h, $m] = array_map('intval', explode(':', $t));
        return $h + $m / 60.0;
    }

    private function buildHourlyKvar(array $components, string $dayName, int $month): array
    {
        $hourlyQ = array_fill(0, 24, 0.0);

        foreach ($components as $c) {
            $peakW = (float) $c['peak_w'];
            if ($peakW <= 0) continue;
            $pf = max(0.01, min(1.0, (float) ($c['pf'] ?? 1.0)));
            if ($pf >= 1.0) continue;

            $isCritical  = ($c['priority'] === 'critical');
            $effectiveDf = $isCritical ? 1.0 : (float) $c['df'];
            $qi          = $peakW * $effectiveDf * tan(acos($pf));

            if ($isCritical) {
                for ($h = 0; $h < 24; $h++) { $hourlyQ[$h] += $qi; }
                continue;
            }

            if (! $this->activeInMonth($c['seasons'], $month))                  continue;
            if (! $this->componentSeasonOk($c['season'], $month))               continue;
            if (! $this->dayTypeOk($c['work_days'], $c['day_type'], $dayName))  continue;

            foreach ($c['intervals'] as $iv) {
                $start = $this->dec($iv['start'] ?? '00:00');
                $end   = $this->dec($iv['end']   ?? '23:59');
                if ($end <= $start) $end += 24;

                for ($h = 0; $h < 24; $h++) {
                    $mid = $h + 0.5;
                    if (($mid >= $start && $mid < $end) ||
                        ($end > 24 && ($mid + 24) >= $start && ($mid + 24) < $end)) {
                        $hourlyQ[$h] += $qi;
                    }
                }
            }
        }

        $result = [];
        for ($h = 0; $h < 24; $h++) {
            $result[] = round($hourlyQ[$h] / 1000, 2);
        }
        return $result;
    }

    // ── Socket outlet load profile helpers ───────────────────────────────────

    /**
     * Return the standby fraction for this project's building type.
     *
     * Resolution: project.building_type → first building.type → 'default'.
     * Matches the same cascade used by DiversityFactorService::buildingDfs().
     */
    private function socketUncontrolledFraction(object $project): float
    {
        $type = !empty($project->building_type) ? $project->building_type : null;
        if ($type === null) {
            foreach (($project->buildings ?? []) as $building) {
                if (!empty($building->type)) {
                    $type = $building->type;
                    break;
                }
            }
        }
        return self::SOCKET_UNCONTROLLED_FRACTION_BY_TYPE[$type]
            ?? self::SOCKET_UNCONTROLLED_FRACTION_BY_TYPE['default'];
    }

    /**
     * Resolve the effective work-time intervals for socket scheduling.
     *
     * Cascade: project.work_time_intervals → first building's work_time_intervals
     * → default 08:00–17:00.  Full per-building decomposition (applying each
     * building's schedule to its own socket outlets separately) is a larger refactor
     * deferred to a follow-up; this project-level fallback covers the common case.
     *
     * Default aligns with the frontend constant DEFAULT_TIME_INTERVALS (08:00–17:00).
     */
    private function resolveWorkIntervals(object $project): array
    {
        if (!empty($project->work_time_intervals)) {
            return $project->work_time_intervals;
        }
        // Check first building with a non-empty value (project → building fallback).
        // Full per-building cascade (decomposing aggregate socket demand per building)
        // is deferred as a follow-up.
        foreach (($project->buildings ?? []) as $building) {
            if (!empty($building->work_time_intervals)) {
                return $building->work_time_intervals;
            }
        }
        // Default matches the frontend constant DEFAULT_TIME_INTERVALS (08:00–17:00).
        return [['start' => '08:00', 'end' => '17:00']];
    }

    /**
     * Build a 24-hour socket-outlet load profile for one day.
     *
     * PF = 1.0: The Socket model carries no power_factor field, so `power` (VA)
     * is treated as real power (W) directly. This is conservative and standard for
     * general-purpose receptacles whose actual PF depends on what is plugged in.
     *
     * Partition (fraction varies by building type — see SOCKET_UNCONTROLLED_FRACTION_BY_TYPE):
     *   Uncontrolled: standby-only baseline, active 24/7.  Real always-on equipment
     *     (servers, UPS) is already modelled as priority=critical RoomComponents.
     *   Controlled (remainder): active only during occupancy window
     *     (resolveWorkIntervals) on work days (ASHRAE 90.1 §8.4.2).
     *
     * @param array   $socketResult Pre-computed SocketDemandService::projectResult()
     * @param bool    $useMax       true → connected_va (MAX mode); false → demand_va (OPTIMIZED)
     */
    private function buildSocketProfile(array $socketResult, object $project, string $dayName, int $month, bool $useMax): array
    {
        $totalW = $useMax ? (float) $socketResult['connected_va']
                          : (float) $socketResult['demand_va'];

        if ($totalW <= 0.0) return array_fill(0, 24, 0.0);

        $uncontrolledW = $totalW * $this->socketUncontrolledFraction($project);
        $controlledW   = $totalW - $uncontrolledW;

        $workDays  = $project->work_days ?? self::DEFAULT_WORK_DAYS;
        $isWorkDay = in_array($dayName, $workDays, true);
        $intervals = $this->resolveWorkIntervals($project);

        // Uncontrolled portion: standby baseline, always present
        $profile = array_fill(0, 24, $uncontrolledW);

        if ($isWorkDay) {
            foreach ($intervals as $iv) {
                $start = $this->dec($iv['start'] ?? '08:00');
                $end   = $this->dec($iv['end']   ?? '17:00');
                if ($end <= $start) $end += 24;
                for ($h = 0; $h < 24; $h++) {
                    $mid = $h + 0.5;
                    if (($mid >= $start && $mid < $end) ||
                        ($end > 24 && ($mid + 24) >= $start && ($mid + 24) < $end)) {
                        $profile[$h] += $controlledW;
                    }
                }
            }
        }

        return array_map(fn($v) => round($v, 2), $profile);
    }

    /**
     * Build LoadSheddingService-compatible slots for socket outlets.
     *
     * socket/uncontrolled  priority=essential — protected from routine shedding;
     *   only shed after all normal loads are exhausted (Step 4 of shedding order).
     *   Represents standby loads that cannot be switched off automatically.
     *
     * socket/controlled    priority=normal — sheds normally with other normal loads
     *   (Step 3).  Represents receptacles with automatic occupancy-based shutoff
     *   per ASHRAE 90.1 §8.4.2.
     */
    private function buildSocketSlots(
        array  $socketResult,
        object $project,
        string $dayName,
        int    $month,
        bool   $useMax,
        array  $socketCircuits = []
    ): array {
        $totalW = $useMax ? (float) $socketResult['connected_va']
                          : (float) $socketResult['demand_va'];

        if ($totalW <= 0.0) return [];

        $uncontrolledFrac = $this->socketUncontrolledFraction($project);
        $controlledFrac   = 1.0 - $uncontrolledFrac;

        // Uncontrolled portion: always-on standby load, priority=essential
        $slots = [[
            'peak_w'          => round($totalW * $uncontrolledFrac, 2),
            'priority'        => 'essential',
            'load_flexibility'=> 'fixed',
            'active_hours'    => array_fill(0, 24, true),
            'curtail_min_pct' => 0.0,
            'earliest_start'  => 0,
            'latest_end'      => 24,
            'required_run_h'  => 0,
            'label'           => 'socket/uncontrolled',
        ]];

        $workDays  = $project->work_days ?? self::DEFAULT_WORK_DAYS;
        $isWorkDay = in_array($dayName, $workDays, true);

        if (!$isWorkDay || ($totalW * $controlledFrac) < 0.5) {
            return $slots;
        }

        // Build occupancy active-hours array
        $active    = array_fill(0, 24, false);
        $intervals = $this->resolveWorkIntervals($project);
        foreach ($intervals as $iv) {
            $start = $this->dec($iv['start'] ?? '08:00');
            $end   = $this->dec($iv['end']   ?? '17:00');
            if ($end <= $start) $end += 24;
            for ($h = 0; $h < 24; $h++) {
                $mid = $h + 0.5;
                if (($mid >= $start && $mid < $end) ||
                    ($end > 24 && ($mid + 24) >= $start && ($mid + 24) < $end)) {
                    $active[$h] = true;
                }
            }
        }

        // ── Real-circuit path ─────────────────────────────────────────────────
        // One slot per socket-model circuit, labelled with its real panel ID
        // (e.g. socket/R/first/SM3).  peak_w is scaled so the total controlled
        // demand exactly matches what SocketDemandService reported.
        if (!empty($socketCircuits)) {
            $realConnectedTotal = (float) array_sum(array_column($socketCircuits, 'total_va'));
            $scale = ($realConnectedTotal > 0.0) ? $totalW / $realConnectedTotal : 1.0;

            foreach ($socketCircuits as $circuit) {
                $slotW = round((float) $circuit['total_va'] * $scale * $controlledFrac, 2);
                if ($slotW < 0.5) continue;
                $slots[] = [
                    'peak_w'          => $slotW,
                    'priority'        => 'normal',
                    'load_flexibility'=> 'fixed',
                    'active_hours'    => $active,
                    'curtail_min_pct' => 0.0,
                    'earliest_start'  => 0,
                    'latest_end'      => 24,
                    'required_run_h'  => 0,
                    'label'           => (string) $circuit['socket_circuit_id'],
                ];
            }
            return $slots;
        }

        // ── Synthetic fallback path ───────────────────────────────────────────
        // Used when the project has no Socket model records.  Splits controlled
        // demand into ≤ SOCKET_CIRCUIT_CAP_W chunks with building attribution tags.
        $controlledW   = round($totalW * $controlledFrac, 2);
        $attribution   = [];
        $bldgBreakdown = $socketResult['buildings_breakdown'] ?? [];
        if (!empty($bldgBreakdown) && $totalW > 0.0) {
            usort($bldgBreakdown, fn($a, $b) =>
                ($useMax ? $b['connected_va'] : $b['demand_va']) <=>
                ($useMax ? $a['connected_va'] : $a['demand_va'])
            );
            foreach ($bldgBreakdown as $b) {
                $bldgW  = (float) ($useMax ? $b['connected_va'] : $b['demand_va']);
                $budget = round($controlledW * $bldgW / $totalW, 4);
                if ($budget > 0.005) {
                    $attribution[] = ['name' => $b['name'], 'w' => $budget];
                }
            }
        }

        $remaining  = round($controlledW, 2);
        $circuitNum = 0;
        $attrIdx    = 0;
        $attrLeft   = !empty($attribution) ? $attribution[0]['w'] : 0.0;

        while ($remaining > 0.005) {
            $circuitNum++;
            $slotW        = round(min($remaining, self::SOCKET_CIRCUIT_CAP_W), 2);
            $circuitBldgs = [];

            if (!empty($attribution)) {
                $toConsume = $slotW;
                while ($toConsume > 0.005 && $attrIdx < count($attribution)) {
                    $take = min($toConsume, $attrLeft);
                    if ($take > 0.005) {
                        $bname = $attribution[$attrIdx]['name'];
                        if (!in_array($bname, $circuitBldgs, true)) {
                            $circuitBldgs[] = $bname;
                        }
                    }
                    $attrLeft  -= $take;
                    $toConsume -= $take;
                    if ($attrLeft <= 0.005 && $attrIdx + 1 < count($attribution)) {
                        $attrIdx++;
                        $attrLeft = $attribution[$attrIdx]['w'];
                    }
                }
            }

            $bldgTag = !empty($circuitBldgs) ? ' [' . implode('+', $circuitBldgs) . ']' : '';
            $slots[] = [
                'peak_w'          => $slotW,
                'priority'        => 'normal',
                'load_flexibility'=> 'fixed',
                'active_hours'    => $active,
                'curtail_min_pct' => 0.0,
                'earliest_start'  => 0,
                'latest_end'      => 24,
                'required_run_h'  => 0,
                'label'           => "socket/controlled/{$circuitNum}{$bldgTag}",
            ];
            $remaining = round($remaining - $slotW, 2);
        }

        return $slots;
    }

    /**
     * Flatten a LoadSheddingService result into the response shape.
     * Kept separate so per_load_shed_list is only included when non-empty.
     */
    private function shedSummary(array $result): array
    {
        $summary = [
            'shed_curtailable_kwh' => $result['shed_curtailable_kwh'],
            'shed_normal_kwh'      => $result['shed_normal_kwh'],
            'shed_essential_kwh'   => $result['shed_essential_kwh'],
            'critical_unmet_kwh'   => $result['critical_unmet_kwh'],
            'any_shedding'         => ($result['shed_curtailable_kwh']
                                       + $result['shed_normal_kwh']
                                       + $result['shed_essential_kwh']
                                       + $result['critical_unmet_kwh']) > 0,
        ];

        if (!empty($result['per_load_shed_list'])) {
            $summary['per_load_shed_list'] = $result['per_load_shed_list'];
        }

        return $summary;
    }

    // ── Chemistry Comparison ──────────────────────────────────────────────────

    /**
     * GET /api/projects/{project}/battery-chemistry-comparison
     * Query params: month (1-12), day (1-31), day_type (weekday|weekend)
     *
     * Runs the same load + solar profile twice — once with a lead-acid bank
     * (DoD 50 %, RTE 82 %) and once with LFP (DoD 85 %, RTE 92 %) — both
     * sharing the project's actual total nominal capacity.
     * Returns generator kWh, hours, and affine fuel cost so the UI can show
     * how chemistry choice affects generator usage for the same building.
     */
    public function chemistryComparison(Request $request, Project $project)
    {
        $this->authorize('view', $project);
        if (! $project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $month   = max(1, min(12, (int) $request->query('month', now()->month)));
        $dayNum  = max(1, min(31, (int) $request->query('day', 15)));
        $dayNum  = min($dayNum, (int) date('t', mktime(0, 0, 0, $month, 1, 2023)));
        $dayType = $request->query('day_type', 'weekday');
        $dayName = $dayType === 'weekend' ? 'saturday' : 'monday';

        // Load profile for a representative day
        $components = $this->collectComponents($project);
        $loadW      = $this->buildHourlyW($components, 'max', $dayName, $month, false);

        // Solar
        $solarSystems = $project->solarSystems()->where('is_active', true)->get();
        $solarMode    = $project->solar_source ?? 'max';
        if ($solarMode === 'existing') {
            $solarCapacityW = $solarSystems->isNotEmpty()
                ? $solarSystems->sum('capacity_kw') * 1000.0
                : (float) ($project->existing_solar_power ?? 0)
                    + (float) $project->buildings()->sum('existing_solar_power');
        } else {
            $totalAreaM2    = (float) $project->buildings()->sum('area');
            $solarCapacityW = SolarIrradianceService::estimateCapacityW($totalAreaM2);
        }
        $lat = $project->location_lat !== null ? (float) $project->location_lat : null;
        $lng = $project->location_lng !== null ? (float) $project->location_lng : null;
        $solarProfile = $this->solarSvc->getHourlyOutputWatts(
            $lat, $lng, $month, $solarCapacityW / 1000.0, performanceRatio: 0.80, day: $dayNum
        );

        // Source capacities
        $utilCapW = (float) $project->utilityLines()->sum('power') * 0.8;
        $genCapW  = (float) $project->generatorLines()->sum('power') * 0.8;

        // Reference nominal capacity from actual batteries (default 10 kWh for illustration)
        $batteries  = $project->batteries()->where('is_active', true)->get();
        $nominalKwh = $batteries->isNotEmpty()
            ? $batteries->sum(fn($b) => $b->nominal_capacity_kwh)
            : 10.0;
        $avgSoc     = $batteries->isNotEmpty()
            ? round($batteries->avg('current_soc'), 3)
            : 0.5;

        // Generator fuel params for affine cost (ISO 8528: F(P) = F₀ + (F_rated-F₀)×P/P_rated)
        $genLine = $project->generatorLines()
            ->whereNotNull('fuel_cost_per_liter')->whereNotNull('fuel_consumption_lph')
            ->where('fuel_cost_per_liter', '>', 0)->where('fuel_consumption_lph', '>', 0)
            ->orderBy('id')->first();

        // Chemistry specs: same nominal, different DoD and RTE
        $chemSpecs = [
            'lead_acid' => [
                'label' => 'Lead-Acid',
                'dod'   => 0.50,  // ⚠ tunable — matches BatteryChemistryService flooded DoD
                'rte'   => 0.82,  // ⚠ tunable — matches BatteryChemistryService flooded RTE
                'c_chg' => 0.10,  // C/10 charge rate
                'c_dch' => 0.20,  // C/5  discharge rate
            ],
            'lithium_lfp' => [
                'label' => 'Lithium LFP',
                'dod'   => 0.85,  // ⚠ tunable — matches BatteryChemistryService lithium_lfp DoD
                'rte'   => 0.92,  // ⚠ tunable — matches BatteryChemistryService lithium_lfp RTE
                'c_chg' => 0.50,
                'c_dch' => 1.00,
            ],
        ];

        $comparison = [];
        foreach ($chemSpecs as $key => $spec) {
            $usableKwh = $nominalKwh * $spec['dod'];
            $fakeBatt  = new Collection([(object) [
                'id'                     => 1,
                'is_active'              => true,
                'usable_capacity_kwh'    => $usableKwh,
                'current_soc'            => max(0.0, min(1.0, $avgSoc)),
                'max_charge_power_kw'    => $nominalKwh * $spec['c_chg'],
                'max_discharge_power_kw' => $nominalKwh * $spec['c_dch'],
                'round_trip_efficiency'  => $spec['rte'],
                'solar_system_id'        => null,
            ]]);

            $dispatch = $this->dispatchSvc->dispatch(
                $loadW, $solarProfile, $utilCapW, $genCapW, $fakeBatt, $solarCapacityW,
                $solarSystems->isNotEmpty() ? $solarSystems : null
            );

            $stats  = $dispatch['stats'];
            $genKwh = $stats['generator_kwh'];

            // Affine hourly fuel cost
            $fuelCost = null;
            if ($genLine && $genCapW > 0) {
                $ratedKw = $genCapW / 1000.0;
                $fRated  = (float) $genLine->fuel_consumption_lph;
                $f0      = $genLine->no_load_fuel_lph ?? round($fRated * 0.30, 4);
                $cpL     = (float) $genLine->fuel_cost_per_liter;
                $litres  = 0.0;
                foreach ($dispatch['generator_used'] as $watt) {
                    if ($watt > 0) {
                        $kw     = $watt / 1000.0;
                        $litres += $f0 + ($fRated - $f0) * ($kw / $ratedKw);
                    }
                }
                $fuelCost = round($litres * $cpL, 2);
            }

            $comparison[$key] = [
                'label'                  => $spec['label'],
                'dod_pct'                => $spec['dod'] * 100,
                'rte_pct'                => $spec['rte'] * 100,
                'nominal_kwh'            => round($nominalKwh, 2),
                'usable_kwh'             => round($usableKwh, 2),
                'generator_hours'        => $stats['generator_hours'],
                'generator_kwh'          => $genKwh,
                'battery_discharged_kwh' => $stats['battery_discharged_kwh'] ?? 0.0,
                'unmet_kwh'              => $stats['unmet_kwh'],
                'fuel_cost'              => $fuelCost,
            ];
        }

        // Delta: LFP advantage over lead-acid
        $la  = $comparison['lead_acid'];
        $lfp = $comparison['lithium_lfp'];
        $delta = [
            'generator_kwh_saved'   => round($la['generator_kwh']   - $lfp['generator_kwh'], 2),
            'generator_hours_saved' => $la['generator_hours']        - $lfp['generator_hours'],
            'fuel_cost_saved'       => ($la['fuel_cost'] !== null && $lfp['fuel_cost'] !== null)
                                        ? round($la['fuel_cost'] - $lfp['fuel_cost'], 2)
                                        : null,
        ];

        return response()->json([
            'nominal_kwh'    => round($nominalKwh, 2),
            'month'          => $month,
            'day_type'       => $dayType,
            'comparison'     => $comparison,
            'delta'          => $delta,
            'currency'       => $project->currency_symbol ?? '$',
            'has_fuel_data'  => $genLine !== null,
        ]);
    }
}
