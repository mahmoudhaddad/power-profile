# POWER PROFILE — COMPLETE PROJECT DOCUMENTATION

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

---

## 12. ALL ELECTRICAL FORMULAS

Every formula found in the codebase, with variable definitions, standards, source files, and worked examples.

---

### 12.1 Apparent Power (Power Triangle)

**Formula:** S = sqrt(P^2 + Q^2)

**Variables:**
- S = Apparent power (VA)
- P = Active power (W)
- Q = Reactive power (VAR)

**Standard:** IEC 60364-8-1

**Source:** `ValidationReferenceService.php`, `TotalPowerController` (via `ValidationController.php`)

**Worked Example:**
- P = 5,000 W, Q = 1,750 VAR
- S = sqrt(5000^2 + 1750^2) = sqrt(25,000,000 + 3,062,500) = sqrt(28,062,500) ≈ 5,297 VA

---

### 12.2 Active Power from Apparent Power

**Formula:** P = S × cos(φ) = S × PF

**Variables:**
- P = Active power (W)
- S = Apparent power (VA)
- PF = Power factor = cos(φ) (dimensionless, 0–1)

**Standard:** IEC 60364-8-1

**Source:** `TotalPowerController.php`, `ValidationReferenceService.php`

**Worked Example:**
- S = 3,000 VA, PF = 0.85
- P = 3,000 × 0.85 = 2,550 W

---

### 12.3 Reactive Power

**Formula:** Q = P × tan(arccos(PF))

**Variables:**
- Q = Reactive power (VAR)
- P = Active power (W)
- PF = Power factor (dimensionless)

**Standard:** IEC 60364-8-1

**Source:** `ValidationReferenceService.php`, `TotalPowerController.php`

**Worked Example:**
- P = 2,550 W, PF = 0.85 → φ = arccos(0.85) = 31.79° → tan(31.79°) = 0.6197
- Q = 2,550 × 0.6197 ≈ 1,580 VAR

---

### 12.4 System Power Factor

**Formula:** PF_sys = P_total / S_total

**Variables:**
- PF_sys = System power factor (dimensionless)
- P_total = Total active power (W)
- S_total = Total apparent power (VA)

**Standard:** PENRA / IEC 60364-8-1

**Source:** `ValidationController.php` line 231: `$pf_sys = $total_va > 0 ? round($total_w / $total_va, 3) : 1.0`

**Worked Example:**
- P = 8,200 W, S = 9,100 VA
- PF_sys = 8,200 / 9,100 ≈ 0.901

---

### 12.5 Diversity Cascade (Full Chain)

**Formula:** DF_effective = DF_room_type × DF_room_to_floor × DF_floor_to_building × DF_project

**Variables:**
- DF_room_type = Room coincidence factor (from CIBSE table, e.g. 0.80 for office_open)
- DF_room_to_floor = Building-type diversity factor, room level (e.g. 0.85 for office)
- DF_floor_to_building = Building-type diversity factor, floor level (e.g. 0.80 for office)
- DF_project = Project-level diversity factor = 0.70 (IEC 60364-8-1 constant)

**Standard:** IEC 60364-8-1, BS 7671, CIBSE Guide C

**Source:** `DiversityFactorService.php`, `ScheduleController.php` line 243, `FinancialAnalysisService.php` line 295

**Worked Example (open-plan office room):**
- DF_room_type (office_open) = 0.80
- DF_room_to_floor (office) = 0.85
- DF_floor_to_building (office) = 0.80
- DF_project = 0.70
- DF_effective = 0.80 × 0.85 × 0.80 × 0.70 = 0.3808
- A 5,000 VA air conditioner contributes 5,000 × 0.3808 = 1,904 VA to the diversified total.

---

### 12.6 Socket Tiered Demand

**Formula:**
- First 10 outlets: demand = n × 200 VA × 1.00
- Next 10 outlets (11–20): demand += (n-10) × 200 VA × 0.75
- Remaining (>20): demand += (n-20) × 200 VA × 0.40

**Variables:**
- n = Total number of socket outlets
- 200 VA = Standard outlet rating per IEC/BS 7671

**Standard:** BS 7671 / IEC demand factor for socket circuits

**Source:** `SocketDemandService.php` lines 19–23

**Worked Example:**
- n = 28 outlets
- Tier 1 (10): 10 × 200 × 1.00 = 2,000 VA
- Tier 2 (10): 10 × 200 × 0.75 = 1,500 VA
- Tier 3 (8): 8 × 200 × 0.40 = 640 VA
- Total demand = 4,140 VA

---

### 12.7 Socket Coincidence Factor

**Formula:**
- Raw demand < 50 kVA: CF = 1.00
- Raw demand 50–250 kVA: CF = 0.92
- Raw demand > 250 kVA: CF = 0.85

**Variables:**
- CF = Coincidence factor (dimensionless)
- Raw demand = Sum of floor-level socket demands (VA)

**Standard:** IEC 60364 / CIBSE Guide C building-level demand

**Source:** `SocketDemandService.php` lines 213–219

**Worked Example:**
- Floor 1: 20 outlets → 3,500 VA; Floor 2: 8 outlets → 1,600 VA
- Raw = 5,100 VA = 5.1 kVA → CF = 1.00
- Final building socket demand = 5,100 × 1.00 = 5,100 VA

---

### 12.8 Capacitor Bank Size

**Formula:** Q_cap = Q_sys - P × tan(arccos(PF_target))

Then round up to next 0.5 kVAR step.

**Variables:**
- Q_cap = Required reactive compensation (VAR)
- Q_sys = Current system reactive power (VAR)
- P = System active power (W)
- PF_target = 0.95 (PENRA target)
- CAP_STEP_KVAR = 0.5 kVAR (standard bank increment)

**Standard:** IEC 60831 (capacitor banks), PENRA PF requirements

**Source:** `ValidationController.php` lines 238–245, `ValidationReferenceService.php` lines 113–118

**Worked Example:**
- P = 8,200 W, Q = 3,500 VAR, PF_target = 0.95
- Q_target = 8,200 × tan(arccos(0.95)) = 8,200 × 0.3287 = 2,695 VAR
- Q_cap = 3,500 - 2,695 = 805 VAR = 0.805 kVAR
- Rounded up to next 0.5 step: cap_bank_kvar = 1.0 kVAR

---

### 12.9 Capacitor Value (Delta Connection)

**Formula:** C_phase_μF = (Q_cap_per_phase / (2π × f × V_LL^2)) × 1,000,000

**Variables:**
- C_phase_μF = Capacitor value per phase (μF)
- Q_cap_per_phase = Q_cap_total / 3 (VAR per phase)
- f = System frequency = 50 Hz
- V_LL = Line-to-line voltage = 400 V

**Standard:** IEC 60831 (delta-connected 3-phase capacitor banks)

**Source:** `ValidationController.php` lines 242–245, `ValidationReferenceService.php` lines 116–120

**Worked Example:**
- Q_cap = 1.0 kVAR = 1,000 VAR → Q per phase = 333.3 VAR
- C = (333.3 / (2π × 50 × 400^2)) × 1e6 = (333.3 / 50,265,482) × 1e6 ≈ 6.63 μF

---

### 12.10 Inrush 125% Rule (NEC Article 430)

**Formula:** I_design = I_rated × 1.25

**Variables:**
- I_design = Design current for motor circuit (A) — used for breaker/conductor sizing
- I_rated = Rated full-load motor current (A)
- 1.25 = NEC Article 430 safety factor for continuous motor loads

**Standard:** NEC Article 430

**Source:** Referenced in `printPowerReport.js` standards footer; applied in `TotalPowerController` motor detection

**Worked Example:**
- Motor rated current = 40 A
- Design current = 40 × 1.25 = 50 A → select 50 A breaker minimum

---

### 12.11 Phase Imbalance Percentage

**Formula:** Imbalance% = ((I_max - I_min) / I_avg) × 100

**Variables:**
- I_max = Highest phase current magnitude (A)
- I_min = Lowest phase current magnitude (A)
- I_avg = (I_A + I_B + I_C) / 3 = Average phase current (A)
- I_x = VA_x / V_phase = Phase x current approximation (A) for VA-based calculation

**Standard:** IEC 60034-26 (motor voltage imbalance); NEMA MG-1

**Source:** `PhaseBalanceController.php` lines 449–461

**Thresholds:** < 10% = balanced; 10–20% = warning; > 20% = critical

**Worked Example:**
- Phase A: 1,380 VA → I_A = 1380/230 = 6.0 A
- Phase B: 920 VA → I_B = 920/230 = 4.0 A
- Phase C: 1,150 VA → I_C = 1150/230 = 5.0 A
- I_avg = (6.0 + 4.0 + 5.0) / 3 = 5.0 A
- Imbalance = (6.0 - 4.0) / 5.0 × 100 = 40% → CRITICAL

---

### 12.12 Phase Current Phasor (IEC Phasor Method)

**Formula per phase:**
- |I| = S / V_phase  (current magnitude, A)
- φ = arccos(PF)  (lag angle, rad)
- θ_I = θ_V - φ  (current angle = voltage angle minus power factor angle)
- I_real = |I| × cos(θ_I)
- I_imag = |I| × sin(θ_I)

Phase angles: θ_V(A) = 0 rad, θ_V(B) = 2π/3 rad (120°), θ_V(C) = 4π/3 rad (240°)

**Variables:**
- S = Load apparent power on this phase (VA)
- V_phase = 230 V (single-phase voltage)
- PF = Load power factor
- θ_V = Phase voltage angle (rad)
- I_real, I_imag = Real and imaginary components of current phasor

**Standard:** IEC 60909 (short-circuit phasor analysis); positive-sequence ABC convention

**Source:** `PhaseBalanceController.php` lines 294–312

**Worked Example:**
- Phase A load: 2,300 VA, PF = 0.85, θ_V = 0
- |I| = 2300/230 = 10 A; φ = arccos(0.85) = 31.79° = 0.5548 rad
- θ_I = 0 - 0.5548 = -0.5548 rad
- I_real = 10 × cos(-0.5548) = 10 × 0.85 = 8.5 A
- I_imag = 10 × sin(-0.5548) = 10 × (-0.527) = -5.27 A

---

### 12.13 Neutral Current (Phasor Sum)

**Formula:**
- I_N_real = I_A_real + I_B_real + I_C_real
- I_N_imag = I_A_imag + I_B_imag + I_C_imag
- |I_N| = sqrt(I_N_real^2 + I_N_imag^2)

**Variables:**
- I_A_real, I_A_imag = Real and imaginary components of Phase A total current
- I_B_real, I_B_imag = Phase B components (at 120° offset)
- I_C_real, I_C_imag = Phase C components (at 240° offset)
- |I_N| = Neutral current magnitude (A)

**Standard:** IEC 60909, Kirchhoff's Current Law

**Source:** `PhaseBalanceController.php` lines 315–323

**Worked Example (perfectly balanced, PF=1):**
- 10 A per phase, θ_V(A)=0, θ_V(B)=120°, θ_V(C)=240°
- I_A = (10, 0); I_B = (-5, 8.66); I_C = (-5, -8.66)
- Sum: real = 0, imag = 0 → |I_N| = 0 A (perfect balance)

---

### 12.14 Solar Capacity Estimate from Roof Area

**Formula:** P_cap (W) = A × 0.17 × 1,000 × 0.75

**Variables:**
- A = Roof/building area (m²)
- 0.17 = Roof coverage ratio (17% usable for PV panels)
- 1,000 W/m² = STC irradiance (Standard Test Conditions)
- 0.75 = Conservative performance ratio for capacity sizing

**Standard:** IEC 61853 (PV performance), STC per IEC 60904-3

**Source:** `SolarIrradianceService.php` lines 33–49: `estimateCapacityW()`

**Worked Example:**
- Building area = 500 m²
- P_cap = 500 × 0.17 × 1000 × 0.75 = 63,750 W ≈ 63.75 kW

---

### 12.15 PSH-Based Sinusoidal Peak Solar Output

**Formula:** P_peak_W = P_capacity × PSH × PR × π / (2 × T_daylight)

Where hourly output at time t (hours after sunrise):
  P(t) = P_peak_W × sin(π × t / T_daylight)

**Variables:**
- P_capacity = Installed panel capacity (W)
- PSH = Peak Sun Hours for latitude/month (kWh/m²/day, from lookup table)
- PR = Performance Ratio = 0.80 (inverter + wiring + temperature losses)
- T_daylight = Sunset - Sunrise (hours)
- t = Hours elapsed since sunrise (0 ≤ t ≤ T_daylight)

**Derivation:** Peak is set so that the integral of sin(πt/D) from 0 to D equals D×2/π, making total daily energy = P_capacity × PSH × PR.

**Standard:** NASA POWER methodology; IEC 61724 (PV system performance monitoring)

**Source:** `SolarIrradianceService.php` lines 107–122

**Worked Example (lat=30°, July, 10 kW system):**
- PSH = 7.5 kWh/m²/day (from PSH_TABLE at 30°, month=7)
- Sunrise ≈ 5.5h, Sunset ≈ 18.5h → T_daylight = 13h
- P_peak = 10,000 × 7.5 × 0.80 × π / (2 × 13) = 10,000 × 7.5 × 0.80 × 0.1208 = 7,243 W
- At solar noon (t=6.5h): P = 7,243 × sin(π × 6.5/13) = 7,243 × sin(π/2) = 7,243 W

---

### 12.16 Solar Output from NASA GHI

**Formula:** P_output (W) = (GHI / 1,000) × P_capacity_W × PR

**Variables:**
- GHI = Global Horizontal Irradiance (W/m²) from NASA POWER API (ALLSKY_SFC_SW_DWN)
- 1,000 = STC reference irradiance (W/m²)
- P_capacity_W = Installed system capacity (W)
- PR = Performance Ratio = 0.80

**Standard:** IEC 61724, NASA POWER satellite data (±3% accuracy)

**Source:** `SolarIrradianceService.php` lines 341–350

**Worked Example:**
- GHI at noon = 850 W/m², P_capacity = 20,000 W, PR = 0.80
- P_output = (850/1000) × 20,000 × 0.80 = 13,600 W

---

### 12.17 Sunrise and Sunset (Spencer's Equation)

**Formula:**
- δ = 23.45 × sin(360/365 × (DOY - 81)) degrees  (solar declination)
- cos(H_A) = -tan(lat) × tan(δ)
- H_A_degrees = arccos(cos(H_A)) in degrees
- Sunrise = 12.0 - H_A_degrees / 15.0  (decimal hours)
- Sunset = 12.0 + H_A_degrees / 15.0

**Variables:**
- δ = Solar declination (degrees)
- DOY = Day of year (15th of each month: Jan=15, Feb=46, Mar=74, ...)
- lat = Latitude (degrees, negative = southern hemisphere)
- H_A = Hour angle at sunrise/sunset (degrees; divided by 15 to convert to hours)
- Special: cos(H_A) > 1 → polar night (no sunrise); cos(H_A) < -1 → midnight sun

**Standard:** Spencer (1971) solar geometry; ASHRAE

**Source:** `SolarIrradianceService.php` lines 131–150

**Worked Example (lat=30°N, July 15, DOY=196):**
- δ = 23.45 × sin(360/365 × (196-81)) = 23.45 × sin(113.4°) = 23.45 × 0.917 = 21.5°
- cos(H_A) = -tan(30°) × tan(21.5°) = -0.577 × 0.394 = -0.227
- H_A = arccos(-0.227) = 103.1° → H_A/15 = 6.87 hours
- Sunrise = 12.0 - 6.87 = 5.13 (≈05:08); Sunset = 12.0 + 6.87 = 18.87 (≈18:52)

---

### 12.18 PSH Latitude Interpolation

**Formula:** PSH(lat, month) = PSH_lower + frac × (PSH_upper - PSH_lower)

Where:
- lower = floor(abs(lat) / 10) × 10 (lower latitude band, multiples of 10°)
- upper = min(lower + 10, 60)
- frac = (abs(lat) - lower) / 10.0
- Southern hemisphere: flip month index by +6 modulo 12

**Variables:**
- lat = Latitude in degrees
- PSH_lower, PSH_upper = PSH values from lookup table at bounding latitude bands
- frac = Fractional position between bands (0.0–1.0)

**Source:** `SolarIrradianceService.php` lines 169–189

**Worked Example (lat=35°N, June):**
- lower=30, upper=40, frac=(35-30)/10=0.5
- PSH_30_June = 7.7; PSH_40_June = 8.0
- PSH = 7.7 + 0.5 × (8.0 - 7.7) = 7.7 + 0.15 = 7.85 kWh/m²/day

---

### 12.19 Battery State of Charge Update

**Charging:**
  SOC_new = SOC_old + (P_charge_W / 1000) × η_one_way / C_usable_kWh

**Discharging:**
  SOC_new = SOC_old - (P_discharge_W / 1000) / η_one_way / C_usable_kWh

Where η_one_way = sqrt(RTE)  (one-way efficiency from round-trip)

