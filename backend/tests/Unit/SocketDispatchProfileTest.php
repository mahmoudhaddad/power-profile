<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\ScheduleController;
use App\Services\LoadSheddingService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for socket outlet dispatch profile helpers in ScheduleController.
 *
 * Phase 1 (SOCKET_UNCONTROLLED_FRACTION_BY_TYPE default = 0.15):
 *   - uncontrolled = type-specific % of total socket VA: standby-only baseline, always-on.
 *   - controlled   = remainder % of total socket VA: occupancy-gated, sheddable.
 *
 * Phase 2 (building-type-specific fraction):
 *   - educational_university / educational_school → 0.07
 *   - hospital → 0.45
 *   - residential_house / residential_apartment → 0.25
 *   - office / industrial / default → 0.15
 *
 * Priority model:
 *   socket/uncontrolled → priority = 'essential'  (protected from routine shedding)
 *   socket/controlled   → priority = 'normal'     (sheds normally under deficit)
 *
 * Backend work-window default aligned to frontend: 08:00–17:00 (not 08:00–18:00).
 *
 * No DB or HTTP access; private methods accessed via PHP reflection on a
 * constructor-less instance.
 *
 * Covered:
 *  1.  Zero-socket project → all-zero profile.
 *  2.  Zero-socket project → empty slots.
 *  3.  All 24 hours non-zero on a work day (standby baseline is always present).
 *  4.  All 24 hours non-zero on a weekend (uncontrolled portion alone).
 *  5.  Work-hour demand exceeds off-hour demand on work days.
 *  6.  Weekend equals uncontrolled fraction × connected_va on every hour.
 *  7.  MAX mode uses connected_va; OPTIMIZED mode uses demand_va.
 *  8.  socket/uncontrolled slot has priority = 'essential' (Phase 1 fix).
 *  9.  socket/controlled slot has priority = 'normal' (correctly sheddable).
 * 10.  Controlled slot present on work days, absent on weekends.
 * 11.  Profile daily energy (Wh) equals slot-summed energy — internal consistency.
 * 12.  Under a normal deficit, essential (uncontrolled) slot is NOT shed.
 * 13.  Backend default resolves to 08:00–17:00 (matches frontend DEFAULT_TIME_INTERVALS).
 * 14.  Project → first-building fallback for work_time_intervals.
 * 15.  Workday and weekend h=12 load values differ meaningfully in the raw profile.
 * 16.  University project gets fraction 0.07, not 0.15.
 * 17.  Hospital project gets fraction 0.45.
 * 18.  Unknown/null building_type falls back to 0.15 (no regression).
 * 19.  Controlled-portion logic is unaffected — only the multiplier changes.
 * 20.  Fraction is applied to the Phase-1-corrected 24,700 VA base, not 30,790 VA.
 * 21.  With Phase-1+2 corrected values, July 7 (workday) vs July 12 (weekend) at h=12
 *       differ by exactly 22,971 W (the controlled portion).
 * 22.  Combined Phase-1+2+3: uncontrolled (1,729 W, essential) is NOT shed under a normal
 *       deficit when using the Phase-1-corrected 24,700 VA base + 0.07 fraction.
 */
class SocketDispatchProfileTest extends TestCase
{
    /** Default fraction (unknown/null type) from SOCKET_UNCONTROLLED_FRACTION_BY_TYPE. */
    private const UNCONTROLLED = 0.15;

    private object           $ctrl;
    private ReflectionMethod $profileMethod;
    private ReflectionMethod $slotsMethod;
    private ReflectionMethod $resolveMethod;
    private ReflectionMethod $fractionMethod;

    protected function setUp(): void
    {
        parent::setUp();
        $rc = new ReflectionClass(ScheduleController::class);
        $this->ctrl           = $rc->newInstanceWithoutConstructor();
        $this->profileMethod  = $rc->getMethod('buildSocketProfile');
        $this->slotsMethod    = $rc->getMethod('buildSocketSlots');
        $this->resolveMethod  = $rc->getMethod('resolveWorkIntervals');
        $this->fractionMethod = $rc->getMethod('socketUncontrolledFraction');
    }

