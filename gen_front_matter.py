"""
Generates sections 1-11 of POWER_PROFILE_COMPLETE_DOCUMENTATION.md
and prepends them to the existing file (which has sections 12-25).
"""
import os

front_matter = r"""# POWER PROFILE — COMPLETE PROJECT DOCUMENTATION

**Generated:** June 7, 2026
**Codebase inventory:** 80 PHP files · 44 JS/JSX files · 55 migrations · 9 services · 28 controllers · 17 models · 21 pages · 15 components

---

## 1. PROJECT OVERVIEW

### What It Is

Power Profile is a full-stack web application for **electrical load analysis** of buildings and facilities.
An engineer logs in, creates a project, enters every electrical load room-by-room (computers, motors, AC units, lighting), and the system automatically computes:

- Total apparent power (kVA), active power (kW), and reactive power (kVAR) with IEC diversity factors
- Whether power factor correction is needed and exactly what capacitor bank to install
- How solar panels, batteries, a utility grid connection, and a diesel generator interact hour-by-hour
- Which hours of the day are cheapest to run shiftable loads (water heaters, HVAC, EV chargers)
- A 25-year financial projection showing payback period and net benefit
- Phase imbalance and the optimal assignment of single-phase loads across three phases

### Context

- **Graduation project** by Ahmed Zoher, June 2026
- Designed for use in the Palestinian territories (Gaza / West Bank) under PENRA regulations
- Has startup potential as a SaaS tool for electrical engineering consultancies

### Technology Stack (exact versions)

**Backend:**
- PHP ^8.3
- Laravel ^13.0 (framework)
- Laravel Sanctum ^4.3 (API token authentication)
- Laravel Socialite ^5.26 (Google OAuth 2.0)
- Laravel Tinker ^3.0 (REPL)
- SQLite (file-based database — no separate DB server required)

**Frontend:**
- React ^18.2.0
- React DOM ^18.2.0
- React Router DOM ^6.30.3 (client-side routing)
- Recharts ^3.8.1 (charts — hourly load profile, 25-year projection)
- Axios ^1.13.6 (HTTP client)
- xlsx ^0.18.5 / SheetJS (Excel export)

**Build / Tooling:**
- Vite ^4.4.5 (bundler)
- Tailwind CSS ^3.4.19 (utility-first CSS)
- ESLint ^8.45.0 (linting)
- PostCSS ^8.5.8 / Autoprefixer ^10.4.27

### The Four Computation Engines

```
User enters loads
        ↓
┌───────────────────┐
│  1. POWER ENGINE  │  TotalPowerController
│  Diversity + PF   │  DiversityFactorService
│  Motor inrush     │  SocketDemandService
└────────┬──────────┘
         ↓
┌───────────────────┐
│ 2. DISPATCH ENGINE│  SourceDispatchService
│  Solar/Batt/Grid  │  SolarIrradianceService
│  Hour-by-hour     │  CostSignalService
└────────┬──────────┘
         ↓
┌───────────────────┐
│ 3. OPTIMIZE ENGINE│  ProjectController::optimizeShiftable()
│  Shift loads to   │  CostSignalService (signal builder)
│  cheapest hours   │  ScheduleController (display)
└────────┬──────────┘
         ↓
┌───────────────────┐
│ 4. FINANCIAL ENG. │  FinancialAnalysisService
│  Savings, LCOE,   │  FinancialController
│  25-yr projection │
└───────────────────┘
```

### Electrical Standards Implemented

| Standard | Where Applied |
|---|---|
| IEC 60364-8-1 | Diversity factors for buildings/rooms; default PF correction threshold 0.85 |
| PENRA (Palestinian Energy Authority) | Min PF = 0.85, target PF = 0.95 |
| NEC Article 430 | Motor inrush 125% rule for largest motor |
| IEC 60831 | Capacitor bank sizing; delta (Δ) configuration |
| ISO 8528 | Affine diesel generator fuel consumption model F(P) = F₀ + slope×P |
| CIBSE Guide C | Per-room coincidence factors; building-type diversity factors |
| NASA POWER | Satellite GHI data for solar irradiance (free, global, ±3% accuracy) |

---

## 2. COMPLETE FILE INVENTORY

### Services (`backend/app/Services/`)

| File | Purpose |
|---|---|
| `DiversityFactorService.php` | IEC 60364-8-1 diversity factor lookup tables (per building type, per room type) |
| `SocketDemandService.php` | 3-tier demand factor for outlet sockets; building coincidence factor |
| `SolarIrradianceService.php` | Hourly solar output via NASA POWER API primary + sinusoidal PSH-table fallback |
| `BatteryChemistryService.php` | Preset parameter tables for 5 battery chemistries (LFP, NMC, lead-acid variants) |
| `SourceDispatchService.php` | 7-step priority dispatch (solar → batteries → grid → generator) for all 24 hours |
| `CostSignalService.php` | 24-element marginal cost signal ($/kWh per hour) from tariff + battery + solar surplus |
| `FinancialAnalysisService.php` | Dual-dispatch baseline + solar comparison, annual costs, savings, LCOE, 25-yr projection |
| `BackupExporter.php` | JSON export of complete project data for portable backup/restore |
| `ValidationReferenceService.php` | Static reference data (standards tables, NEC/IEC limits) used by the validation page |

### Controllers (`backend/app/Http/Controllers/Api/`)

| File | Purpose |
|---|---|
| `AdminController.php` | Admin dashboard data: user list, project list, system stats |
| `BatteryController.php` | CRUD for battery banks; chemistry preset loader |
| `BuildingComponentController.php` | CRUD for components attached at building level |
| `BuildingController.php` | CRUD for buildings within a project |
| `ComponentTypeController.php` | CRUD for reusable component type catalog |
| `CostSignalController.php` | Computes and returns 24-hour cost signal for a project |
| `FinancialController.php` | Runs FinancialAnalysisService and returns structured JSON |
| `FloorComponentController.php` | CRUD for components at floor level |
| `FloorController.php` | CRUD for floors within a building |
| `GeneratorLineController.php` | CRUD for generator power sources |
| `LoadProfileController.php` | Returns hourly load profile (24-element W array) with diversity |
| `NavigationController.php` | Breadcrumb path data for a given entity |
| `PhaseBalanceController.php` | Phase balance analysis + greedy optimal assignment at building/floor |
| `ProjectBackupController.php` | Client-side JSON project export and import |
| `ProjectComponentController.php` | CRUD for components at project level |
| `ProjectController.php` | Project CRUD; shiftable-load optimizer; defense summary |
| `ProjectMemberController.php` | Add/remove/update roles for project collaborators |
| `RoomComponentController.php` | CRUD for components at room level |
| `RoomController.php` | CRUD for rooms within a floor |
| `ScheduleController.php` | 24-hour dispatch result with source breakdown |
| `ServerBackupController.php` | Server-side backup create/download/delete |
| `SocketController.php` | CRUD for outlet socket records (polymorphic) |
| `SolarSystemController.php` | CRUD for named solar PV systems |
| `TotalPowerController.php` | Full power analysis: diversity, PF correction, inrush, battery summary |
| `UserController.php` | Current user profile; update name |
| `UtilityLineController.php` | CRUD for utility grid connections with tariff data |
| `ValidationController.php` | Returns IEC/NEC reference standards for the validation page |
| `GoogleController.php` | Google OAuth 2.0 redirect and callback |

### Auth & Middleware

| File | Purpose |
|---|---|
| `Controller.php` | Base controller (empty — Laravel default) |
| `AdminMiddleware.php` | Restricts routes to users with is_admin = true |
| `SecurityHeaders.php` | Adds HTTP security headers to every response |
| `Auth/GoogleController.php` | Handles Google OAuth redirect and callback |

### Models (`backend/app/Models/`)

| File | Table | Purpose |
|---|---|---|
| `User.php` | `users` | Authenticated users (Google OAuth + admin) |
| `Project.php` | `projects` | Top-level project container |
| `Building.php` | `buildings` | Building within a project |
| `Floor.php` | `floors` | Floor within a building |
| `Room.php` | `rooms` | Room within a floor |
| `ComponentType.php` | `component_types` | Reusable catalog of electrical component types |
| `ProjectComponent.php` | `project_components` | Component instance at project level |
| `BuildingComponent.php` | `building_components` | Component instance at building level |
| `FloorComponent.php` | `floor_components` | Component instance at floor level |
| `RoomComponent.php` | `room_components` | Component instance at room level |
| `UtilityLine.php` | `utility_lines` | Grid utility connection (polymorphic) |
| `GeneratorLine.php` | `generator_lines` | Diesel generator (polymorphic) |
| `Socket.php` | `sockets` | Outlet socket records (polymorphic) |
| `Battery.php` | `batteries` | Battery bank linked to a project |
| `SolarSystem.php` | `solar_systems` | Named solar PV system linked to a project |
| `ProjectUser.php` | `project_users` | Many-to-many: project ↔ user with role |
| `ServerBackup.php` | `server_backups` | Metadata for server-side backup files |

### Form Requests (`backend/app/Http/Requests/`)

| File | Validates |
|---|---|
| `StoreProjectRequest.php` | Create project |
| `UpdateProjectRequest.php` | Update project |
| `StoreBuildingRequest.php` | Create building |
| `UpdateBuildingRequest.php` | Update building |
| `StoreFloorRequest.php` | Create floor |
| `UpdateFloorRequest.php` | Update floor |
| `StoreRoomRequest.php` | Create room |
| `UpdateRoomRequest.php` | Update room |
| `StoreComponentRequest.php` | Create component (all levels) |
| `UpdateComponentRequest.php` | Update component |
| `StoreUtilityLineRequest.php` | Create utility line |
| `UpdateUtilityLineRequest.php` | Update utility line |
| `StoreGeneratorLineRequest.php` | Create generator line |
| `UpdateGeneratorLineRequest.php` | Update generator line |
| `StoreBatteryRequest.php` | Create battery |
| `UpdateBatteryRequest.php` | Update battery |
| `StoreSocketRequest.php` | Create socket record |
| `UpdateSocketRequest.php` | Update socket record |
| `ApiRequest.php` | Base form request (shared auth) |

### Frontend Pages (`frontend/src/pages/`)

| File | Route | Purpose |
|---|---|---|
| `LoginPage.jsx` | `/login` | Google OAuth login button |
| `AuthCallbackPage.jsx` | `/auth/callback` | Handles token from OAuth callback URL |
| `DashboardPage.jsx` | `/dashboard` | Project list with total kW/kVA per project |
| `CreateProjectPage.jsx` | `/projects/new` | Create new project form |
| `ProjectPage.jsx` | `/projects/:id` | Project overview: PowerBanner + sources |
| `BuildingPage.jsx` | `/projects/:id/buildings/:bid` | Building detail with components and power |
| `BuildingsPage.jsx` | (sub-page) | Building list within project |
| `FloorPage.jsx` | `/projects/:id/buildings/:bid/floors/:fid` | Floor detail |
| `FloorsPage.jsx` | (sub-page) | Floor list within building |
| `NewRoomPage.jsx` | `/projects/:id/buildings/:bid/floors/:fid/rooms/:rid` | Room detail with components |
| `RoomPage.jsx` | (sub-page) | Room list within floor |
| `RoomDetailPage.jsx` | (sub-page) | Alternate room detail view |
| `LoadSchedulePage.jsx` | `/projects/:id/schedule` | Load schedule: base/optimized/combined tabs |
| `PhaseBalancePage.jsx` | `/projects/:id/phase-balance` | Phase balance analysis |
| `FinancialPage.jsx` | `/projects/:id/financial` | Financial analysis: costs, savings, 25yr chart |
| `ValidationPage.jsx` | `/validation` | IEC/NEC reference standards browser |
| `DefensePrepPage.jsx` | `/defense-prep` | Admin-only exam Q&A accordion (42 questions) |
| `SingleLineDiagramPage.jsx` | `/projects/:id/single-line` | Auto-generated SVG single-line diagram |
| `AdminLoginPage.jsx` | `/admin/login` | Admin-only login with email/password |
| `AdminDashboardPage.jsx` | `/admin/dashboard` | Admin panel: users, projects, backups |

### Frontend Components (`frontend/src/components/`)

| File | Purpose |
|---|---|
| `Navbar.jsx` | Top navigation bar with user menu and Defense Prep button (admin only) |
| `PowerBanner.jsx` | MAX LOAD / OPTIMIZED kVA/kW display with PDF and Excel export buttons |
| `PowerSourcesBanner.jsx` | Summary strip showing solar capacity, battery, grid, generator |
| `ProjectSidebar.jsx` | Left nav for all project sub-pages |
| `EntityComponents.jsx` | Reusable component CRUD table used on all entity pages |
| `EntityScheduleModal.jsx` | Modal for editing entity-level schedule (work days, season intervals) |
| `EntitySockets.jsx` | Socket outlet management for any entity |
| `TimeScheduleModal.jsx` | Modal for editing component time intervals (usage_time_intervals) |
| `ReactivePowerPanel.jsx` | Capacitor bank sizing panel shown when PF < 0.85 |
| `BackupChoiceModal.jsx` | Modal: choose between client (JSON) or server backup |
| `ProjectMembersModal.jsx` | Manage project member roles |
| `ServerBackupsList.jsx` | List of server-side backups with download/delete |
| `UserCard.jsx` | User avatar + name display |
| `ErrorBoundary.jsx` | Catches React render errors; shows fallback UI |
| `LoadingSpinner.jsx` | Centered spinner shown during lazy-loaded page suspense |

### Utilities & Context (`frontend/src/`)

| File | Purpose |
|---|---|
| `contexts/AuthContext.jsx` | React context: user state, login, logout, isLoading |
| `layouts/ProjectLayout.jsx` | Wrapper for all /projects/:id routes; fetches project data |
| `api/axios.js` | Configured Axios instance with base URL and auth token header |
| `utils/exportToExcel.js` | 3-sheet SheetJS export (Summary, Components, Financial) |
| `utils/printPowerReport.js` | print-to-PDF HTML template with letterhead and power summary |
| `utils/downloadJson.js` | Triggers browser file download for JSON backup |
| `utils/navContext.js` | Navigation context utilities |
| `main.jsx` | React entry point (ReactDOM.createRoot) |
| `App.jsx` | All routes, ProtectedRoute/PublicRoute guards, lazy imports |

**Source:** All files listed above.

---

## 3. DATABASE SCHEMA — COMPLETE

### Table: `users`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | Primary key |
| `name` | varchar(255) | NO | | Display name |
| `email` | varchar(255) | NO | | Unique, used for login |
| `email_verified_at` | timestamp | YES | NULL | OAuth users are auto-verified |
| `password` | varchar(255) | YES | NULL | NULL for OAuth-only users |
| `remember_token` | varchar(100) | YES | NULL | Laravel session token |
| `google_id` | text | YES | NULL | Google sub ID (widened from varchar) |
| `google_token` | text | YES | NULL | OAuth access token (widened) |
| `google_refresh_token` | text | YES | NULL | OAuth refresh token (widened) |
| `avatar` | varchar(255) | YES | NULL | Google profile picture URL |
| `is_admin` | boolean | NO | false | Grants admin dashboard access |
| `created_at` / `updated_at` | timestamp | YES | NULL | Standard timestamps |

**Notes:** `google_id`, `google_token`, `google_refresh_token` were widened from varchar(255) to text in migration `2026_05_17` to accommodate long Google token strings.

---

### Table: `projects`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | Primary key |
| `user_id` | bigint unsigned | NO | | FK → users.id (owner) |
| `name` | varchar(255) | NO | | Project name |
| `description` | text | YES | NULL | Free-text description |
| `area` | decimal(10,2) | YES | NULL | Total site area m² |
| `voltage` | varchar(50) | YES | NULL | System voltage (e.g. '400/230V') |
| `frequency` | integer | YES | NULL | Hz (50 or 60) |
| `currency_symbol` | varchar(10) | NO | '$' | Display currency symbol |
| `solar_source` | enum('max','existing') | NO | 'max' | How solar capacity is determined |
| `existing_solar_power` | decimal(12,2) | YES | NULL | VA if solar_source='existing' |
| `generator_source` | boolean | NO | false | Whether generator is a source |
| `location_lat` | decimal(10,7) | YES | NULL | Latitude for NASA POWER API |
| `location_lng` | decimal(10,7) | YES | NULL | Longitude for NASA POWER API |
| `work_days` | json | YES | NULL | Array of day names, e.g. ["monday","tuesday",...] |
| `working_season_intervals` | json | YES | NULL | Array of {from,to} date-range objects |
| `auto_backup` | boolean | NO | false | Enable daily auto-backup |
| `created_at` / `updated_at` | timestamp | | | |

---

### Table: `buildings`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint | NO | auto | PK |
| `project_id` | bigint | NO | | FK → projects.id |
| `name` | varchar(255) | NO | | Building name |
| `area` | decimal(10,2) | YES | NULL | Floor area m² (used for solar capacity estimate) |
| `type` | varchar(100) | YES | NULL | e.g. 'office', 'hospital', 'residential_apartment' — drives diversity factors |
| `existing_solar_power` | decimal(12,2) | YES | NULL | Existing solar VA on this building |
| `work_days` | json | YES | NULL | Overrides project-level work days |
| `working_season_intervals` | json | YES | NULL | Overrides project-level season |
| `created_at` / `updated_at` | timestamp | | | |

---

### Table: `floors`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint | NO | auto | PK |
| `building_id` | bigint | NO | | FK → buildings.id |
| `name` | varchar(255) | NO | | Floor name/number |
| `work_days` | json | YES | NULL | Overrides building-level work days |
| `working_season_intervals` | json | YES | NULL | |
| `created_at` / `updated_at` | timestamp | | | |

---

### Table: `rooms`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint | NO | auto | PK |
| `floor_id` | bigint | NO | | FK → floors.id |
| `name` | varchar(255) | NO | | Room name |
| `type` | varchar(100) | YES | NULL | e.g. 'bedroom', 'server_room' — drives room coincidence factor |
| `work_days` | json | YES | NULL | |
| `working_season_intervals` | json | YES | NULL | |
| `created_at` / `updated_at` | timestamp | | | |

---

### Table: `component_types`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint | NO | auto | PK |
| `name` | varchar(255) | NO | | e.g. 'LED Lamp', 'Air Conditioner' |
| `default_power` | decimal(12,2) | YES | NULL | Suggested VA for this type |
| `default_power_factor` | decimal(4,3) | YES | NULL | Suggested PF (0.001–1.000) |
| `is_motor` | boolean | NO | false | Marks motor-type loads for inrush rule |
| `created_at` / `updated_at` | timestamp | | | |

---

### Component Tables (4 tables, same structure)

Tables: `project_components`, `building_components`, `floor_components`, `room_components`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint | NO | auto | PK |
| `{entity}_id` | bigint | NO | | FK to parent entity (project_id / building_id / floor_id / room_id) |
| `component_type_id` | bigint | NO | | FK → component_types.id |
| `component_name` | varchar(255) | YES | NULL | Optional override name |
| `power` | decimal(12,2) | NO | | **Apparent power in VA** (nameplate rating) |
| `power_factor` | decimal(4,3) | NO | 1.000 | PF (0.01–1.0) |
| `quantity` | integer | NO | 1 | Number of identical units |
| `phases` | enum('1phase','3phase') | NO | '1phase' | Single or three phase |
| `phase` | char(1) | YES | NULL | Assigned phase: 'A', 'B', or 'C' |
| `priority` | enum('critical','essential','normal') | NO | 'normal' | Load priority class |
| `group_name` | varchar(255) | YES | NULL | N+1 redundancy group identifier |
| `is_motor` | boolean | NO | false | True = NEC inrush rule applies |
| `usage_season` | enum('all','summer','winter','spring','autumn') | NO | 'all' | Season filter |
| `usage_day_type` | enum('all','weekday','weekend') | NO | 'all' | Day filter |
| `usage_time_intervals` | json | YES | NULL | Array of {start,end} time windows |
| `load_flexibility` | enum('fixed','shiftable','curtailable') | NO | 'fixed' | For optimizer |
| `required_run_hours` | integer | YES | NULL | Hours/day the load must run |
| `earliest_start_hour` | integer | YES | NULL | Earliest allowed start (0–23) |
| `latest_end_hour` | integer | YES | NULL | Latest must-finish hour (1–24) |
| `max_interruptions` | integer | YES | NULL | Max separate time windows (0=continuous) |
| `created_at` / `updated_at` | timestamp | | | |

**Note on `power` column:** Stores **VA (apparent power)**, NOT watts. Active power W = power × power_factor. This is intentional — equipment nameplate ratings are in kVA.

---

### Table: `utility_lines`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint | NO | auto | PK |
| `utilizable_type` | varchar(255) | NO | | Polymorphic type ('App\Models\Project' etc.) |
| `utilizable_id` | bigint | NO | | Polymorphic FK |
| `name` | varchar(255) | YES | NULL | Label e.g. 'Main Grid' |
| `power` | decimal(12,2) | YES | NULL | Rated capacity in VA |
| `phases` | enum('1phase','3phase') | NO | '3phase' | |
| `tariff_per_kwh` | decimal(10,4) | YES | NULL | Base electricity rate $/kWh |
| `peak_tariff_per_kwh` | decimal(10,4) | YES | NULL | Peak-hours rate (NULL = flat rate) |
| `peak_hours_start` | integer | YES | NULL | Hour 0–23 when peak period starts |
| `peak_hours_end` | integer | YES | NULL | Hour 1–24 when peak period ends |
| `created_at` / `updated_at` | timestamp | | | |

---

### Table: `generator_lines`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint | NO | auto | PK |
| `generable_type` | varchar(255) | NO | | Polymorphic type |
| `generable_id` | bigint | NO | | Polymorphic FK |
| `name` | varchar(255) | YES | NULL | |
| `power` | decimal(12,2) | YES | NULL | Rated output VA |
| `phases` | varchar(10) | YES | NULL | '1phase' or '3phase' |
| `fuel_cost_per_liter` | decimal(10,4) | YES | NULL | Fuel price $/L |
| `fuel_consumption_lph` | decimal(10,4) | YES | NULL | L/hr at 100% rated load |
| `no_load_fuel_lph` | decimal(10,4) | YES | NULL | L/hr at 0% load (NULL → 30% of rated) |
| `min_load_pct` | integer | YES | NULL | Minimum recommended load % |
| `optimal_load_pct` | integer | YES | NULL | Most efficient load % |
| `created_at` / `updated_at` | timestamp | | | |

---

### Table: `sockets`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint | NO | auto | PK |
| `socketable_type` | varchar(255) | NO | | Polymorphic: Project/Building/Floor/Room |
| `socketable_id` | bigint | NO | | Polymorphic FK |
| `quantity` | integer | NO | 1 | Number of outlet points |
| `created_at` / `updated_at` | timestamp | | | |

---

### Table: `batteries`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint | NO | auto | PK |
| `project_id` | bigint | NO | | FK → projects.id |
| `solar_system_id` | bigint | YES | NULL | If paired to a solar system |
| `name` | varchar(255) | NO | | Label e.g. 'Main BESS' |
| `chemistry` | varchar(50) | NO | | 'lithium_lfp', 'lead_acid_flooded', etc. |
| `nominal_capacity_kwh` | decimal(10,2) | NO | | Nameplate capacity kWh |
| `depth_of_discharge` | decimal(4,3) | NO | | DoD (0.0–1.0) |
| `usable_capacity_kwh` | decimal(10,2) | NO | | = nominal × DoD (stored computed) |
| `round_trip_efficiency` | decimal(4,3) | NO | | RTE (0.0–1.0) |
| `max_charge_power_kw` | decimal(10,2) | NO | | Max charge rate kW |
| `max_discharge_power_kw` | decimal(10,2) | NO | | Max discharge rate kW |
| `current_soc` | decimal(4,3) | NO | 1.000 | Current state of charge |
| `rated_cycle_life` | integer | NO | | Full cycles before 80% capacity |
| `age_years` | decimal(5,2) | NO | 0 | Current age for replacement scheduling |
| `is_active` | boolean | NO | true | Include in dispatch simulation |
| `purchase_cost` | decimal(12,2) | YES | NULL | Initial purchase cost $ |
| `replacement_cost` | decimal(12,2) | YES | NULL | Replacement cost $ |
| `annual_maintenance_cost` | decimal(10,2) | YES | NULL | $/year maintenance |
| `created_at` / `updated_at` | timestamp | | | |

**Computed properties (not stored):**
- `current_available_kwh` = usable_capacity_kwh × current_soc
- `health_status`: 'good' / 'degraded' / 'replace' (based on age vs rated cycle life)

---

### Table: `solar_systems`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint | NO | auto | PK |
| `project_id` | bigint | NO | | FK → projects.id |
| `name` | varchar(255) | NO | | e.g. 'Rooftop South Array' |
| `capacity_kw` | decimal(10,2) | NO | | Nameplate DC peak capacity kW |
| `is_active` | boolean | NO | true | Include in dispatch |
| `installation_cost` | decimal(12,2) | YES | NULL | $ upfront |
| `annual_maintenance_cost` | decimal(10,2) | YES | NULL | $/year |
| `created_at` / `updated_at` | timestamp | | | |

---

### Table: `project_users`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint | NO | auto | PK |
| `project_id` | bigint | NO | | FK → projects.id |
| `user_id` | bigint | NO | | FK → users.id |
| `role` | enum('viewer','main','admin') | NO | 'viewer' | Access level within this project |
| `created_at` / `updated_at` | timestamp | | | |

**Roles:**
- `viewer` — read-only (can see data, cannot edit)
- `main` — can edit everything except delete project / manage members
- `admin` — full control including member management

---

### Table: `server_backups`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint | NO | auto | PK |
| `project_id` | bigint | NO | | FK → projects.id |
| `filename` | varchar(255) | NO | | Stored file name on server |
| `size_bytes` | bigint | YES | NULL | File size |
| `created_at` / `updated_at` | timestamp | | | |

---

**Source:** All migration files in `backend/database/migrations/`

---

## 4. SERVICES — COMPLETE DOCUMENTATION

### 4.1 DiversityFactorService

**File:** `backend/app/Services/DiversityFactorService.php`
**Purpose:** Provides IEC 60364-8-1 and CIBSE Guide C diversity factor values keyed by building type and room type.

#### Constants

```php
BUILDING_DFS = [
    'residential_house'      => ['room_to_floor' => 0.60, 'floor_to_building' => 0.70],
    'residential_apartment'  => ['room_to_floor' => 0.65, 'floor_to_building' => 0.70],
    'hotel'                  => ['room_to_floor' => 0.65, 'floor_to_building' => 0.70],
    'office'                 => ['room_to_floor' => 0.85, 'floor_to_building' => 0.80],
    'educational_school'     => ['room_to_floor' => 0.80, 'floor_to_building' => 0.80],
    'educational_university' => ['room_to_floor' => 0.85, 'floor_to_building' => 0.80],
    'retail'                 => ['room_to_floor' => 0.85, 'floor_to_building' => 0.85],
    'hospital'               => ['room_to_floor' => 0.90, 'floor_to_building' => 0.90],
    'industrial'             => ['room_to_floor' => 0.85, 'floor_to_building' => 0.85],
    'mosque_worship'         => ['room_to_floor' => 0.80, 'floor_to_building' => 0.75],
    'sports'                 => ['room_to_floor' => 0.80, 'floor_to_building' => 0.80],
]
DEFAULT_BUILDING_DFS = ['room_to_floor' => 0.90, 'floor_to_building' => 0.80]
```

```php
ROOM_DFS = [
    'server_room'         => 1.00,   'operating_theater'   => 1.00,
    'laboratory'          => 0.90,   'classroom'           => 0.85,
    'lecture_hall'        => 0.85,   'workshop'            => 0.85,
    'retail_floor'        => 0.85,   'office_open'         => 0.80,
    'gym_sports'          => 0.80,   'kitchen_commercial'  => 0.75,
    'office_private'      => 0.75,   'prayer_hall'         => 0.75,
    'reception_lobby'     => 0.70,   'meeting_room'        => 0.70,
    'corridor'            => 0.60,   'living_room'         => 0.60,
    'kitchen_residential' => 0.55,   'hotel_room'          => 0.50,
    'bedroom'             => 0.45,   'warehouse_storage'   => 0.30,
    'bathroom'            => 0.25,
]
DEFAULT_ROOM_DF = 0.80
```

#### Methods

- `buildingDfs(?string $type): array` — returns `['room_to_floor' => float, 'floor_to_building' => float]`; falls back to DEFAULT_BUILDING_DFS if type is null or unknown.
- `roomDf(?string $type): float` — returns the room coincidence factor; falls back to 0.80.

**Standard:** IEC 60364-8-1 Table B.1 / CIBSE Guide C Table 4.5

---

### 4.2 SocketDemandService

**File:** `backend/app/Services/SocketDemandService.php`
**Purpose:** Calculates estimated socket outlet demand using a tiered demand factor model.

#### Constant
- `OUTLET_VA = 200` — assumed load per outlet outlet (VA)

#### Core Method: `applyFactors(int $n): float`

```
Demand(n) = min(n, 10) × 200 × 1.00
          + min(max(n-10, 0), 10) × 200 × 0.75
          + max(n-20, 0) × 200 × 0.40
```

| Outlets | Factor | Per outlet VA |
|---|---|---|
| 1–10 | 100% | 200 VA |
| 11–20 | 75% | 150 VA |
| 21+ | 40% | 80 VA |

#### Methods

- `roomResult(Room $room)` — sums `room.sockets.quantity`, returns `{outlets, connected_va, demand_va}`
- `floorResult(Floor $floor)` — sums room sockets + own floor sockets, applies `applyFactors()`, returns same structure
- `buildingResult(Building $building)` — bulk-queries all floors (2 SQL queries), computes per-floor demand, applies `coincidenceFactor()`, returns `{outlets, sum_floor_demand_va, coincidence_factor, demand_va, connected_va}`
- `projectResult(Project $project)` — bulk-queries all buildings (6 SQL queries), aggregates building demands

#### `coincidenceFactor(float $demandVA): float`
```
< 50 kVA   → 1.00
50–250 kVA → 0.92
> 250 kVA  → 0.85
```

---

### 4.3 SolarIrradianceService

**File:** `backend/app/Services/SolarIrradianceService.php`
**Purpose:** Returns 24-element hourly solar output array in Watts. Primary path: NASA POWER satellite API. Fallback: static PSH lookup + sinusoidal model.

#### Constants

| Constant | Value | Meaning |
|---|---|---|
| `ROOF_COVERAGE_RATIO` | 0.17 | 17% of roof area usable for panels |
| `STC_IRRADIANCE_W` | 1000.0 | W/m² at Standard Test Conditions |
| `CAPACITY_ESTIMATE_PR` | 0.75 | Conservative PR for rough capacity sizing |
| `PERFORMANCE_RATIO` | 0.80 | PR used in hourly profile computation |
| `NASA_API_BASE_URL` | `https://power.larc.nasa.gov/api/temporal/hourly/point` | NASA POWER endpoint |
| `NASA_TIMEOUT_SEC` | 10 | HTTP timeout seconds |
| `NASA_NULL_VALUE` | -999 | NASA's null/missing data sentinel |
| `CACHE_DAYS` | 30 | Cache duration (historical data never changes) |
| `REPRESENTATIVE_DAY` | 15 | 15th of month used as monthly midpoint |

#### PSH Table (Peak Sun Hours kWh/m²/day)

```
Latitude |  Jan  Feb  Mar  Apr  May  Jun  Jul  Aug  Sep  Oct  Nov  Dec
   0°    |  5.5  5.5  5.5  5.5  5.5  5.5  5.5  5.5  5.5  5.5  5.5  5.5
  10°    |  4.9  5.3  5.7  6.1  6.2  6.1  6.1  6.1  5.7  5.3  4.9  4.7
  20°    |  4.1  4.8  5.7  6.5  6.9  7.0  6.9  6.6  5.8  4.9  4.0  3.7
  30°    |  3.2  4.1  5.4  6.6  7.3  7.7  7.5  6.9  5.8  4.6  3.2  2.8
  40°    |  2.0  3.1  4.8  6.3  7.5  8.0  7.7  6.7  5.3  3.8  2.2  1.7
  50°    |  0.8  1.9  3.8  5.7  7.3  8.0  7.5  6.1  4.3  2.7  1.0  0.5
  60°    |  0.0  0.9  2.7  5.0  7.0  8.0  7.2  5.2  3.1  1.4  0.1  0.0
Source: NASA POWER ALLSKY_SFC_SW_DWN monthly averages, TMY 1991-2020
```

#### Methods

**`estimateCapacityW(float $areaM2): float`** — static
`= areaM2 × 0.17 × 1000 × 0.75`

**`getHourlyOutputWatts(?float $lat, ?float $lng, int $month, float $panelCapacityKw, float $PR=0.80, int $day=15): array`**
1. Calls `fetchNasaHourlyGhi()` — if successful (24 values), uses NASA path
2. Fallback to `hourlyProfile()` sinusoidal model
3. Tracks `$dataSource`: `'nasa_power'` | `'static_lookup'` | `'nasa_fallback'`

**`fetchNasaHourlyGhi(?float $lat, ?float $lng, int $month, int $day): array`**
- Rounds lat/lng to 4dp (~11m) for stable cache keys
- Cache key: `nasa_ghi_{lat}_{lng}_{month}_{day}`
- Fetches `ALLSKY_SFC_SW_DWN` for year 2023, day 15 of month
- Replaces -999 (NASA null) with 0.0
- Guard: if all-zero for non-polar location → fallback (avoids caching bad data)
- Cache: 30 days via Laravel Cache

**`hourlyProfile(float $lat, float $lng, int $month, float $capacityW): array`** — static fallback
Formula:
```
PSH = interpolatePsh(lat, month)
daylight = sunset - sunrise
peakW = capacityW × PSH × PR × π / (2 × daylight)
For each hour h:
  t = (h + 0.5 - sunrise) / daylight  [0..1]
  output(h) = peakW × sin(π × t)
```
Handles polar night (output = 0) and midnight sun (flat distribution).

**`sunriseSunset(float $lat, int $month): array`** — Spencer's equation
```
DOY = cumulativeDaysBeforeMonth + 15
δ   = 23.45° × sin(360° / 365 × (DOY - 81))     [solar declination]
H_A = arccos(-tan(lat) × tan(δ))                  [hour angle in degrees]
sunrise = 12.0 - H_A / 15
sunset  = 12.0 + H_A / 15
```

**`interpolatePsh(float $lat, int $month): float`** — linear interpolation
- Takes `abs(lat)`, clamps to 60°
- Southern hemisphere: shifts month index by 6 (season flip)
- Linearly interpolates between the two bounding 10° latitude bands

---

### 4.4 BatteryChemistryService

**File:** `backend/app/Services/BatteryChemistryService.php`
**Purpose:** Static registry of battery chemistry performance parameters.

| Chemistry | DoD | RTE | C-charge | C-discharge | Cycles | Life | Degradation/yr |
|---|---|---|---|---|---|---|---|
| `lead_acid_flooded` | 0.50 | 0.80 | 0.10 | 0.20 | 500 | 5yr | 5.0% |
| `lead_acid_agm` | 0.50 | 0.85 | 0.20 | 0.30 | 700 | 7yr | 4.0% |
| `lead_acid_gel` | 0.50 | 0.85 | 0.15 | 0.25 | 800 | 8yr | 3.5% |
| `lithium_lfp` | 0.90 | 0.95 | 0.50 | 1.00 | 4000 | 15yr | 2.0% |
| `lithium_nmc` | 0.80 | 0.93 | 0.50 | 1.00 | 2500 | 10yr | 2.5% |

**Methods:** `all(): array`, `getDefaults(string $chemistry): ?array`, `isValid(string $chemistry): bool`

---

### 4.5 SourceDispatchService

**File:** `backend/app/Services/SourceDispatchService.php`
**Purpose:** Runs a greedy per-hour priority dispatch for all 24 hours of a day. Decides how much each source contributes to meeting load.

#### Constants
- `GEN_OPTIMAL_MAX_LOAD = 0.85` — generator capped at 85% when opportunistically charging batteries (prevents exceeding efficient operating band)
- `INV_EFF = 0.95` — AC→DC inverter/rectifier efficiency for generator→battery charging path

#### `dispatch()` — public entry point
Routes to `dispatchOptimized()` (batteries present) or `dispatchBasic()` (no batteries).

#### `dispatchBasic()` — 4-step per hour (no batteries)
```
For each hour h (0..23):
  demand = loadW[h]
  1. Solar: solarUsed = min(solarW[h], demand);  demand -= solarUsed
  2. Utility: utilityUsed = min(utilCapW, demand); demand -= utilityUsed
  3. Generator: genUsed = min(genCapW, demand);  demand -= genUsed
  4. Unmet = max(0, demand)
```

#### `dispatchOptimized()` — 7-step per hour (with batteries)
```
For each hour h:
  STEP 1: Each solar system charges its paired batteries proportionally to their headroom
          share = systemOutputW × (battery_headroom / total_paired_headroom)
          battery.current += (actual_charged / 1000) × η_one_way

  STEP 2: Remaining solar covers load directly
          solarUsed[h] = min(sharedSolar, demand)
          surplus = sharedSolar - solarUsed[h]

  STEP 3: Surplus solar charges unpaired batteries (pool)
          Each unpaired battery gets proportional share of surplus

  STEP 4: All batteries discharge to cover remaining demand
          maxDischW = min(Σ disch_kw × 1000, Σ current_kWh × 1000)
          Capped by inverter rating per battery (inv_cap_w)
          battery.current -= (actual_discharge / 1000) / η_one_way

  STEP 5: Utility covers remaining
          utilityUsed[h] = min(utilCapW, remaining)

  STEP 6: Generator covers remaining
          genUsed[h] = min(genCapW, remaining)

  STEP 7: Opportunistic generator charging
          IF genUsed[h] > 0 AND genCapW > 0:
            spare = genCapW × 0.85 - genUsed[h]
            IF spare > 0: charge batteries with spare × INV_EFF × η_one_way
            genUsed[h] += AC_drawn_for_charging  (generator runs harder)
```

#### Stats computed
- `solar_self_consumption = (solar_used_kWh + battery_charged_solar_kWh) / solar_generated_kWh × 100`
- `generator_efficiency_avg` = average loading % across hours generator ran

---

### 4.6 CostSignalService

**File:** `backend/app/Services/CostSignalService.php`
**Purpose:** Computes a 24-element array of marginal cost ($/kWh) for each hour. Answers: "What does the next kW cost this hour?"

#### Constants
- `BATTERY_DEGRADATION_COST = 0.01` $/kWh — near-zero cost for stored energy (only cell wear)
- `UNMET_COST = 999.0` — sentinel for hours with no source available

#### Per-hour logic (highest priority to lowest):
```
IF solar_surplus[h] > 0:          cost = 0.00  (free energy)
ELIF battery_remaining_kw[h] > 0: cost = 0.01  (stored solar)
ELIF utility available:
    IF peak hour:                  cost = peak_tariff
    ELSE:                          cost = base_tariff
ELIF generator available:          cost = marginal_gen_cost
ELSE:                              cost = 999.0
```

#### Hour classification
- `free_hours`: cost == 0.0
- `cheap_hours`: 0.0 < cost < baseline
- `expensive_hours`: cost > baseline
- (normal: cost == baseline — not listed)

---

### 4.7 FinancialAnalysisService

**File:** `backend/app/Services/FinancialAnalysisService.php`
**Purpose:** Full financial model: annual costs with and without solar, savings, payback, LCOE, 25-year projection.

#### Constants
- `PANEL_DEGRADATION = 0.005` (0.5%/year — industry standard crystalline silicon)
- `PROJECTION_YEARS = 25`
- `DF_PROJECT = 0.7`
- `DEFAULT_WORK_DAYS = ['monday','tuesday','wednesday','thursday','friday']`

#### 7-Step `analyzeProject()` Method

**Step 1:** Build 24-hour load profile + solar profile for representative Monday in requested month
**Step 2:** Compute weighted average tariff (off-peak hours × base + peak hours × peak) / 24
**Step 3:** Run dispatch simulation → get daily kWh per source
   - Annual solar kWh = daily × 365 (and other sources)
   - Generator cost = Σₕ F(P_h) × fuel_price × 365 (affine model per hour)
**Step 4:** Run BASELINE dispatch (no solar, no battery) for comparison
**Step 5:** Compute annual savings = (baseline_grid + baseline_gen) - (with_grid + with_gen + maintenance)
**Step 6:** Simple payback = investment / savings; LCOE = (install + maintenance×25) / (solar_kwh × 25)
**Step 7:** 25-year projection:
```
runningNet = -totalInvestment
For y = 1..25:
    degradFactor = (1 - 0.005)^y
    yearNet = annualSavings × degradFactor - batteryReplacementCost[y]
    runningNet += yearNet
    cumulative[y] = runningNet
    if paybackYear == null AND runningNet > 0: paybackYear = y
```

Battery replacement year calculation:
```
yearsToEol = max(0, rated_cycle_life / 365 - age_years)
replYear = ceil(yearsToEol)
```

---

### 4.8 BackupExporter

**File:** `backend/app/Services/BackupExporter.php`
**Purpose:** Serializes a complete project (all entities, components, sources, members) to a JSON structure for portable backup/restore.

---

### 4.9 ValidationReferenceService

**File:** `backend/app/Services/ValidationReferenceService.php`
**Purpose:** Returns static reference tables from IEC/NEC standards (cable ampacity, circuit breaker ratings, voltage drop limits, wiring rules) for display on the ValidationPage.

---

**Source:** All files in `backend/app/Services/`

---

## 5. CONTROLLERS — COMPLETE

### 5.1 TotalPowerController

**File:** `backend/app/Http/Controllers/Api/TotalPowerController.php`

| Constant | Value | Meaning |
|---|---|---|
| `DF_PROJECT` | 0.7 | Project-level diversity factor |
| `VOLTAGE_3PHASE_LL` | 400 V | IEC line-to-line voltage |
| `VOLTAGE_1PHASE` | 230 V | IEC line-to-neutral voltage |
| `SYSTEM_FREQUENCY` | 50 Hz | IEC system frequency |
| `TARGET_POWER_FACTOR` | 0.95 | PENRA target PF |
| `PF_CORRECTION_THRESHOLD` | 0.85 | Below this, correction recommended |
| `CAPACITOR_BANK_STEP` | 0.5 kVAR | Standard manufactured increment |
| `INRUSH_MULTIPLIER` | 1.25 | NEC Article 430 motor inrush factor |

#### Routes & Methods

**`GET /api/projects/{project}/total-power`** → `project()`
Computes full diversified power for entire project (all buildings/floors/rooms). Returns:
```json
{
  "total_va": 45230.5, "total": 38450.2,
  "max_va": 68000.0, "max_w": 57800.0,
  "critical_va": 5000, "critical_w": 4500,
  "essential_va": 12000, "essential_w": 10200,
  "normal_va": 28230, "normal_w": 23750,
  "socket_demand_va": 3500, "socket_connected_va": 7000,
  "inrush_applied": true,
  "inrush_component": { "name": "Elevator", "per_unit_va": 5000, ... },
  "system_power_factor": 0.850,
  "pf_correction_recommended": false,
  "total_kvar": 23.8, "max_kvar": 35.2,
  "capacitor_bank_kvar": null, "capacitor_bank_uf": null,
  "current_before_correction_a": 65.4,
  "battery_storage": { ... },
  "solar_computed": 12750.0
}
```

**`GET /api/buildings/{building}/total-power`** → `building()`
Same structure but scoped to one building.

**`GET /api/floors/{floor}/total-power`** → `floor()`
Scoped to one floor.

**`GET /api/rooms/{room}/total-power`** → `room()`
Scoped to one room.

#### Key Internal Methods

**`sumPowerWithGroups($query, $entityKey, $withPriority, $df)`**
- Fetches all components, applies group-max deduplication
- Applies diversity factor (callable per-entity or flat float)
- Critical loads: DF always 1.0
- Returns: `{va, w, q, max_va, max_w, max_q, critical_va, critical_w, ...}` (12 fields)

**`reactivePowerFields(...)`**
Computes capacitor bank if PF < 0.85:
```
Q_cap = Q_total - P × tan(arccos(0.95))
bank_kVAR = ceil(Q_cap_kVAR / 0.5) × 0.5
C_per_phase = (bank_kVAR×1000 / 3) / (2π × 50 × 400²) × 10⁶  [μF, delta config]
```

**`applyInrush(?array $motor, &$maxW, &$maxQ)`**
Adds 25% extra to max vectors for the largest motor found across all levels.

---

### 5.2 PhaseBalanceController

**File:** `backend/app/Http/Controllers/Api/PhaseBalanceController.php`

Constants: `VOLT=230`, `WARN_PCT=10`, `CRIT_PCT=20`, `SOCKET_ASSUMED_PF=0.95`
Phase angles: A=0°, B=120°, C=240°

| Route | Method | Purpose |
|---|---|---|
| `GET /api/projects/{project}/phase-balance` | `project()` | Balance report for all buildings |
| `GET /api/buildings/{building}/phase-balance` | `building()` | Balance + optimal for one building |
| `GET /api/floors/{floor}/phase-balance` | `floor()` | Simple greedy for floor |
| `PUT /api/rooms/{room}/phase-balance/assign` | `assignRoom()` | Save phase A/B/C to DB for room |
| `POST /api/buildings/{building}/phase-balance/apply-optimal` | `applyOptimalBuilding()` | Persist optimal assignment |

**Core `buildingReport()` algorithm:**
1. Collect all 1-phase loads grouped as "blocks" (room block, floor-own block, socket block)
2. Sort blocks descending by VA
3. Greedy: assign each block to the phase with minimum current VA
4. Compute phasor currents per phase (accounts for PF/lag, not just VA magnitude)
5. Compute neutral current = phasor sum of all three phase currents

**Imbalance formula:**
```
I_A = VA_A/230, I_B = VA_B/230, I_C = VA_C/230
avg = (I_A + I_B + I_C) / 3
imbalance% = (max - min) / avg × 100
```

**Phasor neutral current:**
```
For each phase X (angle θ_X):
  For each load: real += (VA/230) × cos(θ_X - arccos(PF))
                 imag += (VA/230) × sin(θ_X - arccos(PF))
I_N = sqrt((Σ real)² + (Σ imag)²)
```

---

### 5.3 ProjectController

**File:** `backend/app/Http/Controllers/Api/ProjectController.php`

| Route | Method | Notes |
|---|---|---|
| `GET /api/projects` | `index()` | User's projects with total_power and total_kw |
| `POST /api/projects` | `store()` | Create project |
| `GET /api/projects/{project}` | `show()` | Single project |
| `PUT /api/projects/{project}` | `update()` | Update project |
| `DELETE /api/projects/{project}` | `destroy()` | Delete project |
| `POST /api/projects/{project}/optimize-shiftable` | `optimizeShiftable()` | Run load scheduler |
| `GET /api/projects/{project}/defense-summary` | `defenseSummary()` | Stats for DefensePrepPage |

**`optimizeShiftable()` — detailed:**
1. Validate: `month` (1–12), `components` array with `{id, model_type}`
2. For each component: validate `required_run_hours > 0` and fits in window
3. Build cost signal via `buildCostSignal()` (tariff + solar layers)
4. Call `pickBestIntervals()` to find cheapest window
5. **Improvement guard:** compare new cost vs best window in current schedule
6. Only save if new_cost < current_best_cost - 0.0001
7. Return `{data: [{id, intervals, savings, savings_percent}], optimized_count, errors}`

**`buildCostSignal()` — combined signal:**
```
solarFrac = solarW[h] / max(solarW)
IF monetary cost exists:
    signal[h] = base_cost × (1.0 - 0.9 × solarFrac)
ELSE:
    signal[h] = 1.0 - solarFrac
```

**`pickBestIntervals()` — two paths:**
- maxSplits=0: exhaustive window search O((W-R)×R) where W=window width, R=run_hours
- maxSplits>0: sort hours by cost, pick cheapest R hours, group consecutive

---

### 5.4 ScheduleController

**File:** `backend/app/Http/Controllers/Api/ScheduleController.php`

**`GET /api/projects/{project}/schedule?month=&day_type=&mode=`**

Returns 24-hour dispatch with load profile:
```json
{
  "hours": [0..23],
  "load_profile_w": [1200, 1200, ...],
  "solar_used": [...], "battery_discharged": [...],
  "utility_used": [...], "generator_used": [...],
  "unmet": [...],
  "solar_generated": [...],
  "battery_charged": [...],
  "battery_soc_trace": [...],
  "stats": { "solar_kwh": 12.5, "utility_kwh": 45.2, ... },
  "source_capacities": { "solar_kw": 15.0, "battery_kwh": 20.0, ... }
}
```

---

### 5.5 LoadProfileController

**`GET /api/projects/{project}/load-profile?month=&day_type=`**

Returns hourly W array for the project using diversity factors:
```json
{
  "hours": [0..23],
  "profile": [1500, 1500, 2000, ...],
  "peak_w": 8500,
  "peak_hour": 14,
  "total_kwh": 95.2
}
```

---

### 5.6 FinancialController

**`GET /api/projects/{project}/financial-analysis?month=`**

Calls `FinancialAnalysisService::analyzeProject()`. Returns:
```json
{
  "annual_energy": { "solar_kwh": 4500, "grid_kwh": 12000, "generator_kwh": 0, ... },
  "annual_costs": { "grid_cost": 1800, "generator_cost": 0, "total_with_solar": 2000, "total_without_solar": 3200 },
  "savings": { "annual_savings": 1200, "savings_percent": 37.5 },
  "investment": { "solar_installation": 8000, "battery_purchase": 3000, "total_investment": 11000 },
  "payback": { "simple_payback_years": 9.2, "lcoe_solar_per_kwh": 0.0611 },
  "projection_25yr": { "cumulative_net_by_year": [...], "payback_year": 9, "total_25yr_benefit": 19500 },
  "currency_symbol": "$"
}
```

---

### 5.7 CostSignalController

**`GET /api/projects/{project}/cost-signal?month=`**

Calls full dispatch first, then CostSignalService. Returns:
```json
{
  "cost_signal": [0.12, 0.12, 0.12, ..., 0.0, 0.0, 0.12],
  "solar_surplus_kw": [0, 0, ..., 2.3, 4.1, ...],
  "free_hours": [10, 11, 12, 13],
  "cheap_hours": [9, 14],
  "expensive_hours": [17, 18, 19],
  "currency_symbol": "$",
  "meta": { "utility_tariff_per_kwh": 0.12, "peak_tariff_per_kwh": null, ... }
}
```

---

### 5.8 ValidationController

**`GET /api/validation/reference`**

Returns structured reference data from ValidationReferenceService: cable tables, breaker ratings, voltage drop limits, etc.

---

### 5.9 NavigationController

**`GET /api/navigation/{type}/{id}`** (type: project/building/floor/room)

Returns breadcrumb array: `[{type, id, name}, ...]` from root to current entity.

---

### 5.10 Google OAuth (GoogleController)

**File:** `backend/app/Http/Controllers/Auth/GoogleController.php`

| Route | Method | Purpose |
|---|---|---|
| `GET /auth/google` | `redirect()` | Redirect to Google consent screen |
| `GET /auth/google/callback` | `callback()` | Handle OAuth callback, create/update user, issue Sanctum token |

**Flow:**
1. `redirect()` → `Socialite::driver('google')->redirect()`
2. `callback()` → `Socialite::driver('google')->user()`
3. `User::updateOrCreate(['email' => $googleUser->email], [...])` with google_id, avatar
4. `$user->createToken('google-token')->plainTextToken`
5. Redirect to frontend `/auth/callback?token=...`

---

### 5.11 AdminController

Provides admin dashboard data. Routes behind `AdminMiddleware`:

| Route | Returns |
|---|---|
| `GET /api/admin/users` | All users with project counts |
| `GET /api/admin/stats` | Total users, projects, components, backups |

---

### 5.12 CRUD Controllers (standard patterns)

Each of the following follows standard Laravel CRUD (index/store/show/update/destroy):

| Controller | Resource | Notes |
|---|---|---|
| `BuildingController` | `/api/projects/{project}/buildings` | |
| `FloorController` | `/api/buildings/{building}/floors` | |
| `RoomController` | `/api/floors/{floor}/rooms` | |
| `ProjectComponentController` | `/api/projects/{project}/components` | |
| `BuildingComponentController` | `/api/buildings/{building}/components` | |
| `FloorComponentController` | `/api/floors/{floor}/components` | |
| `RoomComponentController` | `/api/rooms/{room}/components` | |
| `UtilityLineController` | `/api/{type}/{id}/utility-lines` | Polymorphic entity |
| `GeneratorLineController` | `/api/{type}/{id}/generator-lines` | Polymorphic entity |
| `BatteryController` | `/api/projects/{project}/batteries` | Includes chemistry presets endpoint |
| `SolarSystemController` | `/api/projects/{project}/solar-systems` | |
| `SocketController` | `/api/{type}/{id}/sockets` | Polymorphic |
| `ComponentTypeController` | `/api/component-types` | Shared catalog |
| `ProjectMemberController` | `/api/projects/{project}/members` | Add/update/remove members |
| `ProjectBackupController` | `/api/projects/{project}/backup` | Export JSON; import JSON |
| `ServerBackupController` | `/api/projects/{project}/server-backups` | Create/download/delete |
| `UserController` | `/api/user` | Current user profile |

---

**Source:** All controller files in `backend/app/Http/Controllers/`

---

## 6. MODELS — COMPLETE

### User
```php
$fillable = ['name', 'email', 'password', 'google_id', 'google_token', 'google_refresh_token', 'avatar', 'is_admin']
$hidden   = ['password', 'remember_token']
$casts    = ['email_verified_at' => 'datetime', 'password' => 'hashed', 'is_admin' => 'boolean']

Relationships:
  hasMany  → Project (as owner)
  belongsToMany → Project (via ProjectUser, as member)
```

### Project
```php
$fillable = [name, description, area, voltage, frequency, currency_symbol, solar_source,
             existing_solar_power, generator_source, location_lat, location_lng,
             work_days, working_season_intervals, auto_backup]
$casts    = [work_days => array, working_season_intervals => array, auto_backup => boolean,
             generator_source => boolean, location_lat => decimal:7, location_lng => decimal:7]

Relationships:
  belongsTo → User (owner)
  hasMany   → Building, ProjectComponent, ProjectUser
  belongsToMany → User (via ProjectUser)
  morphMany → UtilityLine (as utilizable), GeneratorLine (as generable), Socket (as socketable)
  hasMany   → Battery, SolarSystem, ServerBackup

Methods:
  userRole($userId): ?string — returns 'admin'|'main'|'viewer'|null for the given user
```

### Building
```php
$fillable = [project_id, name, area, type, existing_solar_power, work_days, working_season_intervals]
$casts    = [work_days => array, working_season_intervals => array]

Relationships:
  belongsTo → Project
  hasMany   → Floor, BuildingComponent
  morphMany → UtilityLine, GeneratorLine, Socket
  hasManyThrough → Room (via Floor)
```

### Floor
```php
$fillable = [building_id, name, work_days, working_season_intervals]
Relationships:
  belongsTo → Building
  hasMany   → Room, FloorComponent
  morphMany → Socket
```

### Room
```php
$fillable = [floor_id, name, type, work_days, working_season_intervals]
Relationships:
  belongsTo → Floor
  hasMany   → RoomComponent
  morphMany → Socket
```

### ComponentType
```php
$fillable = [name, default_power, default_power_factor, is_motor]
$casts    = [is_motor => boolean, default_power => decimal:2, default_power_factor => decimal:3]
Relationships:
  hasMany → ProjectComponent, BuildingComponent, FloorComponent, RoomComponent
```

### Component Models (ProjectComponent, BuildingComponent, FloorComponent, RoomComponent)
All four share the same pattern:
```php
$fillable = [component_type_id, component_name, power, power_factor, quantity, phases, phase,
             priority, group_name, is_motor, usage_season, usage_day_type, usage_time_intervals,
             load_flexibility, required_run_hours, earliest_start_hour, latest_end_hour, max_interruptions]
$casts    = [usage_time_intervals => array, is_motor => boolean]
Relationships:
  belongsTo → ComponentType
  belongsTo → Project/Building/Floor/Room (parent entity)
```

### GeneratorLine
```php
$fillable = [name, power, phases, fuel_cost_per_liter, fuel_consumption_lph, no_load_fuel_lph, min_load_pct, optimal_load_pct]
$casts    = [fuel_cost_per_liter => float, fuel_consumption_lph => float, no_load_fuel_lph => float, ...]
$appends  = ['cost_per_kwh']   // computed accessor

Computed methods:
  getCostPerKwhAttribute()    → fuel_cost × lph / rated_kW  (flat-rate, at 100% load)
  fuelAtLoadKw(float $kW)     → F₀ + (F_rated - F₀) × kW/rated_kW  (ISO 8528 affine)
  costPerKwhAtLoad(float $kW) → fuel_cost × fuelAtLoadKw(kW) / kW
  marginalCostPerKwh()        → fuel_cost × (F_rated - F₀) / rated_kW  (dC/dP)
  generable()                 → morphTo() (Project or Building)
```

### Battery
```php
$fillable = [project_id, solar_system_id, name, chemistry, nominal_capacity_kwh,
             depth_of_discharge, usable_capacity_kwh, round_trip_efficiency,
             max_charge_power_kw, max_discharge_power_kw, current_soc,
             rated_cycle_life, age_years, is_active, purchase_cost, replacement_cost, annual_maintenance_cost]

Computed (via accessor or model methods):
  current_available_kwh = usable_capacity_kwh × current_soc
  health_status         = 'good' / 'degraded' / 'replace'  (based on age vs cycle life)
Relationships:
  belongsTo → Project, SolarSystem (nullable)
```

### SolarSystem
```php
$fillable = [project_id, name, capacity_kw, is_active, installation_cost, annual_maintenance_cost]
Relationships:
  belongsTo → Project
  hasMany   → Battery (batteries paired to this system)
```

### Socket
```php
$fillable = [quantity]
$casts    = [quantity => integer]
Relationships:
  morphTo → socketable (Project, Building, Floor, Room)
```

### ProjectUser
```php
$fillable = [project_id, user_id, role]
Relationships:
  belongsTo → Project, User
```

---

**Source:** All model files in `backend/app/Models/`

---

## 7. COMPLETE API REFERENCE

All routes defined in `backend/routes/api.php`. All routes under `/api/` prefix.
Auth middleware: `auth:sanctum` unless noted.

| Method | Path | Controller@method | Notes |
|---|---|---|---|
| POST | /auth/google | GoogleController@redirect | Public |
| GET | /auth/google/callback | GoogleController@callback | Public |
| POST | /auth/admin/login | AdminController@login | Public, admin credentials |
| GET | /api/user | UserController@show | auth:sanctum |
| PUT | /api/user | UserController@update | auth:sanctum |
| GET | /api/projects | ProjectController@index | |
| POST | /api/projects | ProjectController@store | |
| GET | /api/projects/{project} | ProjectController@show | |
| PUT | /api/projects/{project} | ProjectController@update | |
| DELETE | /api/projects/{project} | ProjectController@destroy | |
| POST | /api/projects/{project}/optimize-shiftable | ProjectController@optimizeShiftable | admin/main role |
| GET | /api/projects/{project}/defense-summary | ProjectController@defenseSummary | |
| GET | /api/projects/{project}/total-power | TotalPowerController@project | |
| GET | /api/projects/{project}/load-profile | LoadProfileController@project | |
| GET | /api/projects/{project}/schedule | ScheduleController@project | |
| GET | /api/projects/{project}/phase-balance | PhaseBalanceController@project | |
| GET | /api/projects/{project}/financial-analysis | FinancialController@analyze | |
| GET | /api/projects/{project}/cost-signal | CostSignalController@project | |
| GET | /api/projects/{project}/navigation | NavigationController@project | |
| GET | /api/projects/{project}/buildings | BuildingController@index | |
| POST | /api/projects/{project}/buildings | BuildingController@store | |
| GET | /api/buildings/{building} | BuildingController@show | |
| PUT | /api/buildings/{building} | BuildingController@update | |
| DELETE | /api/buildings/{building} | BuildingController@destroy | |
| GET | /api/buildings/{building}/total-power | TotalPowerController@building | |
| GET | /api/buildings/{building}/phase-balance | PhaseBalanceController@building | |
| POST | /api/buildings/{building}/phase-balance/apply-optimal | PhaseBalanceController@applyOptimalBuilding | admin/main |
| GET | /api/buildings/{building}/floors | FloorController@index | |
| POST | /api/buildings/{building}/floors | FloorController@store | |
| GET | /api/floors/{floor} | FloorController@show | |
| PUT | /api/floors/{floor} | FloorController@update | |
| DELETE | /api/floors/{floor} | FloorController@destroy | |
| GET | /api/floors/{floor}/total-power | TotalPowerController@floor | |
| GET | /api/floors/{floor}/phase-balance | PhaseBalanceController@floor | |
| GET | /api/floors/{floor}/rooms | RoomController@index | |
| POST | /api/floors/{floor}/rooms | RoomController@store | |
| GET | /api/rooms/{room} | RoomController@show | |
| PUT | /api/rooms/{room} | RoomController@update | |
| DELETE | /api/rooms/{room} | RoomController@destroy | |
| GET | /api/rooms/{room}/total-power | TotalPowerController@room | |
| PUT | /api/rooms/{room}/phase-balance/assign | PhaseBalanceController@assignRoom | admin/main |
| GET | /api/projects/{project}/components | ProjectComponentController@index | |
| POST | /api/projects/{project}/components | ProjectComponentController@store | |
| PUT | /api/projects/{project}/components/{component} | ProjectComponentController@update | |
| DELETE | /api/projects/{project}/components/{component} | ProjectComponentController@destroy | |
| GET | /api/buildings/{building}/components | BuildingComponentController@index | |
| POST | /api/buildings/{building}/components | BuildingComponentController@store | |
| PUT | /api/buildings/{building}/components/{component} | BuildingComponentController@update | |
| DELETE | /api/buildings/{building}/components/{component} | BuildingComponentController@destroy | |
| GET | /api/floors/{floor}/components | FloorComponentController@index | |
| POST | /api/floors/{floor}/components | FloorComponentController@store | |
| PUT/DELETE | /api/floors/{floor}/components/{component} | FloorComponentController@... | |
| GET | /api/rooms/{room}/components | RoomComponentController@index | |
| POST | /api/rooms/{room}/components | RoomComponentController@store | |
| PUT/DELETE | /api/rooms/{room}/components/{component} | RoomComponentController@... | |
| GET/POST/PUT/DELETE | /api/.../utility-lines/... | UtilityLineController | Polymorphic |
| GET/POST/PUT/DELETE | /api/.../generator-lines/... | GeneratorLineController | Polymorphic |
| GET/POST/PUT/DELETE | /api/.../sockets/... | SocketController | Polymorphic |
| GET/POST/PUT/DELETE | /api/projects/{project}/batteries/... | BatteryController | |
| GET | /api/batteries/chemistry-presets | BatteryController@presets | |
| GET/POST/PUT/DELETE | /api/projects/{project}/solar-systems/... | SolarSystemController | |
| GET/POST/PUT/DELETE | /api/component-types/... | ComponentTypeController | |
| GET/POST/PUT/DELETE | /api/projects/{project}/members/... | ProjectMemberController | |
| POST | /api/projects/{project}/backup/export | ProjectBackupController@export | |
| POST | /api/projects/{project}/backup/import | ProjectBackupController@import | |
| GET/POST/DELETE | /api/projects/{project}/server-backups/... | ServerBackupController | |
| GET | /api/admin/users | AdminController@users | AdminMiddleware |
| GET | /api/admin/stats | AdminController@stats | AdminMiddleware |
| GET | /api/validation/reference | ValidationController@reference | |

---

**Source:** `backend/routes/api.php`, all controller files

---

## 8. FORM REQUEST VALIDATION RULES

### StoreProjectRequest / UpdateProjectRequest
```
name:         required|string|max:255
description:  nullable|string
area:         nullable|numeric|min:0
voltage:      nullable|string|max:50
frequency:    nullable|integer|in:50,60
currency_symbol: nullable|string|max:10
solar_source: nullable|in:max,existing
location_lat: nullable|numeric|between:-90,90
location_lng: nullable|numeric|between:-180,180
work_days:    nullable|array
```

### StoreBuildingRequest / UpdateBuildingRequest
```
name:  required|string|max:255
area:  nullable|numeric|min:0
type:  nullable|string|in:[all building type keys]
```

### StoreComponentRequest / UpdateComponentRequest
```
component_type_id:    required|integer|exists:component_types,id
power:                required|numeric|min:0
power_factor:         nullable|numeric|between:0.01,1
quantity:             nullable|integer|min:1|max:1000
phases:               nullable|in:1phase,3phase
priority:             nullable|in:critical,essential,normal
group_name:           nullable|string|max:255
usage_season:         nullable|in:all,summer,winter,spring,autumn
usage_day_type:       nullable|in:all,weekday,weekend
usage_time_intervals: nullable|array
load_flexibility:     nullable|in:fixed,shiftable,curtailable
required_run_hours:   nullable|integer|min:1|max:24
earliest_start_hour:  nullable|integer|min:0|max:23
latest_end_hour:      nullable|integer|min:1|max:24
max_interruptions:    nullable|integer|min:0
```

### StoreBatteryRequest / UpdateBatteryRequest
```
name:                   required|string|max:255
chemistry:              required|string|in:[5 chemistry keys]
nominal_capacity_kwh:   required|numeric|min:0.1
depth_of_discharge:     required|numeric|between:0.1,1
round_trip_efficiency:  required|numeric|between:0.5,1
max_charge_power_kw:    required|numeric|min:0.1
max_discharge_power_kw: required|numeric|min:0.1
current_soc:            nullable|numeric|between:0,1
rated_cycle_life:       required|integer|min:10
solar_system_id:        nullable|integer|exists:solar_systems,id
purchase_cost:          nullable|numeric|min:0
replacement_cost:       nullable|numeric|min:0
```

### StoreUtilityLineRequest / UpdateUtilityLineRequest
```
name:                  nullable|string
power:                 nullable|numeric|min:0
phases:                nullable|in:1phase,3phase
tariff_per_kwh:        nullable|numeric|min:0
peak_tariff_per_kwh:   nullable|numeric|min:0
peak_hours_start:      nullable|integer|min:0|max:23
peak_hours_end:        nullable|integer|min:1|max:24
```

### StoreGeneratorLineRequest / UpdateGeneratorLineRequest
```
name:                  nullable|string
power:                 nullable|numeric|min:0
phases:                nullable|in:1phase,3phase
fuel_cost_per_liter:   nullable|numeric|min:0
fuel_consumption_lph:  nullable|numeric|min:0
no_load_fuel_lph:      nullable|numeric|min:0
min_load_pct:          nullable|integer|min:0|max:100
optimal_load_pct:      nullable|integer|min:0|max:100
```

---

**Source:** All files in `backend/app/Http/Requests/`

---

## 9. FRONTEND PAGES — COMPLETE

### LoginPage (`/login`)
- Shows Google OAuth button
- Calls: `GET /auth/google` (redirects to Google)
- Redirects logged-in users to `/dashboard` (PublicRoute guard)

### AuthCallbackPage (`/auth/callback`)
- Reads `?token=` from URL on return from Google OAuth
- Calls `AuthContext.login(token)` to store token and fetch user
- Redirects to `/dashboard`

### DashboardPage (`/dashboard`)
- Lists all user's projects
- API: `GET /api/projects`
- Shows `total_kw` (kW, primary) and `total_power` (kVA, secondary) per project
- Delete project inline
- Link to `/projects/new` for creation
- ProjectRow component with stacked kW/kVA display

### CreateProjectPage (`/projects/new`)
- Form: name, description, area, voltage, frequency, currency
- API: `POST /api/projects`
- On success: navigates to new project page

### ProjectPage (`/projects/:projectId`)
- Top-level project overview
- Shows `<PowerBanner>` with project-level total power
- Shows `<PowerSourcesBanner>` with solar/battery/grid/generator summary
- API: `GET /api/projects/:id/total-power`

### BuildingPage (`/projects/:id/buildings/:bid`)
- Building detail: components table + power banner
- API: `GET /api/buildings/:bid/total-power`, `GET /api/buildings/:bid/components`
- Shows `<EntityComponents>`, `<EntitySockets>`, `<PowerBanner>`

### FloorPage, NewRoomPage — same pattern for floors and rooms

### LoadSchedulePage (`/projects/:id/schedule`)
- **Three tabs:** Schedule (base load profile), Optimized (after optimization), Combined
- **Mode selector:** base / optimized
- Cost signal bar chart (24 bars color-coded by cost level)
- Per-component schedule grid showing intervals
- "Optimize" button → `POST /api/projects/:id/optimize-shiftable`
- API: `GET /api/projects/:id/schedule`, `GET /api/projects/:id/cost-signal`
- On optimize success: switches to 'combined' tab and 'optimized' mode

### PhaseBalancePage (`/projects/:id/phase-balance`)
- Shows Actual vs Optimal phase distribution per building
- Doughnut chart of A/B/C percentage
- Imbalance percentage with status color
- Neutral current display
- "Apply Optimal" button → `POST /api/buildings/:id/phase-balance/apply-optimal`
- API: `GET /api/projects/:id/phase-balance`

### FinancialPage (`/projects/:id/financial`)
- Month selector (1–12)
- Energy mix pie chart (solar/grid/generator %)
- Cost comparison card: with solar vs without solar (annual)
- 25-year cumulative net chart (Recharts LineChart)
- Payback year highlighted
- Investment summary: installation + battery costs
- API: `GET /api/projects/:id/financial-analysis?month=`

### ValidationPage (`/validation`)
- IEC/NEC reference browser
- Tables: cable ratings, breaker sizes, voltage drop limits
- API: `GET /api/validation/reference`
- Standalone route (not inside ProjectLayout)

### DefensePrepPage (`/defense-prep`)
- Admin-only (checks `user.is_admin`)
- 42 accordion Q&A items across 8 categories
- Project selector to pull live data
- Category filter tabs
- "Expand All" / "Print All" buttons
- Print CSS with "Power Profile — Defense Preparation — Ahmed Zoher — June 2026" footer
- Live data pills (pulsing dot) for questions using real project data

### SingleLineDiagramPage (`/projects/:id/single-line`)
- Auto-generated SVG showing power sources → bus → building panels
- SourceNode (solar/battery/grid/generator), BuildingNode, Wire, Breaker SVG components
- Download SVG button
- Legend strip
- Disclaimer text

### AdminLoginPage (`/admin/login`)
- Email + password form
- `POST /api/auth/admin/login`
- Issues admin-level Sanctum token

### AdminDashboardPage (`/admin/dashboard`)
- System statistics (total users, projects, components)
- User list with project counts
- Server backup management

---

## 10. FRONTEND COMPONENTS — COMPLETE

### Navbar
- Top bar with project name, user avatar, logout
- "Defense Prep" button (only shown when `user.is_admin`)
- Uses `useAuth()` context

### PowerBanner
- Displays MAX LOAD / OPTIMIZED kVA and kW
- Toggle: VA view | W view (default: VA)
- PDF export button → calls `printPowerReport()`
- Excel export button → calls `exportProjectExcel()` (shows spinner during export)
- Props: `data` (power response), `title`, `projectId`, `capApplied`

### PowerSourcesBanner
- Shows all configured power sources for the project
- Solar: capacity kW + computed output
- Battery: total kWh, current SOC, health
- Grid: capacity kVA, tariff
- Generator: rated kW, fuel cost/kWh
- API: `GET /api/projects/:id/schedule` for live dispatch data

### ProjectSidebar
- Navigation links for all project sub-pages
- Active state detection via `useLocation()`
- Links: Project Overview, Buildings, Load Schedule, Phase Balance, Financial Analysis, Single-Line Diagram
- "Single-Line Diagram" shown with cyan styling

### EntityComponents
- Reusable CRUD table for components at any entity level
- Props: `entityType`, `entityId`
- Features: add/edit/delete, inline editing, group_name field, priority selector, PF indicator
- Shows `<TimeScheduleModal>` for scheduling

### EntityScheduleModal
- Modal for editing entity work days and season intervals
- Props: `entity`, `entityType`, `onSaved`

### EntitySockets
- Shows socket outlets for any entity
- Displays demand VA and connected VA
- Props: `entityType`, `entityId`

### TimeScheduleModal
- Modal for editing `usage_time_intervals` on a component
- Visual time blocks across 24-hour bar
- Also edits: load_flexibility, required_run_hours, earliest/latest window, max_interruptions

### ReactivePowerPanel
- Shown when `pf_correction_recommended = true`
- Displays capacitor bank details: kVAR, μF, current reduction %
- Color-coded PF status pill

### BackupChoiceModal
- Offers "Download JSON" (client-side) or "Save to Server" options
- Triggers appropriate backup action on choice

### ProjectMembersModal
- List of project members with their roles
- Add member by email
- Change role (viewer/main/admin) inline
- Remove member

### ServerBackupsList
- List of server backups for a project
- Download and delete buttons per backup

### UserCard
- Avatar circle + name text
- Used in member lists and admin dashboard

### ErrorBoundary
- Wraps each major route in App.jsx
- Catches render errors, shows "Something went wrong" with `label` prop context

### LoadingSpinner
- Centered animated spinner
- Shown during `<Suspense>` lazy load of pages

---

## 11. UTILITY FILES

### `contexts/AuthContext.jsx`
- React context providing: `user`, `isLoading`, `login(token)`, `logout()`
- On mount: reads token from localStorage, calls `GET /api/user` to hydrate user object
- `login(token)`: stores to localStorage, sets axios default header, fetches user
- `logout()`: clears localStorage, clears axios header, sets user to null
- `user` object shape: `{ id, name, email, avatar, is_admin }`

### `layouts/ProjectLayout.jsx`
- Wrapper for all `/projects/:projectId/*` routes
- Fetches `GET /api/projects/:projectId` on mount
- Provides project data to all child pages via context or props
- Shows `<ProjectSidebar>` alongside page content
- Handles project-not-found → redirect to dashboard

### `api/axios.js`
- Base URL: configured from `VITE_API_BASE_URL` env or defaults to backend server
- Default header: `Authorization: Bearer {token}` from localStorage
- Interceptor: on 401 response → `logout()` and redirect to `/login`
- Interceptor: adds `Accept: application/json` to all requests

### `App.jsx`
- All pages lazy-loaded via `React.lazy()`
- `ProtectedRoute`: redirects to `/login` if no user
- `PublicRoute`: redirects to `/dashboard` if already logged in
- `BrowserRouter` with future flags `v7_startTransition`, `v7_relativeSplatPath`
- `<Suspense fallback={<LoadingSpinner />}>` wraps all routes
- Each route wrapped in `<ErrorBoundary label="...">` for isolated error handling

### `utils/exportToExcel.js`
- `exportProjectExcel(projectId, projectName, powerData, engineerName)`
- **Sheet 1 — Summary:** project metadata, demand summary (max/optimized), PF status, priority breakdown, socket demand, capacitor bank (if applicable)
- **Sheet 2 — Components:** fetches full hierarchy via 6 nested API calls. Columns: Level, Location, Name, Power(W), Qty, PF, TotalW, Phases, Priority, Scheduling, Season, DayType
- **Sheet 3 — Financial:** annual summary rows + 25-year yearly cashflow table (Year, Solar kWh, Savings, Battery Replacement, Net, Cumulative)
- Header row style: blue background (#1E3A8A), white bold text
- Data rows: alternating white/light-gray (#F9FAFB)
- Column widths set explicitly via `ws['!cols']`

### `utils/printPowerReport.js`
- `printPowerReport(data, title, { capApplied, engineerName })`
- Opens new browser window, writes full HTML with inline CSS, calls `window.print()`
- Sections: letterhead (logo + project name + engineer + date + ref#), 3 summary cards (Max Load / Optimized / Diversity Reduction %), PF status pill (green ≥0.95 / amber ≥0.85 / red <0.85), priority breakdown table, capacitor bank block (if applicable), signature block, standards footer
- Auto-generated ref: `PP-${Date.now().toString(36).toUpperCase()}-${Math.random().toString(36).substr(2,4).toUpperCase()}`

### `utils/downloadJson.js`
- Creates a Blob from JSON string, creates an `<a>` element with object URL, triggers download
- Used by `ProjectBackupController` export flow

### `utils/navContext.js`
- Navigation context helper for passing project/building/floor/room context between pages without prop drilling

---

**Source:** All utility files, `frontend/src/contexts/AuthContext.jsx`, `frontend/src/layouts/ProjectLayout.jsx`, `frontend/src/App.jsx`

---

"""

# Read existing file content
existing_path = r"e:\graduation project\power-profile\POWER_PROFILE_COMPLETE_DOCUMENTATION.md"

with open(existing_path, 'r', encoding='utf-8') as f:
    existing = f.read()

# Strip the old bare header (first 3 lines) if present
lines = existing.split('\n')
# Remove placeholder header lines added by the first agent
start_idx = 0
for i, line in enumerate(lines):
    if line.startswith('## 12.'):
        start_idx = i
        break

existing_sections = '\n'.join(lines[start_idx:])

# Combine
combined = front_matter.rstrip() + '\n\n---\n\n' + existing_sections

with open(existing_path, 'w', encoding='utf-8') as f:
    f.write(combined)

line_count = combined.count('\n')
print(f"Done. File has approximately {line_count} lines.")
print(f"Written to: {existing_path}")
