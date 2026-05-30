/* eslint-disable react/prop-types */
import { useState, useEffect, useCallback, useRef } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import {
  ResponsiveContainer, ComposedChart, AreaChart,
  Area, Line, XAxis, YAxis, CartesianGrid,
  Tooltip, Legend, ReferenceLine,
} from 'recharts';
import api from '../api/axios';

// ── Formatters ────────────────────────────────────────────────────────────────
function fmtW(w) {
  const n = Number(w) || 0;
  if (Math.abs(n) >= 1_000_000) return `${(n / 1_000_000).toFixed(2)} MW`;
  if (Math.abs(n) >= 1_000)     return `${(n / 1_000).toFixed(1)} kW`;
  return `${Math.round(n)} W`;
}
function fmtKwh(v) {
  return `${Number(v).toFixed(1)} kWh`;
}
function hourLabel(h) {
  return `${String(h).padStart(2, '0')}:00`;
}

const MONTHS = [
  'January','February','March','April','May','June',
  'July','August','September','October','November','December',
];
const JS_DAY_NAMES = ['sunday','monday','tuesday','wednesday','thursday','friday','saturday'];

// ── Custom Tooltip ─────────────────────────────────────────────────────────────
function CustomTooltip({ active, payload, label, tab, mode }) {
  if (!active || !payload?.length) return null;

  const row = (color, name, val) => (
    <div key={name} className="flex items-center justify-between gap-6 text-xs">
      <div className="flex items-center gap-1.5">
        <span className="w-2.5 h-2.5 rounded-full flex-shrink-0" style={{ background: color }} />
        <span className="text-gray-600">{name}</span>
      </div>
      <span className="font-semibold text-gray-800">{fmtW(val)}</span>
    </div>
  );

  const byKey = Object.fromEntries(payload.map(p => [p.dataKey, p.value]));

  return (
    <div className="bg-white border border-gray-200 rounded-xl shadow-xl p-3 min-w-[180px]">
      <p className="text-xs font-bold text-gray-700 mb-2">{hourLabel(label)} – {hourLabel((label + 1) % 24)}</p>
      {tab === 'load' && (
        <>
          {row('#4f46e5', 'Max Load',   byKey.load_max)}
          {row('#10b981', 'Optimized', byKey.load_opt)}
          {(byKey.kvar ?? 0) > 0 && (
            <div className="flex items-center justify-between gap-6 text-xs">
              <div className="flex items-center gap-1.5">
                <span className="w-2.5 h-2.5 rounded-full flex-shrink-0" style={{ background: '#f59e0b' }} />
                <span className="text-gray-600">Reactive (kVAR)</span>
              </div>
              <span className="font-semibold text-gray-800">{Number(byKey.kvar).toFixed(2)} kVAR</span>
            </div>
          )}
        </>
      )}
      {tab === 'sources' && (
        <>
          {row('#f59e0b', 'Solar Generation', byKey.solar)}
          {row('#3b82f6', 'Utility Capacity', byKey.utility_cap)}
          {row('#f97316', 'Generator Capacity', byKey.gen_cap)}
        </>
      )}
      {tab === 'combined' && (
        <>
          {row('#6b7280', 'Total Demand',       byKey.demand)}
          {row('#f59e0b', 'Solar Used',          byKey.solar_used)}
          {(byKey.battery_disc ?? 0) > 0 && row('#8b5cf6', 'Battery ↓ Discharge', byKey.battery_disc)}
          {(byKey.battery_chrg_sol ?? 0) > 0 && row('#fbbf24', 'Battery ↑ Solar',    byKey.battery_chrg_sol)}
          {(byKey.battery_chrg_gen ?? 0) > 0 && row('#fb923c', 'Battery ↑ Generator', byKey.battery_chrg_gen)}
          {row('#3b82f6', 'Utility Used',        byKey.utility_used)}
          {row('#f97316', 'Generator Used',      byKey.gen_used)}
          {byKey.unmet > 0 && row('#ef4444', 'Unmet', byKey.unmet)}
          {byKey.battery_soc != null && (
            <div className="flex items-center justify-between gap-6 text-xs border-t border-gray-100 mt-1 pt-1">
              <span className="text-gray-500">Battery SOC</span>
              <span className="font-semibold text-violet-600">{Number(byKey.battery_soc).toFixed(1)}%</span>
            </div>
          )}
        </>
      )}
    </div>
  );
}

// ── Stat Card ─────────────────────────────────────────────────────────────────
function StatCard({ color, dot, label, hours, kwh, pct }) {
  return (
    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
      <div className="flex items-center gap-2 mb-3">
        <span className={`w-3 h-3 rounded-full flex-shrink-0 ${dot}`} />
        <span className="text-sm font-semibold text-gray-800">{label}</span>
      </div>
      <div className="grid grid-cols-3 gap-2 text-center">
        <div>
          <p className="text-lg font-bold text-gray-900">{hours}h</p>
          <p className="text-[10px] text-gray-400 uppercase tracking-wide">Active</p>
        </div>
        <div>
          <p className="text-lg font-bold" style={{ color }}>{fmtKwh(kwh)}</p>
          <p className="text-[10px] text-gray-400 uppercase tracking-wide">Delivered</p>
        </div>
        <div>
          <p className="text-lg font-bold text-gray-700">{pct}%</p>
          <p className="text-[10px] text-gray-400 uppercase tracking-wide">Share</p>
        </div>
      </div>
    </div>
  );
}