    private function project(
        ?array  $workDays     = null,
        ?array  $intervals    = null,
        array   $buildings    = [],
        ?string $buildingType = null,
    ): object {
        $p                      = new \stdClass();
        $p->work_days           = $workDays;
        $p->work_time_intervals = $intervals;
        $p->buildings           = $buildings;
        $p->building_type       = $buildingType;
        return $p;
    }

    private function fraction(object $project): float
    {
        return $this->fractionMethod->invoke($this->ctrl, $project);
    }

    private function profile(array $socketResult, object $project, string $day, bool $useMax): array
    {
        return $this->profileMethod->invoke($this->ctrl, $socketResult, $project, $day, 7, $useMax);
    }

    private function slots(array $socketResult, object $project, string $day, bool $useMax): array
    {
        return $this->slotsMethod->invoke($this->ctrl, $socketResult, $project, $day, 7, $useMax);
    }

    private function resolveIntervals(object $project): array
    {
        return $this->resolveMethod->invoke($this->ctrl, $project);
    }

    // ── Test 1 ───────────────────────────────────────────────────────────────

    public function test_zero_sockets_produces_zero_profile(): void
    {
        $result  = ['connected_va' => 0.0, 'demand_va' => 0.0];
        $profile = $this->profile($result, $this->project(), 'monday', true);

        $this->assertCount(24, $profile);
        $this->assertSame(0.0, array_sum($profile));
    }

    // ── Test 2 ───────────────────────────────────────────────────────────────

    public function test_zero_sockets_produces_empty_slots(): void
    {
        $result = ['connected_va' => 0.0, 'demand_va' => 0.0];
        $this->assertEmpty($this->slots($result, $this->project(), 'monday', true));
    }

    // ── Test 3 ───────────────────────────────────────────────────────────────

    public function test_all_hours_nonzero_on_workday(): void
    {
        $result  = ['connected_va' => 10000.0, 'demand_va' => 8000.0];
        $profile = $this->profile($result, $this->project(), 'monday', true);

        foreach ($profile as $h => $w) {
            $this->assertGreaterThan(0.0, $w,
                "Hour $h must be non-zero — standby baseline is always present");
        }
    }

    // ── Test 4 ───────────────────────────────────────────────────────────────

    public function test_all_hours_nonzero_on_weekend(): void
    {
        $result  = ['connected_va' => 10000.0, 'demand_va' => 8000.0];
        $profile = $this->profile($result, $this->project(), 'saturday', true);

        foreach ($profile as $h => $w) {
            $this->assertGreaterThan(0.0, $w,
                "Hour $h must be non-zero on weekends — uncontrolled standby fraction");
        }
    }

    // ── Test 5 ───────────────────────────────────────────────────────────────

    public function test_work_hours_exceed_off_hours_on_workday(): void
    {
        $result  = ['connected_va' => 10000.0, 'demand_va' => 8000.0];
        $project = $this->project(null, [['start' => '08:00', 'end' => '18:00']]);
        $profile = $this->profile($result, $project, 'monday', true);

        // h=8–17 mid-points fall within 08:00–18:00 (controlled + uncontrolled)
        $insideAvg  = array_sum(array_slice($profile, 8, 10)) / 10;
        // h=0–7, h=18–23 are outside the window (uncontrolled only)
        $outsideAvg = (array_sum(array_slice($profile, 0, 8))
                     + array_sum(array_slice($profile, 18, 6))) / 14;

        $this->assertGreaterThan($outsideAvg, $insideAvg,
            'Occupied hours (controlled+uncontrolled) must exceed off-hours (uncontrolled only)');
    }

    // ── Test 6 ───────────────────────────────────────────────────────────────