**Variables:**
- SOC = State of Charge (0.0–1.0)
- P_charge_W = Charging power (W)
- P_discharge_W = Discharge power (W)
- η_one_way = sqrt(RTE) = one-way efficiency (e.g. sqrt(0.95) = 0.9747 for LFP)
- C_usable_kWh = Usable capacity (kWh) = nominal × DoD × age_factor
- RTE = Round-trip efficiency (e.g. 0.95)

**Standard:** IEC 62619, IEEE 1562

**Source:** `SourceDispatchService.php` lines 211, 272–273

**Worked Example:**
- C_usable = 10 kWh, RTE = 0.95 → η = sqrt(0.95) = 0.9747
- Charge 5,000 W for 1 hour: SOC gain = (5.0 × 0.9747) / 10.0 = 0.4874

---

### 12.20 Battery Round-Trip Efficiency Loss

**Formula:** E_loss = E_charged - E_discharged = sum(P_charge × dt) - sum(P_discharge × dt)

**Variables:**
- E_loss = Energy lost in battery (kWh per day)
- All power in kWh (W/1000)

**Source:** `SourceDispatchService.php` line 421: `battery_efficiency_loss_kwh = charged_kwh - discharged_kwh`

**Worked Example:**
- Charged: 8 kWh, Discharged: 7.4 kWh → Loss = 0.6 kWh (7.5% loss, consistent with RTE=0.93)

---

### 12.21 Generator Flat-Rate Cost (Rated Load)

**Formula:** C_rated = (fuel_price × F_rated) / P_rated_kW

**Variables:**
- C_rated = Cost per kWh at full rated load ($/kWh)
- fuel_price = Fuel cost per liter ($/L)
- F_rated = Fuel consumption at rated load (L/h)
- P_rated_kW = Generator rated power (kW) = power_W / 1000

**Standard:** ISO 8528-5 (generator fuel consumption)

**Source:** `GeneratorLine.php` lines 26–37: `getCostPerKwhAttribute()`

**Worked Example:**
- fuel_price = $0.90/L, F_rated = 15 L/h, P_rated = 50 kW
- C_rated = (0.90 × 15) / 50 = $0.27/kWh

---

### 12.22 Generator Affine Fuel Model F(P)

**Formula:** F(P) = F_0 + (F_rated - F_0) × (P / P_rated)

**Variables:**
- F(P) = Fuel consumption at load P (L/h)
- F_0 = No-load fuel consumption (L/h); default = 0.30 × F_rated if not set
- F_rated = Full-load fuel consumption (L/h)
- P = Actual output power (kW)
- P_rated = Rated generator power (kW)

**Standard:** ISO 8528-10, CIBSE Guide L (generator part-load performance)

**Source:** `GeneratorLine.php` lines 45–62: `fuelAtLoadKw()`

**Worked Example:**
- F_0 = 4.5 L/h (30% of 15), F_rated = 15 L/h, P_rated = 50 kW, P = 25 kW
- F(25) = 4.5 + (15 - 4.5) × (25/50) = 4.5 + 10.5 × 0.5 = 4.5 + 5.25 = 9.75 L/h
- Compare to flat rate: 15 L/h × (25/50) = 7.5 L/h → affine model shows 30% more at half load

---

### 12.23 Generator Marginal Cost

**Formula:** MC = fuel_price × (F_rated - F_0) / P_rated

**Variables:**
- MC = Marginal cost ($/kWh) — cost of serving 1 additional kW
- fuel_price, F_rated, F_0, P_rated as above

**Note:** MC < average cost because no-load overhead is already sunk.

**Standard:** ISO 8528, economic dispatch theory

**Source:** `GeneratorLine.php` lines 81–97: `marginalCostPerKwh()`

**Worked Example:**
- fuel_price = $0.90/L, F_rated = 15 L/h, F_0 = 4.5 L/h, P_rated = 50 kW
- MC = 0.90 × (15 - 4.5) / 50 = 0.90 × 10.5 / 50 = $0.189/kWh
- vs flat-rate $0.27/kWh → marginal is 30% cheaper

---

### 12.24 Cost Signal Ladder

**Formula (per hour):**
1. If solar_surplus > 0: cost = 0.00 $/kWh
2. Else if battery_remaining_kw > 0: cost = 0.01 $/kWh
3. Else if utility available and off-peak: cost = tariff $/kWh
4. Else if utility available and peak hours: cost = peak_tariff $/kWh
5. Else if generator available: cost = marginal_cost $/kWh
6. Else: cost = 999.00 $/kWh (load shedding sentinel)

**Variables:**
- solar_surplus = max(0, solar_generated_kW - load_kW)
- battery_remaining_kw = unused discharge headroom from dispatch
- tariff = utility base rate ($/kWh)
- peak_tariff = utility peak-period rate ($/kWh)
- marginal_cost = generator marginal cost ($/kWh)

**Source:** `CostSignalService.php` lines 90–129

---

### 12.25 Solar Cost Discount Formula (Optimizer)

**Formula:** cost_signal[h] = max(0, base_cost × (1.0 - 0.9 × solar_frac))

Where: solar_frac = solar_W[h] / max(solar_W)

**Variables:**
- base_cost = tariff or peak_tariff ($/kWh) for hour h
- solar_frac = Normalized solar output (0.0 = no sun, 1.0 = peak sun)
- 0.9 = Maximum discount fraction (90% off at peak solar)

**Source:** `ProjectController.php` lines 361–364: `buildCostSignal()`

**Worked Example:**
- tariff = $0.15/kWh, solar at hour noon = 0.95 of max
- cost = max(0, 0.15 × (1.0 - 0.9 × 0.95)) = 0.15 × (1.0 - 0.855) = 0.15 × 0.145 = $0.0218/kWh

---

### 12.26 Window Cost Sum (Scheduler)

**Formula:** cost_window(start, runHours) = sum_{h=start}^{start+runHours-1} cost_signal[h]

**Variables:**
- start = Window start hour (integer, 0–23)
- runHours = Required run duration (integer hours)
- cost_signal[h] = Marginal cost at hour h ($/kWh)

**Source:** `ProjectController.php` lines 400–408: exhaustive window search in `pickBestIntervals()`

---

### 12.27 Annual Energy ×365

**Formula:** E_annual = E_daily × 365

For each source (solar, grid, generator, battery):
- E_daily = sum_{h=0}^{23} P[h] / 1000  (kWh, summing hourly watts)

**Variables:**
- E_annual = Annual energy (kWh/year)
- E_daily = Simulated daily energy from representative Monday in the selected month

**Source:** `FinancialAnalysisService.php` lines 75–79

**Worked Example:**
- Daily solar used = 32 kWh → Annual = 32 × 365 = 11,680 kWh/year

---

### 12.28 Weighted Average Tariff

**Formula:** weighted_tariff = (off_peak_hours × tariff + peak_hours × peak_tariff) / 24

**Variables:**
- off_peak_hours = 24 - (peak_hours_end - peak_hours_start)
- peak_hours = peak_hours_end - peak_hours_start
- tariff = off-peak rate ($/kWh)
- peak_tariff = peak rate ($/kWh)

**Source:** `FinancialAnalysisService.php` lines 89–95

**Worked Example:**
- tariff = $0.10, peak_tariff = $0.22, peak hours = 8 (08:00–16:00)
- off_peak = 16h, peak = 8h
- weighted = (16 × 0.10 + 8 × 0.22) / 24 = (1.60 + 1.76) / 24 = $0.140/kWh

---

### 12.29 Annual Savings Formula

**Formula:** annual_savings = total_cost_without - (grid_cost + gen_cost + maintenance_cost)

Where:
- total_cost_without = baseline_grid_cost + baseline_gen_cost (no solar/battery simulation)
- grid_cost = grid_kwh_annual × weighted_tariff
- gen_cost = sum_{h} F(P_gen[h]) × fuel_price × 365 (affine model, hourly)
- maintenance_cost = sum of solar_system.annual_maintenance_cost

**Standard:** Economic dispatch comparison methodology

**Source:** `FinancialAnalysisService.php` lines 156–159

---

### 12.30 Simple Payback

**Formula:** payback_years = total_investment / annual_savings

**Variables:**
- total_investment = solar_installation_cost + battery_purchase_cost ($)
- annual_savings = as above ($)

**Source:** `FinancialAnalysisService.php` lines 167–169

**Worked Example:**
- Investment = $45,000, Annual savings = $6,200 → Payback = 45,000 / 6,200 = 7.3 years

---

### 12.31 LCOE (Levelized Cost of Energy)

**Formula:** LCOE = (C_install + C_maintenance × N) / (E_solar_annual × N)

**Variables:**
- LCOE = Levelized Cost of Energy ($/kWh)
- C_install = Solar installation cost ($)
- C_maintenance = Annual maintenance cost ($/year)
- N = Projection horizon = 25 years
- E_solar_annual = Annual solar generation (kWh)

**Standard:** NREL / IEA definition of LCOE

**Source:** `FinancialAnalysisService.php` lines 173–177

**Worked Example:**
- Install = $30,000, Maintenance = $500/yr, Solar = 15,000 kWh/yr, N=25
- LCOE = (30,000 + 500×25) / (15,000×25) = 42,500 / 375,000 = $0.1133/kWh

---

### 12.32 Panel Degradation Factor

**Formula:** degrad_factor(year) = (1 - 0.005)^year = 0.995^year

**Variables:**
- degrad_factor = Remaining output fraction after `year` years
- 0.005 = 0.5% annual degradation rate (crystalline silicon industry standard)
- year = Years since installation (1–25)

**Standard:** IEC 61215 (PV module qualification); typical crystalline silicon degradation

**Source:** `FinancialAnalysisService.php` line 23: `PANEL_DEGRADATION = 0.005`; line 202

**Worked Example:**
- Year 10: factor = 0.995^10 = 0.9511 → 4.89% total degradation
- Year 25: factor = 0.995^25 = 0.8822 → 11.78% total degradation

---

### 12.33 Battery Replacement Year

**Formula:** years_to_eol = max(0, rated_cycle_life / 365 - age_years)
            replacement_year = ceil(years_to_eol)

**Variables:**
- rated_cycle_life = Chemistry-rated cycle count (e.g. 4,000 for LFP)
- 365 = Assumed 1 full cycle per day
- age_years = Battery age from installation_date to today

**Source:** `FinancialAnalysisService.php` lines 185–191

**Worked Example:**
- LFP battery, 4,000 cycles, age = 2.5 years
- years_to_eol = max(0, 4000/365 - 2.5) = max(0, 10.96 - 2.5) = 8.46 → replacement_year = 9

---

### 12.34 Cumulative 25-Year Net Benefit

**Formula (per year y):**
  year_net(y) = annual_savings × (0.995^y) - battery_replacement_cost(y)
  cumulative(y) = -total_investment + sum_{i=1}^{y} year_net(i)
  payback_year = first y where cumulative(y) > 0

**Source:** `FinancialAnalysisService.php` lines 199–209

---

### 12.35 Solar Self-Consumption

**Formula:** self_consumption% = (solar_used_kwh + battery_charged_solar_kwh) / solar_generated_kwh × 100

**Variables:**
- solar_used_kwh = Solar energy consumed directly by load (kWh)
- battery_charged_solar_kwh = Solar energy stored in batteries (kWh)
- solar_generated_kwh = Total solar generation (kWh)

**Source:** `SourceDispatchService.php` lines 379–381

**Worked Example:**
- Solar generated = 30 kWh, directly used = 20 kWh, stored = 7 kWh
- Self-consumption = (20 + 7) / 30 × 100 = 90%

---

### 12.36 Backup Hours Formulas

**Runtime at max discharge:**
  T_max = C_usable_kWh / P_max_discharge_kW

**Runtime at average discharge:**
  T_avg = C_usable_kWh / (P_max_discharge_kW / 2)

**Current runtime (from current SOC):**
  T_current = C_available_kWh / (P_max_discharge_kW / 2)

**Variables:**
- C_usable_kWh = Usable capacity = nominal × DoD × age_factor
- C_available_kWh = C_usable_kWh × current_SOC
- P_max_discharge_kW = nominal_capacity_kWh × C_rate_discharge

**Source:** `Battery.php` lines 110–127

**Worked Example:**
- Nominal = 20 kWh, DoD = 0.90, age_factor = 0.95 → C_usable = 17.1 kWh
- C_rate_discharge = 1C → P_max = 20 × 1.0 = 20 kW
- T_max = 17.1 / 20 = 0.855 h; T_avg = 17.1 / 10 = 1.71 h

---

## 13. ALL ALGORITHMS

---

### 13.1 Greedy First-Fit Decreasing (Phase Balance)

**Name:** Greedy FFD Phase Balancer

**Purpose:** Assign single-phase loads (rooms, floor blocks, socket groups) to three phases (A, B, C) to minimize VA imbalance.

**Step-by-step pseudocode:**
```
1. Collect all 1-phase load BLOCKS (room, floor-own, socket panel) each with VA total
2. Sort blocks descending by VA (largest first — FFD heuristic)
3. Initialize phase_va = {A: 0, B: 0, C: 0}
4. For each block in sorted order:
   a. ph = argmin(phase_va)  -- pick the phase with least VA
   b. Assign block to ph
   c. phase_va[ph] += block.va
5. Record optimal_phase per block
6. Write back optimal_phase to room/floor entities in DB
```

**Time Complexity:** O(n log n) for sort + O(n) for greedy assignment = O(n log n)

**Why chosen:** FFD is optimal for bin-packing in practice; simple; produces ≤ 11/9 OPT + 6/9 waste bound.

**Source:** `PhaseBalanceController.php` lines 217–233 (`buildingReport()`)

---

### 13.2 Exhaustive Window Search (No-Split Scheduling)

**Name:** Cheapest Consecutive Window

**Purpose:** Find the single cheapest contiguous block of `runHours` within the allowed window [winStart, winEnd] of the cost signal.

**Step-by-step pseudocode:**
```
1. best_cost = INF; best_start = winStart
2. For s = winStart to (winEnd - runHours):
   a. cost = sum(costSignal[s .. s+runHours-1])
   b. If cost < best_cost:
        best_cost = cost; best_start = s
3. Return interval [{start: best_start, end: best_start + runHours}]
```

**Time Complexity:** O(W × H) where W = window width, H = runHours; effectively O(24×24) = O(576) constant

**Why chosen:** Guarantees global optimum for contiguous scheduling; window is bounded to 24 hours so exhaustive is fast.

**Source:** `ProjectController.php` lines 399–408: `pickBestIntervals()` maxSplits=0 branch

---

### 13.3 Greedy Cheapest Hours (Split Scheduling)

**Name:** Cheapest Individual Hours Picker

**Purpose:** When splits are allowed (`max_interruptions > 0`), pick the `runHours` cheapest individual hours from the window, then group consecutive hours into intervals.

**Step-by-step pseudocode:**
```
1. Build hour_costs = {h: costSignal[h] for h in [winStart, winEnd)}
2. Sort hour_costs ascending by cost
3. chosen = first runHours hours from sorted list
4. Sort chosen ascending (re-order by hour number)
5. Group consecutive hours into intervals:
   intervals = []
   group_start = chosen[0]
   for i in 1..len(chosen):
       if chosen[i] != chosen[i-1] + 1:
           intervals.append({start: group_start, end: chosen[i-1]+1})
           group_start = chosen[i]
   intervals.append({start: group_start, end: last+1})
6. Return intervals
```

**Time Complexity:** O(W log W) for sort + O(H) for grouping

**Why chosen:** Optimal when splits are allowed (selects globally cheapest individual hours); grouping minimizes number of interval records.

**Source:** `ProjectController.php` lines 411–433: `pickBestIntervals()` maxSplits>0 branch

---

### 13.4 Improvement Guard

**Name:** Cost Improvement Gate

**Purpose:** Before saving a new schedule, verify the new intervals actually improve on the current schedule. Prevents unnecessary DB writes and regressions.

**Step-by-step pseudocode:**
```
1. current_best_cost = INF
2. For each existing interval [ivS, ivE] in component.usage_time_intervals:
   a. For s2 = ivS to (ivE - runHours):
        cost = sum(costSignal[s2 .. s2+runHours-1])
        current_best_cost = min(current_best_cost, cost)
3. new_cost = sum(costSignal[h] for h in new_intervals)
4. If new_cost >= current_best_cost - 0.0001:
   SKIP (no improvement)
5. Else:
   savings = current_best_cost - new_cost
   SAVE new intervals; record savings
```

**Time Complexity:** O(K × W × H) where K = number of existing intervals (typically ≤ 5)

**Why chosen:** Idempotent operation; prevents infinite re-optimization loops; preserves user overrides.

**Source:** `ProjectController.php` lines 271–295

---

### 13.5 Seven-Step Dispatch (SourceDispatchService)

**Name:** Greedy Per-Hour Optimal Dispatch

**Purpose:** For each of 24 hours, allocate load across solar, batteries, utility, and generator in cost-priority order.

