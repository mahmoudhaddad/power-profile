/* eslint-disable react/prop-types */
import { useState, useEffect, useCallback, useRef } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import api from '../api/axios';

// ── Category config ────────────────────────────────────────────────────────────
const CAT_STYLES = {
  Engineering:  'bg-surface-inset text-ink-body2',
  Algorithms:   'bg-surface-inset text-ink-body2',
  Standards:    'bg-surface-inset text-ink-body2',
  Architecture: 'bg-surface-inset text-ink-body2',
  Financial:    'bg-surface-inset text-ink-body2',
  Limitations:  'bg-surface-inset text-ink-body2',
  General:      'bg-surface-inset text-ink-body2',
};
const CATEGORIES = ['All', ...Object.keys(CAT_STYLES)];

// ── Live data pill ─────────────────────────────────────────────────────────────
function Live({ s, children }) {
  if (!s) return <em className="text-ink-muted text-xs not-italic">— select a project above to see live data.</em>;
  return (
    <span className="inline-flex items-center gap-1.5 bg-accent-soft border border-accent-border text-accent text-xs rounded-md px-2 py-0.5 font-medium mt-1">
      <span className="w-1.5 h-1.5 bg-accent-light rounded-full animate-pulse flex-shrink-0" />
      {children}
    </span>
  );
}

// ── PF status helper ───────────────────────────────────────────────────────────
function pfStatus(pf) {
  if (!pf) return '';
  if (pf >= 0.95) return `${pf} — above the 0.95 target ✓`;
  if (pf >= 0.85) return `${pf} — above the PENRA 0.85 minimum but below 0.95 target`;
  return `${pf} — PENRA violation: below 0.85 minimum, capacitor banks required`;
}

// ── Text block helpers ─────────────────────────────────────────────────────────
function P({ children }) { return <p className="mb-2 last:mb-0">{children}</p>; }
function Pre({ children }) { return <pre className="bg-surface-deep border border-line rounded p-3 text-xs font-mono text-ink-data whitespace-pre-wrap my-2">{children}</pre>; }
function Li({ children }) { return <li className="ml-4 list-disc">{children}</li>; }