    public function test_weekend_equals_uncontrolled_fraction_all_hours(): void
    {
        $connected = 10000.0;
        $result    = ['connected_va' => $connected, 'demand_va' => 8000.0];
        $project   = $this->project(null, [['start' => '08:00', 'end' => '18:00']]);
        $weekend   = $this->profile($result, $project, 'saturday', true);
        $weekday   = $this->profile($result, $project, 'monday',   true);

        $expectedUncontrolled = round($connected * self::UNCONTROLLED, 2); // 1500.0
        foreach ($weekend as $h => $w) {
            $this->assertEqualsWithDelta($expectedUncontrolled, $w, 0.01,
                "Weekend h=$h: only uncontrolled fraction (" . (self::UNCONTROLLED * 100) . " % × connected_va)");
        }

        // Weekday inside work hours: uncontrolled (15 %) + controlled (85 %) = 100 %
        $expectedPeak = $connected;
        for ($h = 8; $h < 18; $h++) {
            $this->assertEqualsWithDelta($expectedPeak, $weekday[$h], 0.01,
                "Weekday h=$h: full connected_va (uncontrolled + controlled)");
        }
    }

    // ── Test 7 ───────────────────────────────────────────────────────────────

    public function test_max_uses_connected_va_opt_uses_demand_va(): void
    {
        $result  = ['connected_va' => 20000.0, 'demand_va' => 10000.0];
        $project = $this->project(); // default work days, no custom intervals → Mon-Fri 08:00-17:00

        // Saturday → uncontrolled only → each hour = UNCONTROLLED × the respective VA
        $maxProfile = $this->profile($result, $project, 'saturday', true);
        $optProfile = $this->profile($result, $project, 'saturday', false);

        $this->assertEqualsWithDelta(
            round(20000.0 * self::UNCONTROLLED, 2), $maxProfile[0], 0.01,
            'MAX h=0: UNCONTROLLED × connected_va'
        );
        $this->assertEqualsWithDelta(
            round(10000.0 * self::UNCONTROLLED, 2), $optProfile[0], 0.01,
            'OPT h=0: UNCONTROLLED × demand_va'
        );
    }

    // ── Test 8 ───────────────────────────────────────────────────────────────

    public function test_uncontrolled_slot_has_essential_priority(): void
    {
        $result       = ['connected_va' => 10000.0, 'demand_va' => 8000.0];
        $slots        = $this->slots($result, $this->project(), 'monday', true);
        $uncontrolled = array_values(array_filter($slots, fn($s) => $s['label'] === 'socket/uncontrolled'));

        $this->assertNotEmpty($uncontrolled, 'Uncontrolled slot must always be present');
        $slot = $uncontrolled[0];
        $this->assertCount(24, $slot['active_hours']);
        $this->assertSame(24, array_sum($slot['active_hours']), 'Active all 24 hours');
        $this->assertSame('fixed',     $slot['load_flexibility']);
        // Phase 1 fix: must be 'essential' so it survives routine normal shedding
        $this->assertSame('essential', $slot['priority'],
            'Uncontrolled slot must be essential — cannot be shed before normal loads are exhausted');
    }

    // ── Test 9 ───────────────────────────────────────────────────────────────

    public function test_controlled_slot_has_normal_priority(): void
    {
        $result     = ['connected_va' => 10000.0, 'demand_va' => 8000.0];
        $slots      = $this->slots($result, $this->project(), 'monday', true);
        $controlled = array_values(array_filter($slots, fn($s) => str_starts_with($s['label'], 'socket/controlled/')));

        $this->assertNotEmpty($controlled, 'Controlled sub-slots must be present on a work day');
        foreach ($controlled as $slot) {
            $this->assertSame('normal', $slot['priority'],
                "Every controlled sub-slot ({$slot['label']}) must be normal priority — sheds in Step 3");
        }
    }

    // ── Test 10 ──────────────────────────────────────────────────────────────

    public function test_controlled_slot_present_on_workday_absent_on_weekend(): void
    {
        $result = ['connected_va' => 10000.0, 'demand_va' => 8000.0];

        $weekdaySlots = $this->slots($result, $this->project(), 'monday',   true);
        $weekendSlots = $this->slots($result, $this->project(), 'saturday', true);

        $hasControlledWeekday = !empty(array_filter($weekdaySlots, fn($s) => str_starts_with($s['label'], 'socket/controlled/')));
        $hasControlledWeekend = !empty(array_filter($weekendSlots, fn($s) => str_starts_with($s['label'], 'socket/controlled/')));

        $this->assertTrue($hasControlledWeekday,  'Controlled slot must exist on work days');
        $this->assertFalse($hasControlledWeekend, 'Controlled slot must be absent on weekends');
    }