// ── Individual source sub-chart ───────────────────────────────────────────────
function SourceSubChart({ data, dataKey, demandKey, name, stroke, xTick }) {
  const gradId = `sub_${dataKey}`;
  return (
    <div>
      <div className="flex items-center gap-2 mb-2">
        <span className="w-2.5 h-2.5 rounded-full flex-shrink-0" style={{ background: stroke }} />
        <span className="text-xs font-semibold text-gray-700">{name}</span>
      </div>
      <ResponsiveContainer width="100%" height={140}>
        <ComposedChart data={data} margin={{ top: 4, right: 8, left: 0, bottom: 0 }}>
          <defs>
            <linearGradient id={gradId} x1="0" y1="0" x2="0" y2="1">
              <stop offset="5%"  stopColor={stroke} stopOpacity={0.45} />
              <stop offset="95%" stopColor={stroke} stopOpacity={0.05} />
            </linearGradient>
          </defs>
          <CartesianGrid strokeDasharray="3 3" stroke="#f3f4f6" />
          <XAxis dataKey="hour" tickFormatter={xTick} tick={{ fontSize: 9, fill: '#9ca3af' }} />
          <YAxis tickFormatter={fmtW} tick={{ fontSize: 9, fill: '#9ca3af' }} width={52} />
          <Tooltip
            formatter={(val, key) => [fmtW(val), key === demandKey ? 'Demand' : name]}
            labelFormatter={h => `${hourLabel(h)} – ${hourLabel((h + 1) % 24)}`}
          />
          <Area type="monotone" dataKey={dataKey} stroke={stroke}
            fill={`url(#${gradId})`} strokeWidth={2} dot={false} activeDot={{ r: 3 }} />
          {demandKey && (
            <Line type="monotone" dataKey={demandKey} stroke="#d1d5db"
              strokeWidth={1.5} dot={false} strokeDasharray="4 2" />
          )}
        </ComposedChart>
      </ResponsiveContainer>
    </div>
  );
}

// ── City search location setter ────────────────────────────────────────────────
function LocationCard({ projectId, location, onSaved }) {
  const [editing, setEditing]   = useState(false);
  const [query, setQuery]       = useState('');
  const [results, setResults]   = useState([]);
  const [searching, setSearching] = useState(false);
  const [selected, setSelected] = useState(null); // { lat, lng, name }
  const [saving, setSaving]     = useState(false);
  const [error, setError]       = useState('');

  // Debounced city search via OpenStreetMap Nominatim (free, no API key)
  useEffect(() => {
    const q = query.trim();
    if (q.length < 2) { setResults([]); return; }
    const timer = setTimeout(async () => {
      setSearching(true);
      try {
        const url =
          `https://nominatim.openstreetmap.org/search` +
          `?q=${encodeURIComponent(q)}&format=json&limit=7&addressdetails=1&featuretype=settlement`;
        const res  = await fetch(url, { headers: { 'Accept-Language': 'en-US,en' } });
        const json = await res.json();
        setResults(json);
      } catch {
        setResults([]);
      } finally {
        setSearching(false);
      }
    }, 350);
    return () => clearTimeout(timer);
  }, [query]);

  function pickResult(r) {
    const addr  = r.address ?? {};
    const city  = addr.city || addr.town || addr.village || addr.municipality || addr.county || '';
    const state = addr.state || '';
    const country = addr.country || '';
    const parts = [city, state, country].filter(Boolean);
    const name  = parts.length ? parts.join(', ') : r.display_name.split(',').slice(0, 3).join(',').trim();
    setSelected({ lat: parseFloat(r.lat), lng: parseFloat(r.lon), name });
    setResults([]);
    setQuery(name);
  }

  async function save() {
    if (!selected) { setError('Please select a city from the list.'); return; }
    setSaving(true); setError('');
    try {
      const { data } = await api.put(`/api/projects/${projectId}`, {
        location_lat:  selected.lat,
        location_lng:  selected.lng,
        location_name: selected.name,
      });
      onSaved(data.data);
      setEditing(false);
    } catch {
      setError('Save failed. Please try again.');
    } finally {
      setSaving(false);
    }
  }

  function openEditor() {
    setQuery(location?.name ?? '');
    setSelected(location?.lat != null ? { lat: location.lat, lng: location.lng, name: location.name } : null);
    setResults([]);
    setError('');
    setEditing(true);
  }

  const hasLocation = location?.lat != null;

  // ── Collapsed display ──
  if (!editing) {
    return (
      <div className="bg-white rounded-xl border border-gray-100 shadow-sm px-4 py-3 flex items-center gap-3">
        <div className="w-8 h-8 bg-amber-50 rounded-lg flex items-center justify-center flex-shrink-0">
          <svg className="w-4 h-4 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
          </svg>
        </div>
        <div className="flex-1 min-w-0">
          <p className="text-[10px] text-gray-400 uppercase tracking-wide">Project Location</p>
          {hasLocation ? (
            <p className="text-sm font-medium text-gray-800 truncate">
              {location.name || `${Number(location.lat).toFixed(4)}°, ${Number(location.lng).toFixed(4)}°`}
              <span className="ml-2 text-xs text-gray-400">
                ({Number(location.lat).toFixed(4)}°N, {Number(location.lng).toFixed(4)}°E)
              </span>
            </p>
          ) : (
            <p className="text-sm text-gray-400 italic">No location set — solar generation unavailable</p>
          )}
        </div>
        <button onClick={openEditor}
          className="flex-shrink-0 px-3 py-1.5 text-xs font-medium text-amber-600 bg-amber-50
            hover:bg-amber-100 rounded-lg transition-colors border border-amber-200">
          {hasLocation ? 'Change' : 'Set Location'}
        </button>
      </div>
    );
  }

  // ── Edit mode ──
  return (
    <div className="bg-white rounded-xl border border-amber-200 shadow-sm px-4 py-3 space-y-3">
      <div className="flex items-center gap-2">
        <svg className="w-4 h-4 text-amber-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
            d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
        <span className="text-sm font-semibold text-gray-800">Search City</span>
      </div>

      {/* Search input */}
      <div className="relative">
        <div className="relative">
          <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400 pointer-events-none"
            fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
          <input
            autoFocus
            type="text"
            value={query}
            onChange={e => { setQuery(e.target.value); setSelected(null); }}
            placeholder="Type a city name… e.g. Beirut, Paris, Dubai"
            className="w-full border border-gray-200 rounded-xl pl-9 pr-10 py-2.5 text-sm text-gray-800
              placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-amber-400 focus:border-transparent"
          />
          {searching && (
            <div className="absolute right-3 top-1/2 -translate-y-1/2
              w-3.5 h-3.5 border-2 border-amber-400 border-t-transparent rounded-full animate-spin" />
          )}
        </div>

        {/* Dropdown results */}
        {results.length > 0 && (
          <ul className="absolute z-50 mt-1 w-full bg-white border border-gray-200 rounded-xl shadow-xl
            overflow-hidden max-h-60 overflow-y-auto">
            {results.map((r, i) => {
              const addr    = r.address ?? {};
              const city    = addr.city || addr.town || addr.village || addr.municipality || '';
              const country = addr.country || '';
              const state   = addr.state || '';
              const primary = [city, state].filter(Boolean).join(', ') || r.display_name.split(',')[0];
              const secondary = country;
              return (
                <li key={i}>
                  <button
                    onClick={() => pickResult(r)}
                    className="w-full text-left px-4 py-2.5 hover:bg-amber-50 transition-colors
                      border-b border-gray-50 last:border-0 flex items-start gap-3">
                    <svg className="w-3.5 h-3.5 text-amber-400 flex-shrink-0 mt-0.5"
                      fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                        d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                        d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <div className="min-w-0">
                      <p className="text-sm font-medium text-gray-800 truncate">{primary}</p>
                      {secondary && <p className="text-xs text-gray-400 truncate">{secondary}</p>}
                      <p className="text-[10px] text-gray-300 mt-0.5">
                        {parseFloat(r.lat).toFixed(4)}°N, {parseFloat(r.lon).toFixed(4)}°E
                      </p>
                    </div>
                  </button>
                </li>
              );
            })}
          </ul>
        )}
      </div>

      {/* Selected city confirmation */}
      {selected && (
        <div className="flex items-center gap-2.5 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2.5">
          <svg className="w-4 h-4 text-amber-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M5 13l4 4L19 7" />
          </svg>
          <div className="min-w-0">
            <p className="text-sm font-semibold text-amber-800 truncate">{selected.name}</p>
            <p className="text-xs text-amber-600">
              {selected.lat.toFixed(5)}°N, {selected.lng.toFixed(5)}°E
            </p>
          </div>
        </div>
      )}

      {error && <p className="text-xs text-red-500">{error}</p>}

      <div className="flex gap-2">
        <button onClick={save} disabled={saving || !selected}
          className="px-4 py-1.5 text-xs font-semibold text-white bg-amber-500 hover:bg-amber-600
            disabled:opacity-40 disabled:cursor-not-allowed rounded-lg transition-colors">
          {saving ? 'Saving…' : 'Save Location'}
        </button>
        <button onClick={() => { setEditing(false); setError(''); }}
          className="px-4 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">
          Cancel
        </button>
      </div>
    </div>
  );
}

