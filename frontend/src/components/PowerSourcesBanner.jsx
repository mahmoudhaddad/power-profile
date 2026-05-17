/* eslint-disable react/prop-types */
import { useState, useEffect, useRef } from 'react';
import api from '../api/axios';

function fmt(v) {
  const n = Number(v) || 0;
  if (n === 0) return '0 VA';
  if (n >= 1_000_000) return `${(n / 1_000_000).toLocaleString(undefined, { maximumFractionDigits: 2 })} MVA`;
  if (n >= 1_000)     return `${(n / 1_000).toLocaleString(undefined,     { maximumFractionDigits: 2 })} kVA`;
  return `${n.toLocaleString(undefined, { maximumFractionDigits: 0 })} VA`;
}

function fmtW(v) {
  const n = Number(v) || 0;
  if (n === 0) return '0 W';
  if (n >= 1_000_000) return `${(n / 1_000_000).toLocaleString(undefined, { maximumFractionDigits: 2 })} MW`;
  if (n >= 1_000)     return `${(n / 1_000).toLocaleString(undefined,     { maximumFractionDigits: 2 })} kW`;
  return `${Math.round(n)} W`;
}

function fmtDiff(diff) {
  const n    = Math.abs(Number(diff) || 0);
  const sign = diff >= 0 ? '+' : '−';
  if (n >= 1_000_000) return `${sign}${(n / 1_000_000).toLocaleString(undefined, { maximumFractionDigits: 2 })} MVA`;
  if (n >= 1_000)     return `${sign}${(n / 1_000).toLocaleString(undefined,     { maximumFractionDigits: 2 })} kVA`;
  return `${sign}${n.toLocaleString(undefined, { maximumFractionDigits: 0 })} VA`;
}

// ── Small coverage pill shown in the always-visible bar ──────────────────────
function CoverageBadge({ label, available, load }) {
  if (!load) return null;
  const pct = Math.round((available / load) * 100);
  const ok  = available >= load;
  return (
    <div className={`flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border ${
      ok ? 'bg-emerald-400/20 border-emerald-400/40' : 'bg-red-400/25 border-red-400/40'
    }`}>
      <span className={`text-[10px] uppercase tracking-wide ${ok ? 'text-emerald-200' : 'text-red-300'}`}>{label}</span>
      <span className={ok ? 'text-white' : 'text-red-200'}>{pct}%</span>
      {ok
        ? <svg className="w-3 h-3 text-emerald-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M5 13l4 4L19 7" />
          </svg>
        : <svg className="w-3 h-3 text-red-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M12 9v4m0 4h.01" />
          </svg>
      }
    </div>
  );
}

// ── Coverage card shown in the analysis dropdown ─────────────────────────────
function CoverageCard({ label, available, load }) {
  if (!load) return null;
  const ratio  = available / load;
  const barPct = Math.min(ratio, 1) * 100;
  const ok     = available >= load;
  const diff   = available - load;
  return (
    <div className="bg-white/10 hover:bg-white/15 rounded-xl p-3 transition-colors">
      <div className="flex items-center justify-between mb-1">
        <span className="text-xs font-semibold">{label}</span>
        <span className={`text-xs font-bold ${ok ? 'text-emerald-300' : 'text-amber-300'}`}>
          {Math.round(ratio * 100)}%
        </span>
      </div>
      <p className="text-[10px] text-emerald-200 mb-2">{fmt(load)} needed</p>
      <div className="h-1.5 rounded-full bg-white/20 mb-2">
        <div className={`h-full rounded-full transition-all ${ok ? 'bg-emerald-400' : 'bg-amber-400'}`}
          style={{ width: `${barPct}%` }} />
      </div>
      <p className={`text-xs font-bold ${ok ? 'text-emerald-300' : 'text-amber-300'}`}>
        {ok ? '✓' : '⚠'} {fmtDiff(diff)} {ok ? 'surplus' : 'gap'}
      </p>
    </div>
  );
}