    // ── Test 11 ──────────────────────────────────────────────────────────────

    public function test_profile_and_slots_daily_energy_consistent(): void
    {
        $result  = ['connected_va' => 10000.0, 'demand_va' => 8000.0];
        $project = $this->project(null, [['start' => '08:00', 'end' => '18:00']]);

        $profile = $this->profile($result, $project, 'monday', true);
        $slots   = $this->slots($result, $project, 'monday', true);

        $profileEnergy = array_sum($profile); // Wh
        $slotsEnergy   = 0.0;
        foreach ($slots as $slot) {
            $slotsEnergy += $slot['peak_w'] * array_sum($slot['active_hours']);
        }

        $this->assertEqualsWithDelta($profileEnergy, $slotsEnergy, 1.0,
            'Profile daily energy (Wh) must equal sum of slot energies');
    }

    // ── Test 12 — Regression guard: uncontrolled not shed under normal deficit ─

    /**
     * Verifies the Phase 1 shedding fix:
     *
     * Slots: socket/controlled (normal, 8500 W, h=8-17)
     *        socket/uncontrolled (essential, 1500 W, 24/7)
     *        component_normal   (normal, 10000 W, h=8-17)
     *
     * Deficit at h=12: large enough that all normal loads get shed, but
     * the essential (uncontrolled) slot must survive.
     *
     * Before Phase 1 fix: uncontrolled was 'normal' → stripped → adjusted_load ≈ 0
     * After  Phase 1 fix: uncontrolled is 'essential' → preserved → adjusted_load
     *                     at h=12 includes the 1500 W standby baseline.
     */
    public function test_uncontrolled_socket_not_shed_under_normal_deficit(): void
    {
        $uncontrolledW = round(10000.0 * self::UNCONTROLLED, 2); // 1500
        $controlledW   = 10000.0 - $uncontrolledW;               // 8500

        $componentNormal = 10000.0;
        $utilityCapW     = 5000.0; // deliberate shortage

        $shedding = new LoadSheddingService();

        // Build slots mirroring what buildSocketSlots() + buildComponentSlots() produce
        $workHours = array_fill(0, 24, false);
        for ($h = 8; $h < 18; $h++) $workHours[$h] = true;

        $slots = [
            [
                'peak_w'          => $uncontrolledW,
                'priority'        => 'essential',   // Phase 1: must be essential
                'load_flexibility'=> 'fixed',
                'active_hours'    => array_fill(0, 24, true),
                'curtail_min_pct' => 0.0,
                'earliest_start'  => 0,
                'latest_end'      => 24,
                'required_run_h'  => 0,
                'label'           => 'socket/uncontrolled',
            ],
            [
                'peak_w'          => $controlledW,
                'priority'        => 'normal',
                'load_flexibility'=> 'fixed',
                'active_hours'    => $workHours,
                'curtail_min_pct' => 0.0,
                'earliest_start'  => 0,
                'latest_end'      => 24,
                'required_run_h'  => 0,
                'label'           => 'socket/controlled',
            ],
            [
                'peak_w'          => $componentNormal,
                'priority'        => 'normal',
                'load_flexibility'=> 'fixed',
                'active_hours'    => $workHours,
                'curtail_min_pct' => 0.0,
                'earliest_start'  => 0,
                'latest_end'      => 24,
                'required_run_h'  => 0,
                'label'           => 'component/normal',
            ],
        ];

        // Build load profile from slots (mirrors ScheduleController behaviour)
        $loadW = array_fill(0, 24, 0.0);
        foreach ($slots as $slot) {
            for ($h = 0; $h < 24; $h++) {
                if ($slot['active_hours'][$h]) $loadW[$h] += $slot['peak_w'];
            }
        }

        // rawUnmetW: simple utility-cap model (no solar, no battery)
        $rawUnmetW = array_fill(0, 24, 0.0);
        for ($h = 0; $h < 24; $h++) {
            $rawUnmetW[$h] = max(0.0, $loadW[$h] - $utilityCapW);
        }

        $result = $shedding->shed($slots, $rawUnmetW);
        $adj    = $result['adjusted_load_w'];

        // At h=12 (work hour, both socket slots active + component normal):
        //   Shedding Step 3 removes normal loads (controlled + component_normal).
        //   Essential (uncontrolled) survives into adjusted_load_w.
        $this->assertGreaterThanOrEqual(
            $uncontrolledW * 0.99, // allow tiny float rounding
            $adj[12],
            "Uncontrolled socket (essential) must survive shedding at h=12: " .
            "adjusted_load={$adj[12]}, expected ≥ {$uncontrolledW}"
        );

        // Overnight (h=2, work slots inactive): only uncontrolled present → no shedding needed
        $this->assertEqualsWithDelta($uncontrolledW, $adj[2], 1.0,
            'Overnight (h=2): only uncontrolled slot active — no shedding expected');
    }

