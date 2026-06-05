<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BuildingComponent;
use App\Models\Floor;
use App\Models\FloorComponent;
use App\Models\Project;
use App\Models\Room;
use App\Models\RoomComponent;
use App\Services\DiversityFactorService;
use App\Services\SocketDemandService;
use App\Services\ValidationReferenceService;
use Database\Seeders\ValidationCaseStudySeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/validation/case-study
 *
 * 1. Locates the validation reference project seeded by ValidationCaseStudySeeder.
 * 2. Runs the real production calculation path (same code as TotalPowerController).
 * 3. Runs the independent ValidationReferenceService calculation.
 * 4. Returns both results and a field-by-field comparison table.
 */
class ValidationController extends Controller
{
    private const TOLERANCE_PCT = 0.1;   // PASS if |diff| ≤ 0.1 %
    private const DF_PROJECT    = 0.7;
    private const TARGET_PF     = 0.95;
    private const CAP_STEP_KVAR = 0.5;
    private const VOLTAGE_LL    = 400;
    private const FREQUENCY     = 50;

    public function __construct(private SocketDemandService $sockets) {}

    public function show(Request $request): JsonResponse
    {
        // ── 1. Find the validation project ──────────────────────────────────
        $project = Project::where('name', ValidationCaseStudySeeder::PROJECT_NAME)->first();

        if (! $project) {
            return response()->json([
                'error' => 'Validation project not found. Run: php artisan db:seed --class=ValidationCaseStudySeeder',
            ], 404);
        }

        // ── 2. Run the production calculation (mirrors TotalPowerController::project) ──
        $systemResult = $this->runProductionCalc($project);

        // ── 3. Run independent reference ─────────────────────────────────────
        $reference = ValidationReferenceService::compute();

        // ── 4. Build comparison table ─────────────────────────────────────────
        $fields = [
            [
                'field'           => 'Total Apparent Power (VA)',
                'system_key'      => 'total_va',
                'reference_key'   => 'total_va',
                'unit'            => 'VA',
            ],
            [
                'field'           => 'Total Active Power (W)',
                'system_key'      => 'total',
                'reference_key'   => 'total_w',
                'unit'            => 'W',
            ],
            [
                'field'           => 'Total Reactive Power (kVAR)',
                'system_key'      => 'total_kvar',
                'reference_key'   => 'total_kvar',
                'unit'            => 'kVAR',
            ],
            [
                'field'           => 'Max Apparent Power (VA)',
                'system_key'      => 'max_va',
                'reference_key'   => 'max_va',
                'unit'            => 'VA',
            ],
            [
                'field'           => 'System Power Factor',
                'system_key'      => 'system_power_factor',
                'reference_key'   => 'system_power_factor',
                'unit'            => '—',
            ],
            [
                'field'           => 'Socket Demand (VA)',
                'system_key'      => 'socket_demand_va',
                'reference_key'   => 'socket_demand_va',
                'unit'            => 'VA',
            ],
            [
                'field'           => 'Socket Connected (VA)',
                'system_key'      => 'socket_connected_va',
                'reference_key'   => 'socket_connected_va',
                'unit'            => 'VA',
            ],
            [
                'field'           => 'Component Active Power (W, diversified)',
                'system_key'      => 'room_w',
                'reference_key'   => 'component_w',
                'unit'            => 'W',
            ],
            [
                'field'           => 'PF Correction Recommended',
                'system_key'      => 'pf_correction_recommended',
                'reference_key'   => 'pf_correction_recommended',
                'unit'            => 'bool',
            ],
        ];

        $comparison = [];
        $allPass    = true;

        foreach ($fields as $f) {
            $sysVal = $systemResult[$f['system_key']] ?? null;
            $refVal = $reference[$f['reference_key']] ?? null;

            if ($sysVal === null || $refVal === null) {
                $comparison[] = array_merge($f, [
                    'system_value'       => $sysVal,
                    'reference_value'    => $refVal,
                    'difference_percent' => null,
                    'status'             => 'SKIP',
                ]);
                continue;
            }

            if ($f['unit'] === 'bool') {
                $pass   = ($sysVal == $refVal);
                $allPass = $allPass && $pass;
                $comparison[] = array_merge($f, [
                    'system_value'       => $sysVal,
                    'reference_value'    => $refVal,
                    'difference_percent' => null,
                    'status'             => $pass ? 'PASS' : 'FAIL',
                ]);
                continue;
            }

            $refF    = (float) $refVal;
            $sysF    = (float) $sysVal;
            $diffPct = $refF != 0 ? abs(($sysF - $refF) / $refF) * 100 : 0.0;
            $pass    = $diffPct <= self::TOLERANCE_PCT;
            $allPass = $allPass && $pass;

            $comparison[] = array_merge($f, [
                'system_value'       => round($sysF, 4),
                'reference_value'    => round($refF, 4),
                'difference_percent' => round($diffPct, 4),
                'status'             => $pass ? 'PASS' : 'FAIL',
            ]);
        }

        return response()->json([
            'project_id'       => $project->id,
            'project_name'     => $project->name,
            'overall_status'   => $allPass ? 'PASS' : 'FAIL',
            'tolerance_used'   => self::TOLERANCE_PCT . '%',
            'system_result'    => $systemResult,
            'reference_answer' => $reference,
            'comparison'       => $comparison,
        ]);
    }

    // ── Production calculation (same logic as TotalPowerController::project) ──

