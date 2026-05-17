import { useState, useEffect, useMemo, useRef } from 'react';
import api from '../api/axios';

const DAY_KEYS    = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
const DAY_SHORT   = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
const MONTH_NAMES = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const MONTH_FULL  = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const JS_DAY_IDX  = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
const DAY_FULL    = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const CHART_H     = 220;

// ─── helpers ─────────────────────────────────────────────────────────────────

function parseMin(str) {
  const [h, m] = str.split(':').map(Number);
  return h * 60 + m;
}

function isDateInSeason(mmdd, seasons) {
  if (!seasons || seasons.length === 0) return true;
  return seasons.some(({ from, to }) =>
    from <= to ? mmdd >= from && mmdd <= to : mmdd >= from || mmdd <= to
  );
}

function getHourPower(hour, intervals, peakW) {
  for (const iv of (intervals ?? [])) {
    const s = parseMin(iv.start), e = parseMin(iv.end);
    if (hour * 60 >= s && hour * 60 < e) return peakW;
  }
  return 0;
}

// Power contributed by one component at a specific date/hour.
// Priority order: critical > component schedule > entity chain (room→floor→building→project).
function compPowerAt(comp, date, hour) {
  // Critical loads run 24 / 7 — bypass all schedule gates.
  if (comp.priority === 'critical') {
    return getHourPower(hour, comp.usage_time_intervals, comp.peak_w);
  }

  const dayName = JS_DAY_IDX[date.getDay()];
  const mm      = String(date.getMonth() + 1).padStart(2, '0');
  const dd      = String(date.getDate()).padStart(2, '0');
  const mmdd    = `${mm}-${dd}`;
  const month   = date.getMonth() + 1;
  const isWeekend = dayName === 'saturday' || dayName === 'sunday';

  // Day-of-week: explicit component value overrides entity; 'all' inherits from entity.
  if (comp.usage_day_type === 'weekday') {
    if (isWeekend) return 0;
  } else if (comp.usage_day_type === 'weekend') {
    if (!isWeekend) return 0;
  } else {
    // 'all' → fall back to entity work_days
    if (!(comp.entity_work_days ?? []).includes(dayName)) return 0;
  }

  // Season: explicit component value overrides entity; 'all' inherits from entity.
  if (comp.usage_season === 'summer') {
    if (month < 4 || month > 9) return 0;
  } else if (comp.usage_season === 'winter') {
    if (month >= 4 && month <= 9) return 0;
  } else {
    // 'all' → fall back to entity working_season_intervals
    if (!isDateInSeason(mmdd, comp.entity_working_season_intervals)) return 0;
  }

  return getHourPower(hour, comp.usage_time_intervals, comp.peak_w);
}

function totalAt(components, date, hour) {
  return components.reduce((sum, c) => sum + compPowerAt(c, date, hour), 0);
}

function fmtPower(w) {
  if (w >= 1_000_000) return `${(w / 1_000_000).toFixed(2)} MW`;
  if (w >= 1_000)     return `${(w / 1_000).toFixed(2)} kW`;
  return `${w.toFixed(0)} W`;
}

function fmtEnergy(wh) {
  if (wh >= 1_000_000) return `${(wh / 1_000_000).toFixed(2)} MWh`;
  if (wh >= 1_000)     return `${(wh / 1_000).toFixed(2)} kWh`;
  return `${wh.toFixed(0)} Wh`;
}

function fmtAxis(val, unit) {
  return unit === 'W' ? fmtPower(val) : fmtEnergy(val);
}

// ─── component ───────────────────────────────────────────────────────────────