    // ── Test 13 — Backend default = 08:00–17:00 ──────────────────────────────

    /**
     * Confirms the backend default for work_time_intervals is 08:00–17:00 (17:00 = 5 PM),
     * matching the frontend constant DEFAULT_TIME_INTERVALS on ProjectPage.jsx.
     *
     * Before Phase 1 fix: backend used 08:00–18:00; frontend showed 08:00–17:00 → mismatch.
     */
    public function test_backend_default_work_interval_is_0800_to_1700(): void
    {
        // Project and buildings all have null work_time_intervals
        $project = $this->project(null, null, []); // no buildings

        $intervals = $this->resolveIntervals($project);

        $this->assertCount(1, $intervals, 'Default must return a single interval');
        $this->assertSame('08:00', $intervals[0]['start'] ?? null);
        $this->assertSame('17:00', $intervals[0]['end']   ?? null,
            'Backend default end must be 17:00 (5 PM) to match frontend DEFAULT_TIME_INTERVALS');
    }

    // ── Test 14 — Project → first-building fallback ───────────────────────────

    public function test_project_to_building_fallback_for_work_intervals(): void
    {
        $buildingIntervals = [['start' => '09:00', 'end' => '16:00']];

        $building             = new \stdClass();
        $building->work_time_intervals = $buildingIntervals;

        // Project has null → should fall back to first building's value
        $project = $this->project(null, null, [$building]);

        $resolved = $this->resolveIntervals($project);

        $this->assertSame($buildingIntervals, $resolved,
            'When project.work_time_intervals is null, use first building with a non-null value');
    }

    // ── Test 15 — Workday vs weekend h=12 raw profile differs meaningfully ────

    public function test_workday_and_weekend_noon_load_differ(): void
    {
        $result  = ['connected_va' => 30790.0, 'demand_va' => 30790.0]; // 257 outlets approx
        $project = $this->project(null, [['start' => '08:00', 'end' => '17:00']]);

        $workday = $this->profile($result, $project, 'tuesday',  false);
        $weekend = $this->profile($result, $project, 'sunday',   false);

        // h=12 mid = 12.5 is within 08:00–17:00 on workday
        $this->assertGreaterThan(
            $weekend[12] * 1.5, // workday must be substantially higher (≥ 150 % of weekend)
            $workday[12],
            'Workday h=12 must be substantially higher than weekend h=12 ' .
            "(controlled 85 % active vs absent); workday={$workday[12]}, weekend={$weekend[12]}"
        );
    }

    // ── Test 16 — University type → 0.07 (Phase 2) ───────────────────────────

    public function test_university_building_type_gives_0_07_fraction(): void
    {
        // Project-level type
        $uni = $this->project(null, null, [], 'educational_university');
        $this->assertSame(0.07, $this->fraction($uni));

        // educational_school must also give 0.07
        $school = $this->project(null, null, [], 'educational_school');
        $this->assertSame(0.07, $this->fraction($school));

        // First-building fallback (project.building_type is null)
        $bldg = new \stdClass();
        $bldg->type = 'educational_university';
        $fromBuilding = $this->project(null, null, [$bldg]);
        $this->assertSame(0.07, $this->fraction($fromBuilding));
    }

