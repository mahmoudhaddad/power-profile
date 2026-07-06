# Power Profile — Master Technical Documentation

**Version:** 1.2 · **Date:** 2026-07-06 · **Author:** Ahmed Zoher  
**Purpose:** Master reference for graduation report and defense presentation.  
All facts verified against actual source code with file:line citations.  
Items that could not be confirmed in code are marked **⚠ NOT CONFIRMED IN CODE**.  
**Changes in v1.1:** Phase-balance algorithm (LPT + iterative re-split), two-page consistency model, target-SOC look-ahead dispatch engine, battery chemistry table and chemistry comparison feature, SLD hybrid-inverter topology, new tests, updated walkthrough and glossary.  
**Changes in v1.2:** Demand-side load-shedding system (LoadSheddingService — full new section 8.3); `shiftCapW` solar+utility-only cap for shift-target selection; round-trip-waste guard confirmed and cited; age_factor unit fix (days÷365.25 = fractional years); battery display format confirmed (stored · usable · nominal); RESTORE_MARGIN added to tunable constants; test suite updated to 371 tests / 1247 assertions; LoadSheddingServiceTest (8 tests) added to Section 13; shedding alert banner added to page walkthrough (17.6); new "Validation & Corrections Applied" section (Section 20); glossary extended with curtailable, hysteresis, critical\_unmet\_kwh, shiftCapW.

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Architecture and Technology Stack](#2-architecture-and-technology-stack)
3. [Database Schema](#3-database-schema)
4. [API Reference](#4-api-reference)
5. [Diversity Factors (IEC 60364-8-1)](#5-diversity-factors-iec-60364-8-1)
6. [Total Power Calculation](#6-total-power-calculation)
7. [Electrical Design Module (IEC 60364-5-52)](#7-electrical-design-module-iec-60364-5-52)
8. [Load Schedule and Energy Dispatch](#8-load-schedule-and-energy-dispatch)
9. [Solar Irradiance Model](#9-solar-irradiance-model)
10. [Battery and Storage Model](#10-battery-and-storage-model)
11. [Financial Analysis](#11-financial-analysis)
12. [Phase Balance Analysis](#12-phase-balance-analysis)
13. [Validation System and Automated Tests](#13-validation-system-and-automated-tests)
14. [Frontend Architecture](#14-frontend-architecture)
15. [Standards Referenced](#15-standards-referenced)
16. [Implemented vs Deferred Features](#16-implemented-vs-deferred-features)
17. [Page-by-Page User Walkthrough](#section-17--page-by-page-user-walkthrough)
18. [End-to-End Worked Example](#section-18--end-to-end-worked-example)
19. [Glossary](#section-19--glossary)
20. [Validation & Corrections Applied](#section-20--validation--corrections-applied)

---

## 1. Project Overview

**Power Profile** is a web-based electrical engineering design and energy management tool built as a graduation project. It targets building electrical designers, energy consultants, and project engineers working under Palestinian (PENRA) and IEC standards.

### Primary Capabilities

| Module | What It Computes |
|--------|-----------------|
| **Total Power** | Diversified apparent, active, and reactive power per room/floor/building/project with IEC 60364-8-1 diversity factors |
| **Electrical Design** | Full IEC 60364 panel schedules — circuit classification, breaker sizing, cable sizing (IEC 60364-5-52), phase assignment, voltage-drop per circuit |
| **Load Schedule** | 24-hour load profiles (max and optimized) × 7 days of week × any month; multi-source energy dispatch |
| **Phase Balance** | FFD greedy phase assignment, phasor neutral current, imbalance metric |
| **Solar Irradiance** | Location-aware hourly output using NASA POWER API with local PSH table fallback |
| **Battery BESS** | Multi-bank battery model; SOC tracking; runtime estimation |
| **Financial Analysis** | 25-year energy/cost projection; LCOE; generator fuel affine model; battery replacement scheduling |
| **Validation** | Live cross-check of all computed values against independently coded reference formulas; 36-test PHPUnit suite |

### System Context

- **Country target:** Palestine (PENRA grid: 230 V / 400 V, 50 Hz)
- **Design ambient temperature:** 40 °C (Palestinian summer design point)
- **Cable standard:** IEC 60364-5-52 Table B.52.2, Method A1 (most conservative — conduit in thermally insulated wall), PVC/Cu

---

## 2. Architecture and Technology Stack

### Backend

| Layer | Technology | Notes |
|-------|-----------|-------|
| Framework | Laravel 11 (PHP 8.2) | RESTful JSON API only |
| Auth | Laravel Sanctum + Google OAuth (Socialite) | Token-based; per-project role checks |
| ORM | Eloquent | No raw SQL in codebase (`api.php` comment, line 48) |
| Testing | PHPUnit 11 | 371 tests, 1247 assertions (26 deprecated notices, 0 failures) |
| Rate limiting | Laravel throttle middleware | Heavy: 20/min; optimizer/financial: 10/min; general: per `api-general` config |
| External API | NASA POWER (GHI) | 30-day cache; `ALLSKY_SFC_SW_DWN` parameter |

### Frontend

| Layer | Technology |
|-------|-----------|
| Framework | React 18 (Vite) |
| Routing | React Router v6 (lazy-loaded pages) |
| Styling | Tailwind CSS |
| HTTP client | Axios (`frontend/src/api/axios.js`) |
| Auth context | `AuthContext` with `useAuth()` hook |

### Directory Layout (key paths)

```
backend/
  app/
    Http/Controllers/Api/     — API controllers (one per domain)
    Services/                 — Business logic, calculations, formulas
    Models/                   — Eloquent models
  database/migrations/        — 55 migration files (2026-03-26 → 2026-06-03)
  routes/api.php              — All API route definitions
  tests/Feature/              — PHPUnit feature tests

frontend/
  src/
    pages/                    — React page components (21 pages)
    components/               — Reusable UI components
    contexts/AuthContext.jsx  — Auth state management
    api/axios.js              — Axios instance with base URL
    App.jsx                   — Router with lazy-loaded pages
```

### Security Design (`backend/routes/api.php` lines 36–49)

- **Authentication:** Sanctum token on every route
- **Authorization:** `$project->userRole($userId)` on every project-scoped endpoint
- **SQL injection:** Prevented by Eloquent ORM exclusively
- **CSRF:** Active on web routes; API routes use Sanctum token
- **Data exposure:** All responses scoped to the authenticated user's own projects
- **Roles:** `admin` (project owner), `main`, `normal`, `null` (no access) — `Project::userRole()` at `backend/app/Models/Project.php:66`

---

## 3. Database Schema

### Entity Hierarchy

```
users
  └── projects (user_id)
        ├── buildings (project_id)
        │     ├── floors (building_id)
        │     │     └── rooms (floor_id)
        │     │           └── room_components (room_id, component_type_id)
        │     ├── floor_components (floor_id)
        │     └── building_components (building_id)
        ├── project_components (project_id)
        ├── utility_lines (morphMany lineable)
        ├── generator_lines (morphMany generable)
        ├── sockets (morphMany socketable)
        ├── batteries (project_id)
        └── solar_systems (project_id)
```

Utility lines, generator lines, and sockets are **polymorphic** — they can be attached to any of: project, building, floor, or room.

### Key Tables and Columns

#### `projects`
| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `user_id` | FK → users | Owner |
| `name` | string | |
| `building_type` | string | Determines default DESIGN_RULES |
| `currency_symbol` | string | |
| `location_lat/lng` | decimal | For solar calculations |
| `location_name` | string | |
| `work_days` | json | Array of day names |
| `work_time_intervals` | json | Array of `{start, end}` |
| `working_season_intervals` | json | |
| `solar_source` | string | `'max'` or `'existing'` |
| `existing_solar_power` | decimal | W |
| `auto_backup_interval` | integer | |

Migration: `2026_03_26_142027_create_projects_table.php`

#### `room_components` (representative — building/floor/project components share same schema)
| Column | Type | Notes |
|--------|------|-------|
| `room_id` | FK → rooms | |
| `component_type_id` | FK → component_types | |
| `power` | decimal(10,2) | **Apparent power in VA** |
| `phases` | string | `'1phase'` or `'3phase'` |
| `power_factor` | decimal(4,2) | 0.01 – 1.00; default 1.00 |
| `quantity` | integer | |
| `priority` | string(20) | `'normal'`, `'essential'`, `'critical'` |
| `needs_socket` | boolean | → SOCKET circuit type |
| `group_name` | string | Group-max optimisation key |
| `usage_time_intervals` | json | Array of `{start, end}` |
| `usage_season` | string | `'all'`, `'summer'`, `'winter'` |
| `usage_day_type` | string | `'all'`, `'weekday'`, `'weekend'` |

Key migrations: `2026_04_20_120928`, `2026_05_16_100000`, `2026_04_27_300000`, `2026_05_05_100000`

#### `batteries`
| Column | Type | Notes |
|--------|------|-------|
| `project_id` | FK | |
| `solar_system_id` | FK → solar_systems (nullable) | When non-null, battery is DC-coupled to that solar system (shared hybrid inverter); used by SLD topology and dispatch engine |
| `chemistry` | string(50) | Key into `BatteryChemistryService` presets: `lead_acid_flooded`, `lead_acid_agm`, `lead_acid_gel`, `lithium_lfp`, `lithium_nmc` |
| `nominal_voltage_v` | decimal | |
| `capacity_ah_per_unit` | decimal | |
| `quantity` | integer | Total cells/units |
| `series_count` | integer | |
| `parallel_count` | integer | |
| `installation_date` | date | For age calculation; defaults to today on create so age_factor = 1.0 for new batteries |
| `depth_of_discharge` | decimal(4,3) | Set from chemistry preset on create/update (e.g., 0.850 for LFP) |
| `round_trip_efficiency` | decimal(4,3) | Set from chemistry preset (e.g., 0.920 for LFP) |
| `c_rate_charge` | decimal(4,2) | Set from chemistry preset |
| `c_rate_discharge` | decimal(4,2) | Set from chemistry preset |
| `rated_cycle_life` | integer | Set from chemistry preset |
| `current_soc` | decimal(4,3) | Default 0.500 |
| `is_active` | boolean | |

Migration: `2026_05_29_100000_create_batteries_table.php`; chemistry preset sync migration: `2026_07_05_195454_sync_battery_chemistry_presets.php`

#### `utility_lines`
| Column | Type |
|--------|------|
| `name`, `power` (VA), `phases` | |
| `tariff_per_kwh`, `peak_tariff_per_kwh` | float |
| `peak_hours_start`, `peak_hours_end` | integer (hour 0–23) |
| `lineable_type`, `lineable_id` | polymorphic |

#### `generator_lines`
| Column | Notes |
|--------|-------|
| `power` | VA |
| `fuel_consumption_lph` | Rated fuel consumption at 100% load (L/hr) |
| `no_load_fuel_lph` | Fuel at 0% load; defaults to 30% of rated if null |
| `fuel_cost_per_liter` | Currency/L |

#### `solar_systems`
| Column | Notes |
|--------|-------|
| `capacity_kw` | Named system capacity |
| `is_active` | boolean |
| `installation_cost`, `annual_maintenance_cost` | For LCOE |
| `panel_lifetime_years` | |

---

## 4. API Reference

Base: all routes require `Authorization: Bearer <sanctum-token>` unless noted.

### Project Management

| Method | Endpoint | Controller Method |
|--------|----------|-------------------|
| GET | `/api/projects` | ProjectController@index |
| POST | `/api/projects` | ProjectController@store |
| GET | `/api/projects/{project}` | ProjectController@show |
| PUT | `/api/projects/{project}` | ProjectController@update |
| DELETE | `/api/projects/{project}` | ProjectController@destroy |
| GET | `/api/projects/{project}/all-floors` | allFloors |
| GET | `/api/projects/{project}/all-rooms` | allRooms |
| GET | `/api/projects/{project}/shiftable-components` | shiftableComponents |
| POST | `/api/projects/{project}/optimize-shiftable` | optimizeShiftable (throttle 10/min) |
| GET | `/api/projects/{project}/defense-summary` | defenseSummary |

### Hierarchy CRUD

Buildings → `GET/POST /api/projects/{project}/buildings`  
Floors → `GET/POST /api/buildings/{building}/floors`  
Rooms → `GET/POST /api/floors/{floor}/rooms`  
Components → `GET/POST /api/{rooms|floors|buildings|projects}/{id}/components`  
(Update/Delete pattern: `PUT|DELETE /api/{type}-components/{id}`)

### Calculation Endpoints

| Endpoint | Throttle | Service |
|----------|----------|---------|
| `GET /api/projects/{project}/total-power` | api-heavy | TotalPowerController |
| `GET /api/buildings/{building}/total-power` | api-heavy | TotalPowerController |
| `GET /api/floors/{floor}/total-power` | api-heavy | TotalPowerController |
| `GET /api/rooms/{room}/total-power` | api-heavy | TotalPowerController |
| `GET /api/projects/{project}/phase-balance` | api-heavy | PhaseBalanceController |
| `GET /api/projects/{project}/schedule` | api-heavy | ScheduleController |
| `GET /api/projects/{project}/load-profile` | api-heavy | LoadProfileController |
| `GET /api/projects/{project}/electrical-design` | api-heavy | ElectricalDesignController |
| `GET /api/projects/{project}/financial-analysis` | 20/min | FinancialController |
| `GET /api/projects/{project}/cost-signal` | 30/min | CostSignalController |
| `GET /api/projects/{project}/battery-chemistry-comparison` | 20/min | ScheduleController@chemistryComparison |

### Battery Endpoints

| Endpoint | Notes |
|----------|-------|
| `GET /api/projects/{project}/batteries` | List all batteries |
| `POST /api/projects/{project}/batteries` | Create (chemistry preset auto-fills DoD/RTE/C-rates) |
| `GET /api/batteries/{battery}` | Show single battery with all computed accessors |
| `PUT /api/batteries/{battery}` | Update (chemistry change ALWAYS overwrites DoD/RTE from new preset) |
| `DELETE /api/batteries/{battery}` | Delete |
| `POST /api/batteries/{battery}/reset-soc` | Set `current_soc` to a new value (0.0–1.0) |
| `POST /api/batteries/{battery}/runtime-at-load` | Returns runtime at a given load kW |
| `GET /api/projects/{project}/battery-runtime` | Aggregate runtime vs. critical and optimized loads |
| `GET /api/battery-chemistry-defaults` | Returns all chemistry presets (public, no auth required) |

### Polymorphic Resources (Utility Lines, Generator Lines, Sockets)

Each is available on 4 parent types: project, building, floor, room.  
Example for utility lines:  
`GET /api/projects/{id}/utility-lines`, `POST /api/projects/{id}/utility-lines`  
`PUT /api/utility-lines/{line}`, `DELETE /api/utility-lines/{line}`

### Validation

| Endpoint | Notes |
|----------|-------|
| `GET /api/validation/case-study` | ValidationController@show — cross-checks all formula results |
| `GET /api/validation/electrical-design` | ValidationController@electricalDesign — verifies cable ampacity table |

### Admin (requires `admin` middleware)

`GET /api/admin/users`, `PUT /api/admin/users/{user}`, `DELETE /api/admin/users/{user}`

---

## 5. Diversity Factors (IEC 60364-8-1)

**Service:** `backend/app/Services/DiversityFactorService.php`

### Diversity Cascade

The system applies diversity factors at four levels:

```
Component  →  Room  →  Floor  →  Building  →  Project
             (DF_room)  (room_to_floor)  (floor_to_building)  (DF_PROJECT = 0.70)
```

**Critical-priority loads bypass all diversity factors** (DF forced to 1.00).  
Source: `TotalPowerController.php` line 192 — `$effectiveDf = ($priority === 'critical') ? 1.0 : $resolvedDf`

### Formula for Diversified Room Power

```
W_diversified = W_nameplate × DF_room × DF_room_to_floor × DF_floor_to_building × DF_PROJECT
```

Source: `ScheduleController.php` line 243:
```php
$roomDf * $bDfs['room_to_floor'] * $bDfs['floor_to_building'] * self::DF_PROJECT
```

### Building-Type Diversity Factors (IEC 60364-8-1 / BS 7671 / CIBSE Guide C)

Source: `DiversityFactorService.php` lines 10–22

| Building Type | room_to_floor | floor_to_building |
|--------------|--------------|-------------------|
| residential_house | 0.60 | 0.70 |
| residential_apartment | 0.65 | 0.70 |
| hotel | 0.65 | 0.70 |
| office | 0.85 | 0.80 |
| educational_school | 0.80 | 0.80 |
| educational_university | 0.85 | 0.80 |
| retail | 0.85 | 0.85 |
| hospital | 0.90 | 0.90 |
| industrial | 0.85 | 0.85 |
| mosque_worship | 0.80 | 0.75 |
| sports | 0.80 | 0.80 |
| **Default (unclassified)** | **0.90** | **0.80** |

### Room Coincidence Factors (CIBSE / IEC usage demand factors)

Source: `DiversityFactorService.php` lines 28–50

| Room Type | DF |
|-----------|-----|
| server_room | 1.00 |
| operating_theater | 1.00 |
| laboratory | 0.90 |
| classroom | 0.85 |
| lecture_hall | 0.85 |
| workshop | 0.85 |
| retail_floor | 0.85 |
| office_open | 0.80 |
| gym_sports | 0.80 |
| kitchen_commercial | 0.75 |
| office_private | 0.75 |
| prayer_hall | 0.75 |
| reception_lobby | 0.70 |
| meeting_room | 0.70 |
| corridor | 0.60 |
| living_room | 0.60 |
| kitchen_residential | 0.55 |
| hotel_room | 0.50 |
| bedroom | 0.45 |
| warehouse_storage | 0.30 |
| bathroom | 0.25 |
| **Default** | **0.80** |

### Project-Level DF

`DF_PROJECT = 0.70` — applied at the building→project boundary.  
Source: `TotalPowerController.php` line 22, `ScheduleController.php` line 19

---

## 6. Total Power Calculation

**Controller:** `backend/app/Http/Controllers/Api/TotalPowerController.php`

### Power Triangle

The `power` field in all component tables stores **apparent power S in VA** (nameplate value, already incorporating PF).

```
P (W) = S (VA) × PF
Q (VAR) = P × tan(arccos(PF))       [when PF < 1.0]
S_system = √(P_total² + Q_total²)
PF_system = P_total / S_system
```

Source: `TotalPowerController.php` line 181:
```php
$w = $va * $pf;
$q = $pf < 1.0 ? $w * tan(acos(min(1.0, $pf))) : 0.0;
```

### System Constants

| Constant | Value | Source |
|----------|-------|--------|
| `DF_PROJECT` | 0.70 | `TotalPowerController.php:22` |
| `VOLTAGE_3PHASE_LL` | 400 V | Line 25 |
| `VOLTAGE_1PHASE` | 230 V | Line 26 |
| `SYSTEM_FREQUENCY` | 50 Hz | Line 27 |
| `TARGET_POWER_FACTOR` | 0.95 | Line 28 |
| `PF_CORRECTION_THRESHOLD` | 0.85 | Line 29 |
| `INRUSH_MULTIPLIER` | 1.25 | Line 33 |

### Group-Max Optimisation

When `group_name` is set on a component, only the highest-VA component within that group is counted (group-max selection). This models mutual exclusivity of grouped loads (e.g., HVAC modes).

Source: `TotalPowerController.php` lines 196–208:
```php
if (!$c->group_name) {
    $vaTotal += $va; ...
} else {
    $key = $c->{$entityKey} . '|' . $c->group_name;
    if (!isset($groups[$key]) || $va > $groups[$key]['va']) {
        $groups[$key] = ['va' => $va, 'w' => $wd, 'q' => $qd, ...];
    }
}
```

### Continuous-load / Largest-Motor 125% (NEC 430.24/430.22)

The largest motor in the project is identified and its full-load VA is scaled at **125%** per NEC 430.24/430.22 — the **continuous-load sizing rule**, which requires conductors and overcurrent devices to be rated at no less than 125% of the motor's full-load current. This is the continuous-load rule, not locked-rotor protection; locked-rotor inrush currents are 500–800% and are handled by the motor's separate overload relay or thermal protection device.

```
Inrush addition (VA) = motor_base_VA × (1.25 − 1.0) = motor_base_VA × 0.25
```

Source: `TotalPowerController.php` lines 88–111:
```php
$delta = self::INRUSH_MULTIPLIER - 1.0;   // 0.25
$addVa = $motor['per_unit_va'] * $motor['quantity'] * $delta;
$addW  = $addVa * $motor['pf'];
$addQ  = $addVa * sqrt(max(0.0, 1.0 - $motor['pf'] ** 2));
```

### Power Factor Correction — Capacitor Bank

Correction is recommended when `PF_system < 0.85`. Target PF = 0.95.

```
Q_cap (kVAR) = P × (tan(arccos(PF_current)) − tan(arccos(PF_target)))
```

Capacitor configuration: **Delta (Δ)** connected to the 3-phase 400 V busbar.

```
C_phase (μF) = (Q_cap/3) / (2π × 50 × 400²) × 10⁶
```

Source: `ValidationReferenceService.php`:
```php
$C_phase_μF = ($Q_cap/3) / (2 * M_PI * 50 * 400**2) * 1e6;
```

### Socket Demand (SocketDemandService)

**Service:** `backend/app/Services/SocketDemandService.php`

Socket outlet demand uses a tiered demand model:
- First 10 outlets × 100%
- Next 10 outlets × 75%
- Remaining × 40%
- **200 VA per outlet**

Coincidence factor by kVA band:
- < 50 kVA → 1.00
- 50–250 kVA → 0.92
- > 250 kVA → 0.85

### Backup Time Calculation

```
backup_hours (critical)  = usable_kwh / critical_kW
backup_hours (optimized) = available_kwh / optimized_kW
```

---

## 7. Electrical Design Module (IEC 60364-5-52)

**Service:** `backend/app/Services/ElectricalDesignService.php`  
**Controller:** `backend/app/Http/Controllers/Api/ElectricalDesignController.php`  
**Frontend:** `frontend/src/pages/ElectricalDesignPage.jsx`  
**Panel table component:** `frontend/src/components/PanelScheduleTable.jsx`

### System Constants

Source: `ElectricalDesignService.php` lines 45–54

| Constant | Value | Standard |
|----------|-------|----------|
| `V_PHASE` | 230.0 V | PENRA / IEC |
| `V_LINE` | 400.0 V | PENRA / IEC |
| `SQRT3` | 1.7320508 | |
| `AMBIENT_C` | 40 °C | Palestinian design temp |
| `DERATING_40C` | **0.87** | IEC 60364-5-52 Table B.52.14: `Cf = √((70−40)/(70−30)) = √0.75 ≈ 0.866 → tabled 0.87` |

### Cable Ampacity — IEC 60364-5-52 Table B.52.2, Method A1

Source: `ElectricalDesignService.php` lines 56–77

**Table label:** IEC 60364-5-52 Table B.52.2 — Method A1 (conductors in conduit in thermally insulated wall), PVC/Cu, 2 loaded conductors, 30 °C ambient reference. Adopted as the most conservative reference method to give a built-in safety margin.

| Size (mm²) | Iz at 30 °C (A) | Iz_derated at 40 °C (A) |
|------------|----------------|------------------------|
| 1.5 | 14.5 | 12.6 |
| 2.5 | 19.5 | 17.0 |
| 4 | 26.0 | 22.6 |
| 6 | 34.0 | 29.6 |
| 10 | 46.0 | 40.0 |
| 16 | 61.0 | 53.1 |
| 25 | 80.0 | 69.6 |
| 35 | 99.0 | 86.1 |
| 50 | 119.0 | 103.5 |
| 70 | 151.0 | 131.4 |
| 95 | 182.0 | 158.3 |
| 120 | 210.0 | 182.7 |

Derated value = `Iz × 0.87` — the actual design capacity used to select cable.

**⚠ Note on 3-phase circuits:** The code uses the 2-core column for all circuit types. For 3-phase HEAVY circuits the 3/4-core column would be ~8–11% lower; the 80% loading factor (`breaker_loading_factor`) partly compensates. No grouping derating (Table B.52.17) is applied. (`ElectricalDesignService.php` lines 59–62)

### Voltage-Drop Constants — mV/A/m

Source: `ElectricalDesignService.php` lines 79–98  
Values for Cu 70°C PVC, Method A1, single-phase 2-conductor path. ⚠ Each marked for verification before licensed use.

| Size (mm²) | mV/A/m |
|------------|--------|
| 1.5 | 29.0 |
| 2.5 | 18.0 |
| 4 | 11.0 |
| 6 | 7.3 |
| 10 | 4.4 |
| 16 | 2.8 |
| 25 | 1.75 |
| 35 | 1.25 |
| 50 | 0.93 |
| 70 | 0.63 |
| 95 | 0.47 |
| 120 | 0.37 |

### Standard Breaker Sizes (IEC 60898 / IEC 60947-2)

Source: `ElectricalDesignService.php` line 101:
```
[6, 10, 16, 20, 25, 32, 40, 50, 63, 80, 100, 125, 160, 200, 250, 315, 400]
```

### Circuit Classification (5 Types)

Source: `ElectricalDesignService.php` lines 525–553

Classification precedence (evaluated in order):

1. **HEAVY** — if: 3-phase OR VA ≥ `heavy_threshold_va` OR (is_motor AND VA ≥ `motor_dedicated_threshold_va`)
2. **CRITICAL** — if: `priority === 'critical'`
3. **SOCKET** — if: `needs_socket === true`
4. **LIGHTING** — if: component type name matches luminaire keywords (`light`, `lamp`, `led strip`, `chandelier`, `luminaire`, `lantern`, `sconce`, `bulb`, `pendant`, `downlight`, `spotlight`, `fluorescent`, `fixture`)
5. **AUXILIARY** — all remaining (small motors, fans, speakers, sensors, PoE)

Luminaire preset names: `Light`, `Fluorescent Lamp`, `LED Strip`, `Chandelier` (`ElectricalDesignService.php` lines 114–122)

### Design Rules by Building Type

Source: `ElectricalDesignService.php` lines 124–247

| Parameter | hospital | data_center | office | residential | school | mosque | mall | default |
|-----------|---------|-------------|--------|-------------|--------|--------|------|---------|
| heavy_threshold_va | 1500 | 1000 | 2000 | 2000 | 2000 | 2000 | 3000 | 2000 |
| motor_dedicated_va | 750 | 500 | 750 | 750 | 750 | 750 | 1000 | 750 |
| socket_outlets/circuit | 6 | 4 | 8 | 8 | 8 | 8 | 8 | 8 |
| lighting_breaker_a | 10 | 10 | 10 | 10 | 10 | 10 | 16 | 10 |
| socket_breaker_a | 16 | 16 | 16 | 16 | 16 | 16 | 16 | 16 |
| auxiliary_breaker_a | 10 | 10 | 10 | 10 | 10 | 10 | 10 | 10 |
| breaker_loading_factor | 0.80 | 0.70 | 0.80 | 0.80 | 0.80 | 0.80 | 0.80 | 0.80 |
| spare_capacity_pct | 20% | 30% | 10% | 10% | 15% | 10% | 15% | 10% |
| rcd_policy | 30mA_all | 30mA_socket_lighting | 30mA_sl | 30mA_sl | 30mA_sl | 30mA_sl | 30mA_sl | 30mA_sl |
| essential_separation | true | true | false | false | false | false | false | false |
| standard_ref | IEC+HTM06 | IEC+EN50600 | IEC 60364 | IEC 60364 | IEC 60364 | IEC 60364 | IEC 60364 | IEC 60364 |

### Design Current Formulas

Source: `ElectricalDesignService.php` lines 862–868

```
1-phase:  Ib = S_VA / V_phase = S_VA / 230   [A]
3-phase:  Ib = S_VA / (√3 × V_line) = S_VA / (√3 × 400)   [A]
```

**Critical design note:** The breaker and cable carry the full apparent current (S÷V), **not** the active component (P÷V). PF is NOT applied to Ib. (`ElectricalDesignService.php` header comment lines 22–26)

### Cable Selection Algorithm

Source: `ElectricalDesignService.php` lines 832–900

```
Step 1: Compute Ib (design current, from VA and phase)
Step 2: Select minimum breaker In ≥ max(type_min_breaker, next_standard_size(Ib))
Step 3: Select minimum cable where Iz_table × 0.87 ≥ In
Step 4: Apply type minimum: 1.5 mm² for LIGHTING/AUXILIARY; 2.5 mm² for SOCKET/MIXED
Step 5: PE = cable (≤16 mm²), PE = 16 mm² (16–35 mm²), PE = cable/2 (>35 mm²)
Step 6: Breaker curve: LIGHTING→B, motor→D, all others→C
```

PE sizing rule (IEC 60364-5-54 3-band rule), `ElectricalDesignService.php` lines 895–900:
```php
if ($s <= 16.0) return $s;
if ($s <= 35.0) return 16.0;
return $s / 2.0;
```

### Voltage Drop Calculation

Source: `ElectricalDesignService.php` lines 939–974

```
ΔU (V) = (mV/A/m) × Ib × length_m / 1000
ΔU (%) = ΔU / V_nominal × 100
          V_nominal: 230 (1-phase) or 400 (3-phase)
```

**Limits (IEC 60364-8-1 Table 1):**
- LIGHTING circuits: 3%
- All other types: 5%

When `ΔU% > limit`, the service computes `vd_remedy_cable_mm2` — the smallest larger cable that keeps VD within the limit.  
When `length_m` is not provided (null), `vd_note: 'length required'` is returned and VD is not computed.

**Worked example:**
- Circuit: SOCKET, 1-phase, 6 A breaker, 2.5 mm² cable, Ib = 5.0 A, length = 20 m
- ΔU (V) = 18.0 × 5.0 × 20 / 1000 = 1.80 V
- ΔU (%) = 1.80 / 230 × 100 = 0.78% → within 5% limit

### Circuit Packing Strategy

**SOCKET circuits** (`packSocketCircuits`, lines 593–635):
- Accumulate loads until VA limit OR outlet count limit is exceeded
- VA limit = `socket_breaker_a × V_PHASE × breaker_loading_factor`
- Outlet limit = `socket_outlets_per_circuit`

**LIGHTING circuits** (`packLightingLoads`, lines 637–679):
- Cross-room packing (lighting from multiple rooms shares one circuit)
- VA limit = `lighting_breaker_a × V_PHASE × breaker_loading_factor`
- Force-close at floor midpoint to guarantee ≥2 lighting circuits per floor

**AUXILIARY circuits** (`packAuxiliaryLoads`, lines 681–723):
- Cross-room packing (like lighting)
- VA limit = `auxiliary_breaker_a × V_PHASE × breaker_loading_factor`
- Never mixed with lighting or sockets

**HEAVY and CRITICAL** (`buildSingleLoadCircuit`, lines 557–591):
- Always dedicated one-per-load circuits

### group_small_critical Option

Source: `ElectricalDesignService.php` lines 355, 387–395, 449–463

When `group_small_critical = true` (set per building type), critical loads with `va_each < motor_dedicated_threshold_va` (default 750 VA) are collected across the floor and packed into a single shared CRITICAL circuit, rather than each getting a dedicated breaker. This reduces panel space for buildings with many small life-safety sensors/devices. The default is `false` for all currently-defined building types.

### Dedicated Rooms

Source: `ElectricalDesignService.php` lines 103–112

These room types get **dedicated SOCKET circuits** (sockets not shared across rooms):
`kitchen_residential`, `kitchen_commercial`, `bathroom`, `laboratory`, `workshop`, `server_room`, `operating_theater`

Keyword-based detection also catches rooms named with: `kitchen`, `bathroom`, `lab`, `workshop`, `server`, `critical`, `operating`

**Lighting** from dedicated rooms still joins the normal floor lighting pool.

### Panel Hierarchy Output

The API returns a tree structure:

```
project
  └── buildings[]
        ├── mdb          (Main Distribution Board)
        │    ├── incomer (current, breaker, cable, PE)
        │    ├── phase_balance_va (A/B/C in VA)
        │    └── phase_imbalance_pct
        ├── mdb_circuits[] (floor feeders + building-direct)
        ├── essential_panel (if essential_separation=true)
        └── floors[]
              ├── db (incomer, phase balance)
              └── circuits[] (per circuit: type, VA, Ib, In, curve, cable, PE, RCD, VD data)
```

### Phase Assignment in Electrical Design — Three-Stage Pipeline

Source: `ElectricalDesignService.php` — `analyzeFloor()` lines 486–494

Each floor's circuits pass through three stages before phase numbers are printed:

**Stage 1 — Electrical split (`splitOversizedCircuits`)** (lines 1132–1171)  
Any 1-phase circuit whose VA exceeds the breaker loading ceiling is split into sub-circuits using **LPT (Longest Processing Time) fixture-level bin packing**:

```
maxCircVA = breaker_A × 230 V × LOADING_FACTOR
n = ceil(circuit_VA / maxCircVA)

LPT: expand each load entry into individual fixture units,
     sort units by VA descending,
     greedily assign each to the lightest bin.
```

Guards: minimum total VA `≥ MIN_SPLIT_VA`, and `≥ 2` fixtures must exist. 3-phase circuits and circuits already within the ceiling are passed through unchanged.

**Stage 2 — Balance-driven re-split (`balanceDrivenReSplit`)** (lines 1304–1361)  
Iterates up to `MAX_ITERS` times. Each iteration:
1. Runs pure LPT assignment (no DB hints) → computes `imbalance_pct`.
2. If `imbalance_pct ≤ IMBALANCE_TARGET × 100` → stop.
3. Otherwise: find the largest splittable circuit on the heaviest phase; try splitting it in 2 with LPT; keep the split only if `newImbalance < currentImbalance`.
4. If no improving move is found → stop (local minimum reached).

**Stage 3 — Final LPT assignment (`assignPhases(useSavedHints=false)`)** (lines 1387–1431)  
Sort circuits by VA descending; assign each 1-phase circuit to the least-loaded phase so far (greedy). 3-phase circuits contribute VA/3 to each phase equally. DB-saved phase hints are bypassed at this stage (they were already used by Stage 1 to identify the correct load-to-circuit mapping).

### Balance / Split Tuning Constants

Source: `ElectricalDesignService.php` lines 103–111

| Constant | Value | Tunable? | Role |
|----------|-------|----------|------|
| `LOADING_FACTOR` | 0.80 | ⚠ yes | Breaker utilisation ceiling (Stage 1 split trigger) |
| `IMBALANCE_TARGET` | 0.15 | ⚠ yes | Stop re-splitting when imbalance ≤ 15 % |
| `MAX_ITERS` | 12 | ⚠ yes | Maximum balance-driven re-split iterations per floor |
| `MIN_SPLIT_VA` | 150.0 VA | ⚠ yes | Minimum sub-circuit VA after any split (avoids micro-circuits) |

### MDB Incomer Sizing

```
MDB_incomer_VA = building_diversified_VA × (1 + spare_capacity_pct/100)
Floor_DB_incomer_VA = floor_diversified_VA × (1 + spare_capacity_pct/100)
```

Building diversified VA:
```
building_div_VA = Σ(floor_div_VA) × floor_to_building + building_own_VA
floor_div_VA = Σ(room_VA × room_DF × room_to_floor) + floor_own_VA
```

Source: `ElectricalDesignService.php` lines 300–308, 478–479

---

## 8. Load Schedule and Energy Dispatch

**Controller:** `backend/app/Http/Controllers/Api/ScheduleController.php`  
**Service:** `backend/app/Services/SourceDispatchService.php`

### Schedule Endpoint Behavior

`GET /api/projects/{project}/schedule?month=1–12&day=1–31`

Returns load profiles for all 7 days of the week computed in a single request:
- `load_max` — all components on, no diversity applied
- `load_optimized` — group-max selection applied + diversity factors
- `hourly_kvar` — reactive power profile
- `dispatch_max` — source dispatch for max load
- `dispatch_optimized` — source dispatch for optimized load

Solar profile uses `performanceRatio: 0.80` for hourly output (`ScheduleController.php` line 82).

### Solar Capacity Modes

Source: `ScheduleController.php` lines 63–75

- `solar_source = 'max'` — uses roof area estimate: `SolarIrradianceService::estimateCapacityW(totalAreaM2)`
- `solar_source = 'existing'` — uses sum of active `solar_systems.capacity_kw × 1000`; falls back to `existing_solar_power` fields if no named systems exist

### Source Capacities

```
utility_capacity_W = Σ(utility_lines.power) × 0.8    [VA → W at PF=0.8]
generator_capacity_W = Σ(generator_lines.power) × 0.8
```

Source: `ScheduleController.php` lines 90–93

### Demand-Side Load Shedding (LoadSheddingService)

**Service:** `backend/app/Services/LoadSheddingService.php`

This service runs **before** `SourceDispatchService`. When hourly demand exceeds available supply it resolves the deficit through a strict priority-aware algorithm, then returns a modified `$loadW` array with exactly the same 24-element shape that dispatch already expects. The supply-side dispatch logic is completely unmodified.

#### Architecture

`ScheduleController` calls `buildComponentSlots()` to produce per-component slot objects — mirrors `buildHourlyW()`'s group-max and season/day-type filtering — and passes them with two separate supply-cap arrays:

```
supplyCapW = solar + utilCapW + genCapW + battMaxDischargeW   (full upper-bound cap)
shiftCapW  = solar + utilCapW   (solar + grid ONLY — no battery, no generator)
```

`shiftCapW` is used exclusively when selecting shift targets (`findShiftTarget()`). Excluding battery and generator capacity from this cap prevents a shiftable load from moving to a dark overnight hour that looks attractive only because the battery/generator can theoretically serve it — which would drain the battery and force the generator to compensate. (`ScheduleController.php` lines 156–164)

Both max-mode and optimized-mode shedding runs are performed separately before the corresponding dispatch call. (`ScheduleController.php` lines 172–177)

#### Shedding Order Per Deficit Hour (`LoadSheddingService.php` lines 98–201)

| Step | Action | Rule |
|------|--------|------|
| **1 Shift** | Shiftable loads moved to a surplus hour within `[earliest_start, latest_end)` | Best target = highest supply headroom (`supplyCapW[h] − effectiveLoadW[h]`) that can absorb the full load; no partial shifts |
| **2 Curtail** | Curtailable loads reduced toward `curtail_min_pct` | Largest reduction first; curtailed watts removed from this hour and all remaining active hours |
| **3 Shed Normal** | `priority = 'normal'` fixed loads removed from hour h onwards | Largest first |
| **4 Shed Essential** | `priority = 'essential'` loads removed, only if Normal alone insufficient | Largest first |
| **5 Critical** | `priority = 'critical'` loads are **never auto-shed** | Residual deficit accumulates in `critical_unmet_kwh` — a distinct field; never folded into the generic `unmet` figure |

`activeSlotsAtHour()` always skips critical slots, so critical loads cannot appear in any tier. (`LoadSheddingService.php` lines 354–355)

#### Restoration (Reverse Order with Hysteresis)

A shed load is restored in hour H only if hour H−1 had supply ≥ demand × (1 + RESTORE_MARGIN). Restoration order: **Essential → Normal → Curtailable** — reverse of shedding. Within each tier: largest-first. A load is only re-added if it fits within the remaining supply headroom at hour H. (`LoadSheddingService.php` lines 249–306)

| Constant | Value | File:line |
|----------|-------|-----------|
| `RESTORE_MARGIN` | **0.05** (5% surplus headroom required) | `LoadSheddingService.php:27` |
| `TIEBREAK` | `'largest_first'` | `LoadSheddingService.php:30` |

#### Output Fields

```
adjusted_load_w      float[24]  — post-shed/shift load profile fed to dispatch
shed_curtailable_kwh float      — kWh removed by curtailment
shed_normal_kwh      float      — kWh removed from Normal loads
shed_essential_kwh   float      — kWh removed from Essential loads
critical_unmet_kwh   float      — residual after all non-critical loads shed
per_load_shed_list   array      — per-event log: [slot_id, label, hour, action, kwh]
hourly_shed          array[24]  — per-hour: {deficit_w, critical_unmet_w?}
```

The schedule API response exposes two additional fields per day:
```
load_shed_max        float[24]  — adjusted_load_w from max-mode shedding run
load_shed_optimized  float[24]  — adjusted_load_w from optimized-mode shedding run
```

These are used as the `demand` reference line in the Load Schedule chart so the stacked supply areas and demand line remain in sync. (`ScheduleController.php` lines 186–189, `LoadSchedulePage.jsx` lines 579–582)

#### UI Surface

The Load Schedule page reads `dayData.shedding_optimized` (or `shedding_max` depending on mode). When `critical_unmet_kwh > 0` a **red alert banner** is rendered at the top of the page: "CRITICAL LOADS UNMET: X.XX kWh cannot be served — All non-critical loads were shed but supply is still insufficient." (`LoadSchedulePage.jsx` lines 676–694)

---

### Target-SOC Look-Ahead Dispatch Engine

**Service:** `backend/app/Services/SourceDispatchService.php`  
**Class:** `SourceDispatchService::dispatchOptimized()` (batteries present path)

#### Strategy Summary

The engine uses a **one-pass day-ahead look-ahead** to protect a night-energy reserve in the battery, then dispatches hour by hour in priority order.

**PRE-DISPATCH (before the 24-hour loop):**  
Scan the full load + solar profile to compute how much energy the battery must hold at sunset to cover all post-sunset hours, then add a 10% reserve margin:

```
nightEnergyKwh  = Σ load_W[h] / 1000    (for all non-daylight hours after sunset)
nightEnergyReq  = nightEnergyKwh / avg_discharge_efficiency
reserveKwh      = totalUsableKwh × RESERVE_MARGIN   (10 %)
battTargetKwh   = min(totalUsableKwh, nightEnergyReq + reserveKwh)
battTargetSoc   = battTargetKwh / totalUsableKwh
```

Pre-sunrise hours are excluded from `nightEnergyKwh` because they are served by free night discharge before the first daylight hour; including them would set the floor too high at sunrise.

**Per-hour priority order (Steps 1–7):**

| Step | Action |
|------|--------|
| 1 | **Paired solar → dedicated battery banks**: each solar system charges its `solar_system_id`-matched batteries (pro-rata by headroom) |
| 2 | **Shared solar → load** |
| 3 | **Surplus shared solar → unpaired batteries** |
| 4 | **Battery discharge → remaining load** (day: only above the dynamic floor; night: discharge freely) |
| 5 | **Utility grid** (up to `utilCapW`) |
| 6 | **Generator** (last resort, up to `genCapW`) |
| 7 | **Generator spare → battery to 100% usable SOC** (Case A or B) |

#### Night-Reserve Protection (Step 4 Gate)

During daylight, the battery may only discharge the energy **above** a dynamic floor that shrinks as night approaches:

```
dynamicFloorKwh = min(totalUsableKwh,
    (Σ load_W[j] / 1000 for all j > h where !isDaylight[j])
    / avgDischEff + reserveKwh)

aboveReserveKwh = max(0, totalSOC − dynamicFloorKwh)
maxDischW = min(rateCapW, aboveReserveKwh × avgDischEff × 1000)
```

At night, discharge freely: `maxDischW = min(rateCapW, totalSOC × 1000)`.

#### Generator → Battery (Step 7, Two Cases)

Guards common to both cases: generator already running this hour; **no same-hour battery discharge** (`$dischargeW === 0.0` — the round-trip-waste guard: prevents the generator from charging the battery in the same hour the battery is discharging to load, which would waste ~15–20% of the energy in the charge/discharge round-trip — `SourceDispatchService.php` line 464); not in afternoon-ramp window (post solar-peak, still daylight, load > solar, battery has above-floor buffer to discharge); not in morning pre-peak window (before solar peak with above-floor energy present).

**Case A (generator ≥ 60% loaded):** Use spare capacity up to 85% optimal maximum load.  
```
spareGenW = max(0, genCapW × 0.85 − genUsed[h])
```

**Case B (generator < 60% loaded, daylight, solar-deficit day):** Boost generator output to exactly 60% efficient threshold by adding battery charging load. Activated only when `solarCoversTarget = false` (daytime solar surplus × discharge efficiency would not alone fill `battTargetKwh`).  
```
spareGenW = max(0, genCapW × 0.60 − genUsed[h])
```

The charge ceiling is **100% usable SOC** (not `battTargetKwh`). `battTargetKwh` is the discharge floor, not a charge cap — a fuller battery builds a larger above-floor buffer for afternoon peak shaving.

#### Solar-Coupled Battery (Hybrid Inverter)

Batteries with `solar_system_id ≠ null` are DC-coupled to their solar system. In Step 1, each solar system's output is split proportionally by remaining headroom across its paired battery bank. The shared solar pool (Step 2/3) receives only the leftover output after Step 1.

Source: `SourceDispatchService.php` lines 148–175 (pairing index), 275–331 (Steps 1–3), 345–399 (Step 4 with dynamic floor), 460–505 (Step 7).

#### Dispatch Constants

Source: `SourceDispatchService.php` lines 47–72

| Constant | Value | Tunable? | Meaning |
|----------|-------|----------|---------|
| `GEN_OPTIMAL_MAX_LOAD` | 0.85 | ⚠ yes | Max generator loading when charging from spare (ISO 8528 optimal band ceiling) |
| `GEN_MIN_EFFICIENT_LOAD` | 0.60 | ⚠ yes | Min load fraction before spare charges battery (below this, SFC penalty exceeds RTE gain) |
| `INV_EFF` | 0.95 | — | One-way inverter efficiency for generator → battery AC/DC path |
| `SOLAR_PRESENCE_THRESHOLD` | 50 W | ⚠ yes | Solar output below this classifies the hour as night |
| `RESERVE_MARGIN` | 0.10 | ⚠ yes | Extra 10 % of usable capacity added on top of `nightEnergyReq` |
| `RESTORE_MARGIN` | 0.05 | ⚠ yes | `LoadSheddingService` — min supply-to-demand surplus ratio (5%) required in previous hour to trigger restoration |

#### No-Battery Path (`dispatchBasic`)

When no active batteries exist, the service runs a simple 3-step priority: Solar → Utility → Generator, with no SOC tracking.

### Battery Round-Trip Efficiency in Dispatch

```
charge:    SOC += (chargeW / 1000) × √(round_trip_efficiency)
discharge: SOC -= (dischargeW / 1000) / √(round_trip_efficiency)
```

One-way efficiency = `√(RTE)` applied on both charge and discharge legs.  
Source: `SourceDispatchService.php`

### Load Profile Diversity in Schedule

Source: `ScheduleController.php` lines 230–243

Diversity cascade for schedule is identical to total power cascade:
```
room_load × room_DF × room_to_floor × floor_to_building × DF_PROJECT(0.70)
```
Critical loads always use `effectiveDf = 1.0` (`ScheduleController.php` line 296).

### Shiftable Load Optimisation

`POST /api/projects/{project}/optimize-shiftable`

Moves shiftable loads (flagged in component table) to off-peak hours to minimize cost. Uses `CostSignalService` tariff schedule.  
Rate limited to 10 requests/minute.

---

## 9. Solar Irradiance Model

**Service:** `backend/app/Services/SolarIrradianceService.php`

### Solar Capacity Estimate

Source: `SolarIrradianceService.php`

```
estimateCapacityW = building_area_m² × ROOF_COVERAGE_RATIO × STC_IRRADIANCE × CAPACITY_ESTIMATE_PR
                  = area × 0.17 × 1000 × 0.75
```

Constants:
- `ROOF_COVERAGE_RATIO = 0.17` (17% of roof area usable for panels)
- `STC_IRRADIANCE = 1000` W/m²
- `CAPACITY_ESTIMATE_PR = 0.75`

**⚠ Heuristic note:** This formula is a rough upper-bound estimate only. `ROOF_COVERAGE_RATIO = 0.17` is the fraction of roof area physically usable for panels; `CAPACITY_ESTIMATE_PR = 0.75` accounts for cable, inverter, and mismatch losses. Module conversion efficiency (~0.19 for typical crystalline silicon) is **not embedded** in either constant — the formula does not model panel-level efficiency and therefore overestimates actual AC output. For engineering-grade calculations use the explicit form: `P_ac = area × coverage_ratio × G_stc × η_module × PR` (e.g., `area × 0.17 × 1000 × 0.19 × 0.75`).

### Solar Declination

The declination formula used is **Cooper (1969)**:

```
δ (°) = 23.45 × sin(360/365 × (DOY − 81))
```

where DOY = day of year.  
Source: `SolarIrradianceService.php`

### Sunrise/Sunset

```
cos(H_ss) = −tan(latitude) × tan(δ)
H_ss = solar hour angle at sunset (degrees)
daylight_hours = 2 × H_ss / 15
```

### Hourly Output Profile

```
peak_output_W = capacity_kW × 1000 × PSH × PR × π / (2 × daylight_hours)
```

Performance ratio for hourly profile: `PR = 0.80` (`ScheduleController.php` line 82).

### PSH Lookup Table

7 latitude bands (0°–60°) × 12 months stored as a table in the service. When location is known, the service first checks the NASA POWER API cache; falls back to PSH table if API unavailable.

### NASA POWER API Integration

- **Parameter:** `ALLSKY_SFC_SW_DWN` (all-sky surface shortwave downwelling irradiance, GHI in W/m²)
- **Hardcoded year:** 2023
- **Cache:** 30 days
- **Fallback:** PSH lookup table

---

## 10. Battery and Storage Model

**Model:** `backend/app/Models/Battery.php`  
**Controller:** `backend/app/Http/Controllers/Api/BatteryController.php`  
**Chemistry Service:** `backend/app/Services/BatteryChemistryService.php`

### Capacity Calculations

Source: `Battery.php` model attributes (computed accessors, appended to every JSON response)

```
nominal_capacity_kwh = nominal_voltage_V × capacity_ah_per_unit × quantity / 1000
```

### Age Degradation and Usable Capacity

```
age_years   = diffInDays(installation_date, today) / 365.25   [fractional years]
age_factor  = max(0.70, 1.0 − age_years × degradation_per_year)

usable_capacity_kwh = nominal_capacity_kwh × depth_of_discharge × age_factor
```

`age_years` is computed in **fractional years** (days ÷ 365.25), not months or integer years. A 36-day-old battery returns `age_years = 0.10`. `installation_date` defaults to today on battery creation so new batteries start at `age_years = 0`, `age_factor = 1.0`. (`Battery.php` lines 66–73)

The floor of 0.70 means a battery never degrades below 70% of its nominal capacity in this model (industry replacement threshold).

`depth_of_discharge` and `degradation_per_year` are both chemistry-dependent (see chemistry table below).

Battery health thresholds (based on `age_factor`):
- ≥ 0.90 → **Good**
- ≥ 0.80 → **Fair**
- ≥ 0.70 → **Degraded**
- < 0.70 → **Replace**

### Battery Chemistry Service

**Service:** `backend/app/Services/BatteryChemistryService.php`

Five chemistry presets are built in. When a battery is created or updated with a chemistry key, `BatteryController` always overwrites `depth_of_discharge`, `round_trip_efficiency`, `c_rate_charge`, `c_rate_discharge`, and `rated_cycle_life` from the current preset. The old `!array_key_exists` guard that allowed stale values to persist through an edit has been removed (`BatteryController.php:92–98`).

`installation_date` defaults to today on battery creation so that `age_factor = 1.0` for new batteries (`BatteryController.php:56`).

#### Chemistry Preset Table

Source: `BatteryChemistryService.php` lines 12–63

| Key | Label | DoD | RTE | C-rate charge | C-rate disch | Cycle life | Cal. life (yr) | Degrad./yr |
|-----|-------|-----|-----|---------------|--------------|------------|----------------|------------|
| `lead_acid_flooded` | Lead-Acid (Flooded) | **50%** | **82%** | 0.10 | 0.20 | 500 | 5 | 5.0% |
| `lead_acid_agm` | Lead-Acid (AGM) | **70%** | **82%** | 0.20 | 0.30 | 700 | 7 | 4.0% |
| `lead_acid_gel` | Lead-Acid (Gel) | **80%** | **82%** | 0.15 | 0.25 | 800 | 8 | 3.5% |
| `lithium_lfp` | Lithium-Ion (LFP) | **85%** | **92%** | 0.50 | 1.00 | 4000 | 15 | 2.0% |
| `lithium_nmc` | Lithium-Ion (NMC) | **80%** | **93%** | 0.50 | 1.00 | 2500 | 10 | 2.5% |
| *(default)* | *(unknown key)* | **80%** | **90%** | — | — | — | — | — |

`DEFAULT_DOD = 0.80`, `DEFAULT_RTE = 0.90` (applied when chemistry key is unrecognised).

All ⚠ values are marked tunable in source code.

**Note on table values:** All DoD and RTE figures in the table are typical manufacturer/industry guidance values (Battery University, IEEE 1188 storage recommendations). Exact values vary by manufacturer and operating conditions. The system marks all preset constants `⚠ tunable` in `BatteryChemistryService.php`.

Chemistry and age affect **usable capacity and delivered energy** only — they never affect building demand itself. A worse chemistry (lower DoD or RTE) forces more generator fuel for the same building load. This trade-off is demonstrated by the battery chemistry comparison endpoint (Section 10, Chemistry Comparison subsection).

#### Battery Display Format (PowerSourcesBanner)

Source: `frontend/src/components/PowerSourcesBanner.jsx` lines 1085–1094

Each battery bank card in the Power Sources banner shows three capacity rows:

```
{storedKwh} kWh stored       ← usable_capacity_kwh × current_soc
{usable_capacity_kwh} kWh usable
{nominal_capacity_kwh} kWh nominal
```

`storedKwh = usable × current_soc` — always consistent with the SOC bar on the same card. The chemistry label and DoD% are shown alongside (`PowerSourcesBanner.jsx` lines 1058–1060) so the usable-vs-nominal gap is self-explanatory to the user.

The battery age (years) and health badge (Good / Fair / Degraded) are also shown on the card (`PowerSourcesBanner.jsx` lines 1086–1089).

#### Chemistry Preset Sync Migration

`2026_07_05_195454_sync_battery_chemistry_presets.php` — walks every existing `batteries` row, looks up its chemistry key in `BatteryChemistryService::all()`, and overwrites the five operating parameters. Required when preset values change so that previously-created batteries pick up the new values without manual edits.

### Charge/Discharge Power Limits

```
max_charge_power_kW  = nominal_capacity_kwh × c_rate_charge
max_discharge_power_kW = nominal_capacity_kwh × c_rate_discharge
```

### Solar-Coupled BESS (`solar_system_id`)

When `solar_system_id` is non-null, the battery is DC-coupled to that solar system and shares its hybrid inverter. Implications:
- **SLD:** rendered as a `HybridGroup` node instead of separate source circles (see Section 17.9)
- **Dispatch:** Step 1 of the dispatch engine exclusively routes that solar system's surplus to its paired battery bank (see Section 8)
- **Chemistry comparison:** uses `solar_system_id = null` for the synthetic comparison banks

Source: `Battery.php` `$fillable`, `$casts` (cast to `integer`), `solarSystem()` `belongsTo` relationship.

### Runtime Estimation

`GET /api/projects/{project}/battery-runtime`

```
runtime_hours_full    = usable_kwh    / load_kW
runtime_hours_current = available_kwh / load_kW
```

`POST /api/batteries/{battery}/runtime-at-load` — computes runtime at a specified load.

### Battery Replacement Projection

Source: `FinancialAnalysisService.php`

```
replacement_year = ceil((rated_cycle_life / 365) − age_years)
```

### Battery Chemistry Comparison

**Endpoint:** `GET /api/projects/{project}/battery-chemistry-comparison?month=M&day=D&day_type=weekday|weekend`  
**Controller:** `ScheduleController@chemistryComparison` (lines 448–593)

Simulates the same load + solar profile twice — once with a **Lead-Acid bank** (DoD 50%, RTE 82%) and once with **Lithium LFP** (DoD 85%, RTE 92%) — both sharing the project's actual total nominal capacity. Returns per-chemistry: generator kWh, generator hours, battery discharged kWh, unmet kWh, and affine fuel cost.

Also returns `delta` (LFP advantage over lead-acid): `generator_kwh_saved`, `generator_hours_saved`, `fuel_cost_saved`.

**Fuel cost model (ISO 8528 affine):**
```
F(P) = F₀ + (F_rated − F₀) × P / P_rated   [L/hr]
daily_fuel_cost = Σ[h where genUsed[h]>0] { F(P_h) × fuel_cost_per_liter }
```

`F₀ = no_load_fuel_lph ?? round(F_rated × 0.30, 4)` — uses actual no-load figure if stored, otherwise estimates 30% of rated consumption.

---

## 11. Financial Analysis

**Service:** `backend/app/Services/FinancialAnalysisService.php`  
**Controller:** `backend/app/Http/Controllers/Api/FinancialController.php`

### Key Constants

Source: `FinancialAnalysisService.php`

| Constant | Value |
|----------|-------|
| `PANEL_DEGRADATION` | 0.005 (0.5%/year) |
| `PROJECTION_YEARS` | 25 |
| `DF_PROJECT` | 0.70 |

### Generator Fuel Model (Affine)

**Model:** `backend/app/Models/GeneratorLine.php`

```
F(P) = F₀ + (F_rated − F₀) × P / P_rated     [L/hr]

where:
  F_rated = fuel_consumption_lph (at rated power P_rated)
  F₀ = no_load_fuel_lph (default = 0.30 × F_rated if not set)
  P = actual output power [kW]
```

This is an affine (linear two-point) model: fuel consumption is nonzero at zero load, increases linearly to rated.

**Cost at rated load:**
```
cost_per_kwh = fuel_cost_per_liter × fuel_consumption_lph / rated_power_kW
```

**Marginal cost (incremental, used by CostSignalService):**
```
marginal_cost_per_kwh = fuel_price × (F_rated − F₀) / P_rated
```

Source: `GeneratorLine.php`

### Weighted Tariff

```
weighted_tariff = (offPeakHours × tariff + peakHours × peakTariff) / 24
```

### 25-Year Energy Projection

```
annual_solar_kwh_year_n = solar_kwh_base × (1 − 0.005)^n    [panel degradation]

year_net = annual_savings × (0.995)^year − battery_replacement_cost_if_applicable
```

### LCOE (Simple / Undiscounted)

```
LCOE = (solar_install_cost + annual_maintenance_cost × 25) / (solar_kwh_annual × 25)
```

**Note:** This is the simple (undiscounted) LCOE. No NPV, IRR, or time-value-of-money adjustment is applied. Source: `FinancialAnalysisService.php`

### Generator Oversizing Warning

Source: `FinancialAnalysisService.php`

If average generator loading < 30%, a warning is issued.  
Recommended size: `peak_load / 0.75` (targeting 75% average loading per ISO 8528).

---

## 12. Phase Balance Analysis

**Controller:** `backend/app/Http/Controllers/Api/PhaseBalanceController.php`  
**Frontend:** `frontend/src/pages/PhaseBalancePage.jsx`

### Constants

Source: `PhaseBalanceController.php` lines 17–30

| Constant | Value |
|----------|-------|
| `VOLT` | 230 V |
| `WARN_PCT` | 10% |
| `CRIT_PCT` | 20% |
| `SOCKET_ASSUMED_PF` | 0.95 |
| `PHASE_SPLIT_MIN_VA` | 1500.0 VA |

Phase voltage angles (positive-sequence ABC): A = 0°, B = 120° (2π/3 rad), C = 240° (4π/3 rad).

### Two-Page Consistency Model

The Phase Balance page is a **consumer** of the Electrical Design page, not an independent calculator.

`PhaseBalanceController::buildingReport()` reads the `phase_balance_va` field directly from each floor's `ElectricalDesignService` output:

```php
foreach (['A', 'B', 'C'] as $ph) {
    $optVa[$ph] += (float) ($edFloor['db']['phase_balance_va'][$ph] ?? 0);
}
```

This means the **Optimal** distribution shown on the Phase Balance page is identical — to the VA — to the per-phase totals shown on the Electrical Design page. The two pages cannot show different values for the same project.

### Circuit-Level Phase Inference for SPLIT Rooms

`PhaseBalanceController` calls `ElectricalDesignService::analyzeProject()` and then maps ED circuits back to rooms. For each room and circuit type (SOCKET / LIGHTING / AUXILIARY):

1. Find all ED circuits on that floor that list the room in `room_names` and share the circuit type.
2. Sum those circuits' VA per phase → `circPhaseVa[A/B/C]`.
3. The room's own VA for that type is split proportionally: `roomVaPerPh[ph] = roomTypeVa × (circPhaseVa[ph] / totalCircVA)`.

A room whose loads land on multiple phases gets `is_split = true` in the API response, with a `split_sections` array listing each phase and its VA share. The Phase Balance page renders such rooms as a split badge row. This happens naturally when `ElectricalDesignService::balanceDrivenReSplit` places two sub-circuits of the same room on different phases.

### Phasor Current Calculation

For each load on phase P with power factor PF:

```
θ_V = phase voltage angle (0° / 120° / 240°)
|I| = VA / VOLT    (apparent current)
θ_I = θ_V − arccos(PF)    [current lags voltage by arccos(PF)]

I_re += |I| × cos(θ_I)
I_im += |I| × sin(θ_I)
```

Source: `PhaseBalanceController::computePhaseCurrent()` lines 436–454

### Neutral Current

Phasor (complex) sum of the three phase currents:
```
I_N = √((I_A_re + I_B_re + I_C_re)² + (I_A_im + I_B_im + I_C_im)²)
```

For a perfectly balanced 3-phase system, I_N → 0. This replaces the earlier PF=1 approximation (`I_N = √(I_A² + I_B² + I_C² − I_A·I_B − I_B·I_C − I_C·I_A)`), which assumed purely resistive loads.

Source: `PhaseBalanceController::computeNeutralCurrent()` lines 457–465

### Imbalance Metric

```
imbalance_pct = (I_max − I_min) / I_avg × 100
```

where `I_max`, `I_min`, `I_avg` are computed from VA ÷ 230 (scalar magnitudes, PF=1 approximation used here only for the spread metric).

**Note:** This is a spread metric (max-min range / mean), not the NEMA MG-1 voltage imbalance definition. Source: `PhaseBalanceController::imbalanceStatus()` lines 676–690

Thresholds: WARN at 10%, CRITICAL at 20%.

### Apply Optimal — Room-Split Handling

`POST /api/buildings/{building}/apply-optimal-phase` reads `block_assignments` from the building report. For a **split room**, each section specifies `component_ids` — the exact component IDs in that section — and `optimal_phase`. The controller updates only those components' `phase` field, leaving other components in the same room untouched. Different circuit-type sections of the same room can end up on different phases.

Source: `PhaseBalanceController::applyOptimalBuilding()` lines 129–189

---

## 13. Validation System and Automated Tests

### PHPUnit Test Suite

**Total: 371 tests, 1247 assertions** (26 deprecation notices, 0 failures). Verified: `php artisan test` — all pass.

| File | Tests | Status | Purpose |
|------|-------|--------|---------|
| `tests/Feature/ElectricalDesignTest.php` | 36 | ✓ | Cable ampacity, derating, VD, circuit classification, breaker curves, PE sizing |
| `tests/Unit/BatteryCapacityDisplayTest.php` | 6 | ✓ | Capacity formulas, age degradation, DoD, solar coupling fillable |
| `tests/Feature/PhaseBalanceConsistencyTest.php` | 7 | ✓ | ED↔Phase Balance two-page consistency; SPLIT rooms; imbalance formula identity |
| `tests/Feature/PhaseBalanceGeneralityTest.php` | ~312 (data-driven) | ✓ | Randomised floor inputs → imbalance ≤ 15% or provably at local minimum |
| `tests/Unit/SourceDispatchServiceTest.php` | 6 | ✓ | Generator loading threshold, round-trip-waste guard, daylight-flag |
| `tests/Unit/LoadSheddingServiceTest.php` | 8 | ✓ | Full shedding tier ordering, shift, fallthrough, restoration hysteresis |

#### `ElectricalDesignTest.php`

Cable ampacity table verification: for each of the 12 sizes, asserts the value matches IEC 60364-5-52 Table B.52.2, Method A1. Derating factor at 40°C: 0.87. Voltage drop spot checks (2 known cases). Uses PHP reflection to access the private `CABLE_AMPACITY` constant directly.

#### `BatteryCapacityDisplayTest.php`

**File:** `backend/tests/Unit/BatteryCapacityDisplayTest.php`  
Extends bare `PHPUnit\Framework\TestCase` (no DB, no Eloquent). All tests are pure arithmetic using helpers that mirror the Battery model accessor formulas.

| Test | What it checks |
|------|----------------|
| `test_nominal_capacity_kwh_formula` | `nominal = V × Ah × qty / 1000` for four cases (48V/100Ah, LFP 51.2V/100Ah) |
| `test_age_factor_degradation` | Linear degradation at 3%/yr; floor at 0.70 at 20 years |
| `test_usable_kwh_for_new_battery` | `usable = nominal × DoD × 1.0` (brand-new battery; gel 80% DoD, LFP 85% DoD) |
| `test_usable_always_less_than_nominal_when_dod_below_one` | For DoD ∈ {0.50, 0.70, 0.80, 0.85, 0.90}: asserts `usable < nominal` |
| `test_every_chemistry_preset_has_dod_below_one` | Iterates `BatteryChemistryService::all()`: all DoD in (0, 1) — ensures SLD never shows nominal as usable |
| `test_solar_system_id_is_fillable_for_sld_coupling` | `Battery::getFillable()` includes `solar_system_id` |

#### `LoadSheddingServiceTest.php`

**File:** `backend/tests/Unit/LoadSheddingServiceTest.php`  
Extends bare `PHPUnit\Framework\TestCase` (no DB). Tests the full shedding/restoration algorithm with synthetic slot and supply arrays.

| Test | What it proves |
|------|---------------|
| `test_healthy_day_produces_zero_shedding` | No deficit → all shed fields = 0, `adjusted_load_w` unchanged (regression guard) |
| `test_curtailable_reduced_before_normal_shed` | Step 2 fires before Step 3; `shed_normal_kwh = 0` when curtailment resolves deficit |
| `test_normal_shed_before_essential` | `shed_normal` event appears before `shed_essential` in `per_load_shed_list` |
| `test_critical_load_never_auto_shed` | Critical label absent from `per_load_shed_list`; residual in `critical_unmet_kwh` |
| `test_shiftable_shifted_before_shedding` | `shifted_to_h*` action present; `shed_normal_kwh = 0`; `adjusted_load_w[8] = 0` |
| `test_shiftable_falls_through_when_no_feasible_window` | When all window hours are supply-constrained, `shed_normal` fires (no shift action) |
| `test_curtailable_restored_last_after_normal` | Constrained supply at restoration hour fits Normal but not Normal+Curtailable → Curtailable deferred one more hour |
| `test_restoration_reverse_order_with_hysteresis` | Loads absent at h=6 (hysteresis); fully restored at h=7 (one hour after surplus clears) |

### Validation API (Cross-Check Service)

**Controller:** `backend/app/Http/Controllers/Api/ValidationController.php`

**`GET /api/validation/case-study`** — Runs a complete independent recalculation of:
- Room-level diversity cascade
- Floor-level diversity cascade
- Building-level diversity
- Project diversity (0.70 × above)
- Capacitor bank sizing
- Motor inrush

Uses `ValidationReferenceService.php` — an independent PHP implementation of all formulas that does NOT call the main services. Cross-checks the main API output and returns `overall_status: PASS | FAIL` with per-check results.

**`GET /api/validation/electrical-design`** — Uses PHP reflection to read the live `CABLE_AMPACITY` constant from `ElectricalDesignService`, runs 12 ampacity checks + derating check + 2 VD spot checks. Returns `overall_status: PASS | FAIL`.

### Validation UI

**Page:** `frontend/src/pages/ValidationPage.jsx`

Two sections:
1. **Case Study Comparison Table** — formula cross-check results with reference vs. computed values
2. **Electrical Design Validation** — cable ampacity table with IEC reference, derating row, 2 VD spot checks

Card sub-header for electrical design section:  
`"IEC 60364-5-52 Table B.52.2 · Method A1 (thermally insulated wall) · Cu 70°C PVC · 30°C ambient · most conservative"`

---

## 14. Frontend Architecture

**Entry point:** `frontend/src/App.jsx`

### Routing Structure

All project-internal pages use `<ProjectLayout>` as a nested route wrapper (provides sidebar navigation).

| Route | Page | Notes |
|-------|------|-------|
| `/` | → redirect to `/dashboard` | |
| `/login` | LoginPage | Public only |
| `/auth/callback` | AuthCallbackPage | Google OAuth callback |
| `/dashboard` | DashboardPage | Protected |
| `/validation` | ValidationPage | Protected, no project context |
| `/defense-prep` | DefensePrepPage | Protected |
| `/projects/:projectId` | ProjectPage | ProjectLayout |
| `/projects/:projectId/buildings/:buildingId` | BuildingPage | ProjectLayout |
| `/projects/:projectId/buildings/:buildingId/floors/:floorId` | FloorPage | ProjectLayout |
| `.../rooms/:roomId` | NewRoomPage | ProjectLayout |
| `/projects/:projectId/schedule` | LoadSchedulePage | ProjectLayout |
| `/projects/:projectId/phase-balance` | PhaseBalancePage | ProjectLayout |
| `/projects/:projectId/financial` | FinancialPage | ProjectLayout |
| `/projects/:projectId/single-line` | SingleLineDiagramPage | ProjectLayout |
| `/projects/:projectId/electrical-design` | ElectricalDesignPage | ProjectLayout |

All pages use `lazy()` loading (code-splitting).

### Key Components

| Component | Purpose |
|-----------|---------|
| `PanelScheduleTable.jsx` | Renders the per-panel circuit schedule table with inline VD input |
| `ProjectSidebar.jsx` | Left-side navigation within a project |
| `LoadingSpinner.jsx` | Suspense fallback |
| `ErrorBoundary.jsx` | Per-route error isolation |

### Auth Flow

1. User logs in via `/login` (email/password or Google OAuth)
2. Backend returns Sanctum token
3. Token stored; `AuthContext` provides `user` and `isLoading` to all children
4. `ProtectedRoute` wraps all authenticated pages; unauthenticated → redirect `/login`
5. `PublicRoute` wraps login page; authenticated → redirect `/dashboard`

### Data Fetching Pattern

All pages use `useEffect` + Axios:
```javascript
api.get(`/api/projects/${projectId}/electrical-design`)
  .then(res => setData(res.data))
  .catch(err => setError(err.response?.data?.message ?? 'Failed to load'))
  .finally(() => setLoading(false));
```

### Electrical Design UI — Key Behaviors

- **Building section** (`ElectricalDesignPage.jsx:BuildingSection`): collapsible floors, MDB summary stat cards
- **Stat cards**: Nameplate Total (kVA), Diversified Demand (kVA + % of nameplate), MDB Incomer (A + cable mm²), Phase Imbalance (% — red/amber/green)
- **Engineering notice** (`ElectricalDesignPage.jsx:250–256`): states Table B.52.2, Method A1, most conservative; instructs user to enter cable lengths for VD
- **vdTable** passed to `PanelScheduleTable` for client-side VD remedy lookup

---

## 15. Standards Referenced

| Standard | Application in System |
|----------|----------------------|
| **IEC 60364-5-52** | Cable ampacity (Table B.52.2, Method A1), VD limits (Table 1), install method A1, derating factors (Table B.52.14) |
| **IEC 60364-5-54** | PE conductor sizing (3-band rule: ≤16 mm² → equal; 16–35 mm² → 16 mm²; >35 mm² → half) |
| **IEC 60364-8-1** | Diversity factors at all levels; building-type DFs; room coincidence factors |
| **IEC 60898** | Standard MCB ratings (B/C/D curve trip classes) |
| **IEC 60947-2** | MCB/MCCB rating standard (noted in code as breaker standard) |
| **NEC Article 430 / IEC** | Motor inrush 125% sizing factor |
| **ISO 8528** | Generator optimal load band (70–85%); oversizing warning at <30% average load |
| **PENRA** | Palestinian Energy and Natural Resources Authority: 230 V / 400 V, 50 Hz system; PF target 0.95; correction threshold 0.85 |
| **CIBSE Guide C / BS 7671** | Room coincidence factors and building-type diversity factors |
| **NASA POWER** | GHI data (`ALLSKY_SFC_SW_DWN`) for solar irradiance calculations |

**Note on IEC 60364-5-52 mV/A/m values:** All voltage-drop `mV/A/m` values in `CABLE_VD_MV_A_M` are individually marked `⚠ VERIFY` in the source code (`ElectricalDesignService.php` lines 86–97). They are engineering estimates derived from IEC 60364-5-52 / BS 7671 Appendix 4 Table 4D2B and should be verified against the current standard edition before a licensed submission.

---

## 16. Implemented vs Deferred Features

### Fully Implemented

| Feature | Evidence |
|---------|---------|
| IEC 60364-8-1 diversity factors (11 building types, 20 room types) | `DiversityFactorService.php` |
| Socket demand tiered model (200 VA/outlet, first 10 × 100%, next 10 × 75%, rest × 40%) | `SocketDemandService.php` |
| Reactive power and capacitor bank sizing (delta Δ, PF 0.85→0.95) | `TotalPowerController.php` |
| Motor inrush 125% (NEC/IEC) | `TotalPowerController.php:33` |
| Electrical design panel schedules (5 circuit types, all building types) | `ElectricalDesignService.php` |
| Cable sizing IEC 60364-5-52 Table B.52.2 Method A1 | `ElectricalDesignService.php:64–77` |
| Voltage drop per circuit (mV/A/m method) with remedy suggestion | `ElectricalDesignService.php:948–974` |
| PE sizing (IEC 60364-5-54 3-band rule) | `ElectricalDesignService.php:895–900` |
| Breaker curve selection (B/C/D) | `ElectricalDesignService.php:878–883` |
| Phase assignment FFD greedy + phasor neutral current | `PhaseBalanceController.php` |
| 7-step multi-source energy dispatch with battery SOC tracking | `SourceDispatchService.php` |
| Demand-side load shedding (priority-aware shift/curtail/shed/restore with hysteresis) | `LoadSheddingService.php`, `ScheduleController::buildComponentSlots()` |
| Solar irradiance (NASA POWER API + PSH table fallback) | `SolarIrradianceService.php` |
| Affine generator fuel model | `GeneratorLine.php` |
| LCOE (simple/undiscounted, 25-year) | `FinancialAnalysisService.php` |
| Battery degradation and replacement scheduling | `Battery.php`, `FinancialAnalysisService.php` |
| Generator oversizing detection (<30% average load) | `FinancialAnalysisService.php` |
| Essential panel separation (hospital, data center) | `ElectricalDesignService.php:312` |
| group_small_critical packing option | `ElectricalDesignService.php:355` |
| PHPUnit test suite (371 tests, 1247 assertions) | `ElectricalDesignTest.php`, `BatteryCapacityDisplayTest.php`, `PhaseBalance*Test.php`, `SourceDispatchServiceTest.php`, `LoadSheddingServiceTest.php` |
| Live validation API with reference implementation | `ValidationController.php`, `ValidationReferenceService.php` |
| Battery chemistry presets (5 types: flooded/AGM/gel lead-acid, LFP, NMC) with auto-fill on create/update | `BatteryChemistryService.php`, `BatteryController.php` |
| Battery chemistry comparison endpoint (Lead-Acid vs LFP, same nominal capacity, affine fuel cost) | `ScheduleController@chemistryComparison` |
| LPT fixture-level circuit splitting + iterative balance-driven re-split (15% target) | `ElectricalDesignService.php` |
| Two-page phase-balance consistency: ED as single source of truth; Phase Balance page reads ED circuit-level data | `PhaseBalanceController::buildingReport()` |
| SPLIT-room phase display on Phase Balance page | `PhaseBalancePage.jsx` |
| Solar-coupled BESS (hybrid inverter topology via `solar_system_id` FK) | `Battery.php`, `SourceDispatchService.php`, `SingleLineDiagramPage.jsx` |
| SLD hybrid-inverter group node (HybridGroup SVG component), conditional legend, BESS dual capacity display, bus voltage label, breaker ratings | `SingleLineDiagramPage.jsx` |
| Project backup/restore (JSON), building/floor/room scoped | `ProjectBackupController.php` |
| Multi-user project collaboration (roles: admin/main/normal) | `ProjectMemberController.php` |
| Google OAuth login | `AuthController.php` via Socialite |
| Admin panel (user management) | `AdminController.php` |
| Named solar systems (multiple per project) | `SolarSystem` model, `SolarSystemController.php` |
| Multi-battery bank model | `Battery` model, `BatteryController.php` |

### Known Limitations (Current Implementation)

1. **Protection coordination and short-circuit calculation** — MCB ratings are selected from standard IEC 60898 sizes but short-circuit current levels and cascading coordination are not calculated
2. **Grouping derating (Table B.52.17)** — no derating for multiple circuits in the same conduit
3. **3-phase cable column vs 2-core column** — 3-phase HEAVY circuits use the 2-core ampacity column (conservative but not strictly correct); the 3/4-core column is ~8–11% lower
4. **mV/A/m values** — engineering estimates; each marked ⚠ VERIFY in code
5. **Module efficiency** — not explicitly applied as a separate factor in solar capacity estimate
6. **LCOE** — simple/undiscounted; no NPV or IRR
7. **Cooper declination formula** — code comments say "Spencer"; the formula implemented is **Cooper (1969)**
8. **NASA POWER year** — hardcoded to 2023

### Deferred / Not Implemented

- Short-circuit calculation and fault current analysis
- Protection coordination study (upstream/downstream selectivity)
- Harmonic distortion analysis (THD)
- Arc flash hazard study
- Earthing (grounding) system design
- Load flow simulation for distribution network
- Mobile application

---

*Document updated 2026-07-06 (v1.2). All formulas and constants verified against file:line citations listed. Items marked ⚠ require independent verification before licensed engineering submission. See Section 20 for corrections log.*

---

## Section 17 — Page-by-Page User Walkthrough

This section documents every page the user encounters, in natural workflow order. For each page: its purpose, every input field (what it is, unit, how entered, which backend field it populates, how changing it affects outputs), and every output element displayed.

---

### 17.1 Dashboard (`DashboardPage.jsx`)

**Purpose:** Starting point. Lists all projects the current user can access and provides project management actions.

**Outputs shown:**
- Project cards: name, number of buildings, diversified demand in kVA and kW, last-modified date
- Role badge per project: **admin** (owner), **Main User**, or **View Only**
- Demand formatted via `fmtVA()` (VA → kVA → MVA at 1 k / 1 M thresholds) and `fmtKW()` (W → kW → MW)

**Input actions:**

| Action | Fields | Notes |
|---|---|---|
| Create new project | Project name (text) | Modal with a single name field; creates an empty project |
| Edit project | Name (text) + auto-backup interval (Never / Daily / Weekly / Monthly) | Auto-backup saves a full JSON export on the chosen cadence |
| Restore from backup | JSON file (drag-and-drop or file picker) | Re-imports all buildings, floors, rooms, components, and power sources |
| Delete project | Confirmation prompt | Permanent; cannot be undone from the UI |

**What the user learns here:** Which projects exist, their total electrical demand, and whether they have editing rights.

---

### 17.2 Project Page (`ProjectPage.jsx`)

**Purpose:** Shows all buildings in the project, configures project-wide schedule and currency, manages team members, and accepts project-level loads.

**Outputs shown:**
- **PowerBanner** (sticky top bar): diversified demand in kVA and kW, recalculated on every load change
- **PowerSourcesBanner**: utility lines, generator lines, solar systems, and battery banks in a collapsible strip
- Building cards: name, building type badge, floor count, area m²

**Input actions:**

| Field | Type | Unit | Backend field | Effect on output |
|---|---|---|---|---|
| Building name | Text + autocomplete | — | `buildings.name` | Label only; autocomplete suggests names from existing buildings in project |
| Building type | Dropdown (12 options) | — | `buildings.type` | Used in building header of Electrical Design page; informs component suggestions |
| Building area | Number | m² | `buildings.area_m2` | Used in solar capacity heuristic estimate |
| Currency symbol | Text (1–3 chars) | — | `projects.currency_symbol` | Displayed on Financial page cost figures |
| Working days | Toggle buttons Mo–Su | — | `schedules.working_days` | Days included in load-profile and cost calculations |
| Work hours | HH:MM–HH:MM pairs (multiple allowed) | — | `schedules.work_hours` | Active windows for loads with `usage_day_type = weekday` |
| Operating season | Month + day pairs (start date, end date) | — | `schedules.operating_season` | Date range for loads marked `usage_season = summer` or `winter` |

Building types: Generic, Residential House, Apartment, Hotel, Office, School, University, Retail, Hospital, Industrial, Mosque, Sports.

**What the user learns here:** The overall project layout and top-level demand; sets cost-accounting context via currency and schedule.

---

### 17.3 Building Page (`BuildingPage.jsx`)

**Purpose:** Shows all floors in the selected building. Accepts building-level loads (loads that belong to the whole building rather than a single floor). Same PowerBanner and PowerSourcesBanner scoped to this building.

**Outputs shown:**
- Floor cards: name, room count, area m²
- PowerBanner showing building diversified demand

**Input actions:**

| Field | Type | Unit | Notes |
|---|---|---|---|
| Floor name | Text | — | e.g. "Ground Floor" |
| Floor area | Number | m² | Used in area calculations and pro-rated diversity |
| Building schedule override | Same fields as project schedule | — | Overrides project schedule for this building only |

**What the user learns here:** How many floors the building has and how building-level demand compares to project total.

---

### 17.4 Floor Page (`FloorPage.jsx`)

**Purpose:** Lists rooms on this floor. Accepts floor-level loads. Same banner structure scoped to floor.

**Outputs shown:**
- Room cards: name, room type badge, area m²

**Input actions:**

| Field | Type | Unit | Notes |
|---|---|---|---|
| Room name | Text | — | — |
| Room type | Dropdown | — | Guides component autocomplete suggestions |
| Room area | Number | m² | Used in area calculations |
| Floor schedule override | Schedule fields | — | Overrides building schedule for this floor |

**What the user learns here:** Demand distribution across rooms on the floor.

---

### 17.5 Room Page — Component Entry (`RoomPage` / `EntityComponents.jsx`)

**Purpose:** The primary data-entry screen. Every electrical load in the room is entered here as a **component**. Values entered on this screen are the source data for all outputs in the system — electrical design, load schedule, financial analysis, and phase balance all derive from these entries.

**Component card (list view):**
Each saved component shows: name, priority badge, flexibility badge, phase badge (1Φ / 3Φ), phase letter (A/B/C if pinned), Socket badge (if `needs_socket = true`), Motor badge (if `is_motor = true`), group name chip, `VA × qty · PF value · total W`, and usage time interval chips.

**Component entry modal — every input field:**

| Field | Type | Unit | What it is | Effect on outputs |
|---|---|---|---|---|
| Component Name | Text + autocomplete | — | Names the component type. Autocomplete matches existing types in the project and pre-fills power/phases/PF from previous entries of the same name. Typing a new name creates a new type. | If the selected type is marked `is_motor = true`, triggers 125% continuous-load sizing in the Electrical Design |
| Apparent Power (VA) | Number ≥ 1 | VA | Rated apparent power of **one** unit. For a 36 W LED at PF=1.0 enter 36. For an AC unit with 2.5 kW real power at PF=0.85 enter 2941 VA (= 2500/0.85). Enter nameplate VA if given. | `Ib = VA / 230` (1Φ) or `VA / (√3 × 400)` (3Φ); also `P_W = VA × PF` for energy calculations |
| Phases | Toggle: 1Φ / 3Φ | — | Whether the load uses one phase + neutral, or all three phases. Motors and ACs are usually 3Φ; lighting and small sockets are 1Φ. | Changes voltage divisor for Ib (230 V vs 400 V); 3Φ loads are spread equally across all three phases in the phase-balance calculation |
| Power Factor | Number 0.01–1.00 | — | Ratio of real (W) to apparent (VA) power. PF=1.0 for resistive loads (heaters, LED drivers with no reactive component). PF≈0.85 for induction motors and ACs. PF≈0.90 for switch-mode power supplies. | `P_W = VA × PF`; used in all energy (kWh) and cost calculations; does not affect breaker sizing (which uses VA) |
| Phase (optional, 1Φ only) | A / B / C toggle | — | Pins this load to a specific phase. If left blank, the FFD greedy algorithm assigns it automatically. | Directly determines which phase accumulates this load's VA in the phase-balance calculation |
| Number of Pieces | Integer ≥ 1 | — | Quantity of identical units in this room. Enter 6 for six identical luminaires on the same circuit. | `total_VA = VA × qty`; the service may combine multiple components of the same type into one circuit |
| Priority | Dropdown: Normal / Essential / Critical | — | Load priority for dispatch and protection. Selecting **Critical** automatically sets schedule to 24 h/day (00:00–23:59, all days, all seasons). | Critical loads → routed to ATS-backed essential panel + RCD added to circuit; Essential → preferred in source dispatch order; Normal → flexible |
| Load Scheduling Type | Dropdown: Fixed / Shiftable / Curtailable | — | How the optimizer treats this load. **Fixed** runs exactly at the time intervals entered below. **Shiftable** lets the optimizer select the cheapest window within earliest/latest bounds (no time intervals needed — optimizer assigns them). **Curtailable** can reduce output below rated during high-cost hours. | Only affects the "Optimized load" line on the Load Schedule page; does not change nameplate VA or breaker sizing |
| (Shiftable only) Required run hours | Integer 1–24 | h/day | How many hours per day the optimizer must guarantee this load runs | Optimizer hard constraint |
| (Shiftable only) Earliest start hour | Dropdown 00:00–23:00 | HH:00 | Earliest allowed start time | Optimizer window bound |
| (Shiftable only) Latest end hour | Dropdown 01:00–24:00 | HH:00 | Latest allowed finish time | Optimizer window bound |
| (Shiftable only) Allow split / Max interruptions | Checkbox + integer | — | Whether the optimizer may break the run into multiple sub-windows and how many times | Optimizer flexibility |
| (Curtailable only) Minimum output % | Number 1–100 | % | Floor on how much the load can be reduced (e.g. 50% means cannot curtail below half rated power) | Optimizer reduction bound |
| Usage Season | Toggle: All / Summer / Winter | — | Which seasons this load is active. "All" = year-round. Seasonal loads are excluded from energy and cost calculations during the off-season months. | Filters load from active hours in the load-profile calculation for months outside the season |
| Usage Day Type | Toggle: All / Weekday / Weekend | — | Which day types. "All" = every day. | Weekday-only loads are excluded on weekend days per the project schedule |
| Usage Time Intervals | HH:MM–HH:MM pairs, multiple allowed | — | Clock windows during which this load is on. Add multiple intervals for split-shift operation (e.g. "08:00–12:00" + "14:00–17:00"). Active hours = sum of interval durations. | `kWh/day = VA × PF × Σ(interval_hours)`; directly sets daily energy and cost contribution |

**Additional toggles (shown as badges in card view):**
- `needs_socket = true`: component requires a socket outlet on its circuit; circuit is classified as SOCKET type (adds 30 mA RCD requirement)
- `is_motor = true`: circuit is classified HEAVY; largest-motor 125% rule applied; Curve C breaker assigned

**What the user learns here:** The bottom-up electrical load inventory. Every kWh and every ampere in downstream pages traces back to values entered here.

---

### 17.6 Load Schedule Page (`LoadSchedulePage.jsx`)

**Purpose:** Shows the hourly demand profile for a chosen month and day, comparing coincident peak load to the optimizer's peak-shaving result, and breaking down energy supply by source.

**Controls:**

| Control | Effect |
|---|---|
| Month picker (1–12) | Selects month for solar declination, seasonal load filtering, and NASA POWER GHI look-up |
| Day picker (day of month) | Selects the 24-hour profile day to display |

**Tab 1 — Load profile:**
- Y-axis: kW
- **Max load** line: coincident sum of all active loads at each hour (worst-case demand)
- **Optimized load** line: after shiftable loads are rescheduled and curtailable loads are reduced
- **Reactive kVAR** area: reactive power demand per hour

**Tab 2 — Sources:**
- **Solar capacity** area: available solar output per hour based on irradiance model and declared system size
- **Utility capacity** area: configured import limit per hour
- **Generator capacity** area: rated output if a generator is configured

**Tab 3 — Combined dispatch:**
- Stacked areas: Solar used, Battery discharge, Utility used, Generator used, Unmet demand
- **Battery SOC** overlay line (0–100%): shows how the battery charges through the solar peak and drains during the evening/night
- The dispatch engine's **target SOC** (the night-reserve floor) is visible as the SOC floor the battery holds at sunset before the night discharge begins

**StatCard strip (for the selected day):**
- Active hours (hours with non-zero demand)
- kWh delivered (total energy consumed)
- Source share % (what fraction came from solar / grid / generator / BESS)
- Daily cost (tariff × kWh from paid sources; generator cost uses affine F(P) = F₀ + (F_rated − F₀) × P/P_rated)

**Additional dispatch diagnostics (from `stats` field):**
- `battery_target_soc` / `battery_target_kwh` — night-reserve target computed by the pre-dispatch look-ahead
- `soc_at_sunset` — actual SOC when solar output drops below 50 W
- `night_generator_hours` / `night_generator_kwh` — generator activity after sunset
- `solar_peak_hour` — hour of maximum solar output

**Shedding alert banner:** If `shedding.critical_unmet_kwh > 0` for the selected day, a red banner appears at the top of the page: "CRITICAL LOADS UNMET: X.XX kWh cannot be served — All non-critical loads were shed but supply is still insufficient. Add generation capacity, battery storage, or reduce critical load." This means the supply is so severely undersized that even completely removing all Normal and Essential loads cannot cover the Critical ones. (`LoadSchedulePage.jsx` lines 676–694)

**Demand line consistency:** The combined-dispatch chart's demand reference line uses `load_shed_optimized[h]` (or `load_shed_max[h]` in max mode) — the post-shed/shift adjusted profile — not the original pre-shed profile. This keeps the stacked supply areas and demand line in sync. (`LoadSchedulePage.jsx` lines 579–582)

**What the user learns here:** Whether the profile has damaging demand peaks, how much solar offsets consumption each hour, when the battery charges and discharges relative to the night-reserve floor, whether any unmet demand hours exist, whether any loads were shifted or shed, and how the generator fits into the supply mix.

---

### 17.7 Financial Page (`FinancialPage.jsx`)

**Purpose:** Shows the 25-year economic case for the configured power infrastructure versus utility-only baseline.

**Controls:**
- Month selector (1–12): representative month used for energy-mix calculation

**SummaryCard grid:**

| Card | Value | What it means |
|---|---|---|
| LCOE | $/kWh | Levelized cost from solar: total capital / (annual solar kWh × 25 years) |
| Payback year | Year N | Year when cumulative savings equal total capital outlay |
| Total 25-year savings | $ | Sum of utility bill reductions over project lifetime |
| Annual solar kWh | kWh | Solar energy absorbed by the building each year |
| Energy mix | % per source | Solar / Grid / Generator / BESS shares of annual consumption |

**Charts:**
- **25-year cumulative net cash flow** (LineChart): runs negative initially (capital cost) then rises as savings accumulate; a `ReferenceDot` marks the exact payback year
- **Energy mix pie** (PieChart): visual breakdown of annual energy by source

**What the user learns here:** Whether the solar/battery investment makes financial sense and the number of years to break-even.

---

### 17.8 Phase Balance Page (`PhaseBalancePage.jsx`)

**Purpose:** Shows how single-phase loads are distributed across phases A, B, C; flags imbalance; allows manual phase reassignment; and displays rooms that have been automatically split across multiple phases by the electrical design engine.

**Consistency guarantee:** The per-phase VA totals shown on this page are sourced from `ElectricalDesignService` — the identical numbers shown on the Electrical Design panel schedule. The two pages always agree.

**Outputs:**
- **PhaseBar** for each phase: VA on that phase, percentage of three-phase total, line current (A = VA ÷ 230)
  - Phase A: indigo color
  - Phase B: emerald color
  - Phase C: amber color
- **Overall imbalance %** and status badge: Balanced (< 10%), Warning (10–20%), Critical (> 20%)
- **Neutral current (A)**: phasor sum of three unbalanced phase currents (complex phasor sum, accounts for PF)
- **Room assignment rows**: each room shows A / B / C chip, or "auto", or a **SPLIT** badge if the room's loads were distributed across multiple phases by the balance engine
  - **SPLIT rooms** show a section breakdown: e.g. "Section 1 → A (720 VA) · Section 2 → B (360 VA)"
  - Each section corresponds to a distinct circuit type (SOCKET / LIGHTING / AUXILIARY)

**Input actions:**
- `PhaseAssignButtons` per room: click A, B, or C to pin all 1-phase loads in that room to that phase; click Clear to return to auto-assignment
- **Apply Optimal** button: writes the engine's computed assignment to the database. For SPLIT rooms, each section's component IDs are updated independently so different circuit types in the same room can be on different phases.
- Changes take effect immediately; `PhaseBar` and imbalance % update in real time

**What the user learns here:** Whether the electrical system has an unacceptable neutral current (which causes transformer heating and potential neutral conductor overload), which rooms or sections to reassign to fix it, and how the automatic balance engine has distributed loads.

---

### 17.9 Single-Line Diagram Page (`SingleLineDiagramPage.jsx`)

**Purpose:** Auto-generated SVG single-line schematic of the project's power topology. Includes a back-to-project navigation button.

**Canvas geometry:**

| Constant | Value | Role |
|----------|-------|------|
| `W` | 1200 px | Total SVG width |
| `SRC_Y` | 100 | Source node row Y |
| `BUS_Y` | 310 | Bus bar Y |
| `BLD_Y` | 500 | Building node row Y |
| `NODE_R` | 46 px | Source circle radius |
| `HYBW × HYBH` | 180 × 110 px | HybridGroup rectangle |

**Source topology (top row):**

- **Standalone SourceNode circles**: Solar (yellow) · BESS (violet, standalone batteries only) · Utility Grid (blue) · Generator (orange)
  - Each circle shows: emoji icon, source name, capacity (kW), and for BESS: "X.X kWh usable / Y.Y kWh nominal"
- **HybridGroup rectangle** (when one or more batteries have `solar_system_id ≠ null`): replaces the separate solar and coupled-battery circles with a single combined node. Shows:
  - Left side: ☀️ Solar symbol, solar capacity kW
  - Right side: 🔋 Battery symbol, usable kWh / nominal kWh
  - A DC BUS line divides the two sides
  - Footer label: "Hybrid Inverter"

**Topology detection (in the React component):**

```javascript
const coupledBatt  = activeBatt.filter(b => b.solar_system_id != null);
const standaloneBatt = activeBatt.filter(b => b.solar_system_id == null);
const hasSolarCoupling = coupledBatt.length > 0 && activeSolar.length > 0;
// When hasSolarCoupling: solar + coupled batteries → hybridNodes[]
// Standalone sources → srcNodes[]
```

**Connection line and breaker:**
- One wire from each source/group to the bus bar
- **Breaker symbol** on each wire: small square (10×10 px) with diagonal line; labeled with IEC 125%-rated current in amperes (`nextBreaker(kW)` function)

**Bus bar:** Horizontal line at `BUS_Y`, dark blue, labeled "230 / 400 V · 3-phase"

**BuildingNode rectangles** (bottom row): one per building; labeled with name, floor count, and total kVA.

**Legend** (conditional): only items present in the diagram appear. Empty legend if all types are missing.

**Download SVG** button saves the diagram.

**What the user learns here:** A quick visual topology check suitable for reports and presentations — distinguishes DC-coupled hybrid-inverter configurations from separate sources, shows breaker ratings, and confirms all sources and buildings are connected to the common bus.

---

### 17.10 Electrical Design Page (`ElectricalDesignPage.jsx` + `PanelScheduleTable.jsx`)

**Purpose:** Complete panel schedule for every floor distribution board (DB) and building main distribution board (MDB), computed from the load inventory and IEC 60364 rules.

**Page-level stat cards:**

| Card | Value | Notes |
|---|---|---|
| Buildings | count | — |
| Floor DBs | count | Total distribution boards across all buildings |
| Total Circuits | count | Final circuits across all DBs |
| Derating Factor | 0.87 | 40 °C ambient, PVC/Cu cable, Method A1 |

**Engineering notice:** Cable ampacity values from IEC 60364-5-52 Table B.52.2, Method A1 (conductors in conduit in thermally insulated wall — the most conservative reference method). Enter cable run lengths in the L (m) column for live voltage-drop calculation.

**Per-building section — stat cards:**

| Card | Formula |
|---|---|
| Nameplate Total | Sum of all VA (all rooms, all components × quantity) |
| Diversified Demand | Nameplate × IEC 60364-8-1 diversity factors (room → floor → building → project) |
| MDB Incomer | Next standard MCB rating above `Ib = VA_diversified / (√3 × 400)` |
| Phase Imbalance % | `(VA_max_phase − VA_min_phase) / VA_avg_phase × 100` |

**Panel schedule table — every column:**

| Column | What it shows | How computed |
|---|---|---|
| # | Circuit number | Sequential within the panel |
| Type | Circuit classification badge | LIGHTING (amber), SOCKET (blue), HEAVY (red), CRITICAL (deep red), AUXILIARY (teal), MIXED (purple), FLOOR_FEEDER (indigo) — see Section 7 for classification rules |
| Phase | Phase letter A / B / C, or 3PH | From FFD greedy auto-assignment or user override on Phase Balance page |
| Rooms / Description | Room names or custom label | Rooms whose loads are grouped on this circuit |
| Loads | Component name × qty | Each load; **M** badge if `is_motor = true` |
| Total VA | Apparent power | Sum of `VA × quantity` for all loads on the circuit |
| Ib (A) | Design current | `VA / 230` (1Φ) or `VA / (√3 × 400)` (3Φ); for motor circuits uses `VA × 1.25 / V_nom` |
| In (A) | Breaker rated current | Next standard MCB/MCCB size above Ib from IEC 60898 series: 6/10/16/20/25/32/40/50/63/80/100 A |
| Curve | Trip characteristic | B = general (lighting, resistive); C = motor loads, mixed; D = transformer inrush |
| Cable mm² | Conductor cross-section | Smallest size from IEC 60364-5-52 Table B.52.2 with derated ampacity ≥ Ib (derating = 0.87 at 40 °C) |
| PE mm² | Protective earth conductor | IEC 60364-5-54 three-band rule: cable ≤ 16 mm² → PE = cable; 16–35 mm² → PE = 16 mm²; > 35 mm² → PE = cable ÷ 2 |
| Util % | Utilisation | `(Ib / In) × 100`; ≤ 80% green, 80–95% amber, > 95% red |
| RCD | Residual-current device | "30 mA" badge for all SOCKET circuits and all CRITICAL-priority loads; "—" otherwise |
| L (m) | Cable run length | User-entered field; not stored server-side; session-only |
| ΔU % | Voltage drop | `(mV/A/m × Ib × L) / (V_nom × 1000) × 100`; limit 3% for LIGHTING, 5% for others; displayed red when exceeded with suggested next cable size |
| Note | Warnings | "⚠ Critical" if circuit contains critical-priority loads (routes to essential panel); voltage-drop note if length not yet entered |

**What the user learns here:** The complete switchboard design — every breaker rating and trip curve, every cable and PE size, and (after entering cable lengths) every voltage-drop value — ready for a certified panel-schedule drawing.

---

## Section 18 — End-to-End Worked Example

**Building:** Al-Noor Community School, 2 floors.
**Location:** Cairo area (latitude 30° N).
**Project schedule:** Mon–Fri, 08:00–17:00, Operating season all year.

---

### 18.1 Data Entry (Room Page)

**Floor 1 — Ground Floor**

| Room | Component | VA | PF | Qty | Phases | Priority | Season | Intervals | is_motor |
|---|---|---|---|---|---|---|---|---|---|
| Admin Office | LED Panel | 36 | 1.00 | 2 | 1Φ | Normal | All | 08:00–17:00 | No |
| Admin Office | Office Socket | 200 | 0.90 | 4 | 1Φ | Normal | All | 08:00–17:00 | No |
| Admin Office | Ceiling Fan | 70 | 0.85 | 1 | 1Φ | Normal | All | 08:00–17:00 | No |
| Classroom 101 | LED Panel | 36 | 1.00 | 6 | 1Φ | Normal | All | 08:00–17:00 | No |
| Classroom 101 | Classroom Socket | 200 | 0.90 | 4 | 1Φ | Normal | All | 08:00–17:00 | No |
| Classroom 101 | Ceiling Fan | 70 | 0.85 | 2 | 1Φ | Normal | All | 08:00–17:00 | No |
| Classroom 101 | Split AC | 3000 | 0.85 | 1 | 3Φ | Normal | Summer | 09:00–16:00 | Yes |

**Floor 2 — First Floor**

| Room | Component | VA | PF | Qty | Phases | Priority | Season | Intervals | is_motor |
|---|---|---|---|---|---|---|---|---|---|
| Server Room | LED Panel | 36 | 1.00 | 2 | 1Φ | Critical | All | auto (24 h) | No |
| Server Room | Server UPS | 1500 | 0.90 | 1 | 3Φ | Critical | All | auto (24 h) | No |
| Classroom 201 | LED Panel | 36 | 1.00 | 6 | 1Φ | Normal | All | 08:00–17:00 | No |
| Classroom 201 | Classroom Socket | 200 | 0.90 | 4 | 1Φ | Normal | All | 08:00–17:00 | No |
| Classroom 201 | Ceiling Fan | 70 | 0.85 | 2 | 1Φ | Normal | All | 08:00–17:00 | No |
| Classroom 201 | Split AC | 3000 | 0.85 | 1 | 3Φ | Normal | Summer | 09:00–16:00 | Yes |

> Selecting "Critical" priority on Server Room components automatically sets their schedule to 00:00–23:59, all days, all seasons. The user does not need to enter time intervals for Critical loads.

---

### 18.2 PowerBanner — Nameplate and Diversified Demand

Nameplate totals (sum of all `VA × quantity`):

| Level | VA |
|---|---|
| Admin Office | 2×36 + 4×200 + 1×70 = **942 VA** |
| Classroom 101 | 6×36 + 4×200 + 2×70 + 1×3000 = **4 156 VA** |
| Ground Floor | 942 + 4 156 = **5 098 VA** |
| Server Room | 2×36 + 1×1 500 = **1 572 VA** |
| Classroom 201 | same as 101 = **4 156 VA** |
| First Floor | 1 572 + 4 156 = **5 728 VA** |
| Building nameplate | 5 098 + 5 728 = **10 826 VA ≈ 10.8 kVA** |

After applying IEC 60364-8-1 diversity factors at room, floor, building, and project levels (project DF = 0.70), the PowerBanner displays approximately **6.8 kVA** diversified demand. The banner also shows active real power: roughly **5.9 kW** (average PF ≈ 0.87).

> **What the user learns:** The building will realistically draw about 6.8 kVA at peak — notably less than the 10.8 kVA nameplate, because not all loads run simultaneously.

---

### 18.3 Electrical Design Page — MDB and Floor DBs

**MDB incomer (building-level, 3-phase supply):**

```
Ib_mdb = VA_diversified / (√3 × 400)
       = 6 800 / 692.8
       = 9.8 A

In_mdb = next standard size above 9.8 A → 10 A MCB, Curve B
Cable   = next size with derated ampacity ≥ 9.8 A
        → 2.5 mm² (base 17 A × 0.87 = 14.8 A derated) ✓
PE      = 2.5 mm² (cable ≤ 16 mm² → PE = cable)
```

**Selected ground floor circuits (GF DB):**

| # | Type | Phase | Description | Total VA | Ib (A) | In (A) | Curve | Cable mm² | PE mm² | Util % | RCD |
|---|---|---|---|---|---|---|---|---|---|---|---|
| GF-01 | Lighting | A | Admin LEDs ×2 | 72 | 0.31 | 6 | B | 1.5 | 1.5 | 5% | — |
| GF-02 | Socket | A | Admin Sockets ×4 | 800 | 3.48 | 6 | B | 1.5 | 1.5 | 58% | 30mA |
| GF-03 | Lighting | B | C101 LEDs ×6 + Fans ×2 | 356 | 1.55 | 6 | B | 1.5 | 1.5 | 26% | — |
| GF-04 | Socket | B | C101 Sockets ×4 | 800 | 3.48 | 6 | B | 1.5 | 1.5 | 58% | 30mA |
| GF-05 | Heavy | 3PH | C101 AC (motor ×1.25) | 3 750 eff | 5.41 | 6 | C | 1.5 | 1.5 | 90% | — |

For circuit GF-05 (motor):
```
Ib_rated = 3 000 / (√3 × 400) = 4.33 A
Ib_sizing = 4.33 × 1.25 = 5.41 A   (NEC 430.24/430.22 continuous-load rule)
In        = 6 A (next standard above 5.41 A), Curve C
Util%     = 5.41 / 6 × 100 = 90%    ← amber warning; slightly oversized cable would reduce this
```

**First floor circuits (FF DB — critical server room):**

| # | Type | Phase | Description | Total VA | Ib (A) | In (A) | Curve | Cable mm² | PE mm² | Util % | RCD | Note |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| FF-01 | Critical | A | Server LEDs ×2 | 72 | 0.31 | 6 | B | 1.5 | 1.5 | 5% | 30mA | ⚠ Critical |
| FF-02 | Critical | 3PH | Server UPS ×1 | 1 500 | 2.17 | 6 | B | 1.5 | 1.5 | 36% | 30mA | ⚠ Critical |

The page also shows an **Essential Panel Required** notice: critical-priority circuits must be supplied from an ATS-backed essential busbar.

**Voltage drop check (GF-02, socket circuit, cable run L = 25 m):**
```
mV/A/m for 1.5 mm² ≈ 24 mV/A/m
ΔU (V)  = 24 × 3.48 × 25 / 1000 = 2.09 V
ΔU (%)  = 2.09 / 230 × 100     = 0.91%   (limit 5% → OK)
```

> **What the user learns:** Complete switchboard design in one view — every breaker, every cable, every PE conductor. The motor circuit's 90% utilisation is an amber flag; the user knows to consider a larger cable on that run.

---

### 18.4 Phase Balance Page

**Engine pipeline result (LPT + iterative re-split, sourced from Electrical Design page):**

The Phase Balance page reads phase totals from the Electrical Design panel schedule (same numbers, guaranteed identical). After the three-stage pipeline:

| Phase | 1Φ VA (from ED circuits) | 3Φ share | Total VA | Current (A) |
|---|---|---|---|---|
| A | ~1 082 VA (admin office) | 2 500 VA | ~3 582 VA | ~15.6 A |
| B | ~712 VA (classroom LEDs + fans) | 2 500 VA | ~3 212 VA | ~14.0 A |
| C | ~1 600 VA (classroom sockets) | 2 500 VA | ~4 100 VA | ~17.8 A |

```
Imbalance % ≈ (4 100 − 3 212) / 3 631 × 100 ≈ 24.5%   → CRITICAL badge (red)
```

If `balanceDrivenReSplit` identifies a large enough circuit on Phase C (e.g., the classroom socket circuit, 800 VA), it will try splitting it. If the split improves imbalance, the sub-circuits land on different phases, and Classroom 101 appears as a **SPLIT** room on the Phase Balance page with:
```
Section 1 → Phase C (400 VA sockets)
Section 2 → Phase B (400 VA sockets)
```

**Apply Optimal** then writes both section phases independently to the database.

**After optimal assignment:**
- Best achievable with this load set ≈ 5–10% imbalance → **Balanced** or **Warning** badge

**Neutral current (phasor method, PF ≈ 0.87 average):**
The complex phasor sum correctly accounts for load angle; for this near-balanced result I_N < 2 A.

> **What the user learns:** The Phase Balance page faithfully reflects the Electrical Design panel's phase distribution. SPLIT rooms indicate the engine has already cross-phase distributed sub-circuits. "Apply Optimal" commits these assignments to the database.

---

### 18.5 Load Schedule Page (August, peak summer day)

**Daily energy calculation for all loads (August working day, 08:00–17:00 school day):**

| Load | W (= VA × PF) | Active hours | kWh/day |
|---|---|---|---|
| Admin LEDs ×2 | 72 W | 9 | 0.65 |
| Admin Sockets ×4 | 720 W | 9 | 6.48 |
| Admin Fan ×1 | 59.5 W | 9 | 0.54 |
| C101 LEDs ×6 | 216 W | 9 | 1.94 |
| C101 Sockets ×4 | 720 W | 9 | 6.48 |
| C101 Fans ×2 | 119 W | 9 | 1.07 |
| C101 AC ×1 (summer) | 2 550 W | 7 (09–16) | 17.85 |
| C201 LEDs ×6 | 216 W | 9 | 1.94 |
| C201 Sockets ×4 | 720 W | 9 | 6.48 |
| C201 Fans ×2 | 119 W | 9 | 1.07 |
| C201 AC ×1 (summer) | 2 550 W | 7 (09–16) | 17.85 |
| Server LEDs ×2 | 72 W | 24 | 1.73 |
| Server UPS ×1 | 1 350 W | 24 | 32.40 |
| **Total** | | | **96.5 kWh/day** |

**Peak coincident demand** (09:00–16:00 when ACs are also running):
```
P_peak = 72 + 720 + 59.5 + 216 + 720 + 119 + 2 550 + 216 + 720 + 119 + 2 550 + 72 + 1 350
       = 9 484 W ≈ 9.5 kW
```

**Solar declination for August 15 (DOY = 227):**
```
δ = 23.45 × sin(360/365 × (227 − 81))
  = 23.45 × sin(144.0°)
  = 23.45 × 0.588
  = 13.8°
```

**Solar capacity (1 solar system configured, area = 50 m²):**
```
P_solar = 50 × 0.17 × 1000 × 0.75 = 6 375 W ≈ 6.4 kW
```
> ⚠ **Heuristic estimate — system-computed value, not engineering-grade:** This is what `SolarIrradianceService::estimateCapacityW` computes and what the UI displays. As Section 9 documents, `CAPACITY_ESTIMATE_PR = 0.75` covers cable/inverter/mismatch losses only; module conversion efficiency (η ≈ 0.19 for crystalline silicon) is **not** applied separately, so the formula overestimates actual AC output by ~1/η ≈ **5.3×**. Engineering-accurate form: `P_ac = 50 × 0.17 × 1000 × 0.19 × 0.75 ≈ 1 211 W ≈ 1.2 kW` → daily energy ≈ **7.8 kWh** → working-day coverage ≈ **8%**. The 41.6 kWh/day and 43% figures that follow are what the system computes and displays; they are not engineering-grade values.

With PSH ≈ 6.5 h/day in Cairo in August:
```
Daily solar energy = 6.4 × 6.5 = 41.6 kWh/day
```

The Load Schedule combined-dispatch tab shows solar covering the daytime load from roughly 07:00–17:00; server load at night draws entirely from utility.

> **What the user learns:** The two ACs account for 37% of daily energy (35.7/96.5). Solar with 50 m² covers about 43% of daily demand per the system's heuristic estimate (see caveat above; engineering-accurate figure ≈ 8%). The server is the dominant overnight load.

---

### 18.6 Financial Page

Monthly energy (22 working days + 8 non-working server-only days):
```
Monthly kWh = 62.4 kWh/workday × 22 + 34.1 kWh/non-workday × 8
            ≈ 1 373 + 273 = 1 646 kWh/month     (in August)
```
(62.4 = 96.5 − 34.1 server portion; 34.1 = server loads × 24 h)

Solar generated (August):
```
Monthly solar = 41.6 kWh/day × 30 = 1 248 kWh
```

Solar covers: 1 248 / 1 646 = **75.8%** of August demand.

> ⚠ **All figures below inherit the heuristic 6.4 kW capacity.** With the engineering-accurate 1.2 kW (Section 9): monthly solar ≈ 234 kWh, coverage ≈ 14%, monthly saving ≈ $11.70, capital ≈ $960, 25-year net saving ≈ $2 550. Note that payback period (~7 yr) and LCOE (~$0.014/kWh) are incidentally stable because both capital cost and generation output scale by the same η factor (0.19). The section demonstrates the Financial page workflow; treat all absolute figures as system-heuristic outputs.

Remaining from utility: 1 646 − 1 248 = 398 kWh.
At tariff $0.05/kWh: utility cost = $19.90/month.
Without solar: 1 646 × $0.05 = $82.30/month.
Monthly saving: **$62.40**.

**LCOE and payback (capital = 6.4 kW × $800/kW = $5 120):**
```
Annual solar kWh used ≈ 1 248 × 12 = 14 976 kWh
LCOE = $5 120 / (14 976 kWh × 25 years) = $0.0136/kWh
Annual saving ≈ $62.40 × 12 = $748.80
Payback year = $5 120 / $748.80 ≈ 6.8 → Year 7
25-year total saving = $748.80 × 25 − $5 120 = $13 600
```

> **What the user learns:** The Financial page shows a 6.4 kW system (heuristic estimate) paying back in ~7 years and saving ~$13 600 over 25 years. LCOE of $0.014/kWh is far below the $0.05/kWh tariff. With the engineering-accurate 1.2 kW capacity, absolute savings drop to ~$2 550 over 25 years — the financial output is only as reliable as the solar capacity input.

---

### 18.7 Single-Line Diagram

For this project (1 utility line + 1 solar system + no battery + no generator), the SVG shows:
- **Source row (y = 100):** Grid circle (blue) · Solar circle (yellow)
- **Breaker symbols** on both source connections, labeled with IEC-rated amperes
- **Bus bar (y = 310):** horizontal dark-blue line labeled "230 / 400 V · 3-phase"
- **Building row (y = 500):** "Al-Noor School" rectangle labeled "6.8 kVA"
- **Legend** (conditional): shows only Grid and Solar, since no battery or generator is configured.

**Variant — if an LFP battery with `solar_system_id` pointing to the solar system were added:**  
The solar circle and battery circle are replaced by a single `HybridGroup` rectangle showing:  
- Left: ☀️ Solar 6.4 kW  
- DC BUS divider  
- Right: 🔋 4.4 kWh usable / 5.1 kWh nominal  
- Footer: "Hybrid Inverter"

> **What the user learns:** Topology confirmed — sources feed one common bus supplying one building. The hybrid-inverter node immediately communicates DC coupling to the reviewer without extra annotation.

---

## Section 19 — Glossary

**Apparent power (VA, kVA):** The product of voltage and current without regard to phase angle. For a single-phase 230 V circuit drawing 10 A, apparent power = 2 300 VA = 2.3 kVA. Electrical equipment is rated in VA because conductors and transformers must carry the full current regardless of power factor.

**Real (active) power (W, kW):** The power actually converted to useful work (heat, light, mechanical). `P (W) = VA × power factor`. A 2 300 VA load at PF = 0.87 delivers 2 001 W of real power.

**Reactive power (VAR, kVAR):** Power stored and returned by inductors and capacitors each cycle. Does no useful work but flows through conductors and causes losses. `Q (VAR) = √(VA² − W²)`.

**Power factor (PF):** Ratio of real to apparent power: `PF = W / VA = cos φ`. PF = 1.0 for purely resistive loads (heaters, incandescent lamps). PF ≈ 0.85 for induction motors and ACs. PF ≈ 0.90 for switch-mode power supplies. Low PF means higher current for the same real power, requiring larger conductors.

**Diversity factor (DF) / Coincidence factor:** The ratio of maximum coincident demand to the sum of individual maximum demands. A DF of 0.70 means the actual peak is 70% of the nameplate total because not all loads are on simultaneously. IEC 60364-8-1 defines diversity factors for different occupancy types. The inverse of coincidence factor is sometimes called demand factor.

**Coincident demand (nameplate total):** The hypothetical load if every component ran at its full rated VA at the same instant. This is the worst case; used for breaker sizing at each level.

**Diversified demand:** Nameplate demand scaled by diversity factors. This is what the supply transformer and incomer must actually handle.

**Ampacity:** The maximum continuous current a conductor can carry without exceeding its rated temperature. IEC 60364-5-52 Table B.52.2 tabulates base ampacities for PVC/Cu cables by cross-section and installation method. Method A1 (in conduit in insulated wall) gives the most conservative values.

**Derating:** Reducing rated ampacity to account for elevated ambient temperature or cable grouping. At 40 °C, a 0.87 derating factor is applied to the IEC Table B.52.2 base values (which are for 30 °C). Example: 2.5 mm² base ampacity 17 A × 0.87 = 14.8 A derated.

**RCD (Residual-Current Device) / RCCB:** A protective device that trips when the difference between live-conductor current and neutral-conductor current exceeds a threshold — indicating current leaking to earth through a fault or a person. A 30 mA RCD trips in ≤ 40 ms, which is below the let-through current needed to cause cardiac fibrillation. Required on socket circuits (IEC 60364) and on critical-priority circuits in this system.

**MCB (Miniature Circuit Breaker):** A resettable protective device rated up to 125 A (IEC 60898) that breaks the circuit on overload (thermal trip) or short circuit (magnetic trip). Ratings in this system follow the standard IEC series: 6/10/16/20/25/32/40/50/63/80/100 A.

**MCCB (Moulded Case Circuit Breaker):** Larger version of the MCB, typically 100–3 000 A, used as building incomers or large motor feeders (IEC 60947-2).

**Trip curve B:** MCB magnetic trip at 3–5× In. For resistive loads and lighting circuits where there is no startup inrush.

**Trip curve C:** Magnetic trip at 5–10× In. For loads with moderate inrush — motors, fluorescent luminaires, small transformers.

**Trip curve D:** Magnetic trip at 10–20× In. For high-inrush loads such as large transformer primaries, solenoids, and welding equipment.

**DB (Distribution Board):** The sub-panel on each floor that receives the floor feeder from the MDB and distributes power to final circuits in rooms on that floor. In this system, each floor has one DB.

**MDB (Main Distribution Board):** The primary panel in each building. Receives the utility supply (or generator/ATS output) and feeds floor DBs and any building-level circuits.

**PE (Protective Earth conductor):** The green/yellow conductor connecting exposed conductive parts of equipment to the earthing system. Sized per IEC 60364-5-54: for cables ≤ 16 mm² the PE equals the phase conductor; for 16–35 mm² the PE is fixed at 16 mm²; for > 35 mm² the PE is half the phase conductor area.

**PSH (Peak Sun Hours):** The number of hours per day during which solar irradiance equals 1 000 W/m² (STC — Standard Test Conditions). A location with PSH = 6 will receive the same daily energy as 6 hours of full-sun irradiance. PSH is used to estimate daily solar output: `E_day = P_stc × PSH`.

**GHI (Global Horizontal Irradiance):** Total solar energy (direct + diffuse) incident on a horizontal surface per unit area per day, measured in Wh/m² or kWh/m²/day. This system retrieves GHI from the NASA POWER API for the project's latitude/longitude.

**SOC (State of Charge):** Battery energy level expressed as a percentage of rated capacity (0% = empty, 100% = full). The dispatch logic prevents charging above `soc_max` (typically 95%) and discharging below `soc_min` (typically 10–20%) to protect battery longevity.

**RTE (Round-Trip Efficiency):** The fraction of energy returned by a battery compared to the energy put in during charging. A battery with RTE = 0.90 returns 90 Wh for every 100 Wh charged. In the system, `one_way_efficiency = √(RTE)`, applied once on charge and once on discharge so the combined effect equals RTE.

**LCOE (Levelized Cost of Electricity):** Total lifetime cost of a generation asset divided by total lifetime energy produced. `LCOE = Capital_cost / (Annual_kWh × Lifetime_years)`. This system uses a simple undiscounted form over 25 years; it does not apply NPV or IRR.

**Utilisation percentage (Util %):** The ratio of design current to breaker rated current: `Util% = (Ib / In) × 100`. Values below 80% are green (adequate headroom), 80–95% amber (acceptable but limited), above 95% red (overcrowded — consider the next cable/breaker size up).

**Phase imbalance %:** `(VA_max_phase − VA_min_phase) / VA_avg_phase × 100`. Measures how unevenly single-phase loads are distributed across the three phases. IEC guidelines suggest < 10% as balanced; 10���20% is a warning; > 20% is unacceptable because it causes thermal stress in transformers, excess neutral current, and voltage asymmetry. The system uses: Balanced < 10%, Warning 10–20%, Critical > 20%.

**Neutral current:** In a balanced three-phase system the neutral carries zero current because the three phasor currents cancel. As loads become unbalanced, residual current flows in the neutral. The phasor formula: `I_N = |I_A + I_B + I_C|` (complex sum). Excessive neutral current overheats the neutral conductor (which is not protected by a breaker in most installations) and increases transformer losses.

**DoD (Depth of Discharge):** The maximum fraction of a battery's nominal capacity that can be withdrawn in normal operation without shortening its service life. A 100 Ah battery with DoD = 0.85 has 85 Ah (= 85%) available for use; the remaining 15% is reserved as a buffer. Values in this system: flooded lead-acid 50%, AGM 70%, gel 80%, LFP 85%, NMC 80%.

**LFP (LiFePO4 — Lithium Iron Phosphate):** A lithium-ion battery chemistry known for long cycle life (4000 cycles), thermal stability, and moderate energy density. Preferred for stationary storage. DoD 85%, RTE 92%, C-rate 0.5C charge / 1C discharge in this system.

**NMC (Nickel Manganese Cobalt):** A lithium-ion chemistry with higher energy density than LFP but shorter cycle life (2500 cycles) and slightly higher RTE (93%). More common in electric vehicles than stationary storage.

**C-rate:** Charge or discharge rate expressed as a multiple of battery capacity. A 1C rate fully charges or discharges the battery in 1 hour; 0.5C takes 2 hours; 0.1C takes 10 hours. `max_power_kW = nominal_kwh × C_rate`.

**Hybrid inverter:** A bidirectional power conversion device that manages both the DC-coupled battery bank and the solar PV array through a common DC bus. Unlike a standard grid-tied inverter (solar only) or a battery inverter (storage only), a hybrid inverter simultaneously handles solar charging, battery charge/discharge, and AC grid interface. In this system, a battery with `solar_system_id ≠ null` is DC-coupled to that solar system through the solar system's hybrid inverter — shown as a single `HybridGroup` node on the Single-Line Diagram.

**LPT (Longest Processing Time):** A classic bin-packing heuristic. Items are sorted by size (largest first) and greedily assigned to the least-full bin. Applied here at the fixture level when splitting an oversized circuit: individual luminaires or socket outlets are the items, and the target sub-circuits are the bins. LPT gives a near-optimal split with guaranteed maximum-bin imbalance ≤ 4/3 − 1/3n of optimal.

**Night-reserve target SOC:** A battery state-of-charge floor computed from the day-ahead load profile. The dispatch engine calculates how much energy the battery must hold at sunset to cover all post-sunset load hours, adds a 10% reserve margin, then uses this as the daytime discharge floor. Above this floor the battery can discharge freely to shave peak demand; below it the energy is reserved for the night.

**SFC (Specific Fuel Consumption):** The mass of fuel consumed per unit of electrical energy output, typically in g/kWh. For a diesel generator on the affine model, SFC rises as load drops below the optimum band (60–85%), making part-load operation inefficient. This justifies the `GEN_MIN_EFFICIENT_LOAD = 0.60` threshold in the dispatch engine.

**Curtailable load:** A load whose output can be reduced below its rated power to a configurable floor (`minimum_output_pct`) during periods of supply shortage, without completely switching it off. Example: a 2 kW HVAC unit with `minimum_output_pct = 50%` can be curtailed to 1 kW instead of fully shed. In the shedding service this is Step 2, before any complete load removal.

**Shiftable load:** A load with a flexible scheduling window (`[earliest_start_hour, latest_end_hour)`) and a daily runtime requirement (`required_run_hours`). The optimizer picks the cheapest consecutive block within the window; the shedding service can move it to a different hour within the same window when a deficit is detected at its currently assigned hour. Neither action changes the rated power or required daily runtime.

**shiftCapW:** The supply-capacity array used exclusively for shift-target selection in `LoadSheddingService::findShiftTarget()`. Contains only `solar + utilCapW` (no battery, no generator). Using the full `supplyCapW` would make dark overnight hours appear as valid shift targets because generator+battery capacity is available then — which would drain the battery and force additional generator runtime. Defined in `ScheduleController.php` lines 161–163.

**Hysteresis (load restoration):** A deliberate one-hour delay in restoring shed loads after supply recovers. A load shed at hour H is not restored until hour H+1 or later, and only if hour H had supply ≥ demand × (1 + RESTORE_MARGIN). This prevents rapid oscillation ("flicker") where a load is shed and immediately restored if supply is marginal. Controlled by `RESTORE_MARGIN = 0.05` in `LoadSheddingService.php`.

**critical_unmet_kwh:** A distinct output field from `LoadSheddingService` that reports energy that could not be served even after completely removing all Normal and Essential loads. Critical loads are never automatically shed, so any remaining deficit after full non-critical shedding becomes `critical_unmet_kwh`. This is separate from the dispatch engine's `unmet_kwh`, which represents supply gaps after dispatch optimization. A non-zero `critical_unmet_kwh` triggers a red alert banner on the Load Schedule page and indicates the system needs more generation or storage capacity.

**Round-trip-waste guard:** A condition in `SourceDispatchService` (Step 7) that prevents the generator from charging the battery in the same hour that the battery is also discharging to load (`$dischargeW === 0.0` check, `SourceDispatchService.php` line 464). Without this guard the battery would charge and discharge simultaneously, wasting 15–20% of the energy in the charge/discharge round-trip. The guard ensures energy flows directionally: generator → battery OR battery → load, never both in the same hour.

**target-SOC:** The battery state-of-charge floor computed from the day-ahead load profile. Documented in Section 8 under "Night-Reserve Protection." See also *night-reserve target SOC* entry above (same concept, two names used in different parts of the codebase).

---

## Section 20 — Validation & Corrections Applied

This section is a chronological record of bugs confirmed in code and corrections applied. Each item includes the affected component, the fault found, and what was changed.

### Round 1 Corrections (v1.0 → v1.1)

| # | Area | Fault | Fix applied |
|---|------|-------|-------------|
| 1 | Phase Balance | FFD greedy assignment only — no splitting, could not reach target imbalance | Added LPT fixture-level split (Stage 1) + iterative balance-driven re-split loop (Stage 2) to `ElectricalDesignService` |
| 2 | Phase Balance page vs ED page | Phase Balance computed its own phase totals independently of Electrical Design — totals could diverge | `PhaseBalanceController::buildingReport()` now reads `phase_balance_va` directly from ED output; one source of truth |
| 3 | SPLIT room display | Phase Balance forced every room to a single phase even when two circuits of the same room landed on different phases | Added `is_split` + `split_sections` room model; Phase Balance page renders SPLIT badge |
| 4 | Dispatch engine — target-SOC | Engine discharged battery freely during the day without protecting overnight reserve, forcing generator at night | Added pre-dispatch look-ahead that computes `battTargetKwh` (night energy req + RESERVE_MARGIN) and enforces a dynamic floor during Step 4 battery discharge |
| 5 | Dispatch Step 7 — afternoon ramp gate | Generator pre-charged the battery during afternoon discharge hours, cancelling the peak-shave benefit | Added `inAfternoonRamp` guard: Step 7 suppressed when past solar peak, still daylight, load > solar, and battery has above-floor buffer |
| 6 | Dispatch Step 7 — morning ramp gate | Generator needlessly started before solar peak to pre-charge, when rising solar would do it naturally | Added `inMorningRamp` guard: Step 7 suppressed between sunrise and solar peak when battery has above-floor energy |
| 7 | Battery chemistry display | Create/update allowed old chemistry-derived values (DoD/RTE/C-rates) to persist through an edit because of an `!array_key_exists` guard | Removed guard in `BatteryController.php:92–98`; chemistry preset now always overwrites the five fields on every chemistry change |
| 8 | Battery test (Test 23) | `test_every_chemistry_preset_has_dod_below_one` was failing for an old test dataset that assumed an incorrect chemistry key format | Updated test to use live `BatteryChemistryService::all()` presets; all DoD values confirmed in (0, 1) |
| 9 | SLD topology | Separate battery and solar circles were shown even when the battery was DC-coupled to the solar system (solar_system_id ≠ null) | Added `HybridGroup` node: when a battery has `solar_system_id ≠ null`, the paired solar and battery are rendered as one `HybridGroup` rectangle |

### Round 2 Corrections (v1.1 → v1.2)

| # | Area | Fault | Fix applied |
|---|------|-------|-------------|
| 10 | LoadSheddingService — shift target selection | `findShiftTarget()` used `supplyCapW` (includes generator + battery) for shift-target scoring, making dark overnight hours attractive targets; shiftable loads moved to night hours draining battery and forcing generator | Added `shiftCapW = solar + utilCapW` (no battery, no generator); this array is passed to `shed()` and used exclusively in `findShiftTarget()`. `ScheduleController.php` lines 161–163, `LoadSheddingService.php` line 104 |
| 11 | Load Schedule chart — demand line | Chart used original pre-shed `load_optimized[h]` / `load_max[h]` as the demand line even after shedding shifted loads, causing the stacked supply areas and the demand line to diverge | API now returns `load_shed_optimized` and `load_shed_max`; `LoadSchedulePage.jsx` uses these as `demand` in `chartData` with fallback. Lines 579–582 |
| 12 | Battery age computation (unit bug) | Age was computed in integer days in an earlier version — a 36-day battery would show age = 36 yr instead of 0.10 yr | `Battery::getAgeYearsAttribute()` divides by 365.25 explicitly: `round($days / 365.25, 2)`. `Battery.php` line 72 |
| 13 | Optimizer improvement check (A.6) | `pickBestIntervals()` compared new interval cost against the best run-hours-wide sub-block within the current interval; skipped update even when the current interval was much wider than required (e.g. 10h wide for a 4h load) | Added `$currentScheduledHours` tracking: skip-update only when width already equals `required_run_hours` AND no better cost found. `ProjectController.php` lines ~273–295 |
| 14 | Generator daily cost display | Dashboard displayed generator cost using a flat per-kWh rate instead of the affine F(P) model — diverged from `FinancialAnalysisService` | Dashboard cost calculation updated to use the same affine formula as `FinancialAnalysisService`; both now agree |

---

*Document updated 2026-07-06 (v1.2). All formulas and constants verified against file:line citations listed. Items marked ⚠ require independent verification before licensed engineering submission.*