**Step-by-step pseudocode (per hour h):**
```
Step 1: PAIRED SOLAR CHARGING
  For each solar system (by capacity ratio):
    Allocate system's proportional solar output to its paired battery banks
    Battery absorbs up to its C-rate; surplus returns to shared pool

Step 2: SOLAR COVERS LOAD
  solar_used[h] = min(shared_solar, demand)
  surplus = shared_solar - solar_used[h]
  remaining = demand - solar_used[h]

Step 3: SURPLUS SOLAR CHARGES UNPAIRED BANKS
  Distribute surplus proportionally among unpaired batteries (by headroom)

Step 4: BATTERY DISCHARGE
  max_discharge = min(sum of C-rate limits, sum of current_SOC × 1000)
  discharge = min(remaining, max_discharge)
  Distribute proportionally by current SOC across all banks
  Update SOC; guard against SOC < 0

Step 5: UTILITY
  utility_used[h] = min(utilityCapW, remaining)

Step 6: GENERATOR
  gen_used[h] = min(genCapW, remaining)

Step 7: OPPORTUNISTIC GEN CHARGING
  If generator already running AND spare capacity below 85% loading:
    Charge batteries with spare AC capacity (apply INV_EFF = 0.95)
    Generator AC draw increases by charging load
```

**Time Complexity:** O(24 × B) where B = number of battery banks; O(24 × B × S) where S = solar systems

**Why chosen:** Greedy per-hour is computationally efficient and near-optimal for deterministic day-ahead profiles; full MPC would require forecast uncertainty handling beyond current scope.

**Source:** `SourceDispatchService.php` lines 185–346

---

### 13.6 Paired Battery Charging (Solar System)

**Name:** Proportional Paired Bank Charger

**Purpose:** Distribute a solar system's output proportionally among its paired batteries based on available headroom (unfilled capacity).

**Step-by-step pseudocode:**
```
1. total_head = sum of (usable - current) for all batteries in bank
2. If total_head == 0: return (all full)
3. For each battery b in bank:
   a. head_b = usable_b - current_b
   b. share = system_output_W × (head_b / total_head)
   c. actual = min(share, C_rate_charge_b × 1000)
   d. current_b += (actual / 1000) × one_way_eff_b
   e. total_charged += actual
4. Surplus = system_output - total_charged (returned to shared pool)
```

**Source:** `SourceDispatchService.php` lines 193–217

---

### 13.7 Pool Battery Charging (Unpaired/Surplus Solar)

**Name:** Pool Charger (Shared Surplus)

**Purpose:** Distribute shared solar surplus to unpaired battery banks proportionally by headroom.

**Algorithm:** Same as 13.6 but operates on `unpairedIds` list and uses shared surplus as input.

**Source:** `SourceDispatchService.php` lines 227–248

---

### 13.8 Opportunistic Generator Charging

**Name:** Spare-Capacity Gen Charger

**Purpose:** When generator is already running for load, use spare capacity below 85% loading to charge batteries, improving generator fuel efficiency.

**Step-by-step pseudocode:**
```
1. gen_max_for_charging = genCapW × 0.85
2. spare_gen_W = max(0, gen_max_for_charging - gen_used[h])
3. If spare_gen_W == 0: skip
4. total_head = sum of (usable - current) × 1000 for all batteries
5. max_gen_charge_ac = min(spare_gen_W, total_C_rate × 1000, total_head / INV_EFF)
6. For each battery b:
   a. ac_W = max_gen_charge_ac × (head_b / total_head)
   b. ac_W = min(ac_W, C_rate_b × 1000)
   c. current_b += (ac_W × INV_EFF / 1000) × one_way_eff_b
7. gen_used[h] += total_ac_drawn  (generator burns extra fuel for charging)
```

**Note:** Generator does NOT start solely to charge (only if already running). INV_EFF = 0.95 (AC-to-DC conversion).

**Source:** `SourceDispatchService.php` lines 299–338

---

### 13.9 PSH Latitude Interpolation

**Name:** Bilinear PSH Lookup

**Purpose:** Interpolate Peak Sun Hours for any latitude from a 7-row × 12-column table at 10° latitude increments.

**Algorithm:** Linear interpolation between bounding latitude bands, with southern-hemisphere season flip (+6 months).

**Source:** `SolarIrradianceService.php` lines 169–189. See Section 12.18 for full formula.

---

### 13.10 Southern Hemisphere Season Flip

**Name:** Month Index Flip

**Purpose:** Correct PSH lookup for southern hemisphere by shifting months by 6 (January in SH = July solar conditions).

**Algorithm:** `mi = (mi + 6) % 12` applied when lat < 0.

**Source:** `SolarIrradianceService.php` line 175

---

### 13.11 Group-Max Dedup

**Name:** Group-Max Component Deduplicator

**Purpose:** When multiple components share the same `group_name` at the same entity level, keep only the one with the highest VA. This models mutually exclusive loads (e.g. "backup AC unit" — only one runs at a time).

**Step-by-step pseudocode:**
```
1. groups = {}
2. For each component c:
   If c.group_name is NULL:
       ungrouped.append(c)
   Else:
       key = entity_id + '|' + group_name
       If key not in groups OR c.VA > groups[key].VA:
           groups[key] = c
3. active = ungrouped + values(groups)
```

**Source:** `ScheduleController.php` lines 278–289, `PhaseBalanceController.php` lines 327–347, `FinancialAnalysisService.php` lines 328–340

---

### 13.12 Hour Membership Test (Midpoint Sampling)

**Name:** Hour Active Check

**Purpose:** Determine whether a component's usage interval covers a given clock hour, using midpoint sampling to handle boundary conditions correctly.

**Algorithm:**
```
mid = h + 0.5  (midpoint of hour h)
active = (mid >= start AND mid < end)
         OR (end > 24 AND (mid+24) >= start AND (mid+24) < end)  -- overnight wrap
```

**Source:** `ScheduleController.php` lines 313–318, `FinancialAnalysisService.php` lines 358–365

---

### 13.13 Battery Depletion Tracking

**Name:** First-Depletion Sentinel

**Purpose:** Record the first hour any battery bank reaches zero SOC during discharge.

**Algorithm:**
```
battDepletedAt = null
For each hour h:
   After discharge step:
   For each battery b:
       If b.current < 0:
           b.current = 0
           If battDepletedAt is null:
               battDepletedAt = h
```

**Source:** `SourceDispatchService.php` lines 274–279

---

### 13.14 Phase Current Phasor Sum (Per-Phase)

**Name:** Complex Phasor Aggregator

**Purpose:** Compute the total current phasor for all loads on a single phase, accounting for individual power factors.

**Algorithm:** See Section 12.12 (Phase Current Phasor formula). Sum real and imaginary components across all loads on the phase.

**Source:** `PhaseBalanceController.php` lines 294–311

---

### 13.15 Cost Signal Classification

**Name:** Hour Classifier

**Purpose:** Classify each hour as free (solar surplus), cheap (battery), expensive (peak/generator/unmet), or normal (base tariff).

**Algorithm:**
```
baseline = tariff ?? gen_marginal_cost ?? 999.0
For each hour h:
   If cost_signal[h] == 0:      → free_hours
   Elif cost_signal[h] < baseline: → cheap_hours
   Elif cost_signal[h] > baseline: → expensive_hours
   Else: (standard tariff hour, unlisted)
```

**Source:** `CostSignalService.php` lines 131–152

---

## 14. CONSTANTS & CONFIGURATION VALUES

All hardcoded constants from every file.

| Constant | Value | File | Meaning | Standard |
|---|---|---|---|---|
| PANEL_DEGRADATION | 0.005 (0.5%/yr) | FinancialAnalysisService.php | Annual PV panel output degradation | IEC 61215 crystalline Si |
| PROJECTION_YEARS | 25 | FinancialAnalysisService.php | Financial analysis horizon (years) | Industry standard |
| DF_PROJECT | 0.7 | FinancialAnalysisService.php, ScheduleController.php, ValidationController.php | Project-level diversity factor | IEC 60364-8-1 |
| DEFAULT_WORK_DAYS | Mon–Fri | FinancialAnalysisService.php, ScheduleController.php | Default project work days | — |
| ROOF_COVERAGE_RATIO | 0.17 | SolarIrradianceService.php | Usable roof fraction for PV | IEC sizing practice |
| STC_IRRADIANCE_W | 1000 W/m² | SolarIrradianceService.php | Standard Test Conditions irradiance | IEC 60904-3 |
| CAPACITY_ESTIMATE_PR | 0.75 | SolarIrradianceService.php | Conservative PR for capacity sizing | — |
| PERFORMANCE_RATIO | 0.80 | SolarIrradianceService.php | Operational PR for hourly output | IEC 61724 |
| NASA_TIMEOUT_SEC | 10 s | SolarIrradianceService.php | HTTP timeout for NASA API calls | — |
| NASA_NULL_VALUE | -999 | SolarIrradianceService.php | NASA POWER missing-data sentinel | NASA POWER API spec |
| CACHE_DAYS | 30 days | SolarIrradianceService.php | How long NASA GHI data is cached | — |
| REPRESENTATIVE_DAY | 15 | SolarIrradianceService.php | Day-of-month used as solar representative | Statistical midpoint |
| GEN_OPTIMAL_MAX_LOAD | 0.85 (85%) | SourceDispatchService.php | Max generator loading for opportunistic charging | ISO 8528 optimal band |
| INV_EFF | 0.95 | SourceDispatchService.php | Inverter/rectifier one-way efficiency (AC→DC) | Industry standard |
| BATTERY_DEGRADATION_COST | 0.01 $/kWh | CostSignalService.php | Near-zero cost assigned to battery hours | Economic proxy |
| UNMET_COST | 999.0 $/kWh | CostSignalService.php | Load-shedding sentinel cost | — |
| VOLT | 230 V | PhaseBalanceController.php | Single-phase nominal voltage | IEC 60038 |
| WARN_PCT | 10% | PhaseBalanceController.php | Phase imbalance warning threshold | IEC 60034-26 |
| CRIT_PCT | 20% | PhaseBalanceController.php | Phase imbalance critical threshold | IEC 60034-26 |
| SOCKET_ASSUMED_PF | 0.95 | PhaseBalanceController.php | PF assumed for socket outlets | BS 7671 practice |
| PHASE_ANGLE_A | 0.0 rad | PhaseBalanceController.php | Phase A voltage angle | IEC positive-sequence ABC |
| PHASE_ANGLE_B | 2π/3 rad (120°) | PhaseBalanceController.php | Phase B voltage angle | IEC positive-sequence ABC |
| PHASE_ANGLE_C | 4π/3 rad (240°) | PhaseBalanceController.php | Phase C voltage angle | IEC positive-sequence ABC |
| TOLERANCE_PCT | 0.1% | ValidationController.php | Pass/fail tolerance for validation comparison | — |
| TARGET_PF | 0.95 | ValidationController.php, ValidationReferenceService.php | PF correction target | PENRA requirement |
| CAP_STEP_KVAR | 0.5 kVAR | ValidationController.php | Standard capacitor bank step size | IEC 60831 |
| VOLTAGE_LL | 400 V | ValidationController.php | Line-to-line voltage (3-phase) | IEC 60038 |
| FREQUENCY | 50 Hz | ValidationController.php | System frequency | IEC/European standard |
| OUTLET_VA | 200 VA | SocketDemandService.php | Standard outlet rating | BS 7671 / IEC |
| CF threshold 1 | 50 kVA | SocketDemandService.php | Small building CF boundary (CF=1.00) | CIBSE Guide C |
| CF threshold 2 | 250 kVA | SocketDemandService.php | Medium building CF boundary (CF=0.92) | CIBSE Guide C |
| CF large | 0.85 | SocketDemandService.php | Large building coincidence factor (>250 kVA) | CIBSE Guide C |
| DF_ROOM_TO_FLOOR (office) | 0.85 | DiversityFactorService.php | Office room-to-floor diversity | IEC 60364-8-1 |
| DF_FLOOR_TO_BUILDING (office) | 0.80 | DiversityFactorService.php | Office floor-to-building diversity | IEC 60364-8-1 |
| DEFAULT_BUILDING_DFS room_to_floor | 0.90 | DiversityFactorService.php | Generic IEC default | IEC 60364-8-1 |
| DEFAULT_BUILDING_DFS floor_to_building | 0.80 | DiversityFactorService.php | Generic IEC default | IEC 60364-8-1 |
| DEFAULT_ROOM_DF | 0.80 | DiversityFactorService.php | Generic IEC room coincidence | IEC 60364-8-1 |
| PHASE_IMBALANCE threshold healthy | ≥90% age_factor | Battery.php | Battery health "good" threshold | — |
| PHASE_IMBALANCE threshold fair | ≥80% age_factor | Battery.php | Battery health "fair" threshold | — |
| PHASE_IMBALANCE threshold degraded | ≥70% age_factor | Battery.php | Battery health "degraded"; ≤70% = replace | IEC 62619 |
| no_load_fuel default | 0.30 × F_rated | GeneratorLine.php | Default no-load fuel if not configured | ISO 8528-10 |
| SVG_W | 900 px | SingleLineDiagramPage.jsx | SVG canvas width | — |
| SRC_Y | 60 px | SingleLineDiagramPage.jsx | Y-position of source nodes | — |
| BUS_Y | 200 px | SingleLineDiagramPage.jsx | Y-position of main busbar | — |
| BLD_Y | 340 px | SingleLineDiagramPage.jsx | Y-position of building panels | — |
| NODE_R | 32 px | SingleLineDiagramPage.jsx | Source circle radius | — |
| BUS_H | 6 px | SingleLineDiagramPage.jsx | Busbar height | — |

---

## 15. SECURITY & AUTHORIZATION

---

### 15.1 Google OAuth 2.0 Flow (Full Step by Step)

The flow is implemented in `GoogleController.php`:

**Step 1 — Frontend initiates login:**
Frontend calls GET `/auth/google/redirect?origin=http://localhost:5173`. The `origin` parameter tells the backend where to redirect after authentication.

**Step 2 — Session stores origin:**
```php
session(['auth_origin' => $request->input('origin')]);
```

**Step 3 — Redirect to Google:**
```php
Socialite::driver('google')->redirect();
```
Laravel Socialite constructs the Google OAuth2 authorization URL with `client_id`, `redirect_uri`, `scope=openid email profile`, and `response_type=code`. The user is redirected to Google's consent screen.

**Step 4 — Google redirects back:**
Google redirects to GET `/auth/google/callback?code=AUTH_CODE&state=...`. This is the registered redirect URI.

**Step 5 — Exchange code for tokens:**
```php
$googleUser = Socialite::driver('google')->user();
```
Socialite internally POSTs to `https://oauth2.googleapis.com/token` with the auth code, receiving `access_token` and `refresh_token`.

**Step 6 — Upsert user record:**
```php
$user = User::updateOrCreate(
    ['email' => $googleUser->getEmail()],
    ['name' => $googleUser->getName(), 'google_id' => $googleUser->getId(),
     'avatar' => $googleUser->getAvatar(), 'google_token' => $googleUser->token,
     'google_refresh_token' => $googleUser->refreshToken,
     'email_verified_at' => now(), 'password' => null]
);
```
- If the email exists: updates name, tokens, avatar.
- If new: creates a new User row.
- `password` is set to NULL (Google-only accounts have no password).

**Step 7 — Issue Sanctum token:**
```php
$token = $user->createToken('google-auth')->plainTextToken;
```
Laravel Sanctum creates a `personal_access_tokens` record linked to the user. The plain-text token is returned once (never stored, hashed in DB).

**Step 8 — Redirect to frontend with token:**
```php
return redirect("{$frontendUrl}/auth/callback?token={$token}");
```
Frontend at `/auth/callback` reads the token from the URL, stores it in localStorage, then navigates to `/dashboard`.

**Source:** `GoogleController.php`, `AuthCallbackPage.jsx`

---

### 15.2 Admin Login Flow

**Step 1:** POST `/api/admin/login` with `{ email, password }` (no auth middleware — public route).

**Step 2:** `AdminController::login()` finds user by email, verifies password with `Hash::check()`.

**Step 3:** Checks `$user->is_admin === true`. If not admin: returns 403.

**Step 4:** Issues Sanctum token: `$user->createToken('admin-login')->plainTextToken`.

**Step 5:** Returns `{ token, user }`. Frontend stores token and navigates to `/admin/dashboard`.

**Source:** `AdminController.php`, `AdminLoginPage.jsx`

---

### 15.3 Sanctum Token Issuance

All protected API routes use `middleware('auth:sanctum')`. The client must send:
```
Authorization: Bearer {token}
```
Sanctum hashes the token and looks up the `personal_access_tokens` table. The user is resolved and attached to `$request->user()`. Tokens do not expire by default (no `expiration` set in `sanctum.php`).

---

### 15.4 ProjectPolicy Rules

**File:** `ProjectPolicy.php`

| Method | Condition | Used For |
|---|---|---|
| `view` | `userRole(userId) !== null` | Any access (owner, admin, main, or normal member) |
| `update` | `userRole(userId)` in `['admin', 'main']` | Write operations: add components, configure sources |
| `delete` | `project->user_id === user->id` | Only the project owner (admin) can delete |

**Usage:** `$this->authorize('view', $project)` in controllers enforces these rules automatically.

---

### 15.5 userRole() Method

**File:** `Project.php` lines 66–75