export default function TimeScheduleModal({ project, projectId, onClose }) {
  const [view,        setView]       = useState('week');
  const [loadFilter,  setLoadFilter] = useState('all');
  const [components,  setComponents] = useState([]);
  const [loading,     setLoading]    = useState(true);

  const today = useMemo(() => {
    const d = new Date(); d.setHours(0, 0, 0, 0); return d;
  }, []);

  const [selectedDay,   setSelectedDay]   = useState(today);
  const [selectedMonth, setSelectedMonth] = useState({ year: today.getFullYear(), month: today.getMonth() });
  const [selectedYear,  setSelectedYear]  = useState(today.getFullYear());

  useEffect(() => {
    api.get(`/api/projects/${projectId}/load-profile`)
      .then(res => setComponents(res.data.components ?? []))
      .catch(() => setComponents([]))
      .finally(() => setLoading(false));
  }, [projectId]);

  const filteredComponents = useMemo(() =>
    loadFilter === 'critical'
      ? components.filter(c => c.priority === 'critical')
      : components,
  [components, loadFilter]);

  // ── day bars ──
  const { dayBars, isDayActive } = useMemo(() => {
    const bars = Array.from({ length: 24 }, (_, h) => {
      const power = totalAt(filteredComponents, selectedDay, h);
      return {
        label:      `${String(h).padStart(2, '0')}:00`,
        shortLabel: h % 6 === 0 ? `${h}h` : '',
        value:      power,
      };
    });
    return { dayBars: bars, isDayActive: bars.some(b => b.value > 0) };
  }, [filteredComponents, selectedDay]);

  // ── week bars (current week, Mon–Sun) ──
  const weekBars = useMemo(() =>
    DAY_KEYS.map((dayKey, i) => {
      const dow = JS_DAY_IDX.indexOf(dayKey);
      const d   = new Date(today);
      d.setDate(today.getDate() - today.getDay() + dow);
      const energy = Array.from({ length: 24 }, (_, h) => totalAt(filteredComponents, d, h))
        .reduce((s, v) => s + v, 0);
      return { label: DAY_SHORT[i], shortLabel: DAY_SHORT[i], value: energy };
    }), [filteredComponents, today]);

  // ── month bars ──
  const monthBars = useMemo(() => {
    const { year, month } = selectedMonth;
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    return Array.from({ length: daysInMonth }, (_, d) => {
      const date   = new Date(year, month, d + 1);
      const energy = Array.from({ length: 24 }, (_, h) => totalAt(filteredComponents, date, h))
        .reduce((s, v) => s + v, 0);
      return {
        label:      String(d + 1),
        shortLabel: (d + 1) % 7 === 1 ? String(d + 1) : '',
        value:      energy,
      };
    });
  }, [filteredComponents, selectedMonth]);

  // ── year bars + design peak (scanned over whole selected year) ──
  const { yearBars, yearEnergy, designPk } = useMemo(() => {
    let maxPower = 0;
    const bars = MONTH_NAMES.map((name, mo) => {
      const daysInMonth = new Date(selectedYear, mo + 1, 0).getDate();
      let monthEnergy = 0;
      for (let d = 1; d <= daysInMonth; d++) {
        const date = new Date(selectedYear, mo, d);
        for (let h = 0; h < 24; h++) {
          const p = totalAt(filteredComponents, date, h);
          monthEnergy += p;
          if (p > maxPower) maxPower = p;
        }
      }
      return { label: name, shortLabel: name.slice(0, 3), value: monthEnergy };
    });
    return {
      yearBars:  bars,
      yearEnergy: bars.reduce((s, b) => s + b.value, 0),
      designPk:  maxPower,
    };
  }, [filteredComponents, selectedYear]);

  const weekEnergy = weekBars.reduce((s, b) => s + b.value, 0);
  const dayEnergy  = dayBars.reduce((s, b) => s + b.value, 0);

  const bars      = view === 'day' ? dayBars : view === 'week' ? weekBars : view === 'month' ? monthBars : yearBars;
  const maxVal    = Math.max(...bars.map(b => b.value), 1);
  const yUnit     = view === 'day' ? 'W' : 'Wh';
  const barColor  = loadFilter === 'critical' ? 'red' : 'indigo';

  // ── navigator helpers ──
  const dayLabel   = `${DAY_FULL[selectedDay.getDay()]}, ${MONTH_NAMES[selectedDay.getMonth()]} ${selectedDay.getDate()}, ${selectedDay.getFullYear()}`;
  const monthLabel = `${MONTH_FULL[selectedMonth.month]} ${selectedMonth.year}`;

  function prevMonth() {
    setSelectedMonth(m => m.month === 0
      ? { year: m.year - 1, month: 11 }
      : { year: m.year, month: m.month - 1 });
  }
  function nextMonth() {
    setSelectedMonth(m => m.month === 11
      ? { year: m.year + 1, month: 0 }
      : { year: m.year, month: m.month + 1 });
  }

  function goToday()        { setSelectedDay(today); }
  function goCurrentMonth() { setSelectedMonth({ year: today.getFullYear(), month: today.getMonth() }); }
  function goCurrentYear()  { setSelectedYear(today.getFullYear()); }

  const isToday        = selectedDay.getTime() === today.getTime();
  const isCurrentMonth = selectedMonth.year === today.getFullYear() && selectedMonth.month === today.getMonth();
  const isCurrentYear  = selectedYear === today.getFullYear();

  return (
    <div className="fixed inset-0 bg-black/50 flex items-start justify-center z-50 p-4 overflow-y-auto">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-3xl my-8">

        {/* Header */}
        <div className="flex items-center justify-between px-6 pt-6 pb-4 border-b border-gray-100">
          <div className="flex items-center gap-3">
            <button
              onClick={onClose}
              className="flex items-center gap-1.5 text-xs font-medium text-gray-500 hover:text-gray-800
                px-2.5 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors flex-shrink-0">
              <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
              </svg>
              Project
            </button>
            <div>
              <h2 className="text-base font-semibold text-gray-900">Power Schedule</h2>
              <p className="text-xs text-gray-400 mt-0.5">{project.name}</p>
            </div>
          </div>
          <div className="flex items-center gap-3">
            {/* Load filter toggle */}
            <div className="flex items-center gap-0.5 bg-gray-100 rounded-full p-0.5">
              <button
                onClick={() => setLoadFilter('all')}
                className={`px-3 py-1 rounded-full text-xs font-semibold transition-colors ${
                  loadFilter === 'all'
                    ? 'bg-white text-gray-800 shadow-sm'
                    : 'text-gray-500 hover:text-gray-700'
                }`}>
                All loads
              </button>
              <button
                onClick={() => setLoadFilter('critical')}
                className={`px-3 py-1 rounded-full text-xs font-semibold transition-colors ${
                  loadFilter === 'critical'
                    ? 'bg-red-500 text-white shadow-sm'
                    : 'text-gray-500 hover:text-gray-700'
                }`}>
                Critical only
              </button>
            </div>
            <button onClick={onClose} className="text-gray-400 hover:text-gray-600 p-1 rounded-lg hover:bg-gray-100 transition-colors">
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        </div>

        {loading ? (
          <div className="flex items-center justify-center py-16">
            <div className="w-8 h-8 border-4 border-indigo-500 border-t-transparent rounded-full animate-spin" />
          </div>
        ) : (
          <>
            {/* Summary cards */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 px-6 py-4 border-b border-gray-100">
              <StatCard
                label="Peak Demand"
                value={fmtPower(designPk)}
                sub="peak demand"
                accent="indigo"
              />
              <StatCard label="Day Energy"  value={fmtEnergy(dayEnergy)}  sub="selected day"          accent="blue"   />
              <StatCard label="Weekly"      value={fmtEnergy(weekEnergy)} sub="current week"          accent="violet" />
              <StatCard label="Yearly"      value={fmtEnergy(yearEnergy)} sub={`${selectedYear} total`} accent="purple" />
            </div>

            {/* Chart section */}
            <div className="px-6 pt-5 pb-6">

              {/* Tabs */}
              <div className="flex gap-1.5 mb-4">
                {['day', 'week', 'month', 'year'].map(v => (
                  <button key={v} onClick={() => setView(v)}
                    className={`px-4 py-1.5 rounded-full text-xs font-semibold capitalize transition-colors ${
                      view === v
                        ? loadFilter === 'critical' ? 'bg-red-500 text-white' : 'bg-indigo-600 text-white'
                        : 'bg-gray-100 text-gray-500 hover:bg-gray-200'
                    }`}>
                    {v}
                  </button>
                ))}
              </div>

              {/* Navigators */}
              {view === 'day' && (
                <div className="flex items-center justify-between mb-4">
                  <DayPicker value={selectedDay} onChange={setSelectedDay} label={dayLabel} />
                  <div className="flex items-center gap-2">
                    {!isToday && (
                      <button onClick={goToday}
                        className="text-xs text-indigo-600 hover:text-indigo-800 font-medium px-2 py-1 rounded hover:bg-indigo-50 transition-colors">
                        Today
                      </button>
                    )}
                    {!isDayActive && (
                      <span className="text-xs text-gray-400 bg-gray-100 px-2 py-1 rounded-full">Non-working day</span>
                    )}
                  </div>
                </div>
              )}

              {view === 'month' && (
                <div className="flex items-center justify-between mb-4">
                  <div className="flex items-center gap-1">
                    <NavBtn onClick={prevMonth}>‹</NavBtn>
                    <span className="text-sm font-medium text-gray-700 px-2">{monthLabel}</span>
                    <NavBtn onClick={nextMonth}>›</NavBtn>
                  </div>
                  {!isCurrentMonth && (
                    <button onClick={goCurrentMonth}
                      className="text-xs text-indigo-600 hover:text-indigo-800 font-medium px-2 py-1 rounded hover:bg-indigo-50 transition-colors">
                      This month
                    </button>
                  )}
                </div>
              )}

              {view === 'year' && (
                <div className="flex items-center justify-between mb-4">
                  <YearPicker value={selectedYear} onChange={setSelectedYear} />
                  {!isCurrentYear && (
                    <button onClick={goCurrentYear}
                      className="text-xs text-indigo-600 hover:text-indigo-800 font-medium px-2 py-1 rounded hover:bg-indigo-50 transition-colors">
                      This year
                    </button>
                  )}
                </div>
              )}

              {filteredComponents.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-10 text-gray-400">
                  <svg className="w-10 h-10 mb-2 text-gray-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M13 10V3L4 14h7v7l9-11h-7z" />
                  </svg>
                  <p className="text-sm">
                    {loadFilter === 'critical'
                      ? 'No critical loads found — switch to "All loads" or mark components as critical.'
                      : 'No load data yet — add components to see the power profile.'}
                  </p>
                </div>
              ) : (
                <BarChart bars={bars} maxVal={maxVal} yUnit={yUnit} color={barColor} />
              )}

            </div>
          </>
        )}
      </div>
    </div>
  );
}