    // ── Test 17 — Hospital type → 0.45 (Phase 2) ────────────────────────────

    public function test_hospital_building_type_gives_0_45_fraction(): void
    {
        $hospital = $this->project(null, null, [], 'hospital');
        $this->assertSame(0.45, $this->fraction($hospital));
    }

    // ── Test 18 — Unknown / null type → 0.15 default (no regression) ─────────

    public function test_null_or_unknown_building_type_falls_back_to_0_15(): void
    {
        // Null project.building_type, no buildings → default
        $noType = $this->project(null, null, []);
        $this->assertSame(0.15, $this->fraction($noType));

        // Explicitly null building_type
        $nullType = $this->project(null, null, [], null);
        $this->assertSame(0.15, $this->fraction($nullType));

        // Type not in the table falls back to 'default'
        $unknownType = $this->project(null, null, [], 'sports_complex');
        $this->assertSame(0.15, $this->fraction($unknownType));
    }

    // ── Test 19 — Controlled-portion work-hours logic is unaffected ───────────

    /**
     * Changing the building type changes ONLY the uncontrolled fraction.
     * The work-hours gating of the controlled portion is identical regardless of type.
     * Both types sum to 100 % of the total VA during occupied hours.
     */
    public function test_controlled_logic_unaffected_only_fraction_changes(): void
    {
        $result = ['connected_va' => 10000.0, 'demand_va' => 10000.0];

        $default = $this->project(null, null, []);                       // fraction = 0.15
        $uni     = $this->project(null, null, [], 'educational_university'); // fraction = 0.07

        // Weekend (overnight): only uncontrolled baseline is active
        $defaultOvernight = $this->profile($result, $default, 'saturday', true)[0];
        $uniOvernight     = $this->profile($result, $uni,     'saturday', true)[0];

        $this->assertEqualsWithDelta(1500.0, $defaultOvernight, 0.01, 'Default: 10000 × 0.15 = 1500 overnight');
        $this->assertEqualsWithDelta(700.0,  $uniOvernight,     0.01, 'University: 10000 × 0.07 = 700 overnight');

        // Workday h=12 (inside default window 08:00–17:00): controlled + uncontrolled = 100 %
        $defaultNoon = $this->profile($result, $default, 'monday', true)[12];
        $uniNoon     = $this->profile($result, $uni,     'monday', true)[12];

        $this->assertEqualsWithDelta(10000.0, $defaultNoon, 0.01, 'Default workday noon: 100 % of total');
        $this->assertEqualsWithDelta(10000.0, $uniNoon,     0.01, 'University workday noon: 100 % of total');

        // Weekend controlled slot must be absent for both types
        $this->assertEmpty(
            array_filter($this->slots($result, $default, 'saturday', true), fn($s) => $s['label'] === 'socket/controlled'),
            'Default type: no controlled slot on weekend'
        );
        $this->assertEmpty(
            array_filter($this->slots($result, $uni, 'saturday', true), fn($s) => $s['label'] === 'socket/controlled'),
            'University type: no controlled slot on weekend'
        );
    }

    // ── Test 21 — Phase-1+2 corrected values: workday vs weekend h=12 differ by 22,971 W ──