// ── All 42 questions ───────────────────────────────────────────────────────────
function buildQuestions(s) {
  return [
    // ═══════════════════════════════════════════════════════ ENGINEERING
    {
      id: 1, category: 'Engineering',
      q: 'How do you know your calculations are correct?',
      a: <>
        <P>The system includes a built-in validation case study at <code className="bg-surface-inset text-ink-data px-1 rounded text-xs">/validation</code>. A 2-floor office building is manually calculated using the exact formulas from the technical report, then compared against system output with 0.1% tolerance. All key outputs — S, P, Q, power factor, and capacitor sizing — are verified to match hand calculations with a live PASS/FAIL comparison table.</P>
        <Live s={s}>Current project demand: {s?.total_demand_kva} kVA at PF {s?.system_power_factor}</Live>
      </>,
    },
    {
      id: 2, category: 'Engineering',
      q: 'Explain the diversity factor hierarchy — how does it actually work?',
      a: <>
        <P>Every component receives a diversity factor based on where it sits in the hierarchy. A component in a room gets four layers applied:</P>
        <Pre>{`DF = DF_room_type × DF_room_to_floor × DF_floor_to_building × 0.70`}</Pre>
        <P>A component directly on a floor skips the room layer. A component on a building skips both room and floor layers. A project-level component gets DF = 1.00 — it is already at the top. Critical priority loads always get DF = 1.00 regardless of where they sit, because life-safety loads must always be fully sized. The 0.70 top-level factor comes from IEC 60364-8-1 §8.3, which specifies that statistical coincidence between buildings means site-level demand is approximately 70% of the sum of individual building demands.</P>
        <Live s={s}>{s?.building_count} buildings, {s?.floor_count} floors, {s?.room_count} rooms, {s?.component_count} components across the hierarchy</Live>
      </>,
    },
    {
      id: 3, category: 'Engineering',
      q: 'Why 125% for motor sizing and where exactly is it applied?',
      a: <>
        <P>When an AC induction motor starts it draws 5–7× its full-load current for 0.5–10 seconds (the inrush period). NEC Article 430 requires protective devices to be sized for 125% of the largest motor's full-load current to prevent nuisance tripping during inrush.</P>
        <P>In this system the 25% addition is applied <strong>only to S_max</strong> — the vector used for sizing protective devices and cables. It is <strong>not</strong> applied to S_optimized — the diversity-weighted demand used for energy calculations and billing. This is the correct engineering practice: size protection for worst case, but do not inflate the energy estimate.</P>
        <Live s={s}>{s?.motor_load_count} motor loads in this project</Live>
      </>,
    },
    {
      id: 4, category: 'Engineering',
      q: 'How does the socket demand calculation work and why is it tiered?',
      a: <>
        <P>Not all outlets are ever loaded simultaneously. The system implements a three-tier demand factor:</P>
        <Pre>{`First 10 outlets:  200 VA each × 100%  = 200 VA/outlet
Next 10 (11–20):   200 VA each × 75%   = 150 VA/outlet
Beyond 20:         200 VA each × 40%   =  80 VA/outlet`}</Pre>
        <P>This reflects statistical loading probability — the more outlets in a space, the lower the probability that each additional one is in use simultaneously. An additional system-size coincidence factor is applied at aggregation:</P>
        <Pre>{`< 50 kVA total:        1.00
50–250 kVA total:      0.92
250–1000 kVA total:    0.85`}</Pre>
        <Live s={s}>{s?.socket_count} socket outlets across all levels</Live>
      </>,
    },
    {
      id: 5, category: 'Engineering',
      q: 'Explain the power triangle — what is the difference between S, P, and Q?',
      a: <>
        <P>All AC loads are characterised by three power quantities:</P>
        <ul className="space-y-1 my-2">
          <Li><strong>P (active power, kW):</strong> the real work done — heat, light, mechanical motion.</Li>
          <Li><strong>Q (reactive power, kVAR):</strong> energy stored and released by inductors/capacitors — does no useful work but must be supplied by the source.</Li>
          <Li><strong>S (apparent power, kVA):</strong> the vector sum — what the cables and transformer actually carry: <code className="bg-surface-inset text-ink-data px-1 rounded text-xs">S = √(P² + Q²)</code>.</Li>
        </ul>
        <P>Power factor PF = P/S = cos(φ) — how efficiently the load uses the supplied power. A PF of 1.0 is ideal (pure resistive). Motors and transformers typically have PF 0.75–0.90 because they are inductive loads. The system aggregates P and Q separately as scalars, then computes S from the vector sum — scalar addition of VA values would overestimate S when loads have different power factors.</P>
        <Live s={s}>{s?.total_demand_kva} kVA = √({s?.total_demand_kw}² + {s?.total_demand_kvar}²)</Live>
      </>,
    },
    {
      id: 6, category: 'Engineering',
      q: 'How does your power factor correction work?',
      a: <>
        <P>When the system power factor falls below the PENRA threshold of 0.85, shunt capacitor banks must be installed to inject leading reactive power.</P>
        <Pre>{`Step 1 — Compute reactive power deficit:
  Q_current = P × tan(arccos(PF_current))
  Q_target  = P × tan(arccos(0.95))
  Q_needed  = Q_current − Q_target

Step 2 — Round up to nearest 0.5 kVAR manufactured step:
  Q_bank = ceil(Q_needed / 500) × 500 VAR

Step 3 — Size delta capacitors across 400V 3-phase:
  C = Q_bank / (3 × 2π × 50 × 400²)  Farads`}</Pre>
        <P>Delta connection is chosen because it reduces capacitance per unit by 1/3 compared to star, per IEC 60831.</P>
        <Live s={s}>System PF: {pfStatus(s?.system_power_factor)}</Live>
      </>,
    },
    {
      id: 7, category: 'Engineering',
      q: 'How does the 3-phase load balancing algorithm work?',
      a: <>
        <P>Single-phase loads must be distributed across phases A, B, C to minimise neutral current and transformer losses. The system uses a greedy First-Fit Decreasing algorithm:</P>
        <Pre>{`1. Sort all unphased single-phase components by VA descending (largest first)
2. For each component: assign to the phase with the lowest current so far
3. Update that phase's running current total: I += S / 230`}</Pre>
        <P>Imbalance is computed as:</P>
        <Pre>{`Imbalance% = (I_max − I_min) / I_avg × 100`}</Pre>
        <P>Thresholds: &lt;10% acceptable, 10–20% warning, &gt;20% critical. The largest-first ordering is key — it is a variant of bin-packing that has been proven to produce solutions within 11/9 of optimal in the worst case.</P>
      </>,
    },
    // ═══════════════════════════════════════════════════════ ALGORITHMS
    {
      id: 8, category: 'Algorithms',
      q: 'Why greedy for phase balancing instead of linear programming?',
      a: <>
        <P>The greedy FFD approach runs in O(n log n) and produces solutions within 11/9 of optimal — a proven bound for bin-packing variants.</P>
        <Live s={s}>{s?.component_count} components — at this scale greedy completes in under 1ms</Live>
        <P className="mt-2">An LP solver would require an external dependency (PuLP, GLPK, or OR-Tools), add deployment complexity, and provide no measurable improvement for n &lt; 1000. LP is appropriate for grid-scale scheduling with thousands of continuous variables — not for single-building phase assignment.</P>
      </>,
    },
    {
      id: 9, category: 'Algorithms',
      q: 'Prove your load scheduling window search is exact.',
      a: <>
        <P>For contiguous shiftable loads the algorithm tries every possible window of length <code className="bg-surface-inset text-ink-data px-1 rounded text-xs">required_run_hours</code> within <code className="bg-surface-inset text-ink-data px-1 rounded text-xs">[earliest_start, latest_end]</code>. Since the search is exhaustive over all valid windows, it is provably optimal by definition — no valid window is skipped.</P>
        <P>The number of windows is at most <code className="bg-surface-inset text-ink-data px-1 rounded text-xs">(latest_end − earliest_start − required_run_hours + 1)</code>, which for a 24-hour day is at most 24 iterations — trivially fast.</P>
        <P>For fragmented loads the dynamic programming formulation is also exact:</P>
        <Pre>{`State:  dp[h][hours_run][interruptions_used] = min cost to reach hour h
All states explored, optimal substructure holds → DP is globally optimal.`}</Pre>
      </>,
    },
    {
      id: 10, category: 'Algorithms',
      q: 'What is the time complexity of your fragmented load DP?',
      a: <>
        <Pre>{`State space: 24 hours × (required_run_hours + 1) × (max_interruptions + 1)
Typical (8h run, 2 interruptions): 24 × 9 × 3 = 648 states
Each state has 2 transitions (ON / OFF): total = 1296 operations

Time:  O(H × R × I)   where H=24, R=required_run_hours, I=max_interruptions
Space: O(H × R × I)   for the DP table — negligible`}</Pre>
        <P>In practice this runs in microseconds for any realistic load configuration.</P>
      </>,
    },
    {
      id: 11, category: 'Algorithms',
      q: 'How does the curtailable load optimization work?',
      a: <>
        <P>Curtailable loads cannot be moved in time but can run at reduced power during expensive hours. The decision at each hour is independent — curtailing during expensive hours always reduces cost and never affects other hours, making this a greedy and provably optimal solution.</P>
        <Pre>{`For each hour h:
  if cost[h] = 0 (free solar):       run at 100%
  if cost[h] ≤ grid_tariff:          run at 100%
  if cost[h] > grid_tariff:          run at curtail_min_percent%

power[h] = rated_kw × (percentage / 100)
Savings   = cost_at_full_power_all_hours − cost_with_curtailment`}</Pre>
        <Live s={s}>{s?.curtailable_load_count} curtailable loads in this project</Live>
      </>,
    },
    {
      id: 12, category: 'Algorithms',
      q: 'How does the source dispatch algorithm work hour by hour?',
      a: <>
        <P>The dispatch runs 24 iterations, one per hour, in strict priority order:</P>
        <Pre>{`Step 1: Solar covers load first (free)
Step 2: Surplus solar charges batteries (paired first, then pool)
Step 3: Batteries discharge to cover remaining load
Step 4: Utility grid covers what batteries cannot
Step 5: Generator covers final remainder
Step 6: If generator running → opportunistically charge batteries
         at up to 85% of generator capacity (efficiency sweet spot)
Step 7: Any remaining unmet demand → logged as shortfall`}</Pre>
        <P>Priority is economic and environmental: free before cheap, clean before dirty. The 85% generator loading rule keeps the generator in its efficient operating band — diesel generators are most efficient at 70–85% of rated load.</P>
      </>,
    },
    {
      id: 13, category: 'Algorithms',
      q: 'How does the cost signal engine work and what drives the optimizer?',
      a: <>
        <P>The cost signal is a 24-element array (one value per hour) that represents the marginal cost of energy at each hour. It is computed in <code className="bg-surface-inset text-ink-data px-1 rounded text-xs">CostSignalService.php</code> as a blend of two layers:</P>
        <Pre>{`Monetary layer: tariff_per_kwh OR generator_cost_per_kwh (whichever exists)
Solar layer:    discount = base_cost × (1.0 − 0.9 × solarFrac)
                where solarFrac = solar[h] / max(solar) ∈ [0, 1]

Final signal[h] = max(0, base_cost × (1 − 0.9 × solarFrac))`}</Pre>
        <P>During peak solar hours the cost signal drops to 10% of base — the shiftable load optimizer then picks windows with the lowest cumulative signal cost, naturally concentrating loads into solar hours. This integrates economic and environmental signals into a single 24-hour vector.</P>
      </>,
    },
    // ═══════════════════════════════════════════════════════ STANDARDS
    {
      id: 14, category: 'Standards',
      q: 'What exactly is IEC 60364-8-1 and how did you implement it?',
      a: <>
        <P>IEC 60364-8-1:2019 is the international standard for energy efficiency in low-voltage electrical installations. It provides diversity factor tables by building type (Table B.1) that define how to scale rated loads to realistic maximum demand.</P>
        <P>This system implements it through:</P>
        <ul className="space-y-1 my-2">
          <Li>12 building types with their Room→Floor and Floor→Building DFs</Li>
          <Li>15 room types with individual coincidence factors (0.25–1.00)</Li>
          <Li>The 0.70 project-level top-of-hierarchy factor from §8.3</Li>
          <Li>Critical load override (DF = 1.00) for life-safety equipment</Li>
        </ul>
        <P>The implementation is in <code className="bg-surface-inset text-ink-data px-1 rounded text-xs">DiversityFactorService.php</code> which applies the correct factor at each level of the project hierarchy.</P>
      </>,
    },
    {
      id: 15, category: 'Standards',
      q: 'What is PENRA and why is 0.95 the target power factor?',
      a: <>
        <P>PENRA is the Power and Energy Regulatory Authority — the body that regulates electricity supply standards in Palestine. PENRA mandates that consumers maintain a power factor above 0.85. Below 0.85 triggers mandatory capacitor bank installation.</P>
        <P>The system targets 0.95 (above the minimum) because it is the industry-standard "good practice" target, it provides margin above the 0.85 penalty threshold, further correction beyond 0.95 gives diminishing returns, and over-correction can cause leading PF which is also penalised.</P>
        <Live s={s}>System PF: {pfStatus(s?.system_power_factor)}</Live>
      </>,
    },
    {
      id: 16, category: 'Standards',
      q: "How did you implement Spencer's solar declination formula?",
      a: <>
        <P>Spencer (1971) published a Fourier series approximation for solar declination. This system uses the simplified single-term version:</P>
        <Pre>{`δ = 23.45 × sin(360/365 × (doy − 81))  degrees
where doy = day of year of the mid-month (January 15 → doy = 15)`}</Pre>
        <P>Accuracy: ±0.3° which is sufficient for monthly energy calculations. The full 6-term Fourier series achieves ±0.01° but the added complexity is not justified for monthly averages — the error in energy output is &lt;0.1%. The declination feeds into the hour-angle formula:</P>
        <Pre>{`cos(H_ss) = −tan(latitude) × tan(δ)
→ exact sunrise and sunset times for any location on Earth`}</Pre>
      </>,
    },
    {
      id: 17, category: 'Standards',
      q: 'How does NASA POWER integration work and what does it give you?',
      a: <>
        <P>NASA POWER (Prediction of Worldwide Energy Resources) is a satellite-derived dataset providing surface solar irradiance globally. When a project has GPS coordinates, the system queries:</P>
        <Pre>{`Endpoint: power.larc.nasa.gov/api/temporal/hourly/point
Parameter: ALLSKY_SFC_SW_DWN (all-sky surface shortwave downward irradiance)
Day: the 15th of each month as the representative day
Returns: hourly W/m² values — ±3% accuracy for monthly means
Cache: 30 days (historical satellite data does not change)`}</Pre>
        <P>If the API is unavailable the system falls back to a static 7-latitude PSH lookup table and flags the response with <code className="bg-surface-inset text-ink-data px-1 rounded text-xs">solar_data_source: 'nasa_fallback'</code>.</P>
        <Live s={s}>
          {s?.has_solar
            ? `Solar installed: ${s.solar_capacity_kw} kW`
            : 'No solar systems configured in this project'}
        </Live>
      </>,
    },
    {
      id: 18, category: 'Standards',
      q: 'What is CIBSE Guide C and how does it relate to this project?',
      a: <>
        <P>CIBSE Guide C is the Chartered Institution of Building Services Engineers reference for reference data and formulae used in building services engineering. It is referenced in this project for the three-tier socket demand factors (100%/75%/40%) and the system-size coincidence factors applied when aggregating socket loads. These are standard reference values used by UK and internationally-aligned electrical engineers.</P>
        <P>The integration of CIBSE Guide C alongside IEC 60364-8-1, PENRA, NEC Article 430, and IEC 60831 demonstrates that the system uses a multi-standard approach aligned with Palestinian electrical engineering practice, which draws from both European (IEC) and UK traditions.</P>
      </>,
    },
    // ═══════════════════════════════════════════════════════ ARCHITECTURE
    {
      id: 19, category: 'Architecture',
      q: 'Why Laravel + React instead of a monolithic framework or Next.js?',
      a: <>
        <P><strong>Laravel</strong> was chosen because it is the leading PHP framework with excellent ORM (Eloquent), Sanctum provides simple token-based API auth out of the box, Socialite handles Google OAuth in a few lines, Form Requests give clean reusable validation, and the team had existing Laravel experience.</P>
        <P><strong>React</strong> was chosen because component-based architecture suits the complex nested UI (project → buildings → floors → rooms → components), Recharts integrates naturally for 24-hour visualizations, and React Router v6 gives clean client-side navigation.</P>
        <P><strong>Next.js was rejected</strong> because SSR adds complexity for a data-heavy SPA, the engineering calculations are all server-side in Laravel anyway, and SSR provides no SEO benefit for an authenticated engineering tool.</P>
      </>,
    },
    {
      id: 20, category: 'Architecture',
      q: 'Why REST API instead of GraphQL?',
      a: <>
        <P>REST was chosen because the data model is hierarchical and well-defined (no underfetching problem), all 56 endpoints have clear predictable response shapes, Laravel's resource routing maps naturally to REST conventions, and GraphQL adds schema definition overhead and a resolver layer.</P>
        <P>GraphQL is most valuable when clients have unpredictable or highly variable data requirements — like a public API consumed by many different clients. For a single SPA consuming a well-defined backend, REST is simpler and faster to develop and debug.</P>
      </>,
    },
    {
      id: 21, category: 'Architecture',
      q: 'Why SQLite instead of MySQL or PostgreSQL?',
      a: <>
        <P>SQLite requires zero server configuration — the entire database is one file. This makes the application portable, easy to deploy, and easy to back up. All Eloquent ORM queries are fully database-agnostic — switching to PostgreSQL requires only changing three lines in <code className="bg-surface-inset text-ink-data px-1 rounded text-xs">.env</code>.</P>
        <Live s={s}>{s?.building_count} buildings, {s?.floor_count} floors, {s?.room_count} rooms, {s?.component_count} components — SQLite handles this comfortably</Live>
        <P className="mt-2">For a multi-user production deployment: change <code className="bg-surface-inset text-ink-data px-1 rounded text-xs">DATABASE_CONNECTION=pgsql</code>, run <code className="bg-surface-inset text-ink-data px-1 rounded text-xs">php artisan migrate:fresh</code> — zero code changes required.</P>
      </>,
    },
    {
      id: 22, category: 'Architecture',
      q: 'How does the request lifecycle work from UI click to calculation result?',
      a: <>
        <P>Example: user opens the Load Schedule page for a project.</P>
        <Pre>{` 1. React mounts  → axios GET /api/projects/{id}/schedule
 2. Sanctum middleware validates the auth token
 3. ProjectPolicy checks the user is a member of the project
 4. ScheduleController@project is invoked
 5. Controller eager-loads the full project hierarchy in one query set
 6. DiversityFactorService computes weighted demand per component
 7. SourceDispatchService runs the 24-hour dispatch simulation
 8. SolarIrradianceService fetches NASA POWER data (or returns cache)
 9. CostSignalService computes the 24-hour cost array
10. Controller aggregates all results into a single JSON response
11. React stores response in useState and renders Recharts graphs

Total round-trip: under 500ms including NASA API cache hit`}</Pre>
      </>,
    },
    {
      id: 23, category: 'Architecture',
      q: 'How does authentication work — what happens when a user logs in?',
      a: <>
        <P><strong>Path 1 — Google OAuth:</strong> User clicks "Sign in with Google" → Socialite redirects to Google consent screen → Google returns authorization code → Socialite exchanges for user profile data → System creates or finds user record → Sanctum generates an API token → Token stored in memory (not localStorage) → All subsequent API calls include <code className="bg-surface-inset text-ink-data px-1 rounded text-xs">Authorization: Bearer {'{token}'}</code>.</P>
        <P><strong>Path 2 — Admin credential login:</strong> <code className="bg-surface-inset text-ink-data px-1 rounded text-xs">POST /api/admin/login</code> with email + password → Laravel validates credentials → Sanctum generates token → Used only for admin access, regular users must use Google OAuth.</P>
      </>,
    },
    {
      id: 24, category: 'Architecture',
      q: 'How do you prevent N+1 query problems in calculation endpoints?',
      a: <>
        <P>N+1 occurs when loading a collection triggers one database query per item. For a project with 10 buildings × 5 floors × 10 rooms × 10 components that would be 1 + 10 + 50 + 500 + 5000 = <strong>5561 queries per request</strong>.</P>
        <P>The solution is eager loading — all relationships are loaded in one query set:</P>
        <Pre>{`Project::with([
  'buildings.floors.rooms.roomComponents.componentType',
  'buildings.floorComponents',
  'buildings.buildingComponents',
  'projectComponents',
  'solarSystems', 'batteries',
  'utilitySources', 'generatorSources'
])->findOrFail($id)`}</Pre>
        <P>This reduces 5000+ queries to approximately 12 queries regardless of project size. The comment in <code className="bg-surface-inset text-ink-data px-1 rounded text-xs">ScheduleController.php</code> explicitly documents the N+1 problem and the solution.</P>
      </>,
    },
    // ═══════════════════════════════════════════════════════ FINANCIAL
    {
      id: 25, category: 'Financial',
      q: 'How does the financial model calculate the 25-year projection?',
      a: <>
        <P>Year-by-year simulation with these factors:</P>
        <Pre>{`Panel degradation:  0.5%/year (crystalline silicon industry standard)
  year_solar_kwh  = base_solar_kwh × (0.995)^year
  year_savings    = base_annual_savings × (0.995)^year

Battery replacement: triggered when battery age > cycle_life / 365.25
  replacement_cost read from battery.replacement_cost field

  year_net        = year_savings − battery_replacement − maintenance
  cumulative[y]   = cumulative[y-1] + year_net

Payback: first year where cumulative turns positive
LCOE:   (installation + maintenance × 25) / (solar_kwh_annual × 25)`}</Pre>
        <P>LCOE is the standard IEA/IRENA metric for comparing energy sources.</P>
      </>,
    },
    {
      id: 26, category: 'Financial',
      q: 'What assumptions does the financial model make?',
      a: <>
        <P>Explicit assumptions (documented in the model):</P>
        <ul className="space-y-1 my-2">
          <Li>Grid tariff is constant over 25 years (conservative — tariffs typically rise)</Li>
          <Li>Panel degradation is 0.5%/year linear (from IEC 61215 testing data)</Li>
          <Li>Battery replacement at end of rated cycle life (manufacturer spec)</Li>
          <Li>Performance ratio 0.80 constant (soiling and inverter losses stable)</Li>
          <Li>No financing cost — cash purchase assumed (no interest calculation)</Li>
          <Li>Fuel prices are constant (conservative for generator cost)</Li>
        </ul>
        <P>These assumptions make the model slightly pessimistic on solar savings (real tariff increases would improve payback) and slightly optimistic on generator costs (fuel prices historically rise). The model is therefore a reasonable conservative estimate.</P>
      </>,
    },
    {
      id: 27, category: 'Financial',
      q: 'What is the difference between simple payback and LCOE?',
      a: <>
        <Pre>{`Simple Payback = total_investment / annual_savings
  ✓ Easy to understand, widely used for quick decisions
  ✗ Ignores time value of money and long-term degradation
  Good for: comparing options with similar lifetimes

LCOE = total_lifetime_cost / total_lifetime_energy   ($/kWh)
  ✓ Accounts for full lifetime: installation + maintenance + replacement
  ✓ Directly comparable to grid tariff
  ✓ Industry standard: IEA, IRENA, all utility-scale projects
  If LCOE < grid_tariff → solar is cheaper over its lifetime`}</Pre>
        <Live s={s}>Solar capacity: {s?.solar_capacity_kw} kW — fetch LCOE from the financial-analysis endpoint</Live>
      </>,
    },
    // ═══════════════════════════════════════════════════════ LIMITATIONS
    {
      id: 28, category: 'Limitations',
      q: 'What does this tool NOT do? What are its known limitations?',
      a: <>
        <P>The system now includes cable sizing, protective device selection, and voltage-drop calculation via the Electrical Design module (IEC 60364-5-52). Remaining honest limitations:</P>
        <ol className="space-y-1 my-2 ml-4 list-decimal">
          <li>No short-circuit calculation — fault current levels not computed</li>
          <li>No protection coordination — breaker discrimination curves not verified</li>
          <li>No harmonic analysis — assumes sinusoidal waveforms throughout</li>
          <li>No dynamic simulation — dispatch is quasi-static (hourly averages)</li>
          <li>No weather uncertainty — solar model uses monthly averages, not distributions</li>
          <li>No demand forecasting — load profile is based on user-entered schedules</li>
          <li>Single location per project — cannot model geographically distributed sites</li>
          <li>No real-time data — not connected to actual meters or SCADA systems</li>
        </ol>
        <P>These are known scope limitations of a graduation project tool. A professional tool like ETAP would cover items 1–2.</P>
      </>,
    },
    {
      id: 29, category: 'Limitations',
      q: 'How is this different from existing tools like ETAP or DIALux?',
      a: <>
        <P><strong>ETAP</strong> (professional power system analysis): covers short-circuit, protection coordination, arc flash — this tool does not. Costs thousands of dollars per license — this tool is free and web-based. Has no integrated solar/BESS dispatch optimization and no building-hierarchy diversity factor system.</P>
        <P><strong>DIALux</strong> is a lighting design tool — entirely different scope.</P>
        <P><strong>This tool's unique value:</strong> integrates demand calculation + solar + BESS + dispatch + optimization in a single workflow — no existing free tool does this end-to-end. Implements real international standards (IEC, NEC, PENRA) automatically. Uses real NASA satellite data, not manual irradiance input. Provides financial analysis and load optimization in the same platform.</P>
      </>,
    },
    {
      id: 30, category: 'Limitations',
      q: 'What are the edge cases and how does the system handle them?',
      a: <>
        <P>Seven edge cases are explicitly handled:</P>
        <ol className="space-y-1 my-2 ml-4 list-decimal">
          <li>Battery SOC hits zero — stops discharge, falls through to next source</li>
          <li>NASA API unavailable — auto-fallback to static PSH table, logged</li>
          <li>Unmet demand — logged per hour, warning banner shown to user</li>
          <li>No sources configured — structured error with link to add sources</li>
          <li>Solar system with no roof area — returns 0 capacity with warning</li>
          <li>Optimizer finds no improvement — returns savings=0, reason explained</li>
          <li>Impossible constraints (required hours &gt; window width) — validation error with specific message naming the component and the conflict</li>
        </ol>
        <P>None of these cases crash the application or return null to the frontend.</P>
      </>,
    },
    {
      id: 31, category: 'Limitations',
      q: 'How does the system handle a project with only a generator (no grid, no solar)?',
      a: <>
        <P>The dispatch algorithm is fully source-agnostic — it only uses what exists. With generator only, Steps 1–3 (solar, battery) produce zero contribution, Step 4 (grid) is skipped because no utility lines exist, and Step 5 covers all demand up to rated generator capacity. Any demand above generator capacity is logged as unmet.</P>
        <P>The financial model computes 100% generator cost. The optimizer still finds the cheapest hours to run shiftable loads — in this case all hours cost the same (flat generator tariff), so it schedules loads at earliest convenience and reports savings = 0.</P>
      </>,
    },
    // ═══════════════════════════════════════════════════════ GENERAL
    {
      id: 32, category: 'General',
      q: 'Walk me through the most technically challenging part of this project.',
      a: <>
        <P>The most technically challenging part was the multi-source dispatch algorithm combined with the battery state-of-charge simulation. The challenge: battery charging and discharging interact with solar surplus and load demand simultaneously, creating a stateful simulation where each hour's result depends on the previous hour's SOC.</P>
        <P>Getting the charging priority right (paired solar systems charge their paired batteries first before the common pool) required careful sequencing. Getting the round-trip efficiency applied correctly (charge efficiency × discharge efficiency, not just once) was a subtle but important detail that directly affects the accuracy of the BESS simulation.</P>
        <Live s={s}>
          {s?.has_battery
            ? `This project has ${s.battery_capacity_kwh} kWh of BESS capacity`
            : 'This project has no battery storage'}
        </Live>
      </>,
    },
    {
      id: 33, category: 'General',
      q: 'If you could redesign one part of the system, what would it be?',
      a: <>
        <P>The database schema for components. Currently there are four separate component tables (<code className="bg-surface-inset text-ink-data px-1 rounded text-xs">room_components</code>, <code className="bg-surface-inset text-ink-data px-1 rounded text-xs">floor_components</code>, <code className="bg-surface-inset text-ink-data px-1 rounded text-xs">building_components</code>, <code className="bg-surface-inset text-ink-data px-1 rounded text-xs">project_components</code>) with identical columns duplicated across all four.</P>
        <P>A cleaner design would use a single <code className="bg-surface-inset text-ink-data px-1 rounded text-xs">components</code> table with a polymorphic relation pointing to the parent entity. This would eliminate schema duplication, make migrations simpler, and reduce the number of queries needed to fetch all components. The current design was chosen for simplicity of joins in calculation services, but the polymorphic approach would have been more maintainable long-term.</P>
      </>,
    },
    {
      id: 34, category: 'General',
      q: 'How would you scale this to serve 10,000 users simultaneously?',
      a: <>
        <P>Four changes needed for large-scale deployment:</P>
        <ol className="space-y-1 my-2 ml-4 list-decimal">
          <li><strong>Database:</strong> SQLite → PostgreSQL with connection pooling (PgBouncer). Zero code changes required — Eloquent is driver-agnostic.</li>
          <li><strong>Cache:</strong> Add Redis for NASA POWER results and dispatch simulation output. Already cached 30 days — Redis makes cache shared across instances.</li>
          <li><strong>Queue:</strong> Move financial analysis and dispatch calculations to Laravel Queue. Long calculations run asynchronously, frontend polls for result.</li>
          <li><strong>Horizontal scaling:</strong> Laravel is stateless — run multiple instances behind a load balancer (Nginx or AWS ALB).</li>
        </ol>
        <P>The current architecture was built with this path in mind — no static state in services, no session dependency in API routes.</P>
      </>,
    },
    {
      id: 35, category: 'General',
      q: 'What testing did you do to verify the system?',
      a: <>
        <P>Four levels of verification:</P>
        <ol className="space-y-1 my-2 ml-4 list-decimal">
          <li><strong>PHPUnit automated tests:</strong> 36 tests, 152 assertions covering the Electrical Design module — circuit classification, cable sizing, breaker sizing, RCD policy, voltage-drop computation, derating, and group-critical logic. All tests pass on the current codebase.</li>
          <li><strong>Reference case study (<code className="bg-surface-inset text-ink-data px-1 rounded text-xs">/validation</code>):</strong> a 2-floor office building is hand-calculated using the exact formulas from the technical report, then compared against system output with 0.1% tolerance. The IEC 60364-5-52 cable ampacity table (12 sizes) and voltage-drop spot checks are verified live on the /validation page.</li>
          <li><strong>Integration-level:</strong> each API endpoint was tested via the frontend — adding components and verifying the total-power response updates correctly.</li>
          <li><strong>Solar model:</strong> NASA POWER data was compared against the static PSH lookup table for Gaza (31.5°N) and confirmed within expected seasonal variation ranges.</li>
        </ol>
      </>,
    },
    {
      id: 36, category: 'General',
      q: 'Why did you choose this project topic?',
      a: <>
        <P>Three reasons: (1) <strong>Real local need</strong> — Gaza has severe electricity shortages with 8–12 hour daily outages. Optimizing solar + battery + generator dispatch is not theoretical — it is a daily engineering challenge for every building. (2) <strong>Academic fit</strong> — the project combines electrical engineering standards, algorithms, web development, and data science in one tool, demonstrating breadth across the Computer Engineering curriculum. (3) <strong>Practical impact</strong> — the tool can be used immediately by engineers to design power systems for real buildings.</P>
      </>,
    },
    {
      id: 37, category: 'General',
      q: 'How long did this project take to build?',
      a: <>
        <P>Built as a graduation project over approximately one semester. Key milestones:</P>
        <Pre>{`Architecture and database design:          2 weeks
Core calculation services (DF, PF, motor): 3 weeks
Solar model and NASA POWER integration:    2 weeks
BESS and dispatch algorithm:               2 weeks
REST API (56 endpoints) + authentication:  2 weeks
React frontend and Recharts visualizations: 3 weeks
Load optimizer and cost signal engine:     2 weeks
Financial analysis module:                 1 week
Testing, validation, documentation:        1 week

Tech stack: Laravel 13 / PHP 8.4 + React 18 / Vite + SQLite + NASA POWER API`}</Pre>
      </>,
    },
    {
      id: 38, category: 'General',
      q: 'What would you add with three more months?',
      a: <>
        <P>Three additions in priority order:</P>
        <ol className="space-y-1 my-2 ml-4 list-decimal">
          <li><strong>Protection coordination and short-circuit calculation:</strong> compute three-phase fault currents at each panel board using source impedance and cable impedance, then verify that each MCB clears faults before the upstream breaker trips (discrimination / selectivity curves per IEC 60947-2). This is the natural next step after the cable sizing module that was completed.</li>
          <li><strong>Multi-day load optimization:</strong> some loads (EV charging, water heating, industrial processes) are better optimized across a week, not 24 hours. This requires extending the DP state space from 24 to 168 time slots.</li>
          <li><strong>Real-time tariff integration:</strong> connect to utility company APIs so tariffs update automatically — eliminates manual cost entry.</li>
        </ol>
        <P>The current service-layer architecture supports all three without structural changes — the calculation services are fully decoupled from HTTP.</P>
      </>,
    },
    // ═══════════════════════════════════════════════════════ EXTRA (beyond spec)
    {
      id: 39, category: 'Engineering',
      q: 'Explain the paired vs. pooled battery charging architecture.',
      a: <>
        <P>A battery bank can be paired to a specific named solar system (e.g. "Roof A" paired to "Battery Bank A"), or left unpaired and treated as a shared pool. The dispatch handles these differently in Step 2:</P>
        <Pre>{`Step 2a: Each solar system charges its PAIRED batteries first
         using its own surplus (solar[h] − load_covered[h])
         until battery reaches target SOC (default 95%)

Step 2b: Remaining surplus from ALL solar systems is pooled
         and distributed to UNPAIRED battery banks pro-rata
         by remaining capacity`}</Pre>
        <P>This design allows, for example, a rooftop system on Building A to preferentially charge a UPS battery serving the same building's critical loads, while the project-level battery pool covers everything else. Without pairing, all surplus would be distributed uniformly, which may not reflect the physical wiring topology.</P>
        <Live s={s}>{s?.has_battery ? `${s.battery_capacity_kwh} kWh BESS installed` : 'No batteries in this project'}</Live>
      </>,
    },
    {
      id: 40, category: 'Architecture',
      q: 'How does the project collaboration and access control system work?',
      a: <>
        <P>Each project has an owner (the user who created it) and optional members. Access control is enforced at every project-scoped endpoint via <code className="bg-surface-inset text-ink-data px-1 rounded text-xs">$project-&gt;userRole($userId)</code> which returns one of:</P>
        <Pre>{`'admin'  → project owner (can delete the project, manage members)
'main'   → trusted member (can edit everything, run optimizer)
'normal' → read-only member (can view but not modify)
null     → no access → 403 Forbidden`}</Pre>
        <P>Project members are managed via the <code className="bg-surface-inset text-ink-data px-1 rounded text-xs">project_users</code> pivot table. The project index endpoint returns both owned projects and shared-to-user projects in a single list, so collaborators see the project in their dashboard without any extra steps.</P>
      </>,
    },
    {
      id: 41, category: 'Architecture',
      q: 'What API security measures are in place?',
      a: <>
        <P>Five layers of protection:</P>
        <ol className="space-y-1 my-2 ml-4 list-decimal">
          <li><strong>Authentication:</strong> Laravel Sanctum token — every protected endpoint requires a valid Bearer token. No token = 401 Unauthorized.</li>
          <li><strong>Authorization:</strong> per-project role checks (<code className="bg-surface-inset text-ink-data px-1 rounded text-xs">userRole()</code>) on every project-scoped endpoint — users can only access their own projects.</li>
          <li><strong>Input validation:</strong> all endpoints use Form Request classes or inline <code className="bg-surface-inset text-ink-data px-1 rounded text-xs">validate()</code> with typed rules (min/max on numerics, enums on strings) — invalid input returns 422 with field-level error messages.</li>
          <li><strong>Rate limiting:</strong> three tiers — general (api-general), heavy calculations (api-heavy, 20/min), optimizer/financial (10/min).</li>
          <li><strong>SQL injection prevention:</strong> Eloquent ORM for all queries — no raw SQL in the codebase, so parameterization is enforced at the framework level.</li>
        </ol>
      </>,
    },
    {
      id: 42, category: 'Engineering',
      q: 'What is the difference between the load profile and the dispatch simulation?',
      a: <>
        <P><strong>Load profile</strong> (<code className="bg-surface-inset text-ink-data px-1 rounded text-xs">/load-profile</code>) answers: <em>what is the demand at each hour?</em> It applies diversity factors and component schedules to produce a 24-hour demand curve in watts. No sources are considered — it is pure load-side calculation.</P>
        <P><strong>Dispatch simulation</strong> (<code className="bg-surface-inset text-ink-data px-1 rounded text-xs">/schedule</code>) answers: <em>where does each kWh come from?</em> It takes the load profile as input, then simulates the supply sources (solar → battery → utility → generator) hour by hour to determine how much each source contributes. It produces the stacked-area chart, daily energy statistics, and generator/utility costs.</P>
        <P>The separation is intentional — load profile can be reused across different source configurations without recalculation, and the dispatch service is independently testable against any demand array.</P>
      </>,
    },
    // ═══════════════════════════════════════════════════════ ELECTRICAL DESIGN (new)
    {
      id: 43, category: 'Engineering',
      q: 'What is the Electrical Design module and what does it produce?',
      a: <>
        <P>The Electrical Design module generates a <strong>panel schedule</strong> — a professional distribution board layout showing every circuit in a building. It takes the existing demand model (floors, rooms, loads) and classifies each load into one of five circuit types:</P>
        <Pre>{`HEAVY     — loads > 2 kVA (motors, AC units, large appliances)
CRITICAL  — life-safety loads (UPS, fire panel, emergency lighting)
SOCKET    — general-purpose socket outlets (1–8 per circuit)
LIGHTING  — general and emergency luminaires
AUXILIARY — AV, data, security, and other low-power fixed loads`}</Pre>
        <P>For each circuit the module outputs:</P>
        <ul className="space-y-0.5 my-2 ml-4 list-disc text-xs">
          <Li>Cable cross-section (mm²) per IEC 60364-5-52 Table B.52.2 (Method A1, conservative)</Li>
          <Li>PE conductor size per IEC 60364-5-54</Li>
          <Li>MCB rating and curve (B for socket/lighting, D for motor inrush)</Li>
          <Li>RCD requirement (30 mA for socket/lighting where policy requires)</Li>
          <Li>Utilisation % = I<sub>b</sub> / I<sub>n</sub> × 100</Li>
          <Li>Live voltage-drop ΔU% when the engineer enters the cable run length</Li>
        </ul>
        <P>The output matches the format of a real distribution board schedule used by electrical engineers to purchase equipment and verify compliance.</P>
      </>,
    },
    {
      id: 44, category: 'Standards',
      q: 'How does the system size cables and compute voltage drop per IEC 60364-5-52?',
      a: <>
        <P><strong>Cable sizing (ampacity):</strong> The service holds the IEC 60364-5-52 Table B.52.2 ampacity table — Method A1 (conductors in conduit in a thermally insulated wall), PVC/Cu, 2 loaded conductors, 30°C ambient reference. Table B.52.2 Method A1 is the most conservative installation method and is adopted deliberately to give a built-in safety margin. For 40°C ambient (Gaza climate), a derating factor is applied:</P>
        <Pre>{`Derating = √((T_max − T_ambient) / (T_max − T_ref))
         = √((70 − 40) / (70 − 30))
         = √0.75 ≈ 0.866   → tabled as 0.87 in IEC B.52.14

Cable selection: smallest standard size where Iz × 0.87 ≥ In`}</Pre>
        <P><strong>Voltage drop (mV/A/m method):</strong> Cables are also characterised by a millivolt-per-ampere-per-metre value (from resistance + inductive reactance):</P>
        <Pre>{`ΔU (V)   = mV/A/m × Ib × length_m / 1000
ΔU (%)   = ΔU / V_nominal × 100
           (V_nominal: 230 V single-phase, 400 V three-phase)

Limits:  3 % for LIGHTING circuits (IEC 60364-8-1 Table 1)
         5 % for all other circuit types`}</Pre>
        <P>When the user enters the cable run length in the panel schedule table, ΔU% is computed live in the browser. If it exceeds the limit, a warning is shown and the next larger cable size that would satisfy the limit is suggested — but never applied automatically, preserving engineer judgement.</P>
        <Live s={null}>Both the ampacity table and VD formula are verified on the /validation page</Live>
      </>,
    },
    {
      id: 45, category: 'Engineering',
      q: 'What is the group_small_critical option in the Electrical Design module?',
      a: <>
        <P>By default every CRITICAL load gets its own dedicated circuit (one-per-load). The <code className="bg-surface-inset text-ink-data px-1 rounded text-xs">group_small_critical</code> design rule changes this for small critical loads:</P>
        <Pre>{`group_small_critical = false (default):
  Each critical load → dedicated CRITICAL circuit

group_small_critical = true:
  va_each < motor_dedicated_threshold_va (default 750 VA)
    → all small criticals across the whole floor share ONE circuit
  va_each ≥ threshold
    → always gets a dedicated circuit regardless of flag`}</Pre>
        <P>Use case: a floor with 10 emergency LED luminaires at 20 VA each would generate 10 separate circuits at default settings. With <code className="bg-surface-inset text-ink-data px-1 rounded text-xs">group_small_critical = true</code> these pack into one 200 VA CRITICAL circuit — more practical for a real distribution board where breaker count is constrained. Large criticals (fire panel, UPS, life-support) always stay dedicated regardless of the flag, preserving life-safety isolation.</P>
        <P>No 30 mA RCD is fitted on any CRITICAL circuit under the <code className="bg-surface-inset text-ink-data px-1 rounded text-xs">30mA_socket_lighting</code> policy — this is correct because an RCD trip on a life-safety circuit is itself a hazard.</P>
      </>,
    },
  ];
}