// ── Mini Calendar Date Picker ─────────────────────────────────────────────────
const DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

function MiniCalendar({ month, day, year, workDays, onDayChange }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    function handler(e) {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    }
    if (open) document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [open]);

  // Build calendar grid using the actual year so day-of-week columns are correct
  const firstDow  = new Date(year, month - 1, 1).getDay(); // 0=Sun
  const daysInMon = new Date(year, month, 0).getDate();

  const cells = [];
  for (let i = 0; i < firstDow; i++) cells.push(null);
  for (let d = 1; d <= daysInMon; d++) cells.push(d);
  while (cells.length % 7 !== 0) cells.push(null);

  const todayObj = new Date();
  const isToday = (d) =>
    d === todayObj.getDate() &&
    month === todayObj.getMonth() + 1 &&
    year  === todayObj.getFullYear();

  // Non-working days: any day not in the project's work_days (falls back to Sat/Sun)
  const projectWorkDays = workDays?.length > 0
    ? workDays
    : ['monday','tuesday','wednesday','thursday','friday'];
  const nonWorkingDays = new Set();
  for (let d = 1; d <= daysInMon; d++) {
    const dow     = new Date(year, month - 1, d).getDay();
    const dayName = JS_DAY_NAMES[dow];
    if (!projectWorkDays.includes(dayName)) nonWorkingDays.add(d);
  }

  const dateLabel = `${MONTHS[month - 1].slice(0, 3)} ${day}`;

  return (
    <div className="relative" ref={ref}>
      <button
        onClick={() => setOpen(o => !o)}
        className={`flex items-center gap-2 px-3 py-1.5 rounded-lg border text-sm font-semibold transition-colors shadow-sm ${
          open
            ? 'bg-indigo-600 text-white border-indigo-600'
            : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50'
        }`}
      >
        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
            d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
        {dateLabel}
        <svg className={`w-3.5 h-3.5 transition-transform ${open ? 'rotate-180' : ''}`}
          fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
        </svg>
      </button>

      {open && (
        <div className="absolute left-0 top-full mt-2 z-50 bg-white rounded-2xl shadow-2xl border border-gray-100 p-3 w-64">
          <p className="text-[11px] font-semibold text-gray-500 text-center mb-2 uppercase tracking-wide">
            {MONTHS[month - 1]} — pick a day
          </p>

          {/* Day-of-week headers — non-working days highlighted */}
          <div className="grid grid-cols-7 mb-1">
            {DAY_NAMES.map((d, i) => {
              const dayName = JS_DAY_NAMES[i];
              const isOff   = !projectWorkDays.includes(dayName);
              return (
                <div key={d} className={`text-center text-[10px] font-semibold py-0.5 ${
                  isOff ? 'text-indigo-400' : 'text-gray-400'
                }`}>{d}</div>
              );
            })}
          </div>

          {/* Calendar grid */}
          <div className="grid grid-cols-7 gap-0.5">
            {cells.map((d, i) => {
              if (!d) return <div key={`e${i}`} />;
              const selected    = d === day;
              const isNonWorking = nonWorkingDays.has(d);
              const isTodayD    = isToday(d);
              return (
                <button key={d}
                  onClick={() => { onDayChange(d); setOpen(false); }}
                  className={`
                    h-8 w-full rounded-lg text-xs font-semibold transition-all
                    ${selected
                      ? 'bg-indigo-600 text-white shadow-sm'
                      : isTodayD
                        ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-300'
                        : isNonWorking
                          ? 'text-indigo-500 hover:bg-indigo-50'
                          : 'text-gray-700 hover:bg-gray-100'
                    }
                  `}
                >
                  {d}
                </button>
              );
            })}
          </div>

          <p className="text-[10px] text-gray-400 text-center mt-2">
            Solar curve uses actual 2023 irradiance for this date
          </p>
        </div>
      )}
    </div>
  );
}