```php
public function userRole(int $userId): ?string
{
    if ((int) $this->user_id === $userId) return 'admin';
    $member = $this->projectUsers()->where('user_id', $userId)->first();
    return $member?->role;
}
```

Returns: `'admin'` (owner), `'main'` (editor member), `'normal'` (read-only member), or `null` (no access).

---

### 15.6 Role Hierarchy

| Role | Who | Can View | Can Edit | Can Delete Project | Can Manage Members |
|---|---|---|---|---|---|
| `admin` | Project owner (user_id matches) | Yes | Yes | Yes | Yes |
| `main` | Named member with role='main' | Yes | Yes | No | No |
| `normal` (viewer) | Named member with role='normal' | Yes | No | No | No |
| (none) | Not owner, not a member | No | No | No | No |

Most endpoints check: `in_array($project->userRole($userId), ['admin', 'main'])` for write operations.

---

### 15.7 AdminMiddleware

**File:** `AdminMiddleware.php`

```php
if (! $request->user() || ! $request->user()->is_admin) {
    return response()->json(['message' => 'Unauthorized. Admin access required.'], 403);
}
```

Applied to the `admin` middleware group: GET `/api/admin/users`, PUT `/api/admin/users/{user}`, DELETE `/api/admin/users/{user}`.

---

### 15.8 SecurityHeaders Middleware

**File:** `SecurityHeaders.php`

Every API response has these headers added:

| Header | Value | Purpose |
|---|---|---|
| `X-Content-Type-Options` | `nosniff` | Prevent MIME-type sniffing attacks |
| `X-Frame-Options` | `DENY` | Prevent clickjacking via iframe embedding |
| `X-XSS-Protection` | `1; mode=block` | Legacy browser XSS filter |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Limit referrer leakage on cross-origin requests |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=()` | Deny access to sensitive browser APIs |

**Source:** `SecurityHeaders.php` lines 15–19

---

### 15.9 FormRequest Validation Protection

All store/update endpoints use Laravel FormRequest classes (`StoreProjectRequest`, `UpdateProjectRequest`, etc.) with typed rules. Examples from route definitions:
- `'month' => 'required|integer|min:1|max:12'` — prevents invalid month values
- `'components.*.model_type' => 'required|in:project,building,floor,room'` — enum validation
- `'phase' => 'nullable|in:A,B,C'` — strict enum for phase assignment

---

### 15.10 SQL Injection Prevention

**Approach:** All database queries use the Eloquent ORM or Laravel Query Builder with parameterized bindings. The comment in `api.php` line 47 explicitly states: "SQL injection: Prevented by Eloquent ORM — no raw queries in this project." The single use of `selectRaw()` in `SocketDemandService.php` only contains fixed column names with no user input.

---

## 16. BACKUP SYSTEM

---

### 16.1 ServerBackupController — Endpoints

**File:** `ServerBackupController.php`

**GET `/api/projects/{project}/server-backups`** — `index()`
- Auth: admin or main role required
- Query params: `entity_type` (optional filter), `entity_id` (optional filter)
- Returns: `{ data: [{id, entity_type, entity_id, entity_name, created_at}] }` — metadata only, no payload

**GET `/api/server-backups/{backup}/data`** — `show()`
- Auth: admin or main role (via backup→project relationship)
- Returns: `{ data: <full backup JSON> }` — complete backup payload decoded from DB

**DELETE `/api/server-backups/{backup}`** — `destroy()`
- Auth: admin or main role required
- Deletes the `server_backups` record; returns `{ message: 'Server backup deleted.' }`

---

### 16.2 AutoBackupCommand

No dedicated `AutoBackupCommand` class was found in the codebase. The Project model has `auto_backup_interval` and `last_auto_backup_at` fields, suggesting a planned feature. The backup mechanism is user-triggered via `saveProjectToServer()`.

---

### 16.3 BackupExporter — Format and Contents

**File:** `BackupExporter.php`

The exporter serializes the full hierarchy as nested JSON. Format version = "1.0".

**Project export structure:**
```json
{
  "version": "1.0",
  "exported_at": "ISO8601 timestamp",
  "project": {
    "name": "string",
    "building_type": "string|null",
    "solar_power": "number|null",
    "generator_power": "number|null",
    "utility_lines": [{name, power, phases}],
    "generator_lines": [{name, power, phases}],
    "components": [{component_name, power, phases, power_factor, quantity, group_name, priority, needs_socket, usage_season, usage_day_type, usage_time_intervals}],
    "sockets": [{phase_type, power, quantity}],
    "buildings": [<building objects>],
    "solar_systems": [{name, capacity_kw, is_active, notes}],
    "batteries": [{name, chemistry, nominal_voltage_v, capacity_ah_per_unit, quantity, series_count, parallel_count, installation_date, depth_of_discharge, round_trip_efficiency, c_rate_charge, c_rate_discharge, rated_cycle_life, current_soc, is_active, notes, solar_system_name}]
  }
}
```

**Building export:** `{name, area, utility_lines, generator_lines, components, sockets, floors: [<floor objects>]}`

**Floor export:** `{name, area, utility_lines, generator_lines, components, sockets, rooms: [<room objects>]}`

**Room export:** `{name, area, utility_lines, generator_lines, components, sockets}`

**Battery pairing:** `solar_system_name` stores the paired solar system name (string) so restore can re-link by name match after solar systems are imported first.

---

### 16.4 ProjectBackupController — JSON Export/Import

**File:** `ProjectBackupController.php`

**Export endpoints:** GET `/api/projects/{project}/backup`, `/api/buildings/{building}/backup`, `/api/floors/{floor}/backup`, `/api/rooms/{room}/backup` — delegates to `BackupExporter`, returns JSON directly for browser download.

**Import (`restore()`) logic:**
1. Validates `{ data: array, overwrite: bool? }`
2. Checks for read-only conflict (user has `normal` role on existing project with same name → 403)
3. If project with same name exists and `overwrite=false` → returns `{ conflict: true }` (HTTP 409)
4. If `overwrite=true`: deletes existing project first
5. Wraps everything in `DB::transaction()`
6. Import order: project → utility/generator lines → components → solar_systems → batteries (order matters: batteries reference solar_systems by name) → buildings → floors → rooms
7. Component types resolved/created by name in bulk (one query per level)
8. On any failure: `DB::rollBack()`, returns 500

**Save-to-server endpoints:** POST `/{entity}/{id}/save-backup` — creates a `ServerBackup` record in the DB with the full JSON payload.

**Duplicate endpoints:** POST `/projects/{project}/buildings/{building}/duplicate` — exports building JSON, appends " (Copy)" to name, re-imports into same project.

---

### 16.5 BackupChoiceModal — UI Flow

**File:** `BackupChoiceModal.jsx`

The modal is triggered when the user clicks a "Backup" button in any entity page. It presents two options:

**Option 1 — Download to Computer:**
- Calls `onDownload()` (which triggers `downloadJson.js` to create a Blob URL and click a hidden `<a>` tag)
- Closes modal immediately

**Option 2 — Save to Server:**
- Calls `onSaveToServer()` (POST to `/api/{entity}/{id}/save-backup`)
- Shows spinner while saving; shows success confirmation on completion
- Error message displayed inline on failure
- After success, shows "Close" button; modal can also be dismissed by clicking backdrop

---

## 17. VALIDATION REFERENCE SYSTEM

---

### 17.1 ValidationController — Every Endpoint

**File:** `ValidationController.php`

**Single endpoint:** GET `/api/validation/case-study` (auth:sanctum, no admin required)

**Response structure:**
```json
{
  "project_id": integer,
  "project_name": "Validation Reference — 2-Floor Office",
  "overall_status": "PASS" | "FAIL",
  "tolerance_used": "0.1%",
  "system_result": { ...production calc results... },
  "reference_answer": { ...independent reference results... },
  "comparison": [
    {
      "field": "Total Apparent Power (VA)",
      "system_key": "total_va",
      "reference_key": "total_va",
      "unit": "VA",
      "system_value": float,
      "reference_value": float,
      "difference_percent": float,
      "status": "PASS" | "FAIL" | "SKIP"
    },
    ...
  ]
}
```

**Comparison fields validated:**
1. Total Apparent Power (VA) — tolerance 0.1%
2. Total Active Power (W) — tolerance 0.1%
3. Total Reactive Power (kVAR) — tolerance 0.1%
4. Max Apparent Power (VA) — tolerance 0.1%
5. System Power Factor — tolerance 0.1%
6. Socket Demand (VA) — tolerance 0.1%
7. Socket Connected (VA) — tolerance 0.1%
8. Component Active Power (W, diversified) — tolerance 0.1%
9. PF Correction Recommended — boolean exact match

---

### 17.2 ValidationReferenceService — All Standards Referenced

**File:** `ValidationReferenceService.php`

The service is a hand-computed independent implementation. Standards and formulas used:

| Calculation | Standard | Value Used |
|---|---|---|
| Apparent power S = sqrt(P²+Q²) | IEC 60364-8-1 | — |
| Active power P = S × PF | IEC 60364-8-1 | — |
| Reactive power Q = P × tan(arccos(PF)) | IEC 60364-8-1 | — |
| Office room-to-floor DF | IEC 60364-8-1 / CIBSE | 0.85 |
| Office floor-to-building DF | IEC 60364-8-1 / CIBSE | 0.80 |
| Open-office room coincidence | CIBSE Guide C | 0.80 |
| Meeting-room coincidence | CIBSE Guide C | 0.70 |
| Project-level DF | IEC 60364-8-1 | 0.70 |
| Socket outlet VA | IEC / BS 7671 | 200 VA/outlet |
| Socket tiers (100%/75%/40%) | BS 7671 / IEC | 10/10/rest |
| Coincidence factor small (<50 kVA) | CIBSE | 1.00 |
| Coincidence factor medium (50–250 kVA) | CIBSE | 0.92 |
| Coincidence factor large (>250 kVA) | CIBSE | 0.85 |
| PF correction target | PENRA | 0.95 |
| PF correction threshold | PENRA | 0.85 |
| Capacitor bank step | IEC 60831 | 0.5 kVAR |
| Capacitor delta connection | IEC 60831 | C = Q/(2πfV²) × 1e6 |
| System frequency | IEC / European | 50 Hz |
| Line-to-line voltage | IEC 60038 | 400 V |

**Case study inputs (2-Floor Office):**
- Ground Floor, Open Office: 2,000 VA LED lights (PF=1.00), 3,000 VA computers (PF=0.85), 5,000 VA AC (PF=0.90), 20 socket outlets
- First Floor, Meeting Room: 800 VA LED lights (PF=1.00), 500 VA projector (PF=0.95), 8 socket outlets
- DF chain for open office: 0.80 × 0.85 × 0.80 × 0.70 = 0.3808
- DF chain for meeting room: 0.70 × 0.85 × 0.80 × 0.70 = 0.3332

---

### 17.3 ValidationPage — What It Shows

**File:** `ValidationPage.jsx`

The validation page displays:
1. **Overall status banner** — green "PASS" or red "FAIL" with the tolerance (0.1%)
2. **System result panel** — key values from the production calculation (total VA, W, kVAR, PF, socket demand)
3. **Reference answer panel** — independently computed expected values
4. **Comparison table** — row per field: Field Name | System Value | Reference Value | Difference % | Status (PASS/FAIL badge)
5. **Breakdown accordion** — expands to show per-component contributions with DF values, socket tier breakdown formula

The page calls GET `/api/validation/case-study` on load. If the validation project is missing (not seeded), it shows the seeder command instruction.


---

## 18. NAVIGATION & HIERARCHY

---

### 18.1 NavigationController — Breadcrumb Endpoints

**File:** `NavigationController.php`

The NavigationController resolves human-readable URL path segments (project/building/floor/room names) to DB entities and returns breadcrumb data.

**GET `/api/nav/{projectName}`**
- Finds project by `name` (URL-decoded) owned by or shared with authenticated user (`->firstOrFail()`)
- Returns: `{ project, buildings: [{...withCount('floors')}] }`

**GET `/api/nav/{projectName}/{buildingName}`**
- Finds project then building by name within that project
- Returns: `{ project, building, floors: [{...withCount('rooms')}] }`

**GET `/api/nav/{projectName}/{buildingName}/{floorName}`**
- Finds project, building, then floor
- Returns: `{ project, building, floor, rooms: [all rooms ordered by created_at desc] }`

**GET `/api/nav/{projectName}/{buildingName}/{floorName}/{roomName}`**
- Full four-level path resolution
- Returns: `{ project, building, floor, room, components: [{...with('componentType')}] }`

**Path resolution:** All segments use `urldecode()` to handle URL-encoded spaces and special characters. Each level uses `->firstOrFail()` which throws a ModelNotFoundException (HTTP 404) if not found.

---

### 18.2 ProjectSidebar — All Nav Links, Active State

**File:** `ProjectSidebar.jsx`

**Top-level feature links (always visible above the tree):**

| Link Label | Route | Active Detection | Color Accent |
|---|---|---|---|
| Project name | `/projects/{projectId}` | Not on building/feature page | indigo |
| Phase Balance | `/projects/{projectId}/phase-balance` | `pathname.endsWith('/phase-balance')` | violet |
| Load Schedule | `/projects/{projectId}/schedule` | `pathname.endsWith('/schedule')` | amber |
| Financial Analysis | `/projects/{projectId}/financial` | `pathname.endsWith('/financial')` | emerald |
| Single-Line Diagram | `/projects/{projectId}/single-line` | `pathname.endsWith('/single-line')` | cyan |

**Hierarchy tree behavior:**
- Buildings loaded on mount via GET `/api/projects/{id}/buildings`
- Floors loaded lazily on chevron click: GET `/api/buildings/{id}/floors`
- Rooms loaded lazily on chevron click: GET `/api/floors/{id}/rooms`
- Active entity highlighted in indigo based on URL params (`buildingId`, `floorId`, `roomId`)
- Auto-expands tree path when navigating from external link (via `useEffect` on `activeBuildingId`/`activeFloorId`)

---

### 18.3 Full URL Structure Tree

```
/ → /dashboard (redirect)

/login                                      LoginPage (public)
/auth/callback                              AuthCallbackPage (receives OAuth token)
/admin/login                                AdminLoginPage
/admin/dashboard                            AdminDashboardPage

/dashboard                                  DashboardPage (project list)
/validation                                 ValidationPage
/defense-prep                               DefensePrepPage

/projects/:projectId                        ProjectPage (overview + total power)
/projects/:projectId/schedule               LoadSchedulePage
/projects/:projectId/phase-balance          PhaseBalancePage
/projects/:projectId/financial              FinancialPage
/projects/:projectId/single-line            SingleLineDiagramPage

/projects/:projectId/buildings/:buildingId                      BuildingPage
/projects/:projectId/buildings/:buildingId/floors/:floorId      FloorPage
/projects/:projectId/buildings/:buildingId/floors/:floorId/rooms/:roomId   NewRoomPage
```

All routes under `/projects/:projectId/...` are wrapped in `ProjectLayout` (renders `ProjectSidebar` + main content). All are protected by `ProtectedRoute` (redirects to `/login` if no user).

---

## 19. LOAD SCHEDULING SYSTEM

---

### 19.1 Component Scheduling Fields (with Migration Source)

All scheduling fields are on all four component tables: `project_components`, `building_components`, `floor_components`, `room_components`.

| Field | Type | Meaning |
|---|---|---|
| `load_flexibility` | enum: fixed, shiftable, curtailable | Whether load can be time-shifted |
| `required_run_hours` | integer nullable | Hours/day the shiftable component must run |
| `earliest_start_hour` | integer nullable (0-23) | Earliest allowed start hour |
| `latest_end_hour` | integer nullable (1-24) | Latest allowed end hour |
| `min_continuous_run` | integer nullable | Minimum consecutive run hours |
| `max_interruptions` | integer nullable | Max schedule splits allowed (0 = no splits) |
| `usage_time_intervals` | JSON array nullable | Actual schedule: `[{start:'HH:MM', end:'HH:MM'}]` |
| `usage_season` | enum: all, summer, winter, spring, autumn | Active season filter |
| `usage_day_type` | enum: all, weekday, weekend | Day-type filter |
| `priority` | enum: normal, essential, critical | Critical = always-on (DF=1.0, 24 hrs) |

Source: component migrations in `database/migrations/`

---

### 19.2 LoadSchedulePage — All Tabs, All UI Flows

**File:** `LoadSchedulePage.jsx`

**Tab 1 — Load Profile:**
- Stacked area chart: Max Load (indigo, no diversity) vs Optimized Load (emerald, with diversity factors)
- Reactive power Q (amber line, kVAR) overlay
- Day selector buttons (Mon–Sun); defaults to current day of week
- Month selector dropdown (Jan–Dec)
- Stat cards: Peak kW, Daily kWh, Solar kWh, Battery kWh

**Tab 2 — Sources:**
- Solar generation filled area (yellow/amber)
- Utility capacity line (blue dashed)
- Generator capacity line (orange dashed)
- Sunrise/sunset reference lines
- Data source badge: NASA POWER (green), Static Lookup (amber), NASA Fallback (orange)
- PSH value displayed; peak sun hours for the selected month/location

**Tab 3 — Combined Dispatch:**
- Stacked area: Solar Used (yellow), Battery Discharge (purple), Utility Used (blue), Generator Used (orange)
- Total demand as gray line overlay
- Battery charge bands: Solar Charging (faint yellow), Generator Charging (faint orange)
- Battery SOC trace (violet line, right y-axis 0-100%)
- Unmet demand (red area, only visible if load exceeds all sources)
- Hover tooltip: all source values + SOC percentage for that hour

**Tab 4 — Shiftable Loads:**
- Lists all `load_flexibility = shiftable` components project-wide
- Columns: Name, Location, Power (W), Required Run Hours, Window [earliest-latest]
- Status: green checkmark if already assigned, grey circle if unassigned
- 24-cell cost signal row per component: color-coded green/yellow/amber/red/crimson
- "Optimize All" button: submits all components to POST `/api/projects/{id}/optimize-shiftable`
- After optimization: shows savings (old cost → new cost → savings %) per component
- New intervals displayed as time range tags (e.g. "08:00–12:00", "14:00–15:00")

---

### 19.3 buildCostSignal() — Full Algorithm Both Layers

**File:** `ProjectController.php` lines 321–372

Layer 1 — Monetary cost:
1. Find first utility line with `tariff_per_kwh` set
2. Find first generator line with fuel cost > 0 and consumption > 0
3. `base_monetary = tariff ?? gen_cost_per_kwh ?? null`
4. Peak window: if `peak_tariff` and `peak_hours_end > peak_hours_start`: hours in [peak_start, peak_end) use peak_tariff

Layer 2 — Solar irradiance discount:
1. If `location_lat` is set: fetch normalized 1 kW solar profile for the month
2. `max_solar = max(solar_W)` (normalize to 0–1 range)
3. `solar_frac[h] = solar_W[h] / max_solar`

Combined signal computation:
```
If base_monetary is not null:
    base_h = peak_tariff if in-peak else tariff ?? gen_cost
    signal[h] = max(0, base_h × (1.0 - 0.9 × solar_frac[h]))
    // Up to 90% discount at peak solar
