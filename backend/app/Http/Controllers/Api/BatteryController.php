<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBatteryRequest;
use App\Http\Requests\UpdateBatteryRequest;
use App\Models\Battery;
use App\Models\Project;
use App\Services\BatteryChemistryService;
use Illuminate\Http\Request;

class BatteryController extends Controller
{
    // ── 1. GET /api/projects/{project}/batteries ─────────────────────────────

    public function index(Request $request, Project $project)
    {
        if (! $project->userRole($request->user()->id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $batteries = $project->batteries()->orderBy('created_at')->get();

        return response()->json([
            'data'  => $batteries,
            'count' => $batteries->count(),
        ]);
    }

    // ── 2. POST /api/projects/{project}/batteries ─────────────────────────────

    public function store(StoreBatteryRequest $request, Project $project)
    {
        $role = $project->userRole($request->user()->id);
        if (! $role) return response()->json(['error' => 'Forbidden'], 403);
        if (! in_array($role, ['admin', 'main'])) {
            return response()->json(['error' => 'Read-only access'], 403);
        }

        $validated = $request->validated();
        $defaults  = BatteryChemistryService::getDefaults($validated['chemistry']);

        // Fill operating params from chemistry defaults when not supplied
        foreach (['depth_of_discharge', 'round_trip_efficiency', 'c_rate_charge', 'c_rate_discharge', 'rated_cycle_life'] as $field) {
            if (! isset($validated[$field]) || $validated[$field] === null) {
                $validated[$field] = $defaults[$field];
            }
        }

        if (! isset($validated['current_soc']) || $validated['current_soc'] === null) {
            $validated['current_soc'] = 0.50;
        }

        $battery = $project->batteries()->create($validated);

        return response()->json(['data' => $battery], 201);
    }

    // ── 3. GET /api/batteries/{battery} ──────────────────────────────────────

    public function show(Request $request, Battery $battery)
    {
        if (! $battery->project->userRole($request->user()->id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json(['data' => $battery]);
    }

    // ── 4. PUT /api/batteries/{battery} ──────────────────────────────────────

    public function update(UpdateBatteryRequest $request, Battery $battery)
    {
        $role = $battery->project->userRole($request->user()->id);
        if (! $role) return response()->json(['error' => 'Forbidden'], 403);
        if (! in_array($role, ['admin', 'main'])) {
            return response()->json(['error' => 'Read-only access'], 403);
        }

        $battery->update($request->validated());

        return response()->json(['data' => $battery->fresh()]);
    }

    // ── 5. DELETE /api/batteries/{battery} ───────────────────────────────────

    public function destroy(Request $request, Battery $battery)
    {
        $role = $battery->project->userRole($request->user()->id);
        if (! $role) return response()->json(['error' => 'Forbidden'], 403);
        if (! in_array($role, ['admin', 'main'])) {
            return response()->json(['error' => 'Read-only access'], 403);
        }

        $battery->delete();

        return response()->json(null, 204);
    }

    // ── 6. POST /api/batteries/{battery}/reset-soc ───────────────────────────

    public function resetSoc(Request $request, Battery $battery)
    {
        $role = $battery->project->userRole($request->user()->id);
        if (! $role) return response()->json(['error' => 'Forbidden'], 403);
        if (! in_array($role, ['admin', 'main'])) {
            return response()->json(['error' => 'Read-only access'], 403);
        }

        $request->validate(['soc' => 'required|numeric|min:0.0|max:1.0']);

        $battery->update(['current_soc' => round((float) $request->input('soc'), 3)]);

        return response()->json(['data' => $battery->fresh()]);
    }

    // ── 7. POST /api/batteries/{battery}/runtime-at-load ─────────────────────

    public function runtimeAtLoad(Request $request, Battery $battery)
    {
        if (! $battery->project->userRole($request->user()->id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $request->validate(['load_kw' => 'required|numeric']);

        $loadKw = (float) $request->input('load_kw');
        if ($loadKw <= 0) {
            return response()->json(['error' => 'Load must be positive'], 422);
        }

        if ($loadKw > $battery->max_discharge_power_kw) {
            return response()->json([
                'load_kw'               => $loadKw,
                'runtime_hours_full'    => null,
                'runtime_hours_current' => null,
                'warning'               => "Load exceeds maximum discharge power of {$battery->max_discharge_power_kw} kW",
            ]);
        }

        return response()->json([
            'load_kw'               => $loadKw,
            'runtime_hours_full'    => round($battery->usable_capacity_kwh    / $loadKw, 2),
            'runtime_hours_current' => round($battery->current_available_kwh  / $loadKw, 2),
            'warning'               => null,
        ]);
    }

    // ── 8. GET /api/projects/{project}/battery-runtime ───────────────────────
    //
    // Accepts optional query params: critical_kw, optimized_kw
    // (pass these from the frontend's cached total-power response)

    public function projectRuntime(Request $request, Project $project)
    {
        if (! $project->userRole($request->user()->id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $batteries = $project->batteries()->where('is_active', true)->get();

        $zeroed = [
            'active_bank_count'      => 0,
            'total_usable_kwh'       => 0,
            'total_max_discharge_kw' => 0,
            'average_soc'            => 0,
            'available_now_kwh'      => 0,
        ];

        if ($batteries->isEmpty()) {
            return response()->json([
                'battery_summary'            => $zeroed,
                'runtime_against_critical'   => null,
                'runtime_against_optimized'  => null,
            ]);
        }

        $totalUsable    = round($batteries->sum(fn($b) => $b->usable_capacity_kwh),    2);
        $totalAvailable = round($batteries->sum(fn($b) => $b->current_available_kwh),  2);
        $totalDischarge = round($batteries->sum(fn($b) => $b->max_discharge_power_kw), 2);
        $avgSoc         = $totalUsable > 0
            ? round($totalAvailable / $totalUsable, 3)
            : 0.0;

        $summary = [
            'active_bank_count'      => $batteries->count(),
            'total_usable_kwh'       => $totalUsable,
            'total_max_discharge_kw' => $totalDischarge,
            'average_soc'            => $avgSoc,
            'available_now_kwh'      => $totalAvailable,
        ];

        // Load values come from query params (provided by frontend from total-power API)
        $criticalKw  = max(0, (float) $request->query('critical_kw',  0));
        $optimizedKw = max(0, (float) $request->query('optimized_kw', 0));

        return response()->json([
            'battery_summary'           => $summary,
            'runtime_against_critical'  => $this->runtimeBlock('critical', $criticalKw,  $totalUsable, $totalAvailable, $totalDischarge, $avgSoc),
            'runtime_against_optimized' => $this->runtimeBlock('optimized', $optimizedKw, $totalUsable, $totalAvailable, $totalDischarge, $avgSoc),
        ]);
    }

    private function runtimeBlock(
        string $label, float $loadKw,
        float $totalUsable, float $totalAvailable,
        float $totalDischarge, float $avgSoc
    ): array {
        $loadKey = "{$label}_load_kw";

        if ($loadKw <= 0) {
            return [
                $loadKey                => $loadKw,
                'runtime_hours_full'    => null,
                'runtime_hours_current' => null,
                'can_sustain'           => true,
                'status_message'        => "No {$label} load to sustain",
            ];
        }

        if ($totalUsable <= 0) {
            return [
                $loadKey                => $loadKw,
                'runtime_hours_full'    => 0,
                'runtime_hours_current' => 0,
                'can_sustain'           => false,
                'status_message'        => "Battery has no usable capacity — needs replacement",
            ];
        }

        if ($loadKw > $totalDischarge) {
            return [
                $loadKey                => $loadKw,
                'runtime_hours_full'    => null,
                'runtime_hours_current' => null,
                'can_sustain'           => false,
                'status_message'        => "Cannot sustain — discharge power insufficient (need {$loadKw} kW, have {$totalDischarge} kW)",
            ];
        }

        $rtFull    = round($totalUsable    / $loadKw, 2);
        $rtCurrent = round($totalAvailable / $loadKw, 2);
        $socPct    = round($avgSoc * 100, 1);

        return [
            $loadKey                => $loadKw,
            'runtime_hours_full'    => $rtFull,
            'runtime_hours_current' => $rtCurrent,
            'can_sustain'           => true,
            'status_message'        => "Battery can sustain {$loadKw} kW for {$rtFull} h from full charge ({$rtCurrent} h from current SOC of {$socPct}%)",
        ];
    }

    // ── 9. GET /api/battery-chemistry-defaults (public) ──────────────────────

    public function chemistryDefaults()
    {
        return response()->json(BatteryChemistryService::all());
    }
}
