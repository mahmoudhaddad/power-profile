<?php

namespace Database\Seeders;

use App\Models\Building;
use App\Models\ComponentType;
use App\Models\Floor;
use App\Models\Project;
use App\Models\Room;
use App\Models\RoomComponent;
use App\Models\Socket;
use App\Models\User;
use App\Models\UtilityLine;
use Illuminate\Database\Seeder;

/**
 * Builds a fully deterministic "Validation Reference" project that lets us
 * compare the system's computed totals against manually-verified equations.
 *
 * Only runs outside production (APP_ENV != production).
 *
 * Project:  "Validation Reference — 2-Floor Office"
 * Building: "Office Block A"  (type: office)
 *   Floor 1 "Ground Floor"  →  Room "Open Office"   (type: office_open, DF=0.80)
 *     - LED Lighting        2000 VA, PF 1.00
 *     - Desktop Computers   3000 VA, PF 0.85
 *     - Air Conditioning    5000 VA, PF 0.90
 *     - 20 socket outlets
 *   Floor 2 "First Floor"   →  Room "Meeting Room"  (type: meeting_room, DF=0.70)
 *     - LED Lighting         800 VA, PF 1.00
 *     - Projector            500 VA, PF 0.95
 *     - 8 socket outlets
 * Utility: Grid, 50 000 VA (50 kVA), tariff $0.12/kWh
 */
class ValidationCaseStudySeeder extends Seeder
{
    /** Project name used as a stable lookup key — do not change. */
    public const PROJECT_NAME = 'Validation Reference — 2-Floor Office';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->warn('ValidationCaseStudySeeder skipped: not allowed in production.');
            return;
        }

        // ── Idempotent: wipe any previous run of this seeder ────────────────
        $existing = Project::where('name', self::PROJECT_NAME)->first();
        if ($existing) {
            // Cascades delete everything (buildings → floors → rooms → components, sockets)
            $existing->delete();
            $this->command->line('  Deleted previous validation project.');
        }

        // ── Owner: prefer the admin user, fall back to any existing user ────
        $owner = User::where('is_admin', true)->first()
            ?? User::first();

        if (! $owner) {
            $this->command->error('No user found. Run AdminUserSeeder first.');
            return;
        }

        // ── Project ─────────────────────────────────────────────────────────
        $project = Project::create([
            'user_id'         => $owner->id,
            'name'            => self::PROJECT_NAME,
            'building_type'   => 'office',
            'currency_symbol' => '$',
        ]);

        // ── Utility line ─────────────────────────────────────────────────────
        UtilityLine::create([
            'lineable_type'  => Project::class,
            'lineable_id'    => $project->id,
            'name'           => 'Grid Supply',
            'power'          => 50_000,   // 50 kVA
            'phases'         => '3phase',
            'tariff_per_kwh' => 0.12,
        ]);

        // ── Building ─────────────────────────────────────────────────────────
        $building = Building::create([
            'project_id' => $project->id,
            'name'       => 'Office Block A',
            'type'       => 'office',
            'area'       => 500,
        ]);

        // ── Component types (findOrCreate so seeder is idempotent) ───────────
        $typeLighting = ComponentType::firstOrCreate(
            ['name' => 'LED Panel Lighting'],
            ['is_preset' => false]
        );
        $typeComputer = ComponentType::firstOrCreate(
            ['name' => 'Desktop Computer Workstation'],
            ['is_preset' => false]
        );
        $typeAc = ComponentType::firstOrCreate(
            ['name' => 'Air Conditioning Unit (Split)'],
            ['is_preset' => false]
        );
        $typeProjector = ComponentType::firstOrCreate(
            ['name' => 'Presentation Projector'],
            ['is_preset' => false]
        );

        // ── Floor 1 — Ground Floor ───────────────────────────────────────────
        $floor1 = Floor::create([
            'building_id' => $building->id,
            'name'        => 'Ground Floor',
            'area'        => 250,
        ]);

        // Room: Open Office  (room_type=office_open → coincidence DF = 0.80 from DiversityFactorService)
        $openOffice = Room::create([
            'floor_id' => $floor1->id,
            'name'     => 'Open Office',
            'type'     => 'office_open',  // DiversityFactorService::ROOM_DFS['office_open'] = 0.80
            'area'     => 200,
        ]);

        RoomComponent::create([
            'room_id'           => $openOffice->id,
            'component_type_id' => $typeLighting->id,
            'power'             => 2000,
            'power_factor'      => 1.00,
            'quantity'          => 1,
            'priority'          => 'normal',
            'usage_time_intervals' => json_encode([['start' => '08:00', 'end' => '18:00']]),
            'usage_season'      => 'all',
            'usage_day_type'    => 'all',
        ]);

        RoomComponent::create([
            'room_id'           => $openOffice->id,
            'component_type_id' => $typeComputer->id,
            'power'             => 3000,
            'power_factor'      => 0.85,
            'quantity'          => 1,
            'priority'          => 'normal',
            'usage_time_intervals' => json_encode([['start' => '08:00', 'end' => '18:00']]),
            'usage_season'      => 'all',
            'usage_day_type'    => 'all',
        ]);

        RoomComponent::create([
            'room_id'           => $openOffice->id,
            'component_type_id' => $typeAc->id,
            'power'             => 5000,
            'power_factor'      => 0.90,
            'quantity'          => 1,
            'priority'          => 'normal',
            'usage_time_intervals' => json_encode([['start' => '08:00', 'end' => '18:00']]),
            'usage_season'      => 'all',
            'usage_day_type'    => 'all',
        ]);

        Socket::create([
            'socketable_type' => Room::class,
            'socketable_id'   => $openOffice->id,
            'phase_type'      => '1phase',
            'power'           => 200,
            'quantity'        => 20,
        ]);

        // ── Floor 2 — First Floor ─────────────────────────────────────────────
        $floor2 = Floor::create([
            'building_id' => $building->id,
            'name'        => 'First Floor',
            'area'        => 250,
        ]);

        // Room: Meeting Room  (room_type=meeting_room → coincidence DF = 0.70)
        $meetingRoom = Room::create([
            'floor_id' => $floor2->id,
            'name'     => 'Meeting Room',
            'type'     => 'meeting_room',  // DiversityFactorService::ROOM_DFS['meeting_room'] = 0.70
            'area'     => 80,
        ]);

        RoomComponent::create([
            'room_id'           => $meetingRoom->id,
            'component_type_id' => $typeLighting->id,
            'power'             => 800,
            'power_factor'      => 1.00,
            'quantity'          => 1,
            'priority'          => 'normal',
            'usage_time_intervals' => json_encode([['start' => '08:00', 'end' => '18:00']]),
            'usage_season'      => 'all',
            'usage_day_type'    => 'all',
        ]);

        RoomComponent::create([
            'room_id'           => $meetingRoom->id,
            'component_type_id' => $typeProjector->id,
            'power'             => 500,
            'power_factor'      => 0.95,
            'quantity'          => 1,
            'priority'          => 'normal',
            'usage_time_intervals' => json_encode([['start' => '08:00', 'end' => '18:00']]),
            'usage_season'      => 'all',
            'usage_day_type'    => 'all',
        ]);

        Socket::create([
            'socketable_type' => Room::class,
            'socketable_id'   => $meetingRoom->id,
            'phase_type'      => '1phase',
            'power'           => 200,
            'quantity'        => 8,
        ]);

        $this->command->info("  Validation project created (ID {$project->id}), owned by {$owner->email}.");
    }
}