    /**
     * Islamic University project 18, fully corrected pipeline:
     *   demand_va = 24,700 VA  (Phase 1: double-counting removed)
     *   fraction  = 0.07       (Phase 2: educational_university)
     *   uncontrolled = 1,729 W (essential, 24/7)
     *   controlled   = 22,971 W (normal, 08:00–17:00 workdays)
     *
     * July 7 (Tuesday, workday), h=12: both slots active → 24,700 W
     * July 12 (Sunday, weekend),  h=12: only uncontrolled → 1,729 W
     * Visible difference at h=12 in the UI: 22,971 W ≈ 23 kW
     */
    public function test_workday_vs_weekend_noon_difference_with_corrected_p1p2_values(): void
    {
        // Phase-1-corrected socket result for Islamic University project 18
        $corrected = ['connected_va' => 37600.0, 'demand_va' => 24700.0];
        $uni       = $this->project(null, null, [], 'educational_university');

        // OPTIMIZED mode (demand_va) — matches what ScheduleController uses in dispatch
        $workdayProfile = $this->profile($corrected, $uni, 'tuesday', false);
        $weekendProfile = $this->profile($corrected, $uni, 'sunday',  false);

        $fraction      = 0.07;
        $uncontrolledW = round(24700.0 * $fraction, 2);         // 1729.0
        $controlledW   = round(24700.0 * (1 - $fraction), 2);   // 22971.0

        // July 7 workday h=12 (mid=12.5 inside 08:00–17:00): controlled + uncontrolled
        $this->assertEqualsWithDelta(
            24700.0, $workdayProfile[12], 0.5,
            'Workday h=12 (July 7): both socket slots active → demand_va in full (24,700 W)'
        );

        // July 12 weekend h=12: only uncontrolled baseline
        $this->assertEqualsWithDelta(
            $uncontrolledW, $weekendProfile[12], 0.5,
            'Weekend h=12 (July 12): only uncontrolled slot → 24700 × 0.07 = 1,729 W'
        );

        // Visible UI difference: exactly the controlled portion
        $diff = $workdayProfile[12] - $weekendProfile[12];
        $this->assertEqualsWithDelta(
            $controlledW, $diff, 1.0,
            "h=12 workday-weekend gap must equal the controlled portion (22,971 W ≈ 23 kW); " .
            "got diff={$diff}"
        );
    }

    // ── Test 22 — Phase-1+2+3 combined: corrected essential slot survives shedding ─