Else:
    signal[h] = round(1.0 - solar_frac[h], 6)
    // Pure irradiance guide: 0 at peak sun, 1 at night
```

This ensures loads strongly prefer solar hours, and among tariff periods, heavily discounts the sunniest hours.

---

### 19.4 pickBestIntervals() — Continuous and Split Paths

**File:** `ProjectController.php` lines 385–433

Guards: returns `null` if `runHours <= 0`, window invalid, or `runHours > window_width`.

**No-split path (max_interruptions == 0):**
```
best_cost = INF; best_start = winStart
For s = winStart to winEnd-runHours:
    cost = sum(costSignal[s .. s+runHours-1])
    if cost < best_cost: best_cost=cost; best_start=s
return [{start: best_start, end: best_start+runHours}]
```

**Split path (max_interruptions > 0):**
```
hour_costs = {h: costSignal[h] for h in [winStart, winEnd)}
Sort by cost ascending; take first runHours hours
Sort chosen hours ascending
Group consecutive hours into intervals
return intervals
```

---

### 19.5 Improvement Guard — Full Logic

**File:** `ProjectController.php` lines 268–295

```
current_best_cost = PHP_FLOAT_MAX
For each existing interval [ivS, ivE]:
    For s2 = ivS to ivE-runHours:
        c2 = sum(costSignal[s2 .. s2+runHours-1])
        current_best_cost = min(current_best_cost, c2)

new_cost = sum(costSignal[h] for all hours h in new_intervals)

If current_best_cost < PHP_FLOAT_MAX AND new_cost >= current_best_cost - 0.0001:
    continue  // no improvement; skip

