import { useState } from 'react';
import api from '../api/axios';

const DAYS = [
  { key: 'monday',    short: 'Mo' },
  { key: 'tuesday',   short: 'Tu' },
  { key: 'wednesday', short: 'We' },
  { key: 'thursday',  short: 'Th' },
  { key: 'friday',    short: 'Fr' },
  { key: 'saturday',  short: 'Sa' },
  { key: 'sunday',    short: 'Su' },
];

const MONTHS = [
  { v: '01', l: 'Jan' }, { v: '02', l: 'Feb' }, { v: '03', l: 'Mar' },
  { v: '04', l: 'Apr' }, { v: '05', l: 'May' }, { v: '06', l: 'Jun' },
  { v: '07', l: 'Jul' }, { v: '08', l: 'Aug' }, { v: '09', l: 'Sep' },
  { v: '10', l: 'Oct' }, { v: '11', l: 'Nov' }, { v: '12', l: 'Dec' },
];

function mmdd(month, day) {
  return `${month}-${String(day).padStart(2, '0')}`;
}

function parseMmdd(str) {
  if (!str || !str.includes('-')) return { month: '01', day: '1' };
  const [m, d] = str.split('-');
  return { month: m, day: String(parseInt(d, 10)) };
}

export default function EntityScheduleModal({ entity, updateEndpoint, parentSchedule, parentLabel, onUpdate, onClose }) {
  const isCustom = entity.work_days !== null || entity.work_time_intervals !== null || entity.working_season_intervals !== null;

  const resolved = {
    work_days: entity.work_days ?? parentSchedule?.work_days ?? null,
    work_time_intervals: entity.work_time_intervals ?? parentSchedule?.work_time_intervals ?? null,
    working_season_intervals: entity.working_season_intervals ?? parentSchedule?.working_season_intervals ?? null,
  };

  const [editing, setEditing] = useState(false);
  const [saving, setSaving]   = useState(false);

  const initDays      = resolved.work_days ?? ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
  const initIntervals = (resolved.work_time_intervals?.length > 0 ? resolved.work_time_intervals : null) ?? [{ start: '08:00', end: '17:00' }];
  const initSeasons   = resolved.working_season_intervals ?? [];

  const [days,      setDays]      = useState(initDays);
  const [intervals, setIntervals] = useState(initIntervals);
  const [seasons,   setSeasons]   = useState(initSeasons);

  function toggleDay(key) {
    setDays(prev => prev.includes(key) ? prev.filter(d => d !== key) : [...prev, key]);
  }

  function addInterval() {
    setIntervals(prev => [...prev, { start: '08:00', end: '17:00' }]);
  }

  function removeInterval(i) {
    if (intervals.length <= 1) return;
    setIntervals(prev => prev.filter((_, idx) => idx !== i));
  }

  function updateInterval(i, field, val) {
    setIntervals(prev => prev.map((iv, idx) => idx === i ? { ...iv, [field]: val } : iv));
  }

  function addSeason() {
    setSeasons(prev => [...prev, { from: '01-01', to: '12-31' }]);
  }

  function removeSeason(i) {
    setSeasons(prev => prev.filter((_, idx) => idx !== i));
  }

  function updateSeasonPart(i, boundary, field, val) {
    setSeasons(prev => prev.map((s, idx) => {
      if (idx !== i) return s;
      const parsed = parseMmdd(s[boundary]);
      const updated = { ...parsed, [field]: val };
      return { ...s, [boundary]: mmdd(updated.month || '01', updated.day || 1) };
    }));
  }

  async function handleSave() {
    setSaving(true);
    try {
      const { data } = await api.put(updateEndpoint, {
        work_days: days,
        work_time_intervals: intervals,
        working_season_intervals: seasons.length > 0 ? seasons : null,
      });
      onUpdate(data.data);
      onClose();
    } finally {
      setSaving(false);
    }
  }

  async function handleResetToInherited() {
    setSaving(true);
    try {
      const { data } = await api.put(updateEndpoint, {
        work_days: null,
        work_time_intervals: null,
        working_season_intervals: null,
      });
      onUpdate(data.data);
      onClose();
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/40 flex items-start justify-center z-50 p-4 overflow-y-auto">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-lg my-8">

        <div className="flex items-center justify-between px-6 pt-6 pb-4 border-b border-gray-100">
          <div className="flex items-center gap-2.5">
            <h2 className="text-base font-semibold text-gray-900">Schedule</h2>
            {isCustom ? (
              <span className="text-xs font-medium bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full">Custom</span>
            ) : (
              <span className="text-xs font-medium bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full">Inherited from {parentLabel}</span>
            )}
          </div>
          <div className="flex items-center gap-2">
            {!editing && (
              <button onClick={() => setEditing(true)}
                className="flex items-center gap-1.5 text-xs font-semibold text-indigo-600 hover:text-indigo-800 px-3 py-1.5 rounded-lg hover:bg-indigo-50 transition-colors">
                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
                Edit Schedule
              </button>
            )}
            <button onClick={onClose} className="text-gray-400 hover:text-gray-600 p-1 rounded-lg hover:bg-gray-100 transition-colors">
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        </div>

        <div className="px-6 py-5">
          {editing ? (
            <Editor
              days={days} onToggleDay={toggleDay}
              intervals={intervals} onAddInterval={addInterval} onRemoveInterval={removeInterval} onUpdateInterval={updateInterval}
              seasons={seasons} onAddSeason={addSeason} onRemoveSeason={removeSeason} onUpdateSeasonPart={updateSeasonPart}
            />
          ) : (
            <ScheduleView schedule={resolved} />
          )}
        </div>

        <div className="flex gap-3 px-6 pb-6">
          {editing ? (
            <>
              {isCustom && (
                <button onClick={handleResetToInherited} disabled={saving}
                  className="text-xs font-medium text-gray-400 hover:text-red-500 px-3 py-2.5 rounded-lg hover:bg-red-50 transition-colors disabled:opacity-50 flex-shrink-0">
                  Reset to inherited
                </button>
              )}
              <button onClick={() => setEditing(false)}
                className="flex-1 border border-gray-300 text-gray-700 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-50 transition-colors">
                Cancel
              </button>
              <button onClick={handleSave} disabled={saving}
                className="flex-1 bg-indigo-600 text-white py-2.5 rounded-lg text-sm font-semibold hover:bg-indigo-700 transition-colors disabled:opacity-50">
                {saving ? 'Saving…' : 'Save'}
              </button>
            </>
          ) : (
            <button onClick={onClose}
              className="flex-1 border border-gray-300 text-gray-700 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-50 transition-colors">
              Close
            </button>
          )}
        </div>
      </div>
    </div>
  );
}

function ScheduleView({ schedule }) {
  const days    = schedule?.work_days;
  const ivs     = schedule?.work_time_intervals;
  const seasons = schedule?.working_season_intervals;

  return (
    <div className="space-y-5">
      <div>
        <p className="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Working Days</p>
        {days?.length > 0 ? (
          <div className="flex gap-1.5 flex-wrap">
            {DAYS.map(d => (
              <span key={d.key}
                className={`w-9 h-9 flex items-center justify-center rounded-full text-xs font-semibold
                  ${days.includes(d.key) ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-300'}`}>
                {d.short}
              </span>
            ))}
          </div>
        ) : (
          <p className="text-sm text-gray-400 italic">Not set</p>
        )}
      </div>

      <div>
        <p className="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Work Hours</p>
        {ivs?.length > 0 ? (
          <div className="flex flex-wrap gap-2">
            {ivs.map((iv, i) => (
              <span key={i} className="px-3 py-1.5 bg-indigo-50 text-indigo-700 rounded-full text-sm font-medium">
                {iv.start} – {iv.end}
              </span>
            ))}
          </div>
        ) : (
          <p className="text-sm text-gray-400 italic">Not set</p>
        )}
      </div>

      <div>
        <p className="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Operating Season</p>
        {seasons?.length > 0 ? (
          <div className="flex flex-wrap gap-2">
            {seasons.map((s, i) => (
              <span key={i} className="px-3 py-1.5 bg-orange-50 text-orange-700 rounded-full text-sm font-medium">
                {s.from} → {s.to}
              </span>
            ))}
          </div>
        ) : (
          <p className="text-sm text-gray-400 italic">All year</p>
        )}
      </div>
    </div>
  );
}

function Editor({ days, onToggleDay, intervals, onAddInterval, onRemoveInterval, onUpdateInterval, seasons, onAddSeason, onRemoveSeason, onUpdateSeasonPart }) {
  return (
    <div className="space-y-5">
      <div>
        <p className="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2.5">Working Days</p>
        <div className="flex gap-1.5">
          {DAYS.map(d => (
            <button key={d.key} onClick={() => onToggleDay(d.key)}
              className={`w-9 h-9 rounded-full text-xs font-semibold transition-colors
                ${days.includes(d.key) ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-500 hover:bg-gray-200'}`}>
              {d.short}
            </button>
          ))}
        </div>
      </div>

      <div>
        <div className="flex items-center justify-between mb-2.5">
          <p className="text-xs font-semibold text-gray-400 uppercase tracking-wide">Work Hours</p>
          <button onClick={onAddInterval}
            className="text-xs text-indigo-600 hover:text-indigo-800 font-medium flex items-center gap-1">
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
            </svg>
            Add interval
          </button>
        </div>
        <div className="space-y-2">
          {intervals.map((iv, i) => (
            <div key={i} className="flex items-center gap-2">
              <input type="time" value={iv.start} onChange={e => onUpdateInterval(i, 'start', e.target.value)}
                className="flex-1 border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400" />
              <span className="text-gray-400 text-xs">to</span>
              <input type="time" value={iv.end} onChange={e => onUpdateInterval(i, 'end', e.target.value)}
                className="flex-1 border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400" />
              {intervals.length > 1 && (
                <button onClick={() => onRemoveInterval(i)} className="text-gray-300 hover:text-red-400 transition-colors p-1">
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              )}
            </div>
          ))}
        </div>
      </div>

      <div>
        <div className="flex items-center justify-between mb-2.5">
          <p className="text-xs font-semibold text-gray-400 uppercase tracking-wide">Operating Season</p>
          <button onClick={onAddSeason}
            className="text-xs text-orange-600 hover:text-orange-800 font-medium flex items-center gap-1">
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
            </svg>
            Add period
          </button>
        </div>
        {seasons.length === 0 ? (
          <p className="text-xs text-gray-400 italic">All year — no seasonal restriction</p>
        ) : (
          <div className="space-y-2">
            {seasons.map((s, i) => {
              const from = parseMmdd(s.from);
              const to   = parseMmdd(s.to);
              return (
                <div key={i} className="border border-orange-100 rounded-xl p-3 space-y-2 bg-orange-50/30">
                  <div className="flex items-center justify-between">
                    <span className="text-xs font-medium text-orange-700">Period {i + 1}</span>
                    <button onClick={() => onRemoveSeason(i)} className="text-gray-300 hover:text-red-400 transition-colors p-0.5">
                      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                      </svg>
                    </button>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="text-xs text-gray-500 w-8">From</span>
                    <select value={from.month} onChange={e => onUpdateSeasonPart(i, 'from', 'month', e.target.value)}
                      className="flex-1 border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-orange-300 bg-white">
                      {MONTHS.map(m => <option key={m.v} value={m.v}>{m.l}</option>)}
                    </select>
                    <input type="number" min="1" max="31" value={from.day}
                      onChange={e => onUpdateSeasonPart(i, 'from', 'day', e.target.value)}
                      className="w-14 border border-gray-200 rounded-lg px-2 py-1.5 text-xs text-center focus:outline-none focus:ring-2 focus:ring-orange-300" />
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="text-xs text-gray-500 w-8">To</span>
                    <select value={to.month} onChange={e => onUpdateSeasonPart(i, 'to', 'month', e.target.value)}
                      className="flex-1 border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-orange-300 bg-white">
                      {MONTHS.map(m => <option key={m.v} value={m.v}>{m.l}</option>)}
                    </select>
                    <input type="number" min="1" max="31" value={to.day}
                      onChange={e => onUpdateSeasonPart(i, 'to', 'day', e.target.value)}
                      className="w-14 border border-gray-200 rounded-lg px-2 py-1.5 text-xs text-center focus:outline-none focus:ring-2 focus:ring-orange-300" />
                  </div>
                  <p className="text-xs text-orange-500 font-medium">{s.from} → {s.to}</p>
                </div>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
}
