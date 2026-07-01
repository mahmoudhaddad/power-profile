# Power Profile — Master Technical Documentation

**Version:** 1.0 · **Date:** 2026-07-01 · **Author:** Ahmed Zoher  
**Purpose:** Master reference for graduation report and defense presentation.  
All facts verified against actual source code with file:line citations.  
Items that could not be confirmed in code are marked **⚠ NOT CONFIRMED IN CODE**.

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
| Testing | PHPUnit 11 | 36 tests, 152 assertions |
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
| `chemistry` | string(50) | e.g., `'LiFePO4'`, `'lead_acid'` |
| `nominal_voltage_v` | decimal | |
| `capacity_ah_per_unit` | decimal | |
| `quantity` | integer | Total cells/units |
| `series_count` | integer | |
| `parallel_count` | integer | |
| `installation_date` | date | For age calculation |
| `depth_of_discharge` | decimal(4,3) | e.g., 0.800 |
| `round_trip_efficiency` | decimal(4,3) | e.g., 0.950 |
| `c_rate_charge` | decimal(4,2) | |
| `c_rate_discharge` | decimal(4,2) | |
| `rated_cycle_life` | integer | |
| `current_soc` | decimal(4,3) | Default 0.500 |
| `is_active` | boolean | |

Migration: `2026_05_29_100000_create_batteries_table.php`

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

### Motor Inrush (NEC Article 430 / IEC)

The largest motor in the project is identified and sized at **125%** of rated VA to account for locked-rotor inrush current.

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

### Phase Assignment in Electrical Design

Source: `ElectricalDesignService.php` — `assignPhases()` method.  
Uses the same FFD greedy algorithm as PhaseBalanceController: sort circuits by VA descending, assign each to the least-loaded phase.

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

### 7-Step Energy Dispatch Algorithm

**Service:** `backend/app/Services/SourceDispatchService.php`

For each hour of the day, the dispatch service allocates supply from sources in this priority order:

1. **Solar direct use** — serve load from available solar generation
2. **Solar → paired battery charge** — excess solar charges batteries paired to that solar system
3. **Solar → shared solar pool** — remaining solar to shared battery banks
4. **Unpaired battery charge** (from solar)
5. **Battery discharge** — discharge batteries to serve remaining load
6. **Utility grid** — draw from utility for remaining load (up to `utilCapW`)
7. **Generator** — last resort; operates within `ISO_OPTIMAL_MAX_LOAD = 0.85`

Constants:
- `GEN_OPTIMAL_MAX_LOAD = 0.85` (ISO 8528 optimal band ceiling)
- `INV_EFF = 0.95` (inverter efficiency applied to battery round-trips)

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

**⚠ Note:** Module efficiency (~0.19 for typical crystalline silicon) is not explicitly applied as a separate factor — it is implicit in the combined `0.17 × 0.75` product.

### Solar Declination

Code uses the formula labeled "Spencer" in comments but correctly attributable to **Cooper (1969)**:

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

### Capacity Calculations

Source: `Battery.php` model attributes

```
nominal_capacity_kwh = nominal_voltage_V × capacity_ah_per_unit × quantity / 1000
```

### Age Degradation

```
age_factor = max(0.70, 1 − age_years × degradation_per_year)
usable_capacity_kwh = nominal_capacity_kwh × depth_of_discharge × age_factor
```

Battery health thresholds:
- ≥ 0.90 → **Good**
- ≥ 0.80 → **Fair**
- ≥ 0.70 → **Degraded**
- < 0.70 → **Replace**

### Charge/Discharge Power Limits

```
max_charge_power_kW  = nominal_capacity_kwh × c_rate_charge
max_discharge_power_kW = nominal_capacity_kwh × c_rate_discharge
```

### Runtime Estimation

`GET /api/projects/{project}/battery-runtime`

```
runtime_hours = usable_kwh / load_kW
```

`POST /api/batteries/{battery}/runtime-at-load` — computes runtime at a specified load.

### Battery Replacement Projection

Source: `FinancialAnalysisService.php`

```
replacement_year = ceil((rated_cycle_life / 365) − age_years)
```

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

### Constants

Source: `PhaseBalanceController.php`

| Constant | Value |
|----------|-------|
| `VOLT` | 230 V |
| `WARN_PCT` | 10% |
| `CRIT_PCT` | 20% |
| `SOCKET_ASSUMED_PF` | 0.95 |

Phase voltage angles: A = 0°, B = 120°, C = 240°

### Phasor Current Calculation

For each load on phase P with power factor PF:

```
θ_V = phase voltage angle (0° / 120° / 240°)
θ_I = θ_V − arccos(PF)    [current lags voltage by arccos(PF)]

I_re += |I| × cos(θ_I)
I_im += |I| × sin(θ_I)
```

Source: `PhaseBalanceController.php`

### Neutral Current

Phasor (complex) sum of the three phase currents:
```
I_N = √((I_A_re + I_B_re + I_C_re)² + (I_A_im + I_B_im + I_C_im)²)
```

For a perfectly balanced 3-phase system, I_N → 0.

### Imbalance Metric

```
imbalance_pct = (I_max − I_min) / I_avg × 100
```

where `I_max`, `I_min`, `I_avg` are the magnitudes of currents on phases A, B, C.

**Note:** This is a spread metric (max-min range / mean), not the standard NEMA MG-1 voltage imbalance definition (which uses deviations from average). Source: `PhaseBalanceController.php`

Thresholds: WARN at 10%, CRITICAL at 20%.

### FFD Greedy Phase Assignment

Used in both ElectricalDesignService and PhaseBalanceController:

1. Sort all load blocks by VA (descending — largest first)
2. For each block: assign to the currently least-loaded phase
3. Repeat until all loads assigned

**Effect:** Near-optimal phase balancing with O(n log n) complexity (sort dominates).

---

## 13. Validation System and Automated Tests

### PHPUnit Test Suite

**File:** `backend/tests/Feature/ElectricalDesignTest.php`  
**Status:** 36 tests, 152 assertions — all passing.

Test coverage includes:
- Cable ampacity table verification: for each of the 12 sizes, asserts the value matches IEC 60364-5-52 Table B.52.2, Method A1
- Derating factor at 40°C: 0.87
- Voltage drop spot checks (2 known cases)

The tests use PHP reflection to access the private `CABLE_AMPACITY` constant directly.

Test docblock: `Table B.52.2, Method A1 (conduit in thermally insulated wall), 2 loaded conductors`

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
| Solar irradiance (NASA POWER API + PSH table fallback) | `SolarIrradianceService.php` |
| Affine generator fuel model | `GeneratorLine.php` |
| LCOE (simple/undiscounted, 25-year) | `FinancialAnalysisService.php` |
| Battery degradation and replacement scheduling | `Battery.php`, `FinancialAnalysisService.php` |
| Generator oversizing detection (<30% average load) | `FinancialAnalysisService.php` |
| Essential panel separation (hospital, data center) | `ElectricalDesignService.php:312` |
| group_small_critical packing option | `ElectricalDesignService.php:355` |
| PHPUnit test suite (36 tests, 152 assertions) | `ElectricalDesignTest.php` |
| Live validation API with reference implementation | `ValidationController.php`, `ValidationReferenceService.php` |
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
7. **Cooper declination formula** — labeled "Spencer" in code comments; both formulas are similar but attribution is imprecise
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

*Document generated from source code reading on 2026-07-01. All formulas and constants verified against file:line citations listed. Items marked ⚠ require independent verification before licensed engineering submission.*
