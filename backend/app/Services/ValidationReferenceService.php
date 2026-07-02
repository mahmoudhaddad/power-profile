<?php

namespace App\Services;

/**
 * Independent reference implementation of the power calculations for the
 * "Validation Reference — 2-Floor Office" case study.
 *
 * This class does NOT call DiversityFactorService, SocketDemandService, or
 * TotalPowerController.  It re-derives every value from first principles using
 * the same mathematical formulas so the two results can be compared to prove
 * the production code is correct.
 *
 * Formulas used (IEC 60364-8-1 / PENRA / BS 7671):
 *   S [VA] = √(P² + Q²)         Apparent power
 *   P [W]  = S × cos(φ)         Active power
 *   Q [VAR]= P × tan(arccos(PF)) Reactive power
 *   PF     = P / S               Power factor
 *
 * Diversity factor chain (office building, project level):
 *   DF_room = room_type_factor × room_to_floor × floor_to_building × project_df
 *
 * Socket demand (IEC tiered):
 *   first 10 outlets → 100 %  × 200 VA/outlet
 *   next  10 outlets → 75  %  × 200 VA/outlet
 *   rest             → 40  %  × 200 VA/outlet
 *
 * Capacitor bank (Δ, 400 V, 50 Hz):
 *   Q_cap = Q_sys − P × tan(arccos(0.95))
 *   C_phase_μF = (Q_cap / 3) / (2π × 50 × 400²) × 1e6
 */
class ValidationReferenceService
{
    // ── System constants (must mirror TotalPowerController) ──────────────────
    private const TARGET_PF      = 0.95;
    private const VOLTAGE_LL     = 400;    // V, 3-phase line-to-line
    private const FREQUENCY      = 50;     // Hz
    private const CAP_STEP_KVAR  = 0.5;   // standard bank step

    // ── Case-study inputs (IEC 60364-8-1 / DiversityFactorService) ──────────
    // Office building DFs (room→floor = 0.85, floor→building = 0.80)
    // Project DF = 0.70
    private const DF_ROOM_TO_FLOOR     = 0.85;
    private const DF_FLOOR_TO_BUILDING = 0.80;
    private const DF_PROJECT           = 0.70;

    // Room coincidence factors (CIBSE / BS 7671)
    private const DF_OPEN_OFFICE  = 0.80;   // 'office_open'  in DiversityFactorService
    private const DF_MEETING_ROOM = 0.70;   // 'meeting_room' in DiversityFactorService

    // Socket tiered demand
    private const OUTLET_VA = 200;

    // ── Computed once, read many times ───────────────────────────────────────