savings = current_best_cost - new_cost
comp.usage_time_intervals = new_intervals
comp.save()
updated[] += {id, savings, savings_percent}
```

The 0.0001 epsilon prevents false improvements from floating-point rounding. Preserves user-defined schedules that are already optimal.

---

### 19.6 ScheduleController — Full Response Structure

**Endpoint:** GET `/api/projects/{project}/schedule?month=1-12&day=1-31`

```json
{
  "month": 7,
  "month_name": "July",
  "day": 15,
  "location": {"lat": 30.0, "lng": 31.0, "name": "Cairo"},
  "sunrise_hour": 5.13,
  "sunset_hour": 18.87,
  "peak_sun_hours": 7.85,
  "solar_capacity_w": 63750.0,
  "solar_data_source": "nasa_power",
  "utility_capacity_va": 50000,
  "generator_capacity_va": 30000,
  "solar": [0,0,0,0,0,0,1200,3400,5600,...,0],
  "solar_systems": [{"id":1,"name":"Roof South","capacity_kw":20.0,"is_active":true}],
  "battery_summary": {
    "bank_count": 2,
    "total_nominal_kwh": 40.0,
    "total_usable_kwh": 34.2,
    "average_age_years": 1.5,
    "chemistries": ["lithium_lfp"]
  },
  "cost_rates": {
    "tariff_per_kwh": 0.12,
    "peak_tariff_per_kwh": 0.22,
    "peak_hours_start": 14,
    "peak_hours_end": 20,
    "generator_cost_per_kwh": 0.27,
    "generator_rated_kw": 50.0,
    "generator_rated_lph": 15.0,
    "generator_no_load_lph": 4.5,
    "fuel_cost_per_liter": 0.90,
    "currency_symbol": "$"
  },
  "days": {
    "monday": {
      "load_max": [<24 float watts>],
      "load_optimized": [<24 float watts>],
      "hourly_kvar": [<24 float kvar>],
      "dispatch_max": {
        "solar_used": [24 floats],
        "battery_charged_solar": [24 floats],
        "battery_charged_gen": [24 floats],
        "battery_charged": [24 floats],
        "battery_discharged": [24 floats],
        "battery_remaining_capacity_kw": [24 floats],
        "utility_used": [24 floats],
        "generator_used": [24 floats],
        "unmet": [24 floats],
        "unmet_hours": [],
        "max_unmet_kw": 0.0,
        "battery_depleted_at_hour": null,
        "battery_soc_trace": [24 floats 0-1],
        "stats": {
          "solar_hours": int, "solar_kwh": float,
          "solar_generated_kwh": float, "solar_self_consumption": float,
          "battery_discharged_kwh": float, "battery_charged_kwh": float,
          "battery_efficiency_loss_kwh": float, "final_soc": float,
          "utility_hours": int, "utility_kwh": float,
          "generator_hours": int, "generator_kwh": float,
          "generator_efficiency_avg": float,
          "unmet_kwh": float, "total_load_kwh": float
        }
      },
      "dispatch_optimized": { ...same structure... }
    },
    "tuesday": { ... },
    "wednesday": { ... },
    "thursday": { ... },
    "friday": { ... },
    "saturday": { ... },
    "sunday": { ... }
  }
}
```

---

## 20. PHASE BALANCE SYSTEM

---

### 20.1 PhaseBalancePage — UI Sections

**File:** `PhaseBalancePage.jsx`

The page is organized per building:

**Section 1 — Actual Distribution:**
Shows the current real phase assignment (from saved `phase` fields on components):
- Phase A / B / C: VA load, current (A), percentage of total
- Unassigned VA (loads without a phase assignment)
- Status badge: Balanced / Warning / Critical
- Imbalance % and neutral current (A)
- Method note: "phasor_sum_with_pf"

**Section 2 — Optimal Distribution (Greedy FFD Result):**
Shows what the phase balance would be after applying the greedy algorithm:
- Same metrics as actual but for the simulated optimal assignment
- "Apply Optimal Assignment" button: POST to `/api/buildings/{id}/apply-optimal-phase`

**Section 3 — Block Assignments Table:**
Lists each load block (room, floor-own, socket group) with:
- Block type (room / floor_own / socket / building)
- Block name and floor
- VA amount
- Optimal phase assignment (A/B/C)

**Section 4 — Floor/Room Breakdown:**
Expandable floor accordion showing each room with:
- Actual phase (from DB)
- Optimal phase (from greedy result)
- VA on that phase
- "Reassign" button per room: POST to `/api/rooms/{id}/assign-phase`

---

### 20.2 PhaseBalanceController — Every Method

**File:** `PhaseBalanceController.php`

**`project(Request, Project): JsonResponse`**
- Auth: userRole check (any role)
- Loads all buildings with components eagerly
- Maps each building through `buildingReport()`
- Returns: `{ buildings: [<building report objects>] }`

**`building(Request, Building): JsonResponse`**
- Auth: userRole check (any role)
- Returns single `buildingReport()` result

**`floor(Request, Floor): JsonResponse`**
- Auth: userRole check
- Collects 1-phase loads from floor own components + all room components
- Adds socket demand as a synthetic load block
- Runs simple greedy (no per-building-type block grouping)
- Returns standard `simpleResponse()` structure

**`assignRoom(Request, Room): JsonResponse`**
- Auth: admin or main role
- Validates: `phase` in {A, B, C, null}
- Updates all 1-phase components in the room: `components()->where('phases','1phase')->update(['phase' => $phase])`
- Returns: `{ message: 'Phase updated.' }`

**`applyOptimalBuilding(Request, Building): JsonResponse`**
- Auth: admin or main role
- Runs `buildingReport()` to get `block_assignments`
- For each block assignment: updates component phases in DB by block type (room, floor_own, building)
- Socket blocks are virtual (no DB record) — skipped
- Returns: `{ message: 'Optimal phase assignment applied.' }`

**`buildingReport(Building, SocketDemandService): array`** (private)
Core method:
1. Collects 1-phase load blocks (building own, floor own, socket panels, rooms)
2. Sorts by VA descending
3. Runs greedy FFD assignment
4. Computes phasor currents per phase using `computePhaseCurrent()`
5. Computes neutral current using `computeNeutralCurrent()`
6. Builds actual distribution from saved phase fields
7. Returns complete report with actual + optimal sections

**`computePhaseCurrent(loads, phaseAngle): array`** (private)
Returns `{ real, imag, magnitude }` — full complex phasor for all loads on one phase. See Section 12.12.

**`computeNeutralCurrent(phaseLoads): float`** (private)
Returns scalar `|I_N|` from phasor sum of three phase currents. See Section 12.13.

**`extract1ph(components): array`** (private)
Filters for 1-phase components, deduplicates by group_name (max-VA wins), returns `[{name, va, pf, phase}]`.

**`consensusPhase(components): ?string`** (private)
Returns 'A'|'B'|'C' if all 1-phase components agree on a phase, 'mixed' if they disagree, null if none assigned.

**`imbalanceStatus(va): array`** (private)
Computes `[status, imbalance_pct]` using VA/230 approximation for magnitude comparison.

**`extractSimple(components, &loads1ph, &va3ph, &count3ph): void`** (private)
Floor-level helper: splits components into 1-phase and 3-phase lists with group-max dedup.

**`simpleResponse(entityType, entityId, entityName, loads1ph, va3ph, count3ph): JsonResponse`** (private)
Formats the floor-level greedy result with recommendations text.

---

### 20.3 Complete Phasor Math for Neutral Current

**Phase angles (positive-sequence ABC):**
- Phase A: θ_V = 0 rad
- Phase B: θ_V = 2π/3 rad = 120°
- Phase C: θ_V = 4π/3 rad = 240°

**Per load on phase X:**
```
|I| = VA_load / 230        (current magnitude)
φ   = arccos(PF_load)      (lag angle)
θ_I = θ_V(X) - φ          (current angle lags voltage by φ)
I_real += |I| × cos(θ_I)
I_imag += |I| × sin(θ_I)
```

**Phase total phasor:**
```
I_X = (I_real_X, I_imag_X)
|I_X| = sqrt(I_real_X² + I_imag_X²)
```

**Neutral current (Kirchhoff):**
```
I_N_real = I_A_real + I_B_real + I_C_real
I_N_imag = I_A_imag + I_B_imag + I_C_imag
|I_N| = sqrt(I_N_real² + I_N_imag²)
```

**Perfectly balanced example (all phases 2,300 VA, PF=1.0):**
- Phase A: I=(10,0); Phase B: I=(-5,+8.66); Phase C: I=(-5,-8.66)
- Sum: (0,0) → |I_N| = 0 A

**Imbalanced example (A=4,600 VA, B=0 VA, C=0 VA, PF=1.0):**
- Phase A: I=(20,0); B: I=(0,0); C: I=(0,0)
- Sum: (20,0) → |I_N| = 20 A (full phase A current flows back in neutral)

---

### 20.4 Greedy FFD Per Building

The greedy algorithm processes each building independently:

1. Collect all load blocks from the building:
   - Building-level own 1-phase components (one block)
   - Per floor: floor-own 1-phase components (one block each)
   - Per floor: socket demand from `SocketDemandService::floorResult()` (one block each)
   - Per room: room total 1-phase VA (one block each)

2. Sort all blocks descending by VA (FFD order)

3. Initialize `optVa = {A:0, B:0, C:0}`, `phaseLoads = {A:[], B:[], C:[]}`

4. For each block in sorted order:
   - `ph = argmin(optVa)` — always assign to the lightest phase
   - `optVa[ph] += block.va`
   - Append all individual loads from this block to `phaseLoads[ph]`

5. Compute phasor currents and neutral current for the optimal assignment

This ensures the greedy respects the natural granularity of electrical panels: rooms are assigned as units (you don't split a room's loads across phases), which matches real installation practice.

---

## 21. FINANCIAL ANALYSIS SYSTEM

---

### 21.1 FinancialPage — UI Sections

**File:** `FinancialPage.jsx`

**Month Selector:**
Top-right dropdown (Jan–Dec). Changing month re-fetches from `/api/projects/{id}/financial-analysis?month=N`. Default: current month.

**Summary Cards Row:**
6 KPI cards: Annual Solar kWh | Grid kWh | Generator kWh | Annual Savings (with % badge) | Simple Payback (years) | LCOE ($/kWh)

**Energy Mix Pie Chart:**
Recharts PieChart showing Solar % / Grid % / Generator % of total annual load. Labels show percentages.

**Cost Comparison Section:**
Two columns side by side:
- "Without Solar/Battery": grid cost + generator cost
- "With Solar/Battery": grid cost + generator cost + maintenance
- Savings highlighted in green (or red if negative)
- Weighted tariff $/kWh and generator marginal $/kWh displayed

**Investment Summary:**
- Solar installation cost
- Battery purchase cost
- Total investment
- Simple payback years

**25-Year Projection Chart:**
Recharts LineChart:
- X-axis: Year 1–25
- Y-axis: Cumulative net cash flow ($)
- Line color: red below zero, green above zero (via gradient)
- Reference line at y=0 (break-even)
- Payback year dot with label (first year cumulative crosses zero)
- Hover tooltip: year + cumulative value formatted as currency

**Battery Replacement Markers:**
Vertical reference lines on the 25-year chart at years where battery replacement cost is due (diamond markers).

**Data Note:**
Footer shows which month was used, data source (NASA/static), and degradation assumption (0.5%/yr).

---

### 21.2 FinancialController — Response JSON Structure

**File:** `FinancialController.php`

**Endpoint:** GET `/api/projects/{project}/financial-analysis?month=1-12`

```json
{
  "annual_energy": {
    "solar_kwh": 11680.0,
    "grid_kwh": 28470.0,
    "generator_kwh": 2190.0,
    "battery_loss_kwh": 365.0,
    "total_load_kwh": 42340.0,
    "solar_percent": 27.6,
    "grid_percent": 67.2,
    "generator_percent": 5.2
  },
  "annual_costs": {
    "grid_cost": 3988.0,
    "generator_cost": 1971.0,
    "maintenance_cost": 500.0,
    "total_with_solar": 6459.0,
    "total_without_solar": 9840.0,
    "weighted_tariff": 0.1400,
    "generator_cost_per_kwh": 0.2700
  },
  "savings": {
    "annual_savings": 3381.0,
    "savings_percent": 34.4
  },
  "investment": {
    "solar_installation": 30000.0,
    "battery_purchase": 8000.0,
    "total_investment": 38000.0
  },
  "payback": {
    "simple_payback_years": 11.2,
    "lcoe_solar_per_kwh": 0.1133
  },
  "projection_25yr": {
    "cumulative_net_by_year": [-38000, -35300, -32450, ..., 28700],
    "payback_year": 14,
    "total_25yr_benefit": 28700.0
  },
  "currency_symbol": "$"
}
```

---

### 21.3 FinancialAnalysisService — Full Step-by-Step

**File:** `FinancialAnalysisService.php`

**STEP 1: Build load and solar profiles**
- `collectComponents(project)`: traverses full hierarchy, applies diversity chain, returns component list
- `buildHourlyW(components, 'monday', month)`: builds 24-element W array with group-max dedup, season/day filters, diversity factors
- Determines solar capacity: `existing` mode uses named systems sum (or legacy field); `max` mode uses area-based estimate
- Fetches `getHourlyOutputWatts()` for the month (day=15, PR=0.80)
- Applies utility (×0.8) and generator (×0.8) capacity derating

**STEP 2: Normal dispatch simulation**
- Calls `SourceDispatchService::dispatch()` with load, solar, utility cap, gen cap, active batteries, solar systems
- Extracts daily stats: solar_kwh, utility_kwh, generator_kwh, battery_loss_kwh, total_load_kwh
- Annualizes by ×365

**STEP 3: Weighted average tariff**
- Finds first utility line with tariff
- If peak tariff set: `weighted = (off_peak_h × tariff + peak_h × peak_tariff) / 24`
- Else: `weighted = tariff`

**STEP 4: Generator annual cost**
- Uses affine fuel model per hour: `sum(fuelAtLoadKw(kW) × fuel_price) × 365`
- More accurate than flat kWh rate (captures part-load inefficiency)

**STEP 5: Annual costs with solar**
- `grid_cost = grid_kwh_annual × weighted_tariff`
- `gen_cost = affine_daily × 365`
- `maintenance = sum(solar_system.annual_maintenance_cost)`
- `total_with_solar = grid_cost + gen_cost + maintenance`

**STEP 6: Baseline dispatch (no solar, no battery)**
- Re-runs dispatch with zero solar profile and `batteries=null`
- Computes baseline grid cost and gen cost using same affine model
- `total_without = baseline_grid_cost + baseline_gen_cost`

**STEP 7: Savings**
- `annual_savings = total_without - total_with_solar`
- `savings_pct = annual_savings / total_without × 100`

**STEP 8: Investment and payback**
- `total_investment = solar_installation_cost + battery_purchase_cost`
- `simple_payback = total_investment / annual_savings`
- `LCOE = (install + maintenance × 25) / (solar_kwh_annual × 25)`

**STEP 9: Battery replacement schedule**
- For each battery: `years_to_eol = rated_cycle_life/365 - age_years`
- `replacement_year = ceil(years_to_eol)` if within 1–25 year horizon
- Groups by year: `battReplByYear[year] += replacement_cost`

**STEP 10: 25-year cumulative projection**
- `running_net = -total_investment` (start negative)
- For year y = 1 to 25:
  - `degrad_factor = (1 - 0.005)^y`
  - `year_net = annual_savings × degrad_factor - battReplByYear[y]`
  - `running_net += year_net`
  - `cumulative[y] = running_net`
  - If `running_net > 0` for first time: `payback_year = y`

**STEP 11: Energy mix percentages**
- `solar_pct = solar_kwh_annual / total_load_kwh × 100`
- `grid_pct = grid_kwh_annual / total_load_kwh × 100`
- `gen_pct = gen_kwh_annual / total_load_kwh × 100`

---

## 22. POWER SOURCES (ALL 4)

---

### 22.1 Utility Lines

**Model:** `UtilityLine.php`

**Fillable fields:**
| Field | Type | Meaning |
|---|---|---|
| `name` | string | Display name (e.g. "Main Grid Feed") |
| `power` | float | Rated capacity (VA) |
| `phases` | string | '1phase' or '3phase' |
| `tariff_per_kwh` | float nullable | Off-peak electricity tariff (currency/kWh) |
| `peak_tariff_per_kwh` | float nullable | Peak-period tariff (currency/kWh) |
| `peak_hours_start` | integer nullable | Peak period start hour (0–23) |
| `peak_hours_end` | integer nullable | Peak period end hour (1–24) |

**Relationship:** polymorphic `lineable` — can belong to Project, Building, Floor, or Room.

**Controller CRUD:** `UtilityLineController.php`
- GET `/{entity}/{id}/utility-lines` — index: lists all lines for the entity
- POST `/{entity}/{id}/utility-lines` — store: creates new line
- PUT `/utility-lines/{line}` — update: validates `power>=0`, `tariff>=0`, `peak_tariff>=0`
- DELETE `/utility-lines/{line}` — destroy

**Tariff logic in dispatch:** Utility capacity used as hard cap in `SourceDispatchService`. Priority 5 in dispatch order (after solar, battery). In financial analysis, `weighted_tariff` is computed from off-peak/peak weighted average.

**Derating for dispatch:** `utilCapW = utility_lines.sum('power') × 0.8` — 20% derating for safety margin.

---

### 22.2 Generator Lines

**Model:** `GeneratorLine.php`

**Fillable fields:**
| Field | Type | Meaning |
|---|---|---|
| `name` | string | Display name |
| `power` | float | Rated capacity (VA) |
| `phases` | string | '1phase' or '3phase' |
| `fuel_cost_per_liter` | float nullable | Fuel price (currency/L) |
| `fuel_consumption_lph` | float nullable | Full-load fuel consumption (L/h) |
| `no_load_fuel_lph` | float nullable | No-load fuel consumption (L/h); default=0.30×rated |
| `min_load_pct` | integer nullable | Minimum recommended loading % |
| `optimal_load_pct` | integer nullable | Optimal loading % for best fuel efficiency |

**Computed property `cost_per_kwh`:**
`getCostPerKwhAttribute()`: `(fuel_cost × fuel_consumption_lph) / (power / 1000)` at rated load.

**Four model methods:**
1. `getCostPerKwhAttribute()`: flat-rate cost at 100% load — backward compatible accessor
2. `fuelAtLoadKw(kW)`: affine F(P) = F_0 + (F_rated - F_0) × P/P_rated — hourly fuel (L/h) at any kW
3. `costPerKwhAtLoad(kW)`: fuel cost per kWh at specific load: `fuel_price × fuelAtLoadKw(kW) / kW`
4. `marginalCostPerKwh()`: d(fuel_cost)/d(kW) = `fuel_price × (F_rated - F_0) / P_rated` — used in cost signal

**Relationship:** polymorphic `generable` — can belong to Project, Building, Floor, or Room.

**Controller:** `GeneratorLineController.php` — same CRUD pattern as UtilityLineController.

---

### 22.3 Solar Systems

**Model:** `SolarSystem.php`

**Fillable fields:**
| Field | Type | Meaning |
|---|---|---|
| `project_id` | integer | Owning project (project-scoped only) |
| `name` | string | Display name (e.g. "Roof South Array") |
| `capacity_kw` | float | Nameplate DC capacity (kW) |
| `is_active` | boolean | Whether included in dispatch |
| `notes` | text nullable | Free-form notes |
| `installation_cost` | float nullable | Total installed cost ($) |
| `annual_maintenance_cost` | float nullable | Annual O&M cost ($) |
| `panel_lifetime_years` | integer nullable | Expected panel life |

**Relationship:** `hasMany(Battery::class)` — batteries can be paired to a solar system.

**Controller:** `SolarSystemController.php`
- GET `/api/projects/{project}/solar-systems` — index
- POST `/api/projects/{project}/solar-systems` — store
- PUT `/api/solar-systems/{solarSystem}` — update
- DELETE `/api/solar-systems/{solarSystem}` — destroy

**solar_source modes (on Project model):**
- `'existing'`: uses named solar systems sum (or `existing_solar_power` legacy field)
- `'max'`: uses roof area estimate `estimateCapacityW(total_area_m2)`

**In dispatch:** Each solar system's proportional share = `capacity_kw / total_capacity_kw`. Its paired batteries get first access to its output before the shared pool.

---

### 22.4 Batteries

**Model:** `Battery.php`

**Fillable fields:**
| Field | Type | Meaning |
|---|---|---|
| `project_id` | integer | Owning project |
| `name` | string | Display name |
| `chemistry` | string | lead_acid_flooded, lead_acid_agm, lead_acid_gel, lithium_lfp, lithium_nmc |
| `nominal_voltage_v` | float | Cell string voltage (V) |
| `capacity_ah_per_unit` | float | Amp-hour rating per unit (Ah) |
| `quantity` | integer | Number of units |
| `series_count` | integer | Series string count |
| `parallel_count` | integer | Parallel string count |
| `installation_date` | date | For age calculation |
| `depth_of_discharge` | float | DoD fraction (0–1) |
| `round_trip_efficiency` | float | RTE fraction (0–1) |
| `c_rate_charge` | float | Charge rate (C) |
| `c_rate_discharge` | float | Discharge rate (C) |
| `rated_cycle_life` | integer | Manufacturer rated cycles |
| `current_soc` | float | Current state of charge (0–1) |
| `is_active` | boolean | Include in dispatch |
| `notes` | text nullable | Free-form notes |
| `solar_system_id` | integer nullable | Paired solar system (FK) |
| `purchase_cost` | float nullable | Purchase cost ($) |
| `replacement_cost` | float nullable | Future replacement cost ($) |

**Computed accessors (appended, not stored):**

| Accessor | Formula | Units |
|---|---|---|
| `age_years` | `installation_date.diffInDays(now) / 365.25` | years |
| `nominal_capacity_kwh` | `nominal_v × ah_per_unit × quantity / 1000` | kWh |
| `age_factor` | `max(0.70, 1 - age_years × degradation_per_year)` | dimensionless |
| `usable_capacity_kwh` | `nominal_kwh × DoD × age_factor` | kWh |
| `current_available_kwh` | `usable_kwh × current_soc` | kWh |
| `max_charge_power_kw` | `nominal_kwh × c_rate_charge` | kW |
| `max_discharge_power_kw` | `nominal_kwh × c_rate_discharge` | kW |
| `runtime_hours_at_max_discharge` | `usable_kwh / max_discharge_kw` | h |
| `runtime_hours_at_average_discharge` | `usable_kwh / (max_discharge_kw / 2)` | h |
| `current_runtime_hours` | `current_available_kwh / (max_discharge_kw / 2)` | h |
| `health_status` | age_factor ≥0.90→'good'; ≥0.80→'fair'; ≥0.70→'degraded'; else 'replace' | string |
| `remaining_calendar_life_years` | `max(0, calendar_life_years - age_years)` | years |

**Chemistry integration:** `BatteryChemistryService::getDefaults(chemistry)` provides `degradation_per_year` for age_factor and `calendar_life_years` for remaining life calculation.

**Controller:** `BatteryController.php`
- GET `/api/projects/{project}/batteries` — index with all computed properties
- POST `/api/projects/{project}/batteries` — store; chemistry preset auto-fills missing parameters
- GET `/api/projects/{project}/battery-runtime` — aggregated runtime summary
- GET `/api/batteries/{battery}` — show single battery
- PUT `/api/batteries/{battery}` — update
- DELETE `/api/batteries/{battery}` — destroy
- POST `/api/batteries/{battery}/reset-soc` — resets `current_soc` to `depth_of_discharge` (full charge)
- POST `/api/batteries/{battery}/runtime-at-load` — calculates runtime at a specific kW load
- GET `/api/battery-chemistry-defaults` — public endpoint returning all chemistry presets

---

## 23. EXPORT FEATURES

---

### 23.1 Excel Export — All 3 Sheets, All Columns

**File:** `exportToExcel.js`

Uses SheetJS (`xlsx` library). Called via `exportProjectExcel(projectId, projectName, powerData, engineerName)`.

**Sheet 1 — "Summary":**
Built by `buildSummarySheet()`. Array-of-arrays format (no header row styling on this sheet).

Rows:
- Title: "Power Profile — Electrical Load Analysis Export"
- Project, Engineer, Date, Standards
- DEMAND SUMMARY: Max Load (VA + W), Optimized Load (VA + W), Diversity Reduction %
- REACTIVE POWER: System PF, PF Status, Q (kVAR), Max Q, Line Current (A)
- PRIORITY BREAKDOWN table: Critical / Essential / Normal rows with Max kVA, Max kW, Opt kVA, Opt kW
- SOCKETS section (if sockets present): Connected (VA), Estimated Demand (VA)
- CAPACITOR BANK section (if correction needed): Required Bank (kVAR), Capacitor Value (μF), Line Current after, Reduction %

Column widths: [30, 18, 14, 14, 14] characters

**Sheet 2 — "Components":**
Built by `buildComponentsSheet()`. Styled with `applyStyles()`.

Columns (12 total):
| Column | Content |
|---|---|
| Level | Project / Building / Floor / Room |
| Location | Hierarchy path (e.g. "BuildingA > Floor1 > Room2") |
| Component Name | componentType.name |
| Power (W) | Rated power |
| Quantity | Count |
| Power Factor | PF value |
| Total W | Power × Quantity |
| Phases | 1phase / 3phase |
| Priority | normal / essential / critical |
| Scheduling | fixed / shiftable / curtailable |
| Season | all / summer / winter / spring / autumn |
| Day Type | all / weekday / weekend |

Column widths: [10, 22, 28, 10, 9, 12, 10, 8, 10, 12, 10, 10]

Data is collected by traversing the full hierarchy via API calls: project components → each building → each floor → each room.

**Sheet 3 — "Financial":**
Built by `buildFinancialSheet()`.

Rows:
- FINANCIAL ANALYSIS SUMMARY header
- Installation Cost, Annual Savings, Simple Payback, LCOE, 25-Year Net Benefit
- Solar Capacity (kW), Battery Capacity (kWh)
- Blank row separator
- Yearly breakdown table with header: Year | Solar kWh | Savings ($) | Battery Replacement ($) | Net ($) | Cumulative ($)
- One row per year 1–25

Column widths: [28, 14, 14, 24, 12, 14]

**SheetJS cell styling:**
- `headerStyle(bgColor)`: bold white text, colored background fill, centered, bottom border
- `cellStyle(even)`: alternating F9FAFB/FFFFFF row fill, bottom hair border
- `applyStyles(ws, headers, rowCount)`: applies header style to row 0, data style to remaining rows

---

### 23.2 PDF Export — printPowerReport.js Template

**File:** `printPowerReport.js`

Opens a new browser window with a complete HTML report and triggers `window.print()`.

**Template sections:**

**Letterhead:**
- Power Profile logo (blue box with ⚡ icon)
- Brand name "Power Profile" + tagline
- Right side: Project name, Engineer name, Date, Reference number (PP-XXXXXX from timestamp)

**Load Summary section:**
Three cards: Max Load (Unoptimized) VA/W, Optimized Demand VA/W, Diversity Reduction %

**Power Factor & Reactive Power section:**
- Color-coded pill: green (≥95%), amber (≥85%), red (<85%)
- Table: PF, Q (kVAR), Max Q, Line Current
- Progress bar: gradient red→amber→green from 0.0 to 1.0 with labels at 0.85 and 1.0

**Capacitor Bank section:**
- If PF correction needed: Required bank (kVAR), Capacitor value (μF delta 400V), Target PF, line current before/after, reduction %
- If not needed: "No capacitor bank required" green note
- If `capApplied=true`: shows corrected values and green "Applied" banner

**Load Breakdown by Priority:**
Table: Priority (Critical/Essential/Normal) × Max S / Max P / Optimized S / Optimized P

**Socket Outlets section** (if sockets > 0):
Connected capacity (VA), Estimated Demand (VA)

**Certification/Signature block:**
Three fields: Prepared By, Date, Reference No.

**Footer:**
Standards: IEC 60364-8-1 · PENRA · NEC Article 430 · IEC 60831 · BS 7671 · CIBSE Guide C

**Print CSS:** A4 size, 12mm margins, `print-color-adjust: exact` for background fills, `page-break-inside: avoid` on sections.

---

### 23.3 Single-Line Diagram — SVG Structure, All Components

**File:** `SingleLineDiagramPage.jsx`

The diagram is a pure SVG rendered in React. Canvas: 900px wide × variable height.

**Layout constants:**
- SRC_Y = 60px — Y center of source node row
- BUS_Y = 200px — Y center of main busbar
- BLD_Y = 340px — Y center of building panel row
- NODE_R = 32px — Source circle radius
- BUS_H = 6px — Busbar height

**Color palette:**
| Component | Fill | Stroke | Text |
|---|---|---|---|
| Solar | #fef9c3 (light yellow) | #ca8a04 (amber) | #713f12 |
| Battery/BESS | #ede9fe (light purple) | #7c3aed (violet) | #4c1d95 |
| Utility/Grid | #dbeafe (light blue) | #2563eb (blue) | #1e3a8a |
| Generator | #ffedd5 (light orange) | #ea580c (orange) | #7c2d12 |
| Main Bus | #1e3a8a (dark blue) | #1e3a8a | — |
| Building | #f0fdf4 (light green) | #16a34a (green) | #14532d |

**SVG components:**

`SourceNode({ cx, cy, r, col, title, line1, line2, icon })`:
- Outer glow circle (r+4, 40% opacity)
- Main circle (r, solid)
- Icon text (emoji/symbol at cy-6)
- Title text (bold, at cy+7)
- Two label lines below circle

`BuildingNode({ cx, cy, w, h, col, name, line1, line2, active })`:
- Optional active glow rect (w+8, h+8, 30% opacity)
- Main rect (rounded corners)
- Name text (uppercase, bold, at cy-10)
- Line1 (large bold value)
- Line2 (small gray subtitle)

`Wire({ x1, y1, x2, y2, dashed })`:
- SVG line, stroke #6b7280, width 1.5
- Dashed when `dashed=true` (5,3 pattern)

`Breaker({ cx, cy })`:
- Small square (14×14) with diagonal slash — represents circuit breaker symbol

**Source positioning:** Sources are spread evenly across the 900px canvas width. The main busbar is a full-width filled rectangle at BUS_Y. Vertical wires connect each source to the busbar with a Breaker symbol at midpoint. Building panels hang below the busbar connected by vertical wires.

**Data loading:** Parallel API calls on mount: buildings, solar-systems, batteries, generator-lines, utility-lines, total-power.

**Tooltip:** Hover over any node shows a floating tooltip with detailed data (VA, kW, chemistry, etc.).

---

## 24. EDGE CASES & ERROR HANDLING

---

### 24.1 All Try/Catch, Guard Clauses, Fallbacks, and NULL Checks

| File + Function | Condition Handled | Fallback | Why |
|---|---|---|---|
| `SolarIrradianceService::fetchNasaHourlyGhi()` | HTTP non-200 response from NASA | Sets `nasaFailed=true`, returns `[]` | NASA unavailability must not break calculations |
| `SolarIrradianceService::fetchNasaHourlyGhi()` | Empty data in NASA response | Sets `nasaFailed=true`, returns `[]` | NASA sometimes returns empty for recent dates |
| `SolarIrradianceService::fetchNasaHourlyGhi()` | All-zero GHI for non-polar location | Sets `nasaFailed=true`, returns `[]` | Future dates return -999 nulls which become zeros |
| `SolarIrradianceService::fetchNasaHourlyGhi()` | Any `\Throwable` (network timeout, etc.) | `nasaFailed=true`, returns `[]` | Network unreachability must not crash the app |
| `SolarIrradianceService::getHourlyOutputWatts()` | `panelCapacityKw <= 0` | Returns 24-element zero array | Division by zero protection |
| `SolarIrradianceService::hourlyProfile()` | `capacityW <= 0` | Returns 24-element zero array | — |
| `SolarIrradianceService::hourlyProfile()` | `cosHa > 1.0` (polar night) | Returns 24 zeros; sets polar_night flag | Latitude/winter combination above Arctic Circle |
| `SolarIrradianceService::hourlyProfile()` | `cosHa < -1.0` (midnight sun) | Distributes flat output over 24h | Arctic summer / polar regions |
| `SolarIrradianceService::interpolatePsh()` | `abs(lat) > 60` | Clamps to 60° | Table only covers 0°–60° |
| `SourceDispatchService::dispatchOptimized()` | Battery `current` goes below 0 after discharge | Clamps to 0.0, records `battDepletedAt` | SOC physically cannot be negative |
| `SourceDispatchService::dispatchOptimized()` | `totalCurrentStep4 <= 0` | Skips discharge; `maxDischW = 0` | Prevents division-by-zero in proportional share |
| `SourceDispatchService::dispatch()` | No active batteries / empty collection | Falls through to `dispatchBasic()` | Batteries are optional |
| `GeneratorLine::fuelAtLoadKw()` | `ratedKw <= 0` or `fRated <= 0` | Returns 0.0 | Prevent division-by-zero |
| `GeneratorLine::getCostPerKwhAttribute()` | Any parameter <= 0 | Returns `null` | Unconfigured generators must not produce NaN |
| `GeneratorLine::marginalCostPerKwh()` | Any parameter <= 0 | Returns 0.0 | — |
| `Battery::getAgeFactorAttribute()` | Degradation formula results < 0.70 | Clamps to 0.70 (70% floor) | Battery is still operational until 70% capacity |
| `Battery::getCurrentAvailableKwhAttribute()` | `current_soc` outside [0,1] | Clamps: `max(0, min(1, soc))` | Database corruption guard |
| `Battery::getRuntimeHoursAtMaxDischargeAttribute()` | `max_discharge_power_kw <= 0` | Returns 0 | Division-by-zero |
| `CostSignalService::compute()` | No utility line with tariff | `tariff = null` | Partial configuration allowed |
| `CostSignalService::compute()` | No generator with fuel cost | `genCostPerKwh = null` | Partial configuration allowed |
| `CostSignalService::compute()` | No sources configured at all | Cost signal falls through to `UNMET_COST = 999` | Valid state: project without sources yet |
| `FinancialAnalysisService::analyzeProject()` | No utility line found | `tariff = 0.0`, `weightedTariff = 0.0` | Financial calc proceeds with zero grid cost |
| `FinancialAnalysisService::analyzeProject()` | `annualSavings <= 0` or `totalInvestment <= 0` | `simplePayback = null` | Payback undefined for zero/negative savings |
| `FinancialAnalysisService::analyzeProject()` | `lcoeDenominator <= 0` | `lcoeSolar = 0.0` | No solar generation = no LCOE |
| `FinancialAnalysisService::analyzeProject()` | `totalWithout <= 0` | `savingsPct = 0.0` | Avoid division by zero |
| `ProjectController::optimizeShiftable()` | `runHours <= 0` | Adds to `errors[]`, skips component | Unset constraints produce error message |
| `ProjectController::optimizeShiftable()` | `runHours > window_width` | Adds to `errors[]`, skips component | Physically impossible schedule |
| `ProjectController::pickBestIntervals()` | `winEnd <= winStart` | Returns `null` | Invalid window configuration |
| `ProjectController::buildCostSignal()` | No location set | `solarW = array_fill(0,24,0.0)` | Location is optional; solar discount is skipped |
| `ProjectController::buildCostSignal()` | `max_solar == 0` | Uses 1.0 as normalizer | Night-time only month edge case |
| `ProjectBackupController::restore()` | Any `\Throwable` during import | `DB::rollBack()`, returns 500 | Partial imports must never corrupt data |
| `ProjectBackupController::restore()` | Missing project name in backup | Returns 422 with message | Corrupted backup file |
| `ProjectBackupController::restore()` | User has read-only access to same-name project | Returns 403 | Prevent overwrite of shared projects |
| `ProjectBackupController::restore()` | Existing project + no overwrite flag | Returns 409 conflict | User must explicitly confirm overwrite |
| `ValidationController::show()` | Validation project not seeded | Returns 404 with seeder command | Development/setup helper |
| `PhaseBalanceController::buildingReport()` | Building has no 1-phase loads | Returns empty distribution | Valid state; shows all zeros |
| `NavigationController::project/building/floor/room()` | Entity not found by name | `->firstOrFail()` → 404 | URL names are user-defined, may change |
| `ScheduleController::project()` | No power sources configured | Returns 422 with `error:'no_sources'` | Prevents nonsensical empty dispatch result |
| `ScheduleController::extractRaw()` | `usage_time_intervals` is JSON string | `json_decode()` conversion | Legacy data format compatibility |
| `BatteryChemistryService::getDefaults()` | Unknown chemistry string | Returns `null` | User may store custom chemistry not in presets |
| `exportToExcel.js::collectComponents()` | Any API call fails during collection | `catch: continue` (skip level) | Partial export is better than no export |
| `exportToExcel.js::exportProjectExcel()` | Financial analysis fetch fails | Sheet 3 is skipped | Financial data is optional |
| `printPowerReport.js` | `window.open()` blocked by browser | `if (!win) return` | Popup blocker protection |
| `SolarIrradianceService` | NASA returns -999 null values | `max(0.0, value)` — clamps -999 to 0 | NASA API sentinel value must not produce negative generation |

---

## 25. QUICK REFERENCE TABLES

---

### Table 1: All API Endpoints

| Method | Path | Controller@method | Auth | Description |
|---|---|---|---|---|
| GET | /api/user | UserController@show | sanctum | Get current user |
| POST | /api/logout | UserController@logout | sanctum | Revoke current token |
| GET | /api/projects | ProjectController@index | sanctum | List user's projects |
| POST | /api/projects | ProjectController@store | sanctum | Create project |
| GET | /api/projects/{project} | ProjectController@show | sanctum | Get project |
| PUT | /api/projects/{project} | ProjectController@update | sanctum+admin/main | Update project |
| DELETE | /api/projects/{project} | ProjectController@destroy | sanctum+owner | Delete project |
| GET | /api/projects/{project}/all-floors | ProjectController@allFloors | sanctum | All floors in project |
| GET | /api/projects/{project}/all-rooms | ProjectController@allRooms | sanctum | All rooms in project |
| GET | /api/projects/{project}/shiftable-components | ProjectController@shiftableComponents | sanctum | All shiftable loads |
| POST | /api/projects/{project}/optimize-shiftable | ProjectController@optimizeShiftable | sanctum+admin/main | Run schedule optimizer |
| GET | /api/projects/{project}/defense-summary | ProjectController@defenseSummary | sanctum | Defense prep data |
| GET | /api/projects/{project}/backup | ProjectBackupController@backup | sanctum+admin/main | Export project JSON |
| POST | /api/projects/restore | ProjectBackupController@restore | sanctum | Import project JSON |
| GET | /api/buildings/{building}/backup | ProjectBackupController@backupBuilding | sanctum+admin/main | Export building JSON |
| POST | /api/projects/{project}/buildings/restore | ProjectBackupController@restoreBuilding | sanctum+admin/main | Import building JSON |
| GET | /api/floors/{floor}/backup | ProjectBackupController@backupFloor | sanctum+admin/main | Export floor JSON |
| POST | /api/buildings/{building}/floors/restore | ProjectBackupController@restoreFloor | sanctum+admin/main | Import floor JSON |
| GET | /api/rooms/{room}/backup | ProjectBackupController@backupRoom | sanctum+admin/main | Export room JSON |
| POST | /api/floors/{floor}/rooms/restore | ProjectBackupController@restoreRoom | sanctum+admin/main | Import room JSON |
| POST | /api/projects/{project}/save-backup | ProjectBackupController@saveProjectToServer | sanctum+admin/main | Save to server |
| POST | /api/buildings/{building}/save-backup | ProjectBackupController@saveBuildingToServer | sanctum+admin/main | Save building to server |
| POST | /api/floors/{floor}/save-backup | ProjectBackupController@saveFloorToServer | sanctum+admin/main | Save floor to server |
| POST | /api/rooms/{room}/save-backup | ProjectBackupController@saveRoomToServer | sanctum+admin/main | Save room to server |
| GET | /api/projects/{project}/server-backups | ServerBackupController@index | sanctum+admin/main | List server backups |
| GET | /api/server-backups/{backup}/data | ServerBackupController@show | sanctum+admin/main | Get backup data |
| DELETE | /api/server-backups/{backup} | ServerBackupController@destroy | sanctum+admin/main | Delete server backup |
| POST | /api/projects/{project}/buildings/{building}/duplicate | ProjectBackupController@duplicateBuilding | sanctum+admin/main | Duplicate building |
| POST | /api/buildings/{building}/floors/{floor}/duplicate | ProjectBackupController@duplicateFloor | sanctum+admin/main | Duplicate floor |
| POST | /api/floors/{floor}/rooms/{room}/duplicate | ProjectBackupController@duplicateRoom | sanctum+admin/main | Duplicate room |
| GET | /api/projects/{project}/members | ProjectMemberController@index | sanctum | List project members |
| POST | /api/projects/{project}/members | ProjectMemberController@store | sanctum+admin | Add member |
| PUT | /api/projects/{project}/members/{member} | ProjectMemberController@update | sanctum+admin | Update member role |
| DELETE | /api/projects/{project}/members/{member} | ProjectMemberController@destroy | sanctum+admin | Remove member |
| GET | /api/projects/{project}/buildings | BuildingController@index | sanctum | List buildings |
| POST | /api/projects/{project}/buildings | BuildingController@store | sanctum+admin/main | Create building |
| PUT | /api/projects/{project}/buildings/{building} | BuildingController@update | sanctum+admin/main | Update building |
| DELETE | /api/projects/{project}/buildings/{building} | BuildingController@destroy | sanctum+admin/main | Delete building |
| GET | /api/buildings/{building}/floors | FloorController@index | sanctum | List floors |
| POST | /api/buildings/{building}/floors | FloorController@store | sanctum+admin/main | Create floor |
| PUT | /api/buildings/{building}/floors/{floor} | FloorController@update | sanctum+admin/main | Update floor |
| DELETE | /api/buildings/{building}/floors/{floor} | FloorController@destroy | sanctum+admin/main | Delete floor |
| GET | /api/floors/{floor}/rooms | RoomController@index | sanctum | List rooms |
| POST | /api/floors/{floor}/rooms | RoomController@store | sanctum+admin/main | Create room |
| PUT | /api/floors/{floor}/rooms/{room} | RoomController@update | sanctum+admin/main | Update room |
| DELETE | /api/floors/{floor}/rooms/{room} | RoomController@destroy | sanctum+admin/main | Delete room |
| GET | /api/component-types | ComponentTypeController@index | sanctum | List component types |
| GET | /api/projects/{project}/phase-balance | PhaseBalanceController@project | sanctum | Project phase balance |
| GET | /api/buildings/{building}/phase-balance | PhaseBalanceController@building | sanctum | Building phase balance |
| GET | /api/floors/{floor}/phase-balance | PhaseBalanceController@floor | sanctum | Floor phase balance |
| POST | /api/rooms/{room}/assign-phase | PhaseBalanceController@assignRoom | sanctum+admin/main | Assign room phase |
| POST | /api/buildings/{building}/apply-optimal-phase | PhaseBalanceController@applyOptimalBuilding | sanctum+admin/main | Apply greedy phase |
| GET | /api/projects/{project}/load-profile | LoadProfileController@project | sanctum | 24h load profile |
| GET | /api/projects/{project}/schedule | ScheduleController@project | sanctum | Full schedule + dispatch |
| GET | /api/projects/{project}/cost-signal | CostSignalController@show | sanctum | Cost signal array |
| GET | /api/projects/{project}/financial-analysis | FinancialController@show | sanctum | Financial analysis |
| GET | /api/projects/{project}/total-power | TotalPowerController@project | sanctum | Project power totals |
| GET | /api/buildings/{building}/total-power | TotalPowerController@building | sanctum | Building power totals |
| GET | /api/floors/{floor}/total-power | TotalPowerController@floor | sanctum | Floor power totals |
| GET | /api/rooms/{room}/total-power | TotalPowerController@room | sanctum | Room power totals |
| GET | /api/projects/{project}/components | ProjectComponentController@index | sanctum | List project components |
| POST | /api/projects/{project}/components | ProjectComponentController@store | sanctum+admin/main | Add project component |
| PUT | /api/projects/{project}/components/{component} | ProjectComponentController@update | sanctum+admin/main | Update component |
| DELETE | /api/projects/{project}/components/{component} | ProjectComponentController@destroy | sanctum+admin/main | Delete component |
| GET | /api/buildings/{building}/components | BuildingComponentController@index | sanctum | List building components |
| POST | /api/buildings/{building}/components | BuildingComponentController@store | sanctum+admin/main | Add building component |
| PUT | /api/buildings/{building}/components/{component} | BuildingComponentController@update | sanctum+admin/main | Update component |
| DELETE | /api/buildings/{building}/components/{component} | BuildingComponentController@destroy | sanctum+admin/main | Delete component |
| GET | /api/floors/{floor}/components | FloorComponentController@index | sanctum | List floor components |
| POST | /api/floors/{floor}/components | FloorComponentController@store | sanctum+admin/main | Add floor component |
| PUT | /api/floors/{floor}/components/{component} | FloorComponentController@update | sanctum+admin/main | Update component |
| DELETE | /api/floors/{floor}/components/{component} | FloorComponentController@destroy | sanctum+admin/main | Delete component |
| GET | /api/rooms/{room}/components | RoomComponentController@index | sanctum | List room components |
| POST | /api/rooms/{room}/components | RoomComponentController@store | sanctum+admin/main | Add room component |
| PUT | /api/rooms/{room}/components/{component} | RoomComponentController@update | sanctum+admin/main | Update component |
| DELETE | /api/rooms/{room}/components/{component} | RoomComponentController@destroy | sanctum+admin/main | Delete component |
| GET | /api/nav/{projectName} | NavigationController@project | sanctum | Project breadcrumb |
| GET | /api/nav/{projectName}/{buildingName} | NavigationController@building | sanctum | Building breadcrumb |
| GET | /api/nav/{projectName}/{buildingName}/{floorName} | NavigationController@floor | sanctum | Floor breadcrumb |
| GET | /api/nav/{projectName}/{buildingName}/{floorName}/{roomName} | NavigationController@room | sanctum | Room breadcrumb |
| GET | /api/{entity}/{id}/utility-lines | UtilityLineController@index | sanctum | List utility lines |
| POST | /api/{entity}/{id}/utility-lines | UtilityLineController@store | sanctum | Create utility line |
| PUT | /api/utility-lines/{line} | UtilityLineController@update | sanctum | Update utility line |
| DELETE | /api/utility-lines/{line} | UtilityLineController@destroy | sanctum | Delete utility line |
| GET | /api/{entity}/{id}/generator-lines | GeneratorLineController@index | sanctum | List generator lines |
| POST | /api/{entity}/{id}/generator-lines | GeneratorLineController@store | sanctum | Create generator line |
| PUT | /api/generator-lines/{line} | GeneratorLineController@update | sanctum | Update generator line |
| DELETE | /api/generator-lines/{line} | GeneratorLineController@destroy | sanctum | Delete generator line |
| GET | /api/{entity}/{id}/sockets | SocketController@index | sanctum | List sockets |
| POST | /api/{entity}/{id}/sockets | SocketController@store | sanctum | Create socket group |
| PUT | /api/sockets/{socket} | SocketController@update | sanctum | Update socket group |
| DELETE | /api/sockets/{socket} | SocketController@destroy | sanctum | Delete socket group |
| GET | /api/projects/{project}/solar-systems | SolarSystemController@index | sanctum | List solar systems |
| POST | /api/projects/{project}/solar-systems | SolarSystemController@store | sanctum+admin/main | Create solar system |
| PUT | /api/solar-systems/{solarSystem} | SolarSystemController@update | sanctum+admin/main | Update solar system |
| DELETE | /api/solar-systems/{solarSystem} | SolarSystemController@destroy | sanctum+admin/main | Delete solar system |
| GET | /api/projects/{project}/batteries | BatteryController@index | sanctum | List batteries |
| POST | /api/projects/{project}/batteries | BatteryController@store | sanctum+admin/main | Create battery bank |
| GET | /api/projects/{project}/battery-runtime | BatteryController@projectRuntime | sanctum | Battery runtime summary |
| GET | /api/batteries/{battery} | BatteryController@show | sanctum | Get battery detail |
| PUT | /api/batteries/{battery} | BatteryController@update | sanctum+admin/main | Update battery |
| DELETE | /api/batteries/{battery} | BatteryController@destroy | sanctum+admin/main | Delete battery |
| POST | /api/batteries/{battery}/reset-soc | BatteryController@resetSoc | sanctum+admin/main | Reset SOC to full |
| POST | /api/batteries/{battery}/runtime-at-load | BatteryController@runtimeAtLoad | sanctum | Runtime at given kW |
| GET | /api/battery-chemistry-defaults | BatteryController@chemistryDefaults | public | Chemistry presets |
| GET | /api/validation/case-study | ValidationController@show | sanctum | Run validation test |
| POST | /api/admin/login | AdminController@login | public | Admin login |
| GET | /api/admin/users | AdminController@users | sanctum+admin | List all users |
| PUT | /api/admin/users/{user} | AdminController@updateUser | sanctum+admin | Update user |
| DELETE | /api/admin/users/{user} | AdminController@deleteUser | sanctum+admin | Delete user |
| GET | /auth/google/redirect | GoogleController@redirect | public | Start Google OAuth |
| GET | /auth/google/callback | GoogleController@callback | public | OAuth callback |

---

### Table 2: All Electrical Formulas

| Name | Expression | Units | Standard | File |
|---|---|---|---|---|
| Apparent Power | S = sqrt(P² + Q²) | VA | IEC 60364-8-1 | TotalPowerController |
| Active Power | P = S × PF | W | IEC 60364-8-1 | all controllers |
| Reactive Power | Q = P × tan(arccos(PF)) | VAR | IEC 60364-8-1 | ValidationReferenceService |
| System PF | PF = P / S | — | PENRA | TotalPowerController |
| Diversity Chain | DF = DF_room × DF_r2f × DF_f2b × DF_project | — | IEC 60364-8-1 | DiversityFactorService |
| Socket Tier 1 | D = n × 200 × 1.00 (n≤10) | VA | BS 7671 | SocketDemandService |
| Socket Tier 2 | D += (n-10) × 200 × 0.75 (10<n≤20) | VA | BS 7671 | SocketDemandService |
| Socket Tier 3 | D += (n-20) × 200 × 0.40 (n>20) | VA | BS 7671 | SocketDemandService |
| Capacitor Bank | Q_cap = Q_sys - P×tan(arccos(0.95)) | VAR | IEC 60831 | ValidationController |
| Capacitor Value | C = (Q/3)/(2πfV²) × 1e6 | μF | IEC 60831 | ValidationController |
| Inrush 125% | I_design = I_rated × 1.25 | A | NEC Art.430 | TotalPowerController |
| Phase Imbalance | imb% = (I_max-I_min)/I_avg × 100 | % | IEC 60034-26 | PhaseBalanceController |
| Phase Phasor | I = VA/230 × e^j(θ_V-arccos(PF)) | A | IEC 60909 | PhaseBalanceController |
| Neutral Current | |I_N| = |I_A+I_B+I_C| (complex sum) | A | Kirchhoff | PhaseBalanceController |
| Solar Capacity Estimate | P = A × 0.17 × 1000 × 0.75 | W | IEC 61853 | SolarIrradianceService |
| PSH Sinusoidal Peak | P_peak = P_cap × PSH × PR × π / (2×D) | W | IEC 61724 | SolarIrradianceService |
| Solar from GHI | P = (GHI/1000) × P_cap × PR | W | IEC 61724 | SolarIrradianceService |
| Solar Declination | δ = 23.45 × sin(360/365 × (DOY-81)) | degrees | Spencer 1971 | SolarIrradianceService |
| Sunrise/Sunset | cos(H) = -tan(lat)×tan(δ); T = 12±H/15 | hours | Spencer 1971 | SolarIrradianceService |
| PSH Interpolation | PSH = PSH_L + frac×(PSH_U-PSH_L) | kWh/m²/day | NASA POWER | SolarIrradianceService |
| SOC Update (charge) | SOC += P/C × sqrt(RTE) | fraction | IEC 62619 | SourceDispatchService |
| SOC Update (discharge) | SOC -= P/C / sqrt(RTE) | fraction | IEC 62619 | SourceDispatchService |
| Gen Flat-Rate Cost | C = (fuel_price × F_rated) / P_rated | $/kWh | ISO 8528 | GeneratorLine |
| Gen Affine F(P) | F(P) = F_0 + (F_rated-F_0)×P/P_rated | L/h | ISO 8528-10 | GeneratorLine |
| Gen Marginal Cost | MC = fuel_price×(F_rated-F_0)/P_rated | $/kWh | ISO 8528 | GeneratorLine |
| Solar Discount | cost_h = max(0, base × (1-0.9×solar_frac)) | $/kWh | — | ProjectController |
| Annual Energy | E_annual = E_daily × 365 | kWh | — | FinancialAnalysisService |
| Weighted Tariff | tariff_w = (off_h×T + peak_h×T_peak)/24 | $/kWh | — | FinancialAnalysisService |
| Annual Savings | savings = cost_without - cost_with_solar | $ | — | FinancialAnalysisService |
| Simple Payback | T = investment / savings | years | — | FinancialAnalysisService |
| LCOE | LCOE = (C_install + C_maint×N) / (E_solar×N) | $/kWh | NREL/IEA | FinancialAnalysisService |
| Panel Degradation | factor = (1-0.005)^year | — | IEC 61215 | FinancialAnalysisService |
| Battery Replacement | year = ceil(cycle_life/365 - age_years) | year | — | FinancialAnalysisService |
| Cumulative 25yr | cum(y) = -invest + sum(savings×0.995^i - repl_i) | $ | — | FinancialAnalysisService |
| Self-Consumption | SC% = (solar_used+batt_chrg)/solar_gen × 100 | % | — | SourceDispatchService |
| Backup Hours (max) | T = C_usable / P_max_discharge | h | — | Battery model |
| Backup Hours (avg) | T = C_usable / (P_max/2) | h | — | Battery model |

---

### Table 3: Diversity Factors by Building Type

| Building Type | Room→Floor | Floor→Building | × Project DF (0.70) = Min Chain DF |
|---|---|---|---|
| residential_house | 0.60 | 0.70 | 0.60×0.70×0.70 = 0.2940 |
| residential_apartment | 0.65 | 0.70 | 0.65×0.70×0.70 = 0.3185 |
| hotel | 0.65 | 0.70 | 0.65×0.70×0.70 = 0.3185 |
| office | 0.85 | 0.80 | 0.85×0.80×0.70 = 0.4760 |
| educational_school | 0.80 | 0.80 | 0.80×0.80×0.70 = 0.4480 |
| educational_university | 0.85 | 0.80 | 0.85×0.80×0.70 = 0.4760 |
| retail | 0.85 | 0.85 | 0.85×0.85×0.70 = 0.5058 |
| hospital | 0.90 | 0.90 | 0.90×0.90×0.70 = 0.5670 |
| industrial | 0.85 | 0.85 | 0.85×0.85×0.70 = 0.5058 |
| mosque_worship | 0.80 | 0.75 | 0.80×0.75×0.70 = 0.4200 |
| sports | 0.80 | 0.80 | 0.80×0.80×0.70 = 0.4480 |
| (default/unclassified) | 0.90 | 0.80 | 0.90×0.80×0.70 = 0.5040 |

Note: The minimum chain DF above assumes room coincidence = 1.0. Actual component DF = room_type_coincidence × chain_DF. Critical components always use DF = 1.0 regardless.

---

### Table 4: Room Coincidence Factors

| Room Type | Coincidence Factor | Standard |
|---|---|---|
| server_room | 1.00 | IEC/CIBSE |
| operating_theater | 1.00 | IEC/CIBSE |
| laboratory | 0.90 | CIBSE |
| classroom | 0.85 | CIBSE |
| lecture_hall | 0.85 | CIBSE |
| workshop | 0.85 | CIBSE |
| retail_floor | 0.85 | CIBSE |
| office_open | 0.80 | CIBSE |
| gym_sports | 0.80 | CIBSE |
| kitchen_commercial | 0.75 | CIBSE |
| office_private | 0.75 | CIBSE |
| prayer_hall | 0.75 | CIBSE |
| reception_lobby | 0.70 | CIBSE |
| meeting_room | 0.70 | CIBSE |
| corridor | 0.60 | CIBSE |
| living_room | 0.60 | CIBSE |
| kitchen_residential | 0.55 | CIBSE |
| hotel_room | 0.50 | CIBSE |
| bedroom | 0.45 | CIBSE |
| warehouse_storage | 0.30 | CIBSE |
| bathroom | 0.25 | CIBSE |
| (default/unclassified) | 0.80 | IEC 60364-8-1 |

---

### Table 5: Battery Chemistry Presets

| Chemistry | DoD | RTE | C-charge | C-discharge | Cycles | Calendar Life | Degradation/yr |
|---|---|---|---|---|---|---|---|
| lead_acid_flooded | 0.50 | 0.80 | 0.10 C | 0.20 C | 500 | 5 yr | 5.0% |
| lead_acid_agm | 0.50 | 0.85 | 0.20 C | 0.30 C | 700 | 7 yr | 4.0% |
| lead_acid_gel | 0.50 | 0.85 | 0.15 C | 0.25 C | 800 | 8 yr | 3.5% |
| lithium_lfp | 0.90 | 0.95 | 0.50 C | 1.00 C | 4000 | 15 yr | 2.0% |
| lithium_nmc | 0.80 | 0.93 | 0.50 C | 1.00 C | 2500 | 10 yr | 2.5% |

---

### Table 6: System Constants

| Constant | Value | File | Source Standard |
|---|---|---|---|
| PANEL_DEGRADATION | 0.005/yr | FinancialAnalysisService | IEC 61215 |
| PROJECTION_YEARS | 25 | FinancialAnalysisService | Industry standard |
| DF_PROJECT | 0.70 | ScheduleController, FinancialAnalysisService | IEC 60364-8-1 |
| ROOF_COVERAGE_RATIO | 0.17 | SolarIrradianceService | IEC sizing practice |
| STC_IRRADIANCE_W | 1,000 W/m² | SolarIrradianceService | IEC 60904-3 |
| CAPACITY_ESTIMATE_PR | 0.75 | SolarIrradianceService | Conservative PR |
| PERFORMANCE_RATIO | 0.80 | SolarIrradianceService | IEC 61724 |
| GEN_OPTIMAL_MAX_LOAD | 0.85 (85%) | SourceDispatchService | ISO 8528 |
| INV_EFF | 0.95 | SourceDispatchService | Industry standard |
| BATTERY_DEGRADATION_COST | 0.01 $/kWh | CostSignalService | Economic proxy |
| UNMET_COST | 999.0 $/kWh | CostSignalService | Sentinel value |
| VOLT | 230 V | PhaseBalanceController | IEC 60038 |
| WARN_PCT | 10% | PhaseBalanceController | IEC 60034-26 |
| CRIT_PCT | 20% | PhaseBalanceController | IEC 60034-26 |
| SOCKET_ASSUMED_PF | 0.95 | PhaseBalanceController | BS 7671 |
| TARGET_PF | 0.95 | ValidationController | PENRA |
| CAP_STEP_KVAR | 0.5 kVAR | ValidationController | IEC 60831 |
| VOLTAGE_LL | 400 V | ValidationController | IEC 60038 |
| FREQUENCY | 50 Hz | ValidationController | IEC standard |
| OUTLET_VA | 200 VA | SocketDemandService | BS 7671 |
| NASA_TIMEOUT_SEC | 10 s | SolarIrradianceService | — |
| CACHE_DAYS | 30 days | SolarIrradianceService | — |
| TOLERANCE_PCT | 0.1% | ValidationController | — |
| no_load_fuel default | 0.30 × F_rated | GeneratorLine | ISO 8528-10 |

---

### Table 7: Cost Signal Ladder

| Condition | Cost $/kWh | Reason |
|---|---|---|
| Solar generated > load (surplus) | 0.00 | Energy would be curtailed/wasted; cost = 0 |
| Battery discharge headroom > 0 | 0.01 | Stored solar energy; near-zero degradation cost only |
| Utility available, off-peak hours | tariff (e.g. 0.12) | Standard grid rate |
| Utility available, peak hours (peak_start ≤ h < peak_end) | peak_tariff (e.g. 0.22) | Time-of-use peak rate |
| Generator available (no utility) | marginal_cost = fuel_price×(F_rated-F_0)/P_rated | Only incremental fuel burned |
| No source available | 999.00 | Load shedding sentinel — never schedule here |

---

### Table 8: Frontend Routes

| Path | Page Component | Auth Required | Description |
|---|---|---|---|
| / | Redirect to /dashboard | Yes | Root redirect |
| /login | LoginPage | No (public) | Google OAuth initiation |
| /auth/callback | AuthCallbackPage | No (public) | OAuth token receipt |
| /admin/login | AdminLoginPage | No (public) | Admin email/password login |
| /admin/dashboard | AdminDashboardPage | No (admin token) | User management |
| /dashboard | DashboardPage | Yes | Project list + create |
| /validation | ValidationPage | Yes | Case-study validation results |
| /defense-prep | DefensePrepPage | Yes | Defense presentation prep |
| /projects/:projectId | ProjectPage | Yes (ProjectLayout) | Project overview + total power |
| /projects/:projectId/schedule | LoadSchedulePage | Yes (ProjectLayout) | 24h schedule + dispatch |
| /projects/:projectId/phase-balance | PhaseBalancePage | Yes (ProjectLayout) | Phase imbalance analysis |
| /projects/:projectId/financial | FinancialPage | Yes (ProjectLayout) | 25yr financial projection |
| /projects/:projectId/single-line | SingleLineDiagramPage | Yes (ProjectLayout) | SVG single-line diagram |
| /projects/:projectId/buildings/:buildingId | BuildingPage | Yes (ProjectLayout) | Building overview |
| /projects/:projectId/buildings/:buildingId/floors/:floorId | FloorPage | Yes (ProjectLayout) | Floor overview |
| /projects/:projectId/buildings/:buildingId/floors/:floorId/rooms/:roomId | NewRoomPage | Yes (ProjectLayout) | Room components |

---

### Table 9: PSH Table (Peak Sun Hours kWh/m²/day)

Data derived from NASA POWER ALLSKY_SFC_SW_DWN monthly averages, TMY 1991–2020. Southern hemisphere months are season-flipped by +6.

| Latitude | Jan | Feb | Mar | Apr | May | Jun | Jul | Aug | Sep | Oct | Nov | Dec |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 0° | 5.5 | 5.5 | 5.5 | 5.5 | 5.5 | 5.5 | 5.5 | 5.5 | 5.5 | 5.5 | 5.5 | 5.5 |
| 10° | 4.9 | 5.3 | 5.7 | 6.1 | 6.2 | 6.1 | 6.1 | 6.1 | 5.7 | 5.3 | 4.9 | 4.7 |
| 20° | 4.1 | 4.8 | 5.7 | 6.5 | 6.9 | 7.0 | 6.9 | 6.6 | 5.8 | 4.9 | 4.0 | 3.7 |
| 30° | 3.2 | 4.1 | 5.4 | 6.6 | 7.3 | 7.7 | 7.5 | 6.9 | 5.8 | 4.6 | 3.2 | 2.8 |
| 40° | 2.0 | 3.1 | 4.8 | 6.3 | 7.5 | 8.0 | 7.7 | 6.7 | 5.3 | 3.8 | 2.2 | 1.7 |
| 50° | 0.8 | 1.9 | 3.8 | 5.7 | 7.3 | 8.0 | 7.5 | 6.1 | 4.3 | 2.7 | 1.0 | 0.5 |
| 60° | 0.0 | 0.9 | 2.7 | 5.0 | 7.0 | 8.0 | 7.2 | 5.2 | 3.1 | 1.4 | 0.1 | 0.0 |

Intermediate latitudes are linearly interpolated between the bounding rows.

---

*End of POWER_PROFILE_COMPLETE_DOCUMENTATION.md*
*Source: E:\graduation project\power-profile*
*Generated: 2026-06-07*