// ── Accordion item ─────────────────────────────────────────────────────────────
function AccordionItem({ item, isOpen, onToggle }) {
  const contentRef = useRef(null);
  const [height, setHeight] = useState(0);

  useEffect(() => {
    if (contentRef.current) setHeight(isOpen ? contentRef.current.scrollHeight : 0);
  }, [isOpen]);

  return (
    <div className="border border-line rounded-xl overflow-hidden print-item">
      <button
        onClick={onToggle}
        className="w-full flex items-center gap-3 px-4 py-3.5 bg-surface-card hover:bg-surface-inset transition-colors text-left"
      >
        <span className="text-ink-muted text-sm font-mono w-6 shrink-0">{String(item.id).padStart(2, '0')}</span>
        <span className={`text-[11px] font-semibold px-2 py-0.5 rounded-full shrink-0 ${CAT_STYLES[item.category]}`}>
          {item.category}
        </span>
        <span className="flex-1 text-sm font-semibold text-ink-heading">{item.q}</span>
        <svg
          className={`w-4 h-4 text-ink-muted shrink-0 transition-transform duration-200 ${isOpen ? 'rotate-180' : ''}`}
          fill="none" stroke="currentColor" viewBox="0 0 24 24"
        >
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
        </svg>
      </button>

      <div
        className="print-content overflow-hidden transition-all duration-200 ease-in-out"
        style={{ maxHeight: height }}
      >
        <div ref={contentRef}>
          <div className="px-4 pb-4 pt-1 text-sm text-ink-body2 bg-surface-inset leading-relaxed border-t border-line-subtle">
            {item.a}
          </div>
        </div>
      </div>
    </div>
  );
}