// ── Per-source card shown in the analysis dropdown ───────────────────────────
function SourceCard({ label, dot, capacity, maxLoad, optLoad, fmtFn = fmt }) {
  const maxPct = maxLoad > 0 ? Math.min(capacity / maxLoad, 1) * 100 : 0;
  const optPct = optLoad > 0 ? Math.min(capacity / optLoad, 1) * 100 : 0;
  return (
    <div className="bg-white/10 hover:bg-white/15 rounded-xl p-3 transition-colors">
      <div className="flex items-center gap-2 mb-2">
        <span className={`w-2 h-2 rounded-full flex-shrink-0 ${dot}`} />
        <span className="text-xs font-semibold">{label}</span>
      </div>
      <p className="text-sm font-bold mb-2">{fmtFn(capacity)}</p>
      {(maxLoad > 0 || optLoad > 0) && (
        <div className="space-y-1.5">
          {maxLoad > 0 && (
            <div>
              <div className="flex justify-between text-[10px] text-emerald-200 mb-0.5">
                <span>of max</span>
                <span>{Math.round(capacity / maxLoad * 100)}%</span>
              </div>
              <div className="h-1 rounded-full bg-white/20">
                <div className="h-full rounded-full bg-blue-300 transition-all" style={{ width: `${maxPct}%` }} />
              </div>
            </div>
          )}
          {optLoad > 0 && (
            <div>
              <div className="flex justify-between text-[10px] text-emerald-200 mb-0.5">
                <span>of opt</span>
                <span>{Math.round(capacity / optLoad * 100)}%</span>
              </div>
              <div className="h-1 rounded-full bg-white/20">
                <div className="h-full rounded-full bg-emerald-300 transition-all" style={{ width: `${optPct}%` }} />
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

// ── Solar dropdown — toggle max-available vs installed system ─────────────────
function SolarDropdown({ solar, solarMaxAvailable, solarComputed, entity, updateEndpoint, onUpdate, buildingsSolarSum = 0 }) {
  const [open, setOpen]               = useState(false);
  const [existingInput, setExistingInput] = useState('');
  const [solarPowerInput, setSolarPowerInput] = useState('');
  const [saving, setSaving]           = useState(false);
  const ref = useRef(null);

  const solarMode     = entity?.solar_source ?? 'max';
  const solarExisting = Number(entity?.existing_solar_power ?? 0);

  useEffect(() => {
    setExistingInput(solarExisting > 0 ? String(solarExisting) : '');
    setSolarPowerInput(String(Number(entity?.solar_power ?? 0) || ''));
  }, [entity, solarExisting]);

  useEffect(() => {
    function handler(e) {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    }
    if (open) document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [open]);

  async function saveField(payload) {
    if (!updateEndpoint) return;
    setSaving(true);
    try {
      const { data } = await api.put(updateEndpoint, payload);
      onUpdate?.(data.data);
    } finally {
      setSaving(false);
    }
  }

  const modeLabel = solarMode === 'existing' ? 'installed system' : 'max available';

  return (
    <div className="relative" ref={ref}>
      <button onClick={() => setOpen(o => !o)} className="flex items-center gap-2 group">
        <div className="w-7 h-7 bg-white/20 rounded-lg flex items-center justify-center flex-shrink-0">
          <svg className="w-4 h-4 text-yellow-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M12 8a4 4 0 100 8 4 4 0 000-8z" />
          </svg>
        </div>
        <div className="text-left">
          <p className="text-xs text-emerald-100 leading-none mb-0.5">Solar</p>
          <p className="text-sm font-semibold leading-none">{fmtW(solar)}</p>
          <p className="text-xs text-emerald-200 leading-none mt-0.5">{modeLabel}</p>
        </div>
        <svg className={`w-3.5 h-3.5 text-emerald-200 transition-transform duration-200 ${open ? 'rotate-180' : ''}`}
          fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
        </svg>
      </button>

      {open && (
        <div className="absolute right-0 top-full mt-2 w-72 bg-white rounded-2xl shadow-2xl border border-gray-100 z-50 overflow-hidden">

          {/* Header */}
          <div className="bg-gradient-to-r from-yellow-500 to-amber-400 px-4 py-3">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-xs text-white/70 leading-none mb-0.5">Solar Power</p>
                <p className="text-base font-bold text-white leading-none">{fmtW(solar)}</p>
              </div>
              <span className="text-xs bg-white/25 text-white px-2.5 py-1 rounded-full font-medium">{modeLabel}</span>
            </div>
          </div>

          {/* Mode toggle */}
          <div className="px-4 py-3 border-b border-gray-100">
            <p className="text-[10px] font-semibold text-gray-400 mb-2 uppercase tracking-wide">Use in Calculations</p>
            <div className="flex rounded-lg border border-gray-200 overflow-hidden text-xs font-semibold">
              <button
                onClick={() => solarMode !== 'max' && saveField({ solar_source: 'max' })}
                className={`flex-1 px-3 py-2 transition-colors ${
                  solarMode === 'max' ? 'bg-amber-500 text-white' : 'text-gray-500 hover:bg-gray-50'
                }`}>
                Max Available
              </button>
              <button
                onClick={() => solarMode !== 'existing' && saveField({ solar_source: 'existing' })}
                className={`flex-1 px-3 py-2 border-l border-gray-200 transition-colors ${
                  solarMode === 'existing' ? 'bg-amber-500 text-white' : 'text-gray-500 hover:bg-gray-50'
                }`}>
                Existing System
              </button>
            </div>
          </div>

          {/* Max available value */}
          <div className="px-4 py-3 border-b border-gray-100">
            <p className="text-[10px] font-semibold text-gray-400 mb-1.5 uppercase tracking-wide">Max Available</p>
            {solarComputed !== undefined ? (
              <div className="flex items-center justify-between">
                <p className="text-sm font-bold text-gray-800">{fmtW(solarMaxAvailable)}</p>
                <span className="text-xs text-gray-400 bg-gray-100 px-2 py-0.5 rounded-full">auto-calculated</span>
              </div>
            ) : (
              <div className="flex items-center gap-2">
                <div className="relative flex-1">
                  <input type="number" min="0" step="1"
                    value={solarPowerInput}
                    onChange={e => setSolarPowerInput(e.target.value)}
                    onKeyDown={e => e.key === 'Enter' && saveField({ solar_power: Number(solarPowerInput) })}
                    placeholder="0"
                    className="w-full border border-gray-200 rounded-lg pl-3 pr-7 py-1.5 text-xs text-gray-800
                      placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-amber-400 bg-white" />
                  <span className="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-gray-400">W</span>
                </div>
                <button onClick={() => saveField({ solar_power: Number(solarPowerInput) })} disabled={saving}
                  className="w-8 h-8 bg-amber-500 hover:bg-amber-600 disabled:opacity-40 disabled:cursor-not-allowed
                    rounded-lg flex items-center justify-center text-white flex-shrink-0 transition-colors">
                  {saving
                    ? <div className="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin" />
                    : <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M5 13l4 4L19 7" />
                      </svg>
                  }
                </button>
              </div>
            )}
          </div>

          {/* Existing / installed system input */}
          <div className="px-4 py-3 bg-gray-50">
            <p className="text-[10px] font-semibold text-gray-400 mb-1.5 uppercase tracking-wide">Installed System Output</p>

            {/* Buildings solar auto-sum row */}
            {buildingsSolarSum > 0 && (
              <div className="flex items-center justify-between mb-2 px-2.5 py-1.5 bg-amber-50 border border-amber-100 rounded-lg">
                <div className="flex items-center gap-1.5">
                  <svg className="w-3 h-3 text-amber-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                      d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 00-1-1h-2a1 1 0 00-1 1v5m4 0H9" />
                  </svg>
                  <span className="text-[10px] text-amber-700 font-medium">From buildings</span>
                </div>
                <span className="text-xs font-bold text-amber-700">{fmtW(buildingsSolarSum)}</span>
              </div>
            )}

            <div className="flex items-center gap-2">
              <div className="relative flex-1">
                <input type="number" min="0" step="1"
                  value={existingInput}
                  onChange={e => setExistingInput(e.target.value)}
                  onKeyDown={e => e.key === 'Enter' && saveField({ existing_solar_power: Number(existingInput) })}
                  placeholder={buildingsSolarSum > 0 ? 'Additional standalone W' : 'Enter installed solar W'}
                  className="w-full border border-gray-200 rounded-lg pl-3 pr-7 py-1.5 text-xs text-gray-800
                    placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-amber-400 bg-white" />
                <span className="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-gray-400">W</span>
              </div>
              <button onClick={() => saveField({ existing_solar_power: Number(existingInput) })} disabled={saving}
                className="w-8 h-8 bg-amber-500 hover:bg-amber-600 disabled:opacity-40 disabled:cursor-not-allowed
                  rounded-lg flex items-center justify-center text-white flex-shrink-0 transition-colors">
                {saving
                  ? <div className="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin" />
                  : <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M5 13l4 4L19 7" />
                    </svg>
                }
              </button>
            </div>
            {solarMode === 'existing' && (solarExisting > 0 || buildingsSolarSum > 0) && (
              <p className="text-[10px] text-amber-600 font-medium mt-1.5">
                {buildingsSolarSum > 0 && solarExisting > 0
                  ? `Total: ${fmtW(solarExisting + buildingsSolarSum)} — currently used`
                  : 'Currently used in calculations'}
              </p>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

// ── Reusable dropdown for managing generator / utility lines ─────────────────
function LinesDropdown({ label, icon, iconColor, accentFrom, accentTo, endpoint, deleteEndpoint, onTotalChange }) {
  const [lines, setLines]   = useState([]);
  const [total, setTotal]   = useState(0);
  const [open, setOpen]     = useState(false);
  const [name, setName]     = useState('');
  const [power, setPower]   = useState('');
  const [phases, setPhases] = useState('1phase');
  const [adding, setAdding] = useState(false);
  const [error, setError]   = useState('');
  const ref = useRef(null);

  useEffect(() => {
    if (!endpoint) return;
    api.get(endpoint).then(({ data }) => {
      const t = Number(data.total_power ?? 0);
      setLines(data.data ?? []);
      setTotal(t);
      onTotalChange?.(t);
    });
  }, [endpoint, onTotalChange]);

  useEffect(() => {
    function handler(e) {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    }
    if (open) document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [open]);

  function applyUpdate(updated) {
    const t = updated.reduce((s, l) => s + Number(l.power), 0);
    setLines(updated);
    setTotal(t);
    onTotalChange?.(t);
  }

  async function handleAdd() {
    if (!name.trim() || !power) return;
    setError(''); setAdding(true);
    try {
      const { data } = await api.post(endpoint, { name: name.trim(), power: Number(power), phases });
      applyUpdate([...lines, data.data]);
      setName(''); setPower(''); setPhases('1phase');
    } catch {
      setError('Failed to add. Please try again.');
    } finally {
      setAdding(false);
    }
  }

  async function handleDelete(id) {
    await api.delete(`${deleteEndpoint}/${id}`);
    applyUpdate(lines.filter(l => l.id !== id));
  }

  if (!endpoint) return null;

  return (
    <div className="relative" ref={ref}>
      <button onClick={() => setOpen(o => !o)} className="flex items-center gap-2 group">
        <div className="w-7 h-7 bg-white/20 rounded-lg flex items-center justify-center flex-shrink-0">{icon}</div>
        <div className="text-left">
          <p className="text-xs text-emerald-100 leading-none mb-0.5">{label}</p>
          <p className="text-sm font-semibold leading-none">{fmt(total)}</p>
          {lines.length > 0 && (
            <p className="text-xs text-emerald-200 leading-none mt-0.5">{lines.length} unit{lines.length !== 1 ? 's' : ''}</p>
          )}
        </div>
        <svg className={`w-3.5 h-3.5 text-emerald-200 transition-transform duration-200 ${open ? 'rotate-180' : ''}`}
          fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
        </svg>
      </button>

      {open && (
        <div className="absolute right-0 top-full mt-2 w-80 bg-white rounded-2xl shadow-2xl border border-gray-100 z-50 overflow-hidden">
          <div className={`bg-gradient-to-r ${accentFrom} ${accentTo} px-4 py-3`}>
            <div className="flex items-center justify-between">
              <div>
                <p className="text-xs text-white/70 leading-none mb-0.5">{label} Lines</p>
                <p className="text-base font-bold text-white leading-none">{fmt(total)}</p>
              </div>
              <span className="text-xs bg-white/20 text-white px-2 py-0.5 rounded-full">
                {lines.length} unit{lines.length !== 1 ? 's' : ''}
              </span>
            </div>
          </div>

          <div className="max-h-52 overflow-y-auto">
            {lines.length === 0 ? (
              <div className="py-6 text-center">
                <p className="text-xs text-gray-400">No {label.toLowerCase()} units yet</p>
              </div>
            ) : (
              <ul className="divide-y divide-gray-50">
                {lines.map((line, i) => (
                  <li key={line.id}
                    className="flex items-center justify-between px-4 py-2.5 hover:bg-gray-50 transition-colors group/line">
                    <div className="flex items-center gap-3 min-w-0">
                      <span className={`w-5 h-5 rounded-full ${iconColor} text-xs font-bold flex items-center justify-center flex-shrink-0`}>
                        {i + 1}
                      </span>
                      <div className="min-w-0">
                        <div className="flex items-center gap-1.5">
                          <p className="text-sm font-medium text-gray-800 truncate">{line.name}</p>
                          <span className={`flex-shrink-0 text-xs font-semibold px-1.5 py-0.5 rounded-full ${
                            line.phases === '3phase' ? 'bg-violet-100 text-violet-700' : 'bg-blue-100 text-blue-700'
                          }`}>
                            {line.phases === '3phase' ? '3Φ' : '1Φ'}
                          </span>
                        </div>
                        <p className="text-xs text-emerald-600 font-semibold">{fmt(line.power)} max</p>
                      </div>
                    </div>
                    <button onClick={() => handleDelete(line.id)}
                      className="ml-2 w-6 h-6 rounded-lg flex items-center justify-center text-gray-300
                        hover:text-red-500 hover:bg-red-50 transition-all opacity-0 group-hover/line:opacity-100">
                      <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                          d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                      </svg>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>

          <div className="px-4 py-3 border-t border-gray-100 bg-gray-50">
            <p className="text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Add {label}</p>
            {error && <p className="text-xs text-red-500 mb-2">{error}</p>}
            <div className="flex flex-col gap-2">
              <input type="text" value={name} onChange={e => setName(e.target.value)}
                onKeyDown={e => e.key === 'Enter' && handleAdd()}
                placeholder={`${label} name`}
                className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-xs text-gray-800
                  placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:border-transparent bg-white" />
              <div className="flex gap-2 items-center">
                <div className="relative flex-1 min-w-0">
                  <input type="number" value={power} onChange={e => setPower(e.target.value)}
                    onKeyDown={e => e.key === 'Enter' && handleAdd()}
                    placeholder="Max power" min="0"
                    className="w-full border border-gray-200 rounded-lg pl-3 pr-7 py-1.5 text-xs text-gray-800
                      placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:border-transparent bg-white" />
                  <span className="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-gray-400">VA</span>
                </div>
                <div className="flex rounded-lg border border-gray-200 overflow-hidden flex-shrink-0 bg-white">
                  <button type="button" onClick={() => setPhases('1phase')}
                    className={`px-2.5 py-1.5 text-xs font-semibold transition-colors ${
                      phases === '1phase' ? 'bg-blue-500 text-white' : 'text-gray-500 hover:bg-gray-50'
                    }`}>1Φ</button>
                  <button type="button" onClick={() => setPhases('3phase')}
                    className={`px-2.5 py-1.5 text-xs font-semibold transition-colors border-l border-gray-200 ${
                      phases === '3phase' ? 'bg-violet-500 text-white' : 'text-gray-500 hover:bg-gray-50'
                    }`}>3Φ</button>
                </div>
                <button onClick={handleAdd} disabled={!name.trim() || !power || adding}
                  className="w-8 h-8 bg-emerald-500 hover:bg-emerald-600 disabled:opacity-40
                    disabled:cursor-not-allowed rounded-lg flex items-center justify-center text-white transition-colors flex-shrink-0">
                  {adding
                    ? <div className="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin" />
                    : <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                      </svg>
                  }
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

// ── Main component ────────────────────────────────────────────────────────────
// Props:
//   entity, updateEndpoint, onUpdate  — entity being edited (project/building/floor/room)
//   solarComputed                     — if set, solar_power is auto-calculated (read-only)
//   generatorEndpoint / utilityEndpoint
//   maxLoad                           — unoptimized total VA from PowerBanner
//   optimizedLoad                     — optimized total VA from PowerBanner
export default function PowerSourcesBanner({
  entity, updateEndpoint, onUpdate,
  solarComputed,
  generatorEndpoint,
  utilityEndpoint,
  projectId,
  buildingsSolarSum = 0,
  maxLoad       = 0,
  optimizedLoad = 0,
}) {
  const [open, setOpen]         = useState(false);
  const [genTotal, setGenTotal] = useState(0);
  const [utilTotal, setUtilTotal] = useState(0);
  const [bldgGenTotal,  setBldgGenTotal]  = useState(0);
  const [bldgUtilTotal, setBldgUtilTotal] = useState(0);
  const [bldgGenLines,  setBldgGenLines]  = useState([]);
  const [bldgUtilLines, setBldgUtilLines] = useState([]);

  useEffect(() => {
    if (!projectId) return;
    Promise.all([
      api.get(`/api/projects/${projectId}/utility-lines`),
      api.get(`/api/projects/${projectId}/generator-lines`),
    ]).then(([uRes, gRes]) => {
      setBldgUtilLines(uRes.data.data ?? []);
      setBldgUtilTotal(Number(uRes.data.total_power ?? 0));
      setBldgGenLines(gRes.data.data ?? []);
      setBldgGenTotal(Number(gRes.data.total_power ?? 0));
    }).catch(() => {});
  }, [projectId]);

  const solarMode         = entity?.solar_source ?? 'max';
  const solarExisting     = Number(entity?.existing_solar_power ?? 0) + buildingsSolarSum;
  const solarMaxAvailable = solarComputed !== undefined ? Number(solarComputed) : Number(entity?.solar_power ?? 0);
  const solar             = solarMode === 'existing' ? solarExisting : solarMaxAvailable;

  const totalAvailable = solar + genTotal + utilTotal + bldgGenTotal + bldgUtilTotal;
  const hasLoadData    = maxLoad > 0 || optimizedLoad > 0;

  if (!entity) return null;

  const sourceCount = 1 + (generatorEndpoint ? 1 : 0) + (utilityEndpoint ? 1 : 0);

  const generatorIcon = (
    <svg className="w-4 h-4 text-orange-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
    </svg>
  );
  const utilityIcon = (
    <svg className="w-4 h-4 text-blue-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
        d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
    </svg>
  );

  return (
    <div className="bg-gradient-to-r from-emerald-600 to-emerald-500 text-white shadow-sm">

      {/* ── Always-visible bar ── */}
      <div className="px-6 py-2.5 flex items-center gap-4 flex-wrap">

        {/* Icon + total available */}
        <div className="flex items-center gap-2.5">
          <div className="w-8 h-8 bg-white/15 rounded-lg flex items-center justify-center flex-shrink-0">
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 10H3m18-10h-2m2 10h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
            </svg>
          </div>
          <div>
            <p className="text-[10px] text-emerald-200 uppercase tracking-wider leading-none mb-1">Available</p>
            <p className="text-lg font-bold leading-none">{fmt(totalAvailable)}</p>
          </div>
        </div>

        {/* Coverage badges */}
        {hasLoadData && (
          <div className="flex items-center gap-2">
            <CoverageBadge label="Max" available={totalAvailable} load={maxLoad} />
            <CoverageBadge label="Opt" available={totalAvailable} load={optimizedLoad} />
          </div>
        )}

        <div className="flex-1" />

        {/* Solar dropdown */}
        <SolarDropdown
          solar={solar}
          solarMaxAvailable={solarMaxAvailable}
          solarComputed={solarComputed}
          entity={entity}
          updateEndpoint={updateEndpoint}
          buildingsSolarSum={buildingsSolarSum}
          onUpdate={onUpdate}
        />

        {/* Generator */}
        {generatorEndpoint && (
          <>
            <div className="w-px h-8 bg-white/20" />
            <LinesDropdown
              label="Generator" icon={generatorIcon}
              iconColor="bg-orange-100 text-orange-700"
              accentFrom="from-orange-500" accentTo="to-orange-400"
              endpoint={generatorEndpoint} deleteEndpoint="/api/generator-lines"
              onTotalChange={setGenTotal}
            />
          </>
        )}

        {/* Utility */}
        {utilityEndpoint && (
          <>
            <div className="w-px h-8 bg-white/20" />
            <LinesDropdown
              label="Utility" icon={utilityIcon}
              iconColor="bg-emerald-100 text-emerald-700"
              accentFrom="from-emerald-600" accentTo="to-emerald-500"
              endpoint={utilityEndpoint} deleteEndpoint="/api/utility-lines"
              onTotalChange={setUtilTotal}
            />
          </>
        )}

        {/* Building inherited sources — read-only indicator */}
        {projectId && (bldgGenTotal > 0 || bldgUtilTotal > 0) && (
          <>
            <div className="w-px h-8 bg-white/20" />
            <div className="flex items-center gap-2">
              <div className="w-7 h-7 bg-white/20 rounded-lg flex items-center justify-center flex-shrink-0">
                <svg className="w-4 h-4 text-emerald-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 00-1-1h-2a1 1 0 00-1 1v5m4 0H9" />
                </svg>
              </div>
              <div className="text-left">
                <p className="text-xs text-emerald-100 leading-none mb-0.5">Project</p>
                <p className="text-sm font-semibold leading-none">{fmt(bldgGenTotal + bldgUtilTotal)}</p>
                <p className="text-xs text-emerald-200 leading-none mt-0.5">
                  {[bldgGenLines.length > 0 && `${bldgGenLines.length} gen`, bldgUtilLines.length > 0 && `${bldgUtilLines.length} util`].filter(Boolean).join(' · ')}
                </p>
              </div>
            </div>
          </>
        )}

        {/* Analysis dropdown toggle */}
        <button onClick={() => setOpen(o => !o)}
          className="w-7 h-7 flex items-center justify-center rounded-lg border border-white/25 text-emerald-200
            hover:bg-white/10 hover:text-white transition-colors flex-shrink-0">
          <svg className={`w-3.5 h-3.5 transition-transform duration-200 ${open ? 'rotate-180' : ''}`}
            fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M19 9l-7 7-7-7" />
          </svg>
        </button>
      </div>

      {/* ── Analysis dropdown ── */}
      {open && (
        <div className="px-6 pb-4 pt-3 border-t border-white/10">

          {/* Coverage analysis — only when load data is available */}
          {hasLoadData && (
            <div className="grid grid-cols-2 gap-3 mb-3">
              <CoverageCard label="vs Max Load"  available={totalAvailable} load={maxLoad} />
              <CoverageCard label="vs Optimized" available={totalAvailable} load={optimizedLoad} />
            </div>
          )}

          {/* Per-source breakdown */}
          <div className={`grid gap-3 ${
            sourceCount === 3 ? 'grid-cols-3' :
            sourceCount === 2 ? 'grid-cols-2' : 'grid-cols-1'
          }`}>
            <SourceCard
              label="Solar" dot="bg-yellow-300"
              capacity={solar}
              maxLoad={maxLoad} optLoad={optimizedLoad}
              fmtFn={fmtW}
            />
            {generatorEndpoint && (
              <SourceCard
                label="Generator" dot="bg-orange-400"
                capacity={genTotal}
                maxLoad={maxLoad} optLoad={optimizedLoad}
              />
            )}
            {utilityEndpoint && (
              <SourceCard
                label="Utility" dot="bg-blue-400"
                capacity={utilTotal}
                maxLoad={maxLoad} optLoad={optimizedLoad}
              />
            )}
          </div>

          {/* Building sources breakdown */}
          {projectId && (bldgGenTotal > 0 || bldgUtilTotal > 0) && (
            <div className="mt-3 pt-3 border-t border-white/10">
              <p className="text-[10px] font-semibold text-emerald-300/70 uppercase tracking-wider mb-2">
                Project Sources (Inherited)
              </p>
              <div className={`grid gap-3 ${bldgGenTotal > 0 && bldgUtilTotal > 0 ? 'grid-cols-2' : 'grid-cols-1'}`}>
                {bldgGenTotal > 0 && (
                  <SourceCard
                    label={`Bldg Gen${bldgGenLines.length > 0 ? ` (${bldgGenLines.length})` : ''}`}
                    dot="bg-orange-300/70"
                    capacity={bldgGenTotal}
                    maxLoad={maxLoad} optLoad={optimizedLoad}
                  />
                )}
                {bldgUtilTotal > 0 && (
                  <SourceCard
                    label={`Bldg Utility${bldgUtilLines.length > 0 ? ` (${bldgUtilLines.length})` : ''}`}
                    dot="bg-blue-300/70"
                    capacity={bldgUtilTotal}
                    maxLoad={maxLoad} optLoad={optimizedLoad}
                  />
                )}
              </div>
            </div>
          )}

          {!hasLoadData && (
            <p className="text-xs text-emerald-300 mt-2 text-center">
              Load data loading… open the power banner to see coverage analysis.
            </p>
          )}
        </div>
      )}
    </div>
  );
}