    private function runProductionCalc(Project $project): array
    {
        $projectBuildings = $project->buildings()->select('id', 'type')->get();
        $bTypeMap = $projectBuildings->pluck('type', 'id');

        $allFloors = Floor::whereIn('building_id', $projectBuildings->pluck('id'))
            ->select('id', 'building_id')->get();
        $fToBMap = $allFloors->pluck('building_id', 'id');

        $allRooms = Room::whereIn('floor_id', $allFloors->pluck('id'))
            ->select('id', 'floor_id', 'type')->get();
        $rToBMap  = $allRooms->mapWithKeys(fn($r) => [$r->id => $fToBMap->get($r->floor_id)]);
        $rTypeMap = $allRooms->pluck('type', 'id');

        $roomDfFn = function (int $rid) use ($rToBMap, $bTypeMap, $rTypeMap): float {
            $bType = $bTypeMap->get($rToBMap->get($rid));
            $rType = $rTypeMap->get($rid);
            $dfs   = DiversityFactorService::buildingDfs($bType);
            return DiversityFactorService::roomDf($rType)
                 * $dfs['room_to_floor']
                 * $dfs['floor_to_building']
                 * self::DF_PROJECT;
        };

        $floorDfFn = function (int $fid) use ($fToBMap, $bTypeMap): float {
            $bType = $bTypeMap->get($fToBMap->get($fid));
            return DiversityFactorService::buildingDfs($bType)['floor_to_building'] * self::DF_PROJECT;
        };

        $own      = $this->sumPower($project->components(), 'project_id');
        $building = $this->sumPower(
            BuildingComponent::whereHas('building', fn($q) => $q->where('project_id', $project->id)),
            'building_id', self::DF_PROJECT
        );
        $floor    = $this->sumPower(
            FloorComponent::whereHas('floor.building', fn($q) => $q->where('project_id', $project->id)),
            'floor_id', $floorDfFn
        );
        $room     = $this->sumPower(
            RoomComponent::whereHas('room.floor.building', fn($q) => $q->where('project_id', $project->id)),
            'room_id', $roomDfFn
        );

        $comp_w = $own['w'] + $building['w'] + $floor['w'] + $room['w'];
        $comp_q = $own['q'] + $building['q'] + $floor['q'] + $room['q'];
        $max_w_comp = $own['max_w'] + $building['max_w'] + $floor['max_w'] + $room['max_w'];
        $max_q_comp = $own['max_q'] + $building['max_q'] + $floor['max_q'] + $room['max_q'];

        $sd              = $this->sockets->projectResult($project);
        $socketDemand    = $sd['demand_va'];
        $socketConnected = $sd['connected_va'];

        $sinPf   = sin(acos(self::TARGET_PF));
        $total_w = $comp_w + $socketDemand * self::TARGET_PF;
        $total_q = $comp_q + $socketDemand * $sinPf;
        $total_va = round(sqrt($total_w ** 2 + $total_q ** 2), 2);
        $total_w  = round($total_w, 2);

        $max_w = $max_w_comp + $socketConnected * self::TARGET_PF;
        $max_q = $max_q_comp + $socketConnected * $sinPf;
        $max_va = round(sqrt($max_w ** 2 + $max_q ** 2), 2);

        $pf_sys          = $total_va > 0 ? round($total_w / $total_va, 3) : 1.0;
        $total_kvar      = round($total_q / 1000, 2);
        $pf_correction   = $pf_sys < 0.85;

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

        return [
            'total_va'                   => $total_va,
            'total'                      => $total_w,
            'total_kvar'                 => $total_kvar,
            'max_va'                     => $max_va,
            'system_power_factor'        => $pf_sys,
            'pf_correction_recommended'  => $pf_correction,
            'capacitor_bank_kvar'        => $cap_bank_kvar,
            'capacitor_bank_uf'          => $cap_uf,
            'socket_demand_va'           => $socketDemand,
            'socket_connected_va'        => $socketConnected,
            'room_w'                     => round($room['w'], 2),
            'room_q'                     => round($room['q'], 4),
        ];
    }

    private function sumPower($query, string $entityKey, mixed $df = 1.0): array
    {
        $components = $query->get(['power', 'power_factor', 'quantity', 'group_name', 'priority', $entityKey]);

        $w = $q = $mw = $mq = 0.0;
        $groups = [];

        foreach ($components as $c) {
            $pf  = max((float) ($c->power_factor ?? 1), 0.01);
            $va  = (float) $c->power * (int) $c->quantity;
            $cw  = $va * $pf;
            $cq  = $pf < 1.0 ? $cw * tan(acos(min(1.0, $pf))) : 0.0;
            $mw += $cw;
            $mq += $cq;

            $resolvedDf  = is_callable($df) ? $df((int) $c->{$entityKey}) : (float) $df;
            $effectiveDf = ($c->priority === 'critical') ? 1.0 : $resolvedDf;

            if (! $c->group_name) {
                $w += $cw * $effectiveDf;
                $q += $cq * $effectiveDf;
            } else {
                $key = $c->{$entityKey} . '|' . $c->group_name;
                if (! isset($groups[$key]) || $va > $groups[$key]['va']) {
                    $groups[$key] = ['va' => $va, 'w' => $cw * $effectiveDf, 'q' => $cq * $effectiveDf];
                }
            }
        }

        foreach ($groups as $g) {
            $w += $g['w'];
            $q += $g['q'];
        }

        return ['w' => $w, 'q' => $q, 'max_w' => $mw, 'max_q' => $mq];
    }
}