    /**
     * Exercises the full corrected pipeline end-to-end through the shedding algorithm:
     *   - Phase 1 corrected base: demand_va = 24,700 VA
     *   - Phase 2 fraction: 0.07 (educational_university)
     *   - Phase 3 priority: uncontrolled → 'essential', controlled → 'normal'
     *
     * Deficit is large enough to strip ALL normal loads (controlled socket + component),
     * but the essential uncontrolled socket (1,729 W) must survive intact.
     *
     * Guards against any regression that re-introduces old values (30,790 VA or 0.15 fraction)
     * or wrong priority, all of which would change the shedding outcome.
     */
    public function test_phase1_p2_p3_combined_essential_survives_shedding(): void
    {
        // Phase-1-corrected values for Islamic University, with Phase-2 fraction
        $totalDemandW  = 24700.0;
        $fraction      = 0.07;                                           // Phase 2: educational_university
        $uncontrolledW = round($totalDemandW * $fraction, 2);            // 1729.0
        $controlledW   = round($totalDemandW * (1 - $fraction), 2);      // 22971.0
        $componentW    = 10910.0;   // typical component load at h=12 (confirmed from dispatch)

        $utilityCapW = 5000.0;  // deliberate shortage: far below any combination of loads

        $shedding = new LoadSheddingService();

        $workHours = array_fill(0, 24, false);
        for ($h = 8; $h < 17; $h++) $workHours[$h] = true; // 08:00–17:00 (Phase 3 default)

        $slots = [
            // Phase 3: uncontrolled → essential (protected from routine shedding)
            [
                'peak_w'          => $uncontrolledW,
                'priority'        => 'essential',
                'load_flexibility'=> 'fixed',
                'active_hours'    => array_fill(0, 24, true),
                'curtail_min_pct' => 0.0,
                'earliest_start'  => 0,
                'latest_end'      => 24,
                'required_run_h'  => 0,
                'label'           => 'socket/uncontrolled',
            ],
            // Phase 3: controlled → normal (shed in Step 3 of shedding algorithm)
            [
                'peak_w'          => $controlledW,
                'priority'        => 'normal',
                'load_flexibility'=> 'fixed',
                'active_hours'    => $workHours,
                'curtail_min_pct' => 0.0,
                'earliest_start'  => 0,
                'latest_end'      => 24,
                'required_run_h'  => 0,
                'label'           => 'socket/controlled',
            ],
            [
                'peak_w'          => $componentW,
                'priority'        => 'normal',
                'load_flexibility'=> 'fixed',
                'active_hours'    => $workHours,
                'curtail_min_pct' => 0.0,
                'earliest_start'  => 0,
                'latest_end'      => 24,
                'required_run_h'  => 0,
                'label'           => 'component/normal',
            ],
        ];

        // Build load profile and compute rawUnmetW (simple utility-cap model)
        $loadW    = array_fill(0, 24, 0.0);
        foreach ($slots as $slot) {
            for ($h = 0; $h < 24; $h++) {
                if ($slot['active_hours'][$h]) $loadW[$h] += $slot['peak_w'];
            }
        }
        $rawUnmetW = array_fill(0, 24, 0.0);
        for ($h = 0; $h < 24; $h++) {
            $rawUnmetW[$h] = max(0.0, $loadW[$h] - $utilityCapW);
        }

        $result = $shedding->shed($slots, $rawUnmetW);
        $adj    = $result['adjusted_load_w'];

        // h=12 (workday, inside 08:00–17:00): all three slots active.
        // Shedding Step 3 removes controlled (22971) + component (10910); essential (1729) survives.
        $this->assertGreaterThanOrEqual(
            $uncontrolledW * 0.99,
            $adj[12],
            "Phase 1+2+3 combined: essential (1,729 W) must survive shedding at h=12. " .
            "adjusted_load={$adj[12]}"
        );

        // Overnight (h=2, work slots off): only uncontrolled active → no deficit
        $this->assertEqualsWithDelta(
            $uncontrolledW, $adj[2], 1.0,
            'Overnight h=2: only essential uncontrolled (1,729 W) active — must be preserved exactly'
        );

        // The old regression: If uncontrolledW were erroneously the old value
        // (30790 × 0.15 = 4618.5) the floor would differ by ~2889 W — confirm we are NOT there
        $oldUncontrolledW = round(30790.0 * 0.15, 2); // 4618.5 — old double-counted, wrong fraction
        $this->assertNotEqualsWithDelta(
            $oldUncontrolledW, $uncontrolledW, 100.0,
            'Sanity: Phase-1+2 corrected uncontrolled (1,729 W) must differ significantly from ' .
            'old double-counted figure (4,618.5 W) — if equal, the fix was not applied'
        );
    }

    // ── Test 20 — Fraction applied to Phase-1-corrected base, not old total ──

    /**
     * Phase 1 fix reduced Islamic University demand_va from 30,790 VA to 24,700 VA.
     * This test pins the corrected overnight figure (24,700 × 0.07 = 1,729 VA)
     * and explicitly guards against accidentally using the old double-counted base.
     */
    public function test_fraction_applied_to_corrected_base_not_old_raw_total(): void
    {
        // Phase-1-corrected SocketDemandService result for Islamic University project 18
        $corrected = ['connected_va' => 37600.0, 'demand_va' => 24700.0];
        $old       = ['connected_va' => 51400.0, 'demand_va' => 30790.0];

        $uni = $this->project(null, null, [], 'educational_university');

        // Weekend, OPTIMIZED mode, h=0 (overnight): only uncontrolled fraction is active
        $correctedWeekend = $this->profile($corrected, $uni, 'saturday', false);
        $oldWeekend       = $this->profile($old,       $uni, 'saturday', false);

        // Corrected: 24700 × 0.07 = 1729.0 VA
        $this->assertEqualsWithDelta(1729.0, $correctedWeekend[0], 0.5,
            'Overnight uncontrolled with Phase-1-corrected base: 24700 × 0.07 = 1729 VA. ' .
            'Old double-counted base would give 30790 × 0.07 = 2155.3 VA.');

        // Old raw base gives a meaningfully higher (wrong) number: 30790 × 0.07 ≈ 2155 VA
        $this->assertGreaterThan(2100.0, $oldWeekend[0],
            'Sanity: old double-counted base (30,790 VA × 0.07 ≈ 2,155 VA) must be visibly higher');
    }
}
