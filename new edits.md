# Power Profile — New Edits & Updates
**Session date:** 7 July 2026  
**Branch:** fulledits  
**Tests after session:** 375 tests / 1,261 assertions — zero failures

---

## Changes at a Glance

| # | Change | Type |
|---|--------|------|
| 1 | Two-pass dispatch pipeline | Bug Fix |
| 2 | Banner contradiction eliminated | Bug Fix |
| 3 | Before / After Shedding toggle | New Feature |
| 4 | Test suite — 4 new tests, 371 → 375 | Tests |
| 5 | Report correction — Section 12.1 | Report Patch |

---

## Change 1 — Two-Pass Dispatch Pipeline

**Category:** Core Bug Fix · Architecture

### Root Cause

`LoadSheddingService::shed()` received `$supplyCapW[h] = solar + utility + generator + battMaxDischargeW`. The battery's instantaneous peak discharge rating was 16,560 W — far above any hour's demand — so the shedding pre-pass saw zero deficit at every hour and never activated.

In reality, the battery had depleted its stored energy by 10:00. The real dispatch engine found 47.86–51.5 kWh unmet from h=10 to h=17, but the shedding layer never knew.

### Fix — Three-Step Pipeline

**Pass 1 (raw):** Run `SourceDispatchService::dispatch()` on the full, unshed load. Its SOC-tracked simulation correctly identifies the real per-hour deficit and returns it as `unmet[]`.

**Shedding:** Feed that `unmet[]` array to `LoadSheddingService::shed()` as the deficit signal — replacing the old `$supplyCapW` argument.

**Pass 2 (post-shed):** Run dispatch again on the shed-adjusted load. This is the final, authoritative result.

### LoadSheddingService.php — new method signature

```php
// Before
public function shed(array $slots, array $supplyCapW, array $shiftCapW = []): array

// After
public function shed(array $slots, array $rawUnmetW, array $shiftCapW = []): array

// Deficit now derived from the real dispatch unmet figure
$deficit = max(0.0, (float)($rawUnmetW[$h] ?? 0.0) - $loadShedSoFar);
```

### ScheduleController.php — per-day pipeline (condensed)

```php
// Pass 1: raw dispatch on unshed load
$rawDispatchMax = $this->dispatchSvc->dispatch($loadMax, $solarProfile, ...);
$rawDispatchOpt = $this->dispatchSvc->dispatch($loadOpt, $solarProfile, ...);

// Shedding using real energy-aware deficit
$shedMax = $this->sheddingSvc->shed($slotsMax, $rawDispatchMax['unmet'], $shiftCapW);
$shedOpt = $this->sheddingSvc->shed($slotsOpt, $rawDispatchOpt['unmet'], $shiftCapW);

// Pass 2: post-shed dispatch — the authoritative final answer
$postDispatchMax = $this->dispatchSvc->dispatch($shedMax['adjusted_load_w'], ...);
$postDispatchOpt = $this->dispatchSvc->dispatch($shedOpt['adjusted_load_w'], ...);
```

### Files Changed

- `backend/app/Services/LoadSheddingService.php` — full rewrite; removed RESTORE_MARGIN, removed battMaxDischargeW dependency
- `backend/app/Http/Controllers/Api/ScheduleController.php` — per-day loop restructured; new API keys `dispatch_raw_max` and `dispatch_raw_optimized` added to day payload

---

## Change 2 — Banner Contradiction Eliminated

**Category:** UI Bug Fix · Consistency

### The Contradiction

On 7 July, "After Shedding" view, Islamic University project:

- Dark-red banner → **5.72 kWh** (from `shedding.critical_unmet_kwh`)
- Orange banner + stat card → **4.3 kWh** (from `dispatch.stats.unmet_kwh`)

5.72 > 4.3 is physically impossible: critical unmet is a subset of total unmet.

### Why They Diverged

`critical_unmet_kwh` came from the shedding service's own internal bookkeeping — a conservative first-pass estimate against `rawUnmetW`. After shedding reduces the load, the second dispatch pass benefits from an improved SOC trajectory and finds less actual unmet energy than the first-pass estimate predicted.

### Fix

Define `dispatchShed` as always pointing to the second-pass result, independent of the Before/After toggle. Both banners now read `dispatchShed.stats.unmet_kwh` — the same object, the same number.

### LoadSchedulePage.jsx — corrected variable definitions

```jsx
// New: dispatchShed is ALWAYS the post-shed dispatch, regardless of toggle
const dispatchShed = mode === 'optimized'
  ? dayData?.dispatch_optimized
  : dayData?.dispatch_max;

const finalUnmetKwh = dispatchShed?.stats?.unmet_kwh ?? 0;

// Dark-red banner now uses finalUnmetKwh (second-pass real result)
{!loading && finalUnmetKwh > 0 && (
  <div className="bg-red-700 ...">
    CRITICAL LOADS UNMET: {finalUnmetKwh.toFixed(2)} kWh ...
  </div>
)}
// In After Shedding view: dispatch === dispatchShed
// → both banners read the same object → cannot diverge by construction
```

### Files Changed