// ─── sub-components ───────────────────────────────────────────────────────────

function YearPicker({ value, onChange }) {
  const [open,       setOpen]       = useState(false);
  const [rangeStart, setRangeStart] = useState(() => Math.floor(value / 12) * 12);
  const ref = useRef(null);

  useEffect(() => {
    if (!open) return;
    function handler(e) {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    }
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [open]);

  const years       = Array.from({ length: 12 }, (_, i) => rangeStart + i);
  const currentYear = new Date().getFullYear();

  return (
    <div className="relative" ref={ref}>
      <button
        onClick={() => setOpen(o => !o)}
        className={`flex items-center gap-1.5 text-sm font-medium px-3 py-1.5 rounded-lg border transition-colors
          ${open
            ? 'border-indigo-400 text-indigo-600 bg-indigo-50'
            : 'border-gray-200 text-gray-700 hover:border-indigo-400 hover:text-indigo-600 hover:bg-indigo-50'}`}
      >
        <svg className="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
            d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
        <span>{value}</span>
        <svg className="w-3.5 h-3.5 opacity-40 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
        </svg>
      </button>

      {open && (
        <div className="absolute top-full left-0 mt-1.5 bg-white rounded-xl shadow-xl border border-gray-200 z-50 p-3 w-52 select-none">
          <div className="flex items-center justify-between mb-2">
            <button onClick={() => setRangeStart(r => r - 12)}
              className="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-500 hover:text-gray-700 text-base font-bold transition-colors">
              ‹
            </button>
            <span className="text-xs font-semibold text-gray-600">{rangeStart} – {rangeStart + 11}</span>
            <button onClick={() => setRangeStart(r => r + 12)}
              className="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-500 hover:text-gray-700 text-base font-bold transition-colors">
              ›
            </button>
          </div>
          <div className="grid grid-cols-3 gap-1">
            {years.map(yr => (
              <button key={yr}
                onClick={() => { onChange(yr); setOpen(false); }}
                className={`py-1.5 text-xs rounded-lg font-medium transition-colors
                  ${yr === value
                    ? 'bg-indigo-600 text-white'
                    : yr === currentYear
                      ? 'bg-indigo-50 text-indigo-600 hover:bg-indigo-100'
                      : 'text-gray-700 hover:bg-gray-100'}`}>
                {yr}
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

function DayPicker({ value, onChange, label }) {
  const [open,     setOpen]    = useState(false);
  const [calYear,  setCalYear]  = useState(value.getFullYear());
  const [calMonth, setCalMonth] = useState(value.getMonth());
  const ref = useRef(null);

  useEffect(() => {
    setCalYear(value.getFullYear());
    setCalMonth(value.getMonth());
  }, [value]);

  useEffect(() => {
    if (!open) return;
    function handler(e) {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    }
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [open]);

  function prevCal() {
    if (calMonth === 0) { setCalYear(y => y - 1); setCalMonth(11); }
    else setCalMonth(m => m - 1);
  }
  function nextCal() {
    if (calMonth === 11) { setCalYear(y => y + 1); setCalMonth(0); }
    else setCalMonth(m => m + 1);
  }

  const firstDOW  = new Date(calYear, calMonth, 1).getDay();
  const daysInMon = new Date(calYear, calMonth + 1, 0).getDate();
  const cells     = [...Array(firstDOW).fill(null), ...Array.from({ length: daysInMon }, (_, i) => i + 1)];
  const todayMs   = (() => { const d = new Date(); d.setHours(0,0,0,0); return d.getTime(); })();

  return (
    <div className="relative" ref={ref}>
      <button
        onClick={() => setOpen(o => !o)}
        className={`flex items-center gap-1.5 text-sm font-medium px-3 py-1.5 rounded-lg border transition-colors
          ${open
            ? 'border-indigo-400 text-indigo-600 bg-indigo-50'
            : 'border-gray-200 text-gray-700 hover:border-indigo-400 hover:text-indigo-600 hover:bg-indigo-50'}`}
      >
        <svg className="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
            d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
        <span>{label}</span>
        <svg className="w-3.5 h-3.5 opacity-40 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
        </svg>
      </button>

      {open && (
        <div className="absolute top-full left-0 mt-1.5 bg-white rounded-xl shadow-xl border border-gray-200 z-50 p-3 w-64 select-none">
          <div className="flex items-center justify-between mb-2">
            <button onClick={prevCal}
              className="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-500 hover:text-gray-700 text-base font-bold transition-colors">
              ‹
            </button>
            <span className="text-sm font-semibold text-gray-700">{MONTH_FULL[calMonth]} {calYear}</span>
            <button onClick={nextCal}
              className="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-500 hover:text-gray-700 text-base font-bold transition-colors">
              ›
            </button>
          </div>
          <div className="grid grid-cols-7 mb-1">
            {['Su','Mo','Tu','We','Th','Fr','Sa'].map(d => (
              <div key={d} className="text-center text-xs font-medium text-gray-400 py-0.5">{d}</div>
            ))}
          </div>
          <div className="grid grid-cols-7 gap-y-0.5">
            {cells.map((day, i) => {
              if (day === null) return <div key={i} />;
              const date    = new Date(calYear, calMonth, day);
              const dateMs  = date.getTime();
              const isSelected = value.getFullYear() === calYear && value.getMonth() === calMonth && value.getDate() === day;
              const isToday    = dateMs === todayMs;
              return (
                <button key={i}
                  onClick={() => { onChange(date); setOpen(false); }}
                  className={`w-full aspect-square flex items-center justify-center text-xs rounded-lg transition-colors
                    ${isSelected
                      ? 'bg-indigo-600 text-white font-semibold'
                      : isToday
                        ? 'bg-indigo-50 text-indigo-600 font-semibold hover:bg-indigo-100'
                        : 'text-gray-700 hover:bg-gray-100'}`}>
                  {day}
                </button>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}

function NavBtn({ onClick, children }) {
  return (
    <button onClick={onClick}
      className="w-7 h-7 flex items-center justify-center rounded-lg border border-gray-200
        text-gray-500 hover:border-indigo-400 hover:text-indigo-600 hover:bg-indigo-50
        text-base font-semibold transition-colors">
      {children}
    </button>
  );
}

function StatCard({ label, value, sub, accent }) {
  const cls = {
    indigo: 'bg-indigo-50 text-indigo-700',
    blue:   'bg-blue-50   text-blue-700',
    violet: 'bg-violet-50 text-violet-700',
    purple: 'bg-purple-50 text-purple-700',
  }[accent];
  return (
    <div className={`rounded-xl px-3 py-3 ${cls}`}>
      <p className="text-xs font-medium opacity-70 mb-1">{label}</p>
      <p className="text-sm font-bold leading-tight">{value}</p>
      <p className="text-xs opacity-60 mt-0.5">{sub}</p>
    </div>
  );
}

const TICKS = [1, 0.75, 0.5, 0.25, 0];

function BarChart({ bars, maxVal, yUnit, color = 'indigo' }) {
  const barCls  = color === 'red' ? 'bg-red-500 group-hover:bg-red-400'     : 'bg-indigo-500 group-hover:bg-indigo-400';
  return (
    <div className="flex gap-3 items-start">
      <div className="flex-shrink-0 w-16 relative select-none" style={{ height: CHART_H }}>
        {TICKS.map(t => (
          <span key={t}
            className="absolute right-1 text-xs text-gray-400 leading-none -translate-y-1/2"
            style={{ top: `${(1 - t) * CHART_H}px` }}>
            {t === 0 ? '0' : fmtAxis(maxVal * t, yUnit)}
          </span>
        ))}
      </div>

      <div className="flex-1 min-w-0">
        <div className="relative border-l border-b border-gray-200 select-none" style={{ height: CHART_H }}>
          {TICKS.map(t => (
            <div key={t} className="absolute left-0 right-0 border-t border-gray-100"
              style={{ top: `${(1 - t) * CHART_H}px` }} />
          ))}
          <div className="absolute inset-0 flex gap-px px-1">
            {bars.map((bar, i) => (
              <div key={i} className="relative flex-1 group" style={{ height: '100%' }}>
                {bar.value > 0 && (
                  <div className="absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 z-10
                    pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity">
                    <div className="bg-gray-900 text-white text-xs px-2 py-1 rounded-lg whitespace-nowrap shadow-lg">
                      {bar.label}: {fmtAxis(bar.value, yUnit)}
                    </div>
                  </div>
                )}
                <div
                  className={`absolute bottom-0 left-0 right-0 rounded-t-sm transition-colors
                    ${bar.value > 0 ? barCls : 'bg-gray-100'}`}
                  style={{ height: `${(bar.value / maxVal) * 100}%`, minHeight: bar.value > 0 ? '3px' : '1px' }}
                />
              </div>
            ))}
          </div>
        </div>
        <div className="flex gap-px px-1 mt-1.5">
          {bars.map((bar, i) => (
            <div key={i} className="flex-1 text-center overflow-hidden">
              {bar.shortLabel && <span className="text-xs text-gray-400 leading-none">{bar.shortLabel}</span>}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