    /**
     * Return the full reference answer array.
     *
     * Keys match the ones returned by TotalPowerController::project() so the
     * ValidationController can zip them together for a field-by-field comparison.
     */
    public static function compute(): array
    {
        // ── Diversity factors per room ───────────────────────────────────────
        $dfOpen    = self::DF_OPEN_OFFICE
                   * self::DF_ROOM_TO_FLOOR
                   * self::DF_FLOOR_TO_BUILDING
                   * self::DF_PROJECT;   // 0.80 × 0.85 × 0.80 × 0.70 = 0.3808

        $dfMeeting = self::DF_MEETING_ROOM
                   * self::DF_ROOM_TO_FLOOR
                   * self::DF_FLOOR_TO_BUILDING
                   * self::DF_PROJECT;   // 0.70 × 0.85 × 0.80 × 0.70 = 0.3332

        // ── Component contributions (W and Q, diversified) ───────────────────
        // Ground Floor — Open Office
        [$w_light_f1, $q_light_f1]    = self::component(2000, 1.00, $dfOpen);
        [$w_computers, $q_computers]   = self::component(3000, 0.85, $dfOpen);
        [$w_ac,        $q_ac]          = self::component(5000, 0.90, $dfOpen);

        // First Floor — Meeting Room
        [$w_light_f2, $q_light_f2]    = self::component(800,  1.00, $dfMeeting);
        [$w_projector, $q_projector]   = self::component(500,  0.95, $dfMeeting);

        $comp_w = $w_light_f1 + $w_computers + $w_ac + $w_light_f2 + $w_projector;
        $comp_q = $q_light_f1 + $q_computers + $q_ac + $q_light_f2 + $q_projector;

        // ── Socket demand (SocketDemandService logic) ────────────────────────
        // Floor 1: 20 outlets → floor-level applyFactors(20)
        $f1_socket_demand = self::applySocketFactors(20);  // 3500
        // Floor 2: 8 outlets → applyFactors(8)
        $f2_socket_demand = self::applySocketFactors(8);   // 1600
        // Building rawDemand = 3500 + 1600 = 5100 → cf=1.00 (5.1 kVA < 50 kVA)
        $raw_demand       = $f1_socket_demand + $f2_socket_demand;
        $cf               = self::coincidenceFactor($raw_demand);
        $socket_demand_va = round($raw_demand * $cf, 2);
        $socket_connected_va = (20 + 8) * self::OUTLET_VA;   // 5600

        // ── Total power (TotalPowerController logic) ──────────────────────────
        // Sockets are assumed to run at TARGET_PF for W and reactive contribution
        $sinPf   = sin(acos(self::TARGET_PF));  // sin(arccos(0.95)) = 0.31225
        $total_w = $comp_w + $socket_demand_va * self::TARGET_PF;
        $total_q = $comp_q + $socket_demand_va * $sinPf;
        $total_va = sqrt($total_w ** 2 + $total_q ** 2);
        $pf_sys   = $total_va > 0 ? $total_w / $total_va : 1.0;
        $pf_sys   = round($pf_sys, 3);
        $total_kvar = round($total_q / 1000, 2);

        // PF correction (PENRA threshold = 0.85)
        $pf_correction = $pf_sys < 0.85;
        $cap_bank_kvar = null;
        $cap_uf        = null;
        if ($pf_correction) {
            $q_target     = $total_w * tan(acos(self::TARGET_PF));
            $q_cap_var    = $total_q - $q_target;
            $cap_bank_kvar = ceil(($q_cap_var / 1000) / self::CAP_STEP_KVAR) * self::CAP_STEP_KVAR;
            $q_per_phase   = ($cap_bank_kvar * 1000) / 3;
            $cap_uf        = round(
                ($q_per_phase / (2 * M_PI * self::FREQUENCY * self::VOLTAGE_LL ** 2)) * 1e6,
                2
            );
        }

        // ── Max (un-diversified) vectors ─────────────────────────────────────
        [$max_w_comps, $max_q_comps] = self::maxComponents();
        $max_w = $max_w_comps + $socket_connected_va * self::TARGET_PF;
        $max_q = $max_q_comps + $socket_connected_va * $sinPf;
        $max_va = round(sqrt($max_w ** 2 + $max_q ** 2), 2);

        return [
            // ── Key totals ────────────────────────────────────────────────────
            'total_va'   => round($total_va, 2),
            'total_w'    => round($total_w,  2),
            'total_kvar' => $total_kvar,
            'max_va'     => $max_va,

            // ── Reactive / PF ─────────────────────────────────────────────────
            'system_power_factor'        => $pf_sys,
            'pf_correction_recommended'  => $pf_correction,
            'capacitor_bank_kvar'        => $cap_bank_kvar,
            'capacitor_bank_uf'          => $cap_uf,

            // ── Sockets ───────────────────────────────────────────────────────
            'socket_demand_va'    => $socket_demand_va,
            'socket_connected_va' => (float) $socket_connected_va,

            // ── Component-only totals (before sockets) ────────────────────────
            'component_w'    => round($comp_w, 2),
            'component_q'    => round($comp_q, 2),

            // ── Per-component breakdown (for the description table) ───────────
            'breakdown' => [
                'df_open_office'   => round($dfOpen,    4),
                'df_meeting_room'  => round($dfMeeting, 4),
                'floor1_socket_demand'  => $f1_socket_demand,
                'floor2_socket_demand'  => $f2_socket_demand,
                'socket_coincidence_cf' => $cf,
                'components' => [
                    ['name' => 'LED Lighting (Open Office)',  'va' => 2000, 'pf' => 1.00, 'df' => round($dfOpen, 4),
                     'w_demand' => round($w_light_f1, 2), 'q_demand' => round($q_light_f1, 2)],
                    ['name' => 'Desktop Computers',          'va' => 3000, 'pf' => 0.85, 'df' => round($dfOpen, 4),
                     'w_demand' => round($w_computers,  2), 'q_demand' => round($q_computers,  2)],
                    ['name' => 'Air Conditioning',           'va' => 5000, 'pf' => 0.90, 'df' => round($dfOpen, 4),
                     'w_demand' => round($w_ac,         2), 'q_demand' => round($q_ac,         2)],
                    ['name' => 'LED Lighting (Meeting Room)','va' =>  800, 'pf' => 1.00, 'df' => round($dfMeeting, 4),
                     'w_demand' => round($w_light_f2, 2), 'q_demand' => round($q_light_f2, 2)],
                    ['name' => 'Projector',                  'va' =>  500, 'pf' => 0.95, 'df' => round($dfMeeting, 4),
                     'w_demand' => round($w_projector,  2), 'q_demand' => round($q_projector,  2)],
                ],
                'df_formula' => 'DF = room_type_factor × room_to_floor(0.85) × floor_to_building(0.80) × project(0.70)',
                'socket_formula' => 'first 10 → 100% × 200 VA; next 10 → 75% × 200 VA; rest → 40% × 200 VA',
                'total_formula' => 'S = √(P² + Q²);  sockets contribute at PF=0.95 assumption',
            ],
        ];
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /** Compute diversified [W, Q] for one component. */
    private static function component(float $va, float $pf, float $df): array
    {
        $w = $va * $pf;
        $q = $pf < 1.0 ? $w * tan(acos(min(1.0, $pf))) : 0.0;
        return [$w * $df, $q * $df];
    }

    /** Un-diversified [W, Q] for all components (max demand). */
    private static function maxComponents(): array
    {
        $specs = [
            [2000, 1.00], [3000, 0.85], [5000, 0.90],   // Open Office
            [800,  1.00], [500,  0.95],                  // Meeting Room
        ];
        $mw = $mq = 0.0;
        foreach ($specs as [$va, $pf]) {
            $w   = $va * $pf;
            $q   = $pf < 1.0 ? $w * tan(acos(min(1.0, $pf))) : 0.0;
            $mw += $w;
            $mq += $q;
        }
        return [$mw, $mq];
    }

    /** Tiered socket demand (mirrors SocketDemandService::applyFactors). */
    private static function applySocketFactors(int $n): float
    {
        return min($n, 10)               * self::OUTLET_VA * 1.00
             + min(max($n - 10, 0), 10) * self::OUTLET_VA * 0.75
             + max($n - 20, 0)          * self::OUTLET_VA * 0.40;
    }

    /** Coincidence factor (mirrors SocketDemandService::coincidenceFactor). */
    private static function coincidenceFactor(float $demandVA): float
    {
        $kva = $demandVA / 1000;
        if ($kva < 50)   return 1.00;
        if ($kva <= 250) return 0.92;
        return 0.85;
    }
}