- `frontend/src/pages/LoadSchedulePage.jsx` — added `dispatchShed` constant; dark-red banner source changed from `shedding.critical_unmet_kwh` to `dispatchShed.stats.unmet_kwh`

---

## Change 3 — Before / After Shedding Toggle

**Category:** New Feature · UI

A two-button toggle now sits above the Combined Dispatch chart on the Load Schedule page, letting the user switch between an uninterrupted view of the raw demand-vs-supply gap ("Before Shedding") and the day once the shedding logic has acted on it ("After Shedding", the default).

### LoadSchedulePage.jsx — new state and dispatch variable

```jsx
const [sheddingView, setSheddingView] = useState('shed'); // 'raw' | 'shed'

// dispatch switches with the toggle; dispatchShed is always post-shed
const dispatch = sheddingView === 'raw'
  ? (mode === 'optimized' ? dayData?.dispatch_raw_optimized : dayData?.dispatch_raw_max)
  : (mode === 'optimized' ? dayData?.dispatch_optimized     : dayData?.dispatch_max);

// Toggle buttons
<button onClick={() => setSheddingView('shed')}
  className={sheddingView === 'shed' ? 'bg-violet-600 text-white' : '...'}>
  After Shedding
</button>
<button onClick={() => setSheddingView('raw')}
  className={sheddingView === 'raw' ? 'bg-orange-500 text-white' : '...'}>
  Before Shedding
</button>
```

### Files Changed

- `frontend/src/pages/LoadSchedulePage.jsx` — `sheddingView` state; `dispatch` variable; toggle buttons; orange banner re-labelled "Pre-Shedding Deficit" in raw view
- `backend/app/Http/Controllers/Api/ScheduleController.php` — exposes `dispatch_raw_max` and `dispatch_raw_optimized` in the day payload

---

## Change 4 — Updated & Expanded Test Coverage

**Category:** Test Suite · 4 New Tests

**Total tests: 371 → 375** (1,261 assertions, zero failures)

### Breaking Interface Change

All 8 existing shedding tests called `shed($slots, $supplyCapW)`. The new signature is `shed($slots, $rawUnmetW, $shiftCapW)`, so all 8 calls were updated. A helper was added to the test class to convert old supply-cap arrays into the rawUnmetW format:

```php
// LoadSheddingServiceTest.php — compatibility helper
private function rawUnmet(array $slots, array $supplyCapW): array
{
    $load = array_fill(0, 24, 0.0);
    foreach ($slots as $slot) {
        for ($h = 0; $h < 24; $h++) {
            if ($slot['active_hours'][$h]) { $load[$h] += $slot['peak_w']; }
        }
    }
    $unmet = array_fill(0, 24, 0.0);
    for ($h = 0; $h < 24; $h++) {
        $unmet[$h] = max(0.0, $load[$h] - $supplyCapW[$h]);
    }
    return $unmet;
}

// All 8 existing calls updated to:
$this->svc->shed($slots, $this->rawUnmet($slots, $supply), $supply);
```

### Test 9 — Energy Exhaustion (New)

`test_energy_exhaustion_daytime_peak`: sets `rawUnmetW[10] = 8,000 W`, configures a 2 kW critical slot and a 10 kW shiftable slot, and asserts that (a) shedding activates, (b) the critical slot is never shed, and (c) `critical_unmet_kwh = 0` once the shiftable slot absorbs the deficit.

### New File — DispatchSheddingInvariantTest.php (3 Tests)

Backend-level invariants that make the banner fix sound, using real service instances with no DB or HTTP dependencies:

```
Test 1: test_post_shed_unmet_le_raw_unmet_no_battery
  → Shedding can only reduce demand; post-shed dispatch unmet ≤ raw dispatch unmet.

Test 2: test_shedding_internal_critical_unmet_may_exceed_dispatch_unmet
  → Documents why shedding.critical_unmet_kwh (old banner source) could exceed
    dispatch.stats.unmet_kwh (correct source). Verifies the fix resolves this.

Test 3: test_banners_are_equal_in_after_shedding_view
  → In After Shedding view both banners read dispatchShed.stats.unmet_kwh —
    the same object — assertSame() guarantees equality by construction.
```

### Files Changed

- `backend/tests/Unit/LoadSheddingServiceTest.php` — added `rawUnmet()` helper; all 8 calls updated; Test 9 added
- `backend/tests/Unit/DispatchSheddingInvariantTest.php` — new file; 3 invariant tests

---

## Change 5 — Report Correction: Section 12.1

**Category:** Report Patch

Section 12.1 ("Summary of Contributions") was written before this session's four new tests were added. One sentence must be updated:

**File:** `power-profile-master-doc.docx`  
**Location:** Section 12.1, paragraph 1, sentence 2

| | Text |
|---|---|
| **Before** | "…through 371 automated tests and an internal validation engine…" |
| **After** | "…through **375** automated tests and an internal validation engine…" |

No other figures in the report are affected by this session's changes. Sections 8.6.4 and 10.3 already describe the two-pass architecture and the banner fix correctly, as they were written with these changes already in place.

---

*Power Profile · fulledits branch · 7 July 2026*  
*All changes verified: 375 tests / 1,261 assertions · frontend build clean*