// ── Main page ──────────────────────────────────────────────────────────────────
export default function DefensePrepPage() {
  const { user } = useAuth();
  const [projects, setProjects]   = useState([]);
  const [selectedId, setSelectedId] = useState('');
  const [summary, setSummary]     = useState(null);
  const [fetching, setFetching]   = useState(false);
  const [activeCategory, setActiveCategory] = useState('All');
  const [openItems, setOpenItems] = useState(new Set());

  // Load projects list
  useEffect(() => {
    api.get('/api/projects').then(({ data }) => setProjects(data.data ?? []));
  }, []);

  // Fetch defense summary when project changes
  useEffect(() => {
    if (!selectedId) { setSummary(null); return; }
    setFetching(true);
    api.get(`/api/projects/${selectedId}/defense-summary`)
      .then(({ data }) => setSummary(data))
      .catch(() => setSummary(null))
      .finally(() => setFetching(false));
  }, [selectedId]);

  // Build questions with live data
  const allQuestions = buildQuestions(summary);
  const filtered = activeCategory === 'All'
    ? allQuestions
    : allQuestions.filter(q => q.category === activeCategory);

  const toggleItem = useCallback((id) => {
    setOpenItems(prev => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  }, []);

  function expandAll() {
    setOpenItems(new Set(filtered.map(q => q.id)));
  }

  function handlePrint() {
    setOpenItems(new Set(allQuestions.map(q => q.id)));
    setTimeout(() => window.print(), 300);
  }

  return (
    <div className="min-h-screen bg-base">
      {/* Print styles */}
      <style>{`
        @media print {
          .no-print { display: none !important; }
          .print-item { break-inside: avoid; margin-bottom: 12pt; }
          .print-content { max-height: none !important; overflow: visible !important; }
          nav, header button { display: none !important; }
          body { font-size: 10pt; background: white; }
          .print-footer::after {
            content: "Power Profile — Defense Preparation — Ahmed Zoher — June 2026";
            display: block; text-align: center; font-size: 8pt; color: #666;
            padding-top: 8pt; border-top: 1px solid #ccc; margin-top: 8pt;
          }
        }
      `}</style>

      {/* Top nav bar */}
      <nav className="bg-surface-card border-b border-line no-print">
        <div className="max-w-5xl mx-auto px-4 h-14 flex items-center justify-between">
          <div className="flex items-center gap-2">
            <div className="w-7 h-7 bg-accent rounded-lg flex items-center justify-center">
              <svg className="w-4 h-4 text-base" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
              </svg>
            </div>
            <span className="font-semibold text-ink-heading text-sm">Power Profile</span>
          </div>
          <Link to="/dashboard" className="text-xs text-ink-muted hover:text-accent transition-colors">
            ← Back to Dashboard
          </Link>
        </div>
      </nav>

      <div className="max-w-5xl mx-auto px-4 py-8 print-footer">

        {/* Header */}
        <div className="mb-6">
          <div className="flex items-start justify-between flex-wrap gap-4">
            <div>
              <div className="flex items-center gap-3 flex-wrap">
                <h1 className="text-2xl font-bold text-ink-heading">Defense Q&amp;A Reference</h1>
                <span className="inline-flex items-center gap-1.5 bg-accent-soft text-accent border border-accent-border text-xs font-semibold px-2.5 py-1 rounded-full">
                  <svg className="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fillRule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clipRule="evenodd" />
                  </svg>
                  Admin Only
                </span>
              </div>
              <p className="text-ink-muted text-sm mt-1">Graduation Project Examination Preparation Tool &mdash; {allQuestions.length} questions</p>
              {user && (
                <p className="text-xs text-ink-muted2 mt-0.5">Logged in as {user.name} {user.is_admin ? '· Admin' : ''}</p>
              )}
            </div>

            {/* Action buttons */}
            <div className="flex items-center gap-2 no-print">
              <button
                onClick={expandAll}
                className="px-3 py-1.5 text-xs font-semibold text-ink-body2 bg-surface-card border border-line rounded-lg hover:bg-surface-inset hover:border-line-strong transition-colors"
              >
                Expand All
              </button>
              <button
                onClick={handlePrint}
                className="px-3 py-1.5 text-xs font-semibold text-base bg-accent-gradient rounded-lg hover:shadow-accent transition-shadow flex items-center gap-1.5"
              >
                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                </svg>
                Print All
              </button>
            </div>
          </div>

          {/* Project selector */}
          <div className="mt-4 flex items-center gap-3 no-print">
            <label className="text-xs font-semibold text-ink-muted shrink-0">LIVE DATA FROM</label>
            <div className="relative flex-1 max-w-sm">
              <select
                value={selectedId}
                onChange={e => setSelectedId(e.target.value)}
                className="w-full border border-line rounded-lg px-3 py-2 text-sm bg-surface-card appearance-none pr-8 focus:outline-none focus:ring-2 focus:ring-accent/40 focus:border-accent"
              >
                <option value="">— Select a project —</option>
                {projects.map(p => (
                  <option key={p.id} value={p.id}>{p.name}</option>
                ))}
              </select>
              <div className="absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none">
                {fetching
                  ? <div className="w-4 h-4 border-2 border-accent border-t-transparent rounded-full animate-spin" />
                  : <svg className="w-4 h-4 text-ink-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" /></svg>
                }
              </div>
            </div>
            {summary && (
              <span className="flex items-center gap-1.5 text-xs text-accent bg-accent-soft border border-accent-border px-2 py-1 rounded-full font-medium">
                <span className="w-1.5 h-1.5 bg-accent-light rounded-full animate-pulse" />
                Live Data
              </span>
            )}
          </div>

          {/* Live stats strip */}
          {summary && (
            <div className="mt-3 p-3 bg-accent-soft border border-accent-border rounded-xl grid grid-cols-2 sm:grid-cols-4 gap-3 text-center no-print">
              {[
                ['Total Demand',  `${summary.total_demand_kva} kVA`],
                ['Power Factor',  summary.system_power_factor],
                ['Components',    summary.component_count],
                ['Solar',         summary.has_solar ? `${summary.solar_capacity_kw} kW` : 'None'],
              ].map(([label, value]) => (
                <div key={label}>
                  <div className="text-xs text-accent font-medium">{label}</div>
                  <div className="text-sm font-bold text-ink-heading">{value}</div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Category filter */}
        <div className="flex flex-wrap gap-1.5 mb-4 no-print">
          {CATEGORIES.map(cat => (
            <button
              key={cat}
              onClick={() => setActiveCategory(cat)}
              className={`px-3 py-1 text-xs font-semibold rounded-full border transition-colors ${
                activeCategory === cat
                  ? 'bg-accent-tint text-accent-bright border-accent-border-strong'
                  : 'bg-surface-card text-ink-body2 border-line hover:border-line-strong'
              }`}
            >
              {cat}
              {cat !== 'All' && (
                <span className="ml-1 opacity-60">
                  ({allQuestions.filter(q => q.category === cat).length})
                </span>
              )}
            </button>
          ))}
        </div>

        {/* Accordion */}
        <div className="space-y-2">
          {filtered.map(item => (
            <AccordionItem
              key={item.id}
              item={item}
              isOpen={openItems.has(item.id)}
              onToggle={() => toggleItem(item.id)}
            />
          ))}
        </div>

        {/* Standards footer */}
        <div className="mt-8 p-4 bg-surface-card border border-line rounded-xl no-print">
          <p className="text-xs font-semibold text-ink-muted mb-2">STANDARDS IMPLEMENTED</p>
          <div className="flex flex-wrap gap-2">
            {['IEC 60364-8-1','IEC 60364-5-52','IEC 60364-5-54','BS 7671','PENRA','NEC Article 430','IEC 60947-2','IEC 60831','IEC 61675-3','Spencer (1971)','NASA POWER v2','CIBSE Guide C'].map(s => (
              <span key={s} className="text-xs bg-surface-inset text-ink-body2 px-2 py-0.5 rounded-full">{s}</span>
            ))}
          </div>
        </div>

      </div>
    </div>
  );
}