// ── Main Page ─────────────────────────────────────────────────────────────────
export default function LoadSchedulePage() {
  const navigate = useNavigate();
  const { projectId } = useParams();

  const [project, setProject] = useState(null);
  const [data, setData]       = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState('');

  const [year,  setYear]  = useState(new Date().getFullYear());
  const [month, setMonth] = useState(new Date().getMonth() + 1);
  const [day,   setDay]   = useState(new Date().getDate());
  const [tab,   setTab]   = useState('load');       // load | sources | combined
  const [mode,  setMode]  = useState('optimized');  // optimized | max

  // Derive the day-of-week name from the selected date so we pick the right profile.
  const dayName = JS_DAY_NAMES[new Date(year, month - 1, day).getDay()];

  function handleDayChange(newDay) { setDay(newDay); }

  function handleMonthChange(newMonth) {
    const maxDay = new Date(year, newMonth, 0).getDate();
    setMonth(newMonth);
    setDay(d => Math.min(d, maxDay));
  }

  function handleYearChange(delta) {
    const newYear = year + delta;
    const maxDay  = new Date(newYear, month, 0).getDate();
    setYear(newYear);
    setDay(d => Math.min(d, maxDay));
  }

  useEffect(() => {
    if (!projectId) { navigate('/dashboard'); return; }
    api.get(`/api/projects/${projectId}/buildings`)
      .then(r => setProject(r.data.project))
      .catch(() => navigate('/dashboard'));
  }, [projectId]);

  const fetchSchedule = useCallback(() => {
    if (!projectId) return;
    setLoading(true); setError('');
    api.get(`/api/projects/${projectId}/schedule`, { params: { month, day } })
      .then(r => setData(r.data))
      .catch(() => setError('Failed to load schedule.'))
      .finally(() => setLoading(false));
  }, [projectId, month, day]);

  useEffect(() => { fetchSchedule(); }, [fetchSchedule]);

  // ── Build chart data for the selected day-of-week ──────────────────────────
  const dayData  = data?.days?.[dayName];                          // e.g. data.days.saturday
  const dispatch = mode === 'optimized' ? dayData?.dispatch_optimized : dayData?.dispatch_max;

  const hasBattery = dispatch?.has_battery_storage === true;

  const chartData = dayData
    ? Array.from({ length: 24 }, (_, h) => {
        const demand = mode === 'optimized' ? dayData.load_optimized[h] : dayData.load_max[h];
        return {
          hour:         h,
          load_max:     dayData.load_max[h],
          load_opt:     dayData.load_optimized[h],
          kvar:         dayData.hourly_kvar?.[h] ?? 0,
          solar:        data.solar[h],
          solar_cap:    data.solar_capacity_w,
          utility_cap:  data.utility_capacity_va || 0,
          gen_cap:      data.generator_capacity_va || 0,
          solar_used:   dispatch?.solar_used[h]         ?? 0,
          battery_disc:      dispatch?.battery_discharged?.[h]      ?? 0,
          battery_chrg_sol:  dispatch?.battery_charged_solar?.[h]   ?? 0,
          battery_chrg_gen:  dispatch?.battery_charged_gen?.[h]     ?? 0,
          battery_soc:       dispatch?.battery_soc_trace?.[h] != null
                               ? dispatch.battery_soc_trace[h] * 100
                               : null,
          utility_used: dispatch?.utility_used[h]       ?? 0,
          gen_used:     dispatch?.generator_used[h]     ?? 0,
          unmet:        dispatch?.unmet[h]               ?? 0,
          demand,
        };
      })
    : [];

  const stats        = dispatch?.stats;
  const totalLoadKwh = stats?.total_load_kwh ?? 0;

  const pct = (kwh) => totalLoadKwh > 0 ? Math.round(kwh / totalLoadKwh * 100) : 0;

  const hasLocation = data?.location?.lat != null;
  const hasSolar    = hasLocation && (data?.solar_capacity_w ?? 0) > 0;

  // ── Tick formatter ──────────────────────────────────────────────────────────
  const xTick = h => h % 3 === 0 ? hourLabel(h) : '';

  // ── Gradient defs ─────────────────────────────────────────────────────────
  const Defs = () => (
    <defs>
      <linearGradient id="gLoadMax" x1="0" y1="0" x2="0" y2="1">
        <stop offset="5%"  stopColor="#4f46e5" stopOpacity={0.18} />
        <stop offset="95%" stopColor="#4f46e5" stopOpacity={0.01} />
      </linearGradient>
      <linearGradient id="gLoadOpt" x1="0" y1="0" x2="0" y2="1">
        <stop offset="5%"  stopColor="#10b981" stopOpacity={0.22} />
        <stop offset="95%" stopColor="#10b981" stopOpacity={0.01} />
      </linearGradient>
      <linearGradient id="gSolar" x1="0" y1="0" x2="0" y2="1">
        <stop offset="5%"  stopColor="#f59e0b" stopOpacity={0.30} />
        <stop offset="95%" stopColor="#f59e0b" stopOpacity={0.01} />
      </linearGradient>
      <linearGradient id="gUtilSrc" x1="0" y1="0" x2="0" y2="1">
        <stop offset="5%"  stopColor="#3b82f6" stopOpacity={0.14} />
        <stop offset="95%" stopColor="#3b82f6" stopOpacity={0.01} />
      </linearGradient>
      <linearGradient id="gGenSrc" x1="0" y1="0" x2="0" y2="1">
        <stop offset="5%"  stopColor="#f97316" stopOpacity={0.14} />
        <stop offset="95%" stopColor="#f97316" stopOpacity={0.01} />
      </linearGradient>
      <linearGradient id="gSolarUsed" x1="0" y1="0" x2="0" y2="1">
        <stop offset="5%"  stopColor="#fbbf24" stopOpacity={0.85} />
        <stop offset="95%" stopColor="#fbbf24" stopOpacity={0.60} />
      </linearGradient>
      <linearGradient id="gUtil" x1="0" y1="0" x2="0" y2="1">
        <stop offset="5%"  stopColor="#3b82f6" stopOpacity={0.85} />
        <stop offset="95%" stopColor="#3b82f6" stopOpacity={0.60} />
      </linearGradient>
      <linearGradient id="gGen" x1="0" y1="0" x2="0" y2="1">
        <stop offset="5%"  stopColor="#f97316" stopOpacity={0.85} />
        <stop offset="95%" stopColor="#f97316" stopOpacity={0.60} />
      </linearGradient>
      <linearGradient id="gUnmet" x1="0" y1="0" x2="0" y2="1">
        <stop offset="5%"  stopColor="#ef4444" stopOpacity={0.85} />
        <stop offset="95%" stopColor="#ef4444" stopOpacity={0.60} />
      </linearGradient>
      <linearGradient id="gBattDisc" x1="0" y1="0" x2="0" y2="1">
        <stop offset="5%"  stopColor="#8b5cf6" stopOpacity={0.85} />
        <stop offset="95%" stopColor="#8b5cf6" stopOpacity={0.60} />
      </linearGradient>
      <linearGradient id="gBattChrgSol" x1="0" y1="0" x2="0" y2="1">
        <stop offset="5%"  stopColor="#fbbf24" stopOpacity={0.70} />
        <stop offset="95%" stopColor="#fbbf24" stopOpacity={0.40} />
      </linearGradient>
      <linearGradient id="gBattChrgGen" x1="0" y1="0" x2="0" y2="1">
        <stop offset="5%"  stopColor="#fb923c" stopOpacity={0.70} />
        <stop offset="95%" stopColor="#fb923c" stopOpacity={0.40} />
      </linearGradient>
    </defs>
  );

  return (
    <div className="p-6 space-y-4">

      {/* ── Page header ── */}
      <div className="flex items-center gap-3 flex-wrap">
        <button
          onClick={() => navigate(-1)}
          className="flex items-center gap-1.5 text-xs font-medium text-gray-500 hover:text-gray-800
            px-2.5 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors flex-shrink-0">
          <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
          </svg>
          Project
        </button>
        <div>
          <h1 className="text-xl font-bold text-gray-900">Load Schedule</h1>
          <p className="text-xs text-gray-400 mt-0.5">
            {project?.name} — 24-hour power profile & source dispatch
          </p>
        </div>
        <div className="flex-1" />

        {/* Month selector */}
        <select value={month} onChange={e => handleMonthChange(Number(e.target.value))}
          className="border border-gray-200 rounded-lg px-3 py-1.5 text-sm text-gray-700
            focus:outline-none focus:ring-2 focus:ring-indigo-400 bg-white shadow-sm">
          {MONTHS.map((m, i) => (
            <option key={i + 1} value={i + 1}>{m}</option>
          ))}
        </select>

        {/* Day picker calendar */}
        <MiniCalendar
          month={month} day={day} year={year}
          workDays={project?.work_days}
          onDayChange={handleDayChange}
        />

        {/* Year navigator */}
        <div className="flex items-center gap-1 border border-gray-200 rounded-lg overflow-hidden shadow-sm">
          <button onClick={() => handleYearChange(-1)}
            className="px-2 py-1.5 text-gray-500 hover:bg-gray-50 transition-colors text-sm font-bold">‹</button>
          <span className="px-2 text-sm font-semibold text-gray-700">{year}</span>
          <button onClick={() => handleYearChange(1)}
            className="px-2 py-1.5 text-gray-500 hover:bg-gray-50 transition-colors text-sm font-bold">›</button>
        </div>

        {/* Selected day badge — auto-derived from the picked date */}
        <span className="px-3 py-1.5 text-xs font-semibold text-indigo-700 bg-indigo-50
          border border-indigo-200 rounded-lg capitalize shadow-sm">
          {dayName}
        </span>
      </div>

      {/* ── Location ── */}
      {project && (
        <LocationCard
          projectId={projectId}
          location={data?.location ?? project}
          onSaved={updated => { setProject(updated); fetchSchedule(); }}
        />
      )}

      {/* ── Tabs + mode ── */}
      <div className="flex items-center gap-3 flex-wrap">
        <div className="flex rounded-lg border border-gray-200 overflow-hidden shadow-sm text-sm font-semibold">
          {[
            { key: 'load',     label: 'Load Schedule' },
            { key: 'sources',  label: 'Sources' },
            { key: 'combined', label: 'Combined Dispatch' },
          ].map(({ key, label }) => (
            <button key={key} onClick={() => setTab(key)}
              className={`px-4 py-2 transition-colors border-l first:border-l-0 border-gray-200 ${
                tab === key ? 'bg-indigo-600 text-white' : 'text-gray-600 hover:bg-gray-50'
              }`}>
              {label}
            </button>
          ))}
        </div>

        <div className="flex rounded-lg border border-gray-200 overflow-hidden shadow-sm text-xs font-semibold">
          <button onClick={() => setMode('optimized')}
            className={`px-3 py-1.5 transition-colors ${
              mode === 'optimized' ? 'bg-emerald-500 text-white' : 'text-gray-600 hover:bg-gray-50'
            }`}>
            Optimized
          </button>
          <button onClick={() => setMode('max')}
            className={`px-3 py-1.5 border-l border-gray-200 transition-colors ${
              mode === 'max' ? 'bg-indigo-600 text-white' : 'text-gray-600 hover:bg-gray-50'
            }`}>
            Max Load
          </button>
        </div>
      </div>

      {/* ── Chart area ── */}
      <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">

        {/* Solar / location info bar */}
        {data && (
          <div className="flex items-center gap-4 flex-wrap mb-4 px-1">
            {hasSolar ? (
              <>
                <div className="flex items-center gap-1.5 text-xs text-amber-700 bg-amber-50 px-2.5 py-1 rounded-full border border-amber-200">
                  <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                      d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M12 8a4 4 0 100 8 4 4 0 000-8z" />
                  </svg>
                  <span>Sunrise {data.sunrise_hour != null ? hourLabel(Math.floor(data.sunrise_hour)) : '—'}</span>
                </div>
                <div className="flex items-center gap-1.5 text-xs text-amber-700 bg-amber-50 px-2.5 py-1 rounded-full border border-amber-200">
                  <span>Sunset {data.sunset_hour != null ? hourLabel(Math.floor(data.sunset_hour)) : '—'}</span>
                </div>
                <div className="flex items-center gap-1.5 text-xs text-indigo-700 bg-indigo-50 px-2.5 py-1 rounded-full border border-indigo-200">
                  <span>PSH {data.peak_sun_hours} h/day</span>
                </div>
                <div className="flex items-center gap-1.5 text-xs text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-full border border-emerald-200">
                  <span>Solar cap {fmtW(data.solar_capacity_w)}</span>
                </div>
              </>
            ) : (
              <p className="text-xs text-gray-400 italic">
                {hasLocation ? 'No solar capacity configured.' : 'Set location to enable solar generation profile.'}
              </p>
            )}
            <div className="flex-1" />
            <span className="text-xs text-gray-400 capitalize">
              {MONTHS[month - 1]} {day}, {year} — {dayName}
            </span>
          </div>
        )}

        {loading ? (
          <div className="h-72 flex items-center justify-center">
            <div className="w-8 h-8 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin" />
          </div>
        ) : error ? (
          <div className="h-72 flex items-center justify-center">
            <p className="text-sm text-red-400">{error}</p>
          </div>
        ) : (
          <>
            {/* ── Load Schedule Tab ── */}
            {tab === 'load' && (
              <ResponsiveContainer width="100%" height={320}>
                <ComposedChart data={chartData} margin={{ top: 5, right: 55, left: 10, bottom: 0 }}>
                  <Defs />
                  <CartesianGrid strokeDasharray="3 3" stroke="#f3f4f6" />
                  <XAxis dataKey="hour" tickFormatter={xTick} tick={{ fontSize: 11, fill: '#9ca3af' }} />
                  <YAxis yAxisId="w" tickFormatter={fmtW} tick={{ fontSize: 11, fill: '#9ca3af' }} width={65} />
                  <YAxis yAxisId="kvar" orientation="right"
                    tickFormatter={v => `${v} kVAR`} tick={{ fontSize: 10, fill: '#f59e0b' }} width={55} />
                  <Tooltip content={<CustomTooltip tab="load" />} />
                  <Legend iconType="circle" iconSize={8} wrapperStyle={{ fontSize: 12, paddingTop: 8 }} />
                  {data?.sunrise_hour != null && (
                    <ReferenceLine yAxisId="w" x={Math.round(data.sunrise_hour)} stroke="#fbbf24" strokeDasharray="4 3"
                      label={{ value: '☀ Rise', position: 'top', fontSize: 10, fill: '#d97706' }} />
                  )}
                  {data?.sunset_hour != null && (
                    <ReferenceLine yAxisId="w" x={Math.round(data.sunset_hour)} stroke="#fbbf24" strokeDasharray="4 3"
                      label={{ value: '☀ Set', position: 'top', fontSize: 10, fill: '#d97706' }} />
                  )}
                  <Area yAxisId="w" type="monotone" dataKey="load_max" name="Max Load"
                    stroke="#4f46e5" fill="url(#gLoadMax)" strokeWidth={2} dot={false} activeDot={{ r: 4 }} />
                  <Area yAxisId="w" type="monotone" dataKey="load_opt" name="Optimized"
                    stroke="#10b981" fill="url(#gLoadOpt)" strokeWidth={2} dot={false} activeDot={{ r: 4 }} />
                  <Line yAxisId="kvar" type="monotone" dataKey="kvar" name="Reactive (kVAR)"
                    stroke="#f59e0b" strokeWidth={1.5} strokeDasharray="5 3" dot={false} activeDot={{ r: 3 }} />
                </ComposedChart>
              </ResponsiveContainer>
            )}

            {/* ── Sources Tab — all 3 sources overlaid ── */}
            {tab === 'sources' && (
              <ResponsiveContainer width="100%" height={320}>
                <ComposedChart data={chartData} margin={{ top: 5, right: 20, left: 10, bottom: 0 }}>
                  <Defs />
                  <CartesianGrid strokeDasharray="3 3" stroke="#f3f4f6" />
                  <XAxis dataKey="hour" tickFormatter={xTick} tick={{ fontSize: 11, fill: '#9ca3af' }} />
                  <YAxis tickFormatter={fmtW} tick={{ fontSize: 11, fill: '#9ca3af' }} width={65} />
                  <Tooltip content={<CustomTooltip tab="sources" />} />
                  <Legend iconType="circle" iconSize={8} wrapperStyle={{ fontSize: 12, paddingTop: 8 }} />
                  {data?.sunrise_hour != null && (
                    <ReferenceLine x={Math.round(data.sunrise_hour)} stroke="#fbbf24" strokeDasharray="4 3"
                      label={{ value: '☀ Rise', position: 'top', fontSize: 10, fill: '#d97706' }} />
                  )}
                  {data?.sunset_hour != null && (
                    <ReferenceLine x={Math.round(data.sunset_hour)} stroke="#fbbf24" strokeDasharray="4 3"
                      label={{ value: '☀ Set', position: 'top', fontSize: 10, fill: '#d97706' }} />
                  )}
                  {/* Generator capacity — flat area (behind others) */}
                  <Area type="monotone" dataKey="gen_cap" name="Generator Capacity"
                    stroke="#f97316" strokeWidth={2} strokeDasharray="6 3"
                    fill="url(#gGenSrc)" dot={false} activeDot={{ r: 4 }} />
                  {/* Utility capacity — flat area */}
                  <Area type="monotone" dataKey="utility_cap" name="Utility Capacity"
                    stroke="#3b82f6" strokeWidth={2} strokeDasharray="6 3"
                    fill="url(#gUtilSrc)" dot={false} activeDot={{ r: 4 }} />
                  {/* Solar generation bell curve — solid fill, in front */}
                  <Area type="monotone" dataKey="solar" name="Solar Generation"
                    stroke="#f59e0b" strokeWidth={2.5}
                    fill="url(#gSolar)" dot={false} activeDot={{ r: 4 }} />
                  {!hasSolar && (
                    <text x="50%" y="45%" textAnchor="middle" fill="#9ca3af" fontSize={13}>
                      Set location &amp; solar capacity to see solar generation curve
                    </text>
                  )}
                </ComposedChart>
              </ResponsiveContainer>
            )}

            {/* ── Combined Dispatch Tab ── */}
            {tab === 'combined' && (
              <>
                {/* Main stacked dispatch chart */}
                <ResponsiveContainer width="100%" height={300}>
                  <ComposedChart data={chartData} margin={{ top: 5, right: 20, left: 10, bottom: 0 }}>
                    <Defs />
                    <CartesianGrid strokeDasharray="3 3" stroke="#f3f4f6" />
                    <XAxis dataKey="hour" tickFormatter={xTick} tick={{ fontSize: 11, fill: '#9ca3af' }} />
                    <YAxis tickFormatter={fmtW} tick={{ fontSize: 11, fill: '#9ca3af' }} width={65} />
                    {hasBattery && (
                      <YAxis yAxisId="soc" orientation="right" domain={[0, 100]}
                        tickFormatter={v => `${v}%`} tick={{ fontSize: 10, fill: '#8b5cf6' }} width={35} />
                    )}
                    <Tooltip content={<CustomTooltip tab="combined" />} />
                    <Legend iconType="circle" iconSize={8} wrapperStyle={{ fontSize: 12, paddingTop: 8 }} />
                    {data?.sunrise_hour != null && (
                      <ReferenceLine x={Math.round(data.sunrise_hour)} stroke="#fbbf24" strokeDasharray="4 3" />
                    )}
                    {data?.sunset_hour != null && (
                      <ReferenceLine x={Math.round(data.sunset_hour)} stroke="#fbbf24" strokeDasharray="4 3" />
                    )}
                    {/* Stacked areas — solar, battery, utility, generator, unmet */}
                    <Area type="monotone" dataKey="solar_used"   name="Solar"     stackId="s"
                      stroke="#f59e0b" fill="url(#gSolarUsed)" strokeWidth={1.5} dot={false} />
                    {hasBattery && (
                      <Area type="monotone" dataKey="battery_disc" name="Battery" stackId="s"
                        stroke="#8b5cf6" fill="url(#gBattDisc)" strokeWidth={1.5} dot={false} />
                    )}
                    <Area type="monotone" dataKey="utility_used" name="Utility"    stackId="s"
                      stroke="#3b82f6" fill="url(#gUtil)"      strokeWidth={1.5} dot={false} />
                    <Area type="monotone" dataKey="gen_used"     name="Generator"  stackId="s"
                      stroke="#f97316" fill="url(#gGen)"       strokeWidth={1.5} dot={false} />
                    <Area type="monotone" dataKey="unmet"        name="Unmet"      stackId="s"
                      stroke="#ef4444" fill="url(#gUnmet)"     strokeWidth={1.5} dot={false} />
                    {/* Demand line */}
                    <Line type="monotone" dataKey="demand" name="Demand"
                      stroke="#1f2937" strokeWidth={2.5} dot={false} activeDot={{ r: 4 }} />
                    {/* Battery SOC % — right axis */}
                    {hasBattery && (
                      <Line yAxisId="soc" type="monotone" dataKey="battery_soc" name="Battery SOC %"
                        stroke="#8b5cf6" strokeWidth={1.5} strokeDasharray="5 3" dot={false} activeDot={{ r: 3 }} />
                    )}
                  </ComposedChart>
                </ResponsiveContainer>

                {/* Per-source sub-charts */}
                <div className={`grid gap-4 mt-4 pt-4 border-t border-gray-100 ${
                  hasBattery ? 'grid-cols-3 lg:grid-cols-5' : 'grid-cols-3'
                }`}>
                  <SourceSubChart
                    data={chartData} dataKey="solar_used" demandKey="demand"
                    name="Solar → Load" stroke="#f59e0b" xTick={xTick}
                  />
                  {hasBattery && (
                    <SourceSubChart
                      data={chartData} dataKey="battery_chrg_sol" demandKey="demand"
                      name="Solar → Battery" stroke="#fbbf24" xTick={xTick}
                    />
                  )}
                  {hasBattery && (
                    <SourceSubChart
                      data={chartData} dataKey="battery_disc" demandKey="demand"
                      name="Battery → Load" stroke="#8b5cf6" xTick={xTick}
                    />
                  )}
                  <SourceSubChart
                    data={chartData} dataKey="utility_used" demandKey="demand"
                    name="Utility Grid" stroke="#3b82f6" xTick={xTick}
                  />
                  <SourceSubChart
                    data={chartData} dataKey="gen_used" demandKey="demand"
                    name="Generator" stroke="#f97316" xTick={xTick}
                  />
                </div>
                {/* Battery charging from generator — shown only when it occurs */}
                {hasBattery && chartData.some(d => d.battery_chrg_gen > 0) && (
                  <div className="mt-3 pt-3 border-t border-gray-100">
                    <p className="text-xs font-semibold text-orange-600 mb-2">
                      Generator → Battery Charging (spare capacity)
                    </p>
                    <SourceSubChart
                      data={chartData} dataKey="battery_chrg_gen" demandKey="demand"
                      name="Gen spare → Battery" stroke="#fb923c" xTick={xTick}
                    />
                  </div>
                )}
              </>
            )}
          </>
        )}
      </div>

      {/* ── Stats cards (shown in combined tab when data is ready) ── */}
      {tab === 'combined' && stats && !loading && (
        <div className="space-y-3">
          <h2 className="text-sm font-semibold text-gray-700">
            Daily Energy Breakdown
            <span className="ml-2 text-xs font-normal text-gray-400">
              ({mode === 'optimized' ? 'Optimized' : 'Max'} load — {MONTHS[month - 1]} {day}, {year} — {dayName})
            </span>
          </h2>

          <div className={`grid gap-3 ${hasBattery && (stats.battery_discharged_kwh ?? 0) > 0 ? 'grid-cols-2 lg:grid-cols-5' : 'grid-cols-2 lg:grid-cols-4'}`}>
            <StatCard
              color="#f59e0b" dot="bg-amber-400"
              label="Solar"
              hours={stats.solar_hours}
              kwh={stats.solar_kwh}
              pct={pct(stats.solar_kwh)}
            />
            {hasBattery && (stats.battery_discharged_kwh ?? 0) > 0 && (
              <StatCard
                color="#8b5cf6" dot="bg-violet-400"
                label={`Battery${(stats.battery_charged_gen_kwh ?? 0) > 0 ? ' ⚡' : ''}`}
                hours={chartData.filter(d => d.battery_disc > 0).length}
                kwh={stats.battery_discharged_kwh}
                pct={pct(stats.battery_discharged_kwh)}
              />
            )}
            <StatCard
              color="#3b82f6" dot="bg-blue-400"
              label="Utility Grid"
              hours={stats.utility_hours}
              kwh={stats.utility_kwh}
              pct={pct(stats.utility_kwh)}
            />
            <StatCard
              color="#f97316" dot="bg-orange-400"
              label="Generator"
              hours={stats.generator_hours}
              kwh={stats.generator_kwh}
              pct={pct(stats.generator_kwh)}
            />
            {stats.unmet_kwh > 0 ? (
              <StatCard
                color="#ef4444" dot="bg-red-400"
                label="Unmet Load"
                hours={0}
                kwh={stats.unmet_kwh}
                pct={pct(stats.unmet_kwh)}
              />
            ) : (
              <div className="bg-emerald-50 rounded-xl border border-emerald-200 p-4 flex flex-col items-center justify-center gap-1">
                <svg className="w-8 h-8 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <p className="text-xs font-semibold text-emerald-700">All Load Covered</p>
                <p className="text-[10px] text-emerald-500">0 kWh unmet</p>
              </div>
            )}
          </div>

          {/* Summary row */}
          <div className="bg-white rounded-xl border border-gray-100 shadow-sm px-4 py-3 flex flex-wrap gap-6 text-sm">
            <div>
              <span className="text-gray-400 text-xs uppercase tracking-wide">Total Load</span>
              <p className="font-bold text-gray-800">{fmtKwh(stats.total_load_kwh)}</p>
            </div>
            {hasSolar && stats.solar_generated_kwh > 0 && (
              <div>
                <span className="text-gray-400 text-xs uppercase tracking-wide">Solar Generated</span>
                <p className="font-bold text-amber-600">{fmtKwh(stats.solar_generated_kwh)}</p>
              </div>
            )}
            {hasSolar && stats.solar_generated_kwh > 0 && (
              <div>
                <span className="text-gray-400 text-xs uppercase tracking-wide">Solar Self-consumption</span>
                <p className="font-bold text-emerald-600">{stats.solar_self_consumption}%</p>
              </div>
            )}
            <div className="ml-auto text-right">
              <span className="text-gray-400 text-xs uppercase tracking-wide">Utility + Generator</span>
              <p className="font-bold text-gray-800">{fmtKwh((stats.utility_kwh || 0) + (stats.generator_kwh || 0))}</p>
            </div>
          </div>

          {/* Dispatch priority note */}
          <div className="flex items-start gap-2 bg-blue-50 border border-blue-100 rounded-xl px-4 py-3">
            <svg className="w-4 h-4 text-blue-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <p className="text-xs text-blue-700">
              <strong>Dispatch priority:</strong> Solar → Utility Grid → Generator.
              Solar covers as much of the demand as available; utility fills the gap up to its capacity;
              generator covers any remaining demand. Unmet load appears only if all sources are insufficient.
            </p>
          </div>
        </div>
      )}

    </div>
  );
}
