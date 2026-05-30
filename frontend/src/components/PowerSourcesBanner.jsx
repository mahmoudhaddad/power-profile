/* eslint-disable react/prop-types */
import { useState, useEffect, useRef } from 'react';
import api from '../api/axios';

const CHEM_LABELS = {
  lead_acid_flooded: 'Lead-Acid Flooded',
  lead_acid_agm:     'Lead-Acid AGM',
  lead_acid_gel:     'Lead-Acid Gel',
  lithium_lfp:       'LFP (LiFePO4)',
  lithium_nmc:       'NMC Lithium',
};

const HEALTH = {
  good:     { cls: 'bg-emerald-100 text-emerald-700', label: 'Good' },
  fair:     { cls: 'bg-yellow-100  text-yellow-700',  label: 'Fair' },
  degraded: { cls: 'bg-orange-100  text-orange-700',  label: 'Degraded' },
  replace:  { cls: 'bg-red-100     text-red-600',     label: 'Replace' },
};

function fmtKwh(v) {
  const n = Number(v) || 0;
  if (n >= 1000) return `${(n / 1000).toFixed(1)} MWh`;
  if (n >= 1)    return `${n.toFixed(1)} kWh`;
  return `${Math.round(n * 1000)} Wh`;
}

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

// ── Named solar systems manager ──────────────────────────────────────────────
function SolarSystemsDropdown({ endpoint, onTotalChange, onSystemsChange }) {
  const [systems, setSystems]   = useState([]);
  const [totalKw, setTotalKw]   = useState(0);
  const [open, setOpen]         = useState(false);
  const [name, setName]         = useState('');
  const [capKw, setCapKw]       = useState('');
  const [adding, setAdding]     = useState(false);
  const [editingId, setEditingId] = useState(null);
  const [editName, setEditName] = useState('');
  const [editCap, setEditCap]   = useState('');
  const [saving, setSaving]     = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    if (!endpoint) return;
    api.get(endpoint).then(({ data }) => applyUpdate(data.data ?? []));
  }, [endpoint]);

  useEffect(() => {
    function h(e) { if (ref.current && !ref.current.contains(e.target)) setOpen(false); }
    if (open) document.addEventListener('mousedown', h);
    return () => document.removeEventListener('mousedown', h);
  }, [open]);

  function applyUpdate(list) {
    setSystems(list);
    const kw = list.filter(s => s.is_active !== false).reduce((s, x) => s + Number(x.capacity_kw), 0);
    setTotalKw(kw);
    onTotalChange?.(kw * 1000);
    onSystemsChange?.(list);
  }

  async function handleAdd() {
    if (!name.trim() || !capKw) return;
    setAdding(true);
    try {
      const { data } = await api.post(endpoint, { name: name.trim(), capacity_kw: Number(capKw) });
      applyUpdate([...systems, data.data]);
      setName(''); setCapKw('');
    } finally { setAdding(false); }
  }

  async function handleDelete(id) {
    await api.delete(`/api/solar-systems/${id}`);
    applyUpdate(systems.filter(s => s.id !== id));
  }

  function startEdit(sys) {
    setEditingId(sys.id);
    setEditName(sys.name);
    setEditCap(String(sys.capacity_kw));
  }

  async function handleSaveEdit() {
    if (!editName.trim() || !editCap) return;
    setSaving(true);
    try {
      const { data } = await api.put(`/api/solar-systems/${editingId}`, { name: editName.trim(), capacity_kw: Number(editCap) });
      applyUpdate(systems.map(s => s.id === editingId ? data.data : s));
      setEditingId(null);
    } finally { setSaving(false); }
  }

  if (!endpoint) return null;

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
          <p className="text-sm font-semibold leading-none">{totalKw > 0 ? `${totalKw.toFixed(1)} kW` : '—'}</p>
          <p className="text-xs text-emerald-200 leading-none mt-0.5">
            {systems.length > 0 ? `${systems.length} system${systems.length !== 1 ? 's' : ''}` : 'no systems'}
          </p>
        </div>
        <svg className={`w-3.5 h-3.5 text-emerald-200 transition-transform duration-200 ${open ? 'rotate-180' : ''}`}
          fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
        </svg>
      </button>

      {open && (
        <div className="absolute right-0 top-full mt-2 w-72 bg-white rounded-2xl shadow-2xl border border-gray-100 z-50 overflow-hidden">
          <div className="bg-gradient-to-r from-yellow-500 to-amber-400 px-4 py-3">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-xs text-white/70 leading-none mb-0.5">Solar Systems</p>
                <p className="text-base font-bold text-white leading-none">{totalKw.toFixed(1)} kW total</p>
              </div>
              <span className="text-xs bg-white/25 text-white px-2.5 py-1 rounded-full font-medium">
                {systems.length} system{systems.length !== 1 ? 's' : ''}
              </span>
            </div>
          </div>

          <div className="max-h-52 overflow-y-auto">
            {systems.length === 0 ? (
              <div className="py-6 text-center"><p className="text-xs text-gray-400">No solar systems yet</p></div>
            ) : (
              <ul className="divide-y divide-gray-50">
                {systems.map((sys, i) => (
                  <li key={sys.id} className="px-4 py-2.5 hover:bg-gray-50 transition-colors group/sys">
                    {editingId === sys.id ? (
                      <div className="flex flex-col gap-1.5">
                        <input type="text" value={editName} onChange={e => setEditName(e.target.value)} autoFocus
                          className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-xs text-gray-800 focus:outline-none focus:ring-2 focus:ring-amber-400 bg-white" />
                        <div className="flex gap-2">
                          <div className="relative flex-1">
                            <input type="number" min="0.01" step="0.1" value={editCap} onChange={e => setEditCap(e.target.value)}
                              className="w-full border border-gray-200 rounded-lg pl-3 pr-7 py-1.5 text-xs text-gray-800 focus:outline-none focus:ring-2 focus:ring-amber-400 bg-white" />
                            <span className="absolute right-2 top-1/2 -translate-y-1/2 text-[10px] text-gray-400">kW</span>
                          </div>
                          <button onClick={handleSaveEdit} disabled={saving || !editName.trim() || !editCap}
                            className="px-3 py-1.5 text-xs font-semibold bg-amber-500 hover:bg-amber-600 disabled:opacity-40 text-white rounded-lg">
                            {saving ? '…' : 'Save'}
                          </button>
                          <button onClick={() => setEditingId(null)}
                            className="px-3 py-1.5 text-xs font-semibold bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-lg">
                            ✕
                          </button>
                        </div>
                      </div>
                    ) : (
                      <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2 min-w-0">
                          <span className="w-5 h-5 rounded-full bg-amber-100 text-amber-700 text-xs font-bold flex items-center justify-center flex-shrink-0">{i + 1}</span>
                          <div className="min-w-0">
                            <p className="text-sm font-medium text-gray-800 truncate">{sys.name}</p>
                            <p className="text-xs font-semibold text-amber-600">{Number(sys.capacity_kw).toFixed(1)} kW</p>
                          </div>
                        </div>
                        <div className="flex items-center gap-1 opacity-0 group-hover/sys:opacity-100 transition-all ml-2">
                          <button onClick={() => startEdit(sys)}
                            className="w-6 h-6 rounded-lg flex items-center justify-center text-gray-300 hover:text-amber-500 hover:bg-amber-50 transition-all">
                            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                          </button>
                          <button onClick={() => handleDelete(sys.id)}
                            className="w-6 h-6 rounded-lg flex items-center justify-center text-gray-300 hover:text-red-500 hover:bg-red-50 transition-all">
                            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                          </button>
                        </div>
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </div>

          <div className="px-4 py-3 border-t border-gray-100 bg-gray-50">
            <p className="text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Add Solar System</p>
            <div className="flex flex-col gap-2">
              <input type="text" value={name} onChange={e => setName(e.target.value)}
                onKeyDown={e => e.key === 'Enter' && handleAdd()}
                placeholder="System name (e.g. Rooftop Array A)"
                className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-xs text-gray-800 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-amber-400 bg-white" />
              <div className="flex gap-2">
                <div className="relative flex-1">
                  <input type="number" min="0.01" step="0.1" value={capKw}
                    onChange={e => setCapKw(e.target.value)}
                    onKeyDown={e => e.key === 'Enter' && handleAdd()}
                    placeholder="Capacity"
                    className="w-full border border-gray-200 rounded-lg pl-3 pr-7 py-1.5 text-xs text-gray-800 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-amber-400 bg-white" />
                  <span className="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-gray-400">kW</span>
                </div>
                <button onClick={handleAdd} disabled={!name.trim() || !capKw || adding}
                  className="w-8 h-8 bg-amber-500 hover:bg-amber-600 disabled:opacity-40 rounded-lg flex items-center justify-center text-white transition-colors flex-shrink-0">
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

// ── Inline edit row for a single solar system ────────────────────────────────
// Uses uncontrolled inputs (defaultValue + refs) so typing is never blocked
// by parent re-renders.
function SysEditRow({ sys, onSave, onCancel, saving }) {
  const nameRef = useRef(null);
  const kwRef   = useRef(null);

  function save() {
    const n = nameRef.current?.value?.trim() ?? '';
    const k = kwRef.current?.value ?? '';
    if (n && k) onSave(n, k);
  }

  return (
    <div className="flex gap-1.5" onMouseDown={e => e.stopPropagation()}>
      <input
        ref={nameRef}
        type="text"
        defaultValue={sys.name}
        placeholder="System name"
        autoFocus
        onKeyDown={e => { if (e.key === 'Enter') save(); if (e.key === 'Escape') onCancel(); }}
        className="flex-1 min-w-0 border border-amber-300 rounded px-2 py-1.5 text-xs text-gray-800
          focus:outline-none focus:ring-2 focus:ring-amber-400 bg-white"
      />
      <div className="relative w-24 flex-shrink-0">
        <input
          ref={kwRef}
          type="number" min="0.01" step="0.1"
          defaultValue={sys.capacity_kw}
          placeholder="kW"
          onKeyDown={e => { if (e.key === 'Enter') save(); if (e.key === 'Escape') onCancel(); }}
          className="w-full border border-amber-300 rounded pl-2 pr-7 py-1.5 text-xs text-gray-800
            focus:outline-none focus:ring-2 focus:ring-amber-400 bg-white"
        />
        <span className="absolute right-2 top-1/2 -translate-y-1/2 text-[9px] text-gray-400">kW</span>
      </div>
      <button onClick={save} disabled={saving}
        className="px-2.5 py-1.5 text-xs font-semibold bg-amber-500 hover:bg-amber-600
          text-white rounded disabled:opacity-40 flex-shrink-0 transition-colors">
        {saving ? '…' : '✓'}
      </button>
      <button onClick={onCancel}
        className="px-2.5 py-1.5 text-xs font-semibold bg-gray-100 hover:bg-gray-200
          text-gray-600 rounded flex-shrink-0 transition-colors">
        ✕
      </button>
    </div>
  );
}

// ── Solar dropdown — toggle max-available vs installed system ─────────────────
function SolarDropdown({ solar, solarMaxAvailable, solarComputed, entity, updateEndpoint, onUpdate,
  buildingsSolarSum = 0, solarSystemsEndpoint, onSystemsChange }) {
  const [open, setOpen]               = useState(false);
  const [existingInput, setExistingInput] = useState('');
  const [saving, setSaving]           = useState(false);
  const ref = useRef(null);

  // ── Named solar systems (inside the "Existing" section) ─────────────────────
  const [solarSystems, setSolarSystemsLocal] = useState([]);
  const [sysAddName, setSysAddName]   = useState('');
  const [sysAddKw, setSysAddKw]       = useState('');
  const [sysAdding, setSysAdding]     = useState(false);
  const [sysEditId, setSysEditId]     = useState(null);
  const [sysSaving, setSysSaving]     = useState(false);

  useEffect(() => {
    if (!solarSystemsEndpoint) return;
    api.get(solarSystemsEndpoint).then(({ data }) => applySystemsUpdate(data.data ?? []));
  }, [solarSystemsEndpoint]);

  function applySystemsUpdate(list) {
    setSolarSystemsLocal(list);
    onSystemsChange?.(list);
  }

  async function handleSysAdd() {
    if (!sysAddName.trim() || !sysAddKw) return;
    setSysAdding(true);
    try {
      const { data } = await api.post(solarSystemsEndpoint, { name: sysAddName.trim(), capacity_kw: Number(sysAddKw) });
      applySystemsUpdate([...solarSystems, data.data]);
      setSysAddName(''); setSysAddKw('');
    } finally { setSysAdding(false); }
  }

  async function handleSysDelete(id) {
    await api.delete(`/api/solar-systems/${id}`);
    applySystemsUpdate(solarSystems.filter(s => s.id !== id));
  }

  async function handleSysEditSave(id, name, kw) {
    if (!name.trim() || !kw) return;
    setSysSaving(true);
    try {
      const { data } = await api.put(`/api/solar-systems/${id}`, {
        name: name.trim(),
        capacity_kw: Number(kw),
      });
      applySystemsUpdate(solarSystems.map(s => s.id === id ? data.data : s));
      setSysEditId(null);
    } finally { setSysSaving(false); }
  }

  const solarMode     = entity?.solar_source ?? 'max';
  const solarExisting = Number(entity?.existing_solar_power ?? 0);

  useEffect(() => {
    setExistingInput(solarExisting > 0 ? String(solarExisting) : '');
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

          {/* Existing / installed system section — only shown when mode = existing */}
          {solarMode === 'existing' && <div className="px-4 py-3 bg-gray-50">
            <p className="text-[10px] font-semibold text-gray-400 mb-1.5 uppercase tracking-wide">Installed System Output</p>

            {solarSystemsEndpoint ? (
              /* ── Named solar systems mode ── */
              <>
                {/* System list */}
                {solarSystems.length > 0 && (
                  <ul className="divide-y divide-amber-100 mb-2 bg-white rounded-lg border border-amber-100 overflow-hidden">
                    {solarSystems.map((sys, i) => (
                      <li key={sys.id} className="group/sys px-3 py-2 hover:bg-amber-50 transition-colors">
                        {sysEditId === sys.id ? (
                          <SysEditRow
                            sys={sys}
                            saving={sysSaving}
                            onSave={(name, kw) => handleSysEditSave(sys.id, name, kw)}
                            onCancel={() => setSysEditId(null)}
                          />
                        ) : (
                          <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2 min-w-0">
                              <span className="w-4 h-4 rounded-full bg-amber-100 text-amber-700 text-[9px] font-bold flex items-center justify-center flex-shrink-0">{i + 1}</span>
                              <span className="text-xs font-medium text-gray-800 truncate">{sys.name}</span>
                              <span className="text-xs font-semibold text-amber-600 flex-shrink-0">{Number(sys.capacity_kw).toFixed(1)} kW</span>
                            </div>
                            <div className="flex gap-1 opacity-0 group-hover/sys:opacity-100 transition-all">
                              <button onClick={() => setSysEditId(sys.id)}
                                className="w-5 h-5 flex items-center justify-center text-gray-300 hover:text-amber-500 transition-colors">
                                <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                    d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                              </button>
                              <button onClick={() => handleSysDelete(sys.id)}
                                className="w-5 h-5 flex items-center justify-center text-gray-300 hover:text-red-500 transition-colors">
                                <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                    d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                              </button>
                            </div>
                          </div>
                        )}
                      </li>
                    ))}
                  </ul>
                )}

                {/* Total */}
                {solarSystems.length > 0 && (
                  <div className="flex items-center justify-between mb-2 px-2 py-1 bg-amber-50 border border-amber-200 rounded-lg">
                    <span className="text-[10px] font-semibold text-amber-700">Total installed</span>
                    <span className="text-xs font-bold text-amber-700">
                      {fmtKwh(solarSystems.reduce((s, x) => s + Number(x.capacity_kw), 0))}
                    </span>
                  </div>
                )}

                {/* Add form */}
                <div className="flex gap-1.5">
                  <input type="text" placeholder="System name" value={sysAddName}
                    onChange={e => setSysAddName(e.target.value)}
                    onKeyDown={e => e.key === 'Enter' && handleSysAdd()}
                    className="flex-1 min-w-0 border border-gray-200 rounded-lg px-2 py-1.5 text-xs text-gray-800
                      placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-amber-400 bg-white" />
                  <div className="relative w-20 flex-shrink-0">
                    <input type="number" min="0.01" step="0.1" placeholder="kW" value={sysAddKw}
                      onChange={e => setSysAddKw(e.target.value)}
                      onKeyDown={e => e.key === 'Enter' && handleSysAdd()}
                      className="w-full border border-gray-200 rounded-lg pl-2 pr-6 py-1.5 text-xs text-gray-800
                        placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-amber-400 bg-white" />
                    <span className="absolute right-1.5 top-1/2 -translate-y-1/2 text-[9px] text-gray-400">kW</span>
                  </div>
                  <button onClick={handleSysAdd} disabled={!sysAddName.trim() || !sysAddKw || sysAdding}
                    className="w-8 h-8 bg-amber-500 hover:bg-amber-600 disabled:opacity-40 rounded-lg flex items-center justify-center text-white flex-shrink-0 transition-colors">
                    {sysAdding
                      ? <div className="w-3 h-3 border-2 border-white border-t-transparent rounded-full animate-spin" />
                      : <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                        </svg>
                    }
                  </button>
                </div>

                {solarMode === 'existing' && solarSystems.length > 0 && (
                  <p className="text-[10px] text-amber-600 font-medium mt-1.5">Currently used in calculations</p>
                )}
              </>
            ) : (
              /* ── Legacy single watt input ── */
              <>
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
              </>
            )}
          </div>}
        </div>
      )}
    </div>
  );
}

// ── Battery bank add / edit form (top-level so React never unmounts on rerender)
function BatteryBankForm({ initialValues, solarSystems = [], onSubmit, onCancel, submitLabel }) {
  const [form, setForm] = useState(initialValues);
  const [busy, setBusy] = useState(false);
  const set = (key, val) => setForm(f => ({ ...f, [key]: val }));

  const chemOptions = Object.entries(CHEM_LABELS).map(([v, l]) => (
    <option key={v} value={v}>{l}</option>
  ));

  const computedQty = (Number(form.series_count) || 1) * (Number(form.parallel_count) || 1);
  const isValid = form.name.trim() && form.nominal_voltage_v && form.capacity_ah_per_unit;

  async function handleSubmit() {
    if (!isValid) return;
    setBusy(true);
    try {
      await onSubmit({ ...form, quantity: computedQty });
      if (!onCancel) setForm(initialValues);
    } finally { setBusy(false); }
  }

  return (
    <div className="flex flex-col gap-1.5 py-2">
      <input type="text" placeholder="Bank name" value={form.name}
        onChange={e => set('name', e.target.value)}
        className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-xs text-gray-800
          focus:outline-none focus:ring-2 focus:ring-violet-400 bg-white" />
      <select value={form.chemistry} onChange={e => set('chemistry', e.target.value)}
        className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-xs text-gray-700
          focus:outline-none focus:ring-2 focus:ring-violet-400 bg-white">
        {chemOptions}
      </select>
      <div className="grid grid-cols-2 gap-1.5">
        {[['nominal_voltage_v','Voltage','V'],['capacity_ah_per_unit','Ah/unit','Ah']].map(([k,ph,unit]) => (
          <div key={k} className="relative">
            <input type="number" placeholder={ph} min="1" value={form[k]}
              onChange={e => set(k, e.target.value)}
              className="w-full border border-gray-200 rounded-lg pl-2 pr-7 py-1.5 text-xs text-gray-800
                focus:outline-none focus:ring-2 focus:ring-violet-400 bg-white" />
            <span className="absolute right-1.5 top-1/2 -translate-y-1/2 text-[10px] text-gray-400">{unit}</span>
          </div>
        ))}
      </div>
      <div className="flex items-center gap-1.5">
        <div className="relative flex-1">
          <input type="number" placeholder="Series" min="1" value={form.series_count}
            onChange={e => set('series_count', e.target.value)}
            className="w-full border border-gray-200 rounded-lg pl-2 pr-5 py-1.5 text-xs text-gray-800
              focus:outline-none focus:ring-2 focus:ring-violet-400 bg-white" />
          <span className="absolute right-1.5 top-1/2 -translate-y-1/2 text-[10px] text-gray-400">S</span>
        </div>
        <span className="text-xs text-gray-400 flex-shrink-0">×</span>
        <div className="relative flex-1">
          <input type="number" placeholder="Parallel" min="1" value={form.parallel_count}
            onChange={e => set('parallel_count', e.target.value)}
            className="w-full border border-gray-200 rounded-lg pl-2 pr-5 py-1.5 text-xs text-gray-800
              focus:outline-none focus:ring-2 focus:ring-violet-400 bg-white" />
          <span className="absolute right-1.5 top-1/2 -translate-y-1/2 text-[10px] text-gray-400">P</span>
        </div>
        <span className="text-xs font-semibold text-violet-600 flex-shrink-0">= {computedQty} batteries</span>
      </div>
      <input type="date" value={form.installation_date}
        max={new Date().toISOString().split('T')[0]}
        onChange={e => set('installation_date', e.target.value)}
        className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-xs text-gray-800
          focus:outline-none focus:ring-2 focus:ring-violet-400 bg-white" />
      {solarSystems.length > 0 && (
        <select value={form.solar_system_id} onChange={e => set('solar_system_id', e.target.value)}
          className="w-full border border-amber-200 rounded-lg px-3 py-1.5 text-xs text-gray-700
            focus:outline-none focus:ring-2 focus:ring-amber-400 bg-amber-50/50">
          <option value="">☀ No dedicated solar system</option>
          {solarSystems.map(s => (
            <option key={s.id} value={s.id}>☀ {s.name} ({Number(s.capacity_kw).toFixed(1)} kW)</option>
          ))}
        </select>
      )}
      <div className="flex gap-1.5">
        <button onClick={handleSubmit} disabled={busy || !isValid}
          className="flex-1 py-1.5 text-xs font-semibold bg-violet-500 hover:bg-violet-600
            disabled:opacity-40 text-white rounded-lg transition-colors">
          {busy ? 'Saving…' : submitLabel}
        </button>
        {onCancel && (
          <button onClick={onCancel}
            className="flex-1 py-1.5 text-xs font-semibold bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-lg">
            Cancel
          </button>
        )}
      </div>
    </div>
  );
}

// ── Battery bank dropdown ─────────────────────────────────────────────────────
const EMPTY_ADD = () => ({
  name: '', chemistry: 'lithium_lfp',
  nominal_voltage_v: '', capacity_ah_per_unit: '',
  quantity: '', series_count: '1', parallel_count: '1',
  installation_date: new Date().toISOString().split('T')[0],
  solar_system_id: '',
});

function BatteryDropdown({ endpoint, onTotalChange, solarSystems = [] }) {
  const [banks, setBanks]           = useState([]);
  const [totalKwh, setTotalKwh]     = useState(0);
  const [open, setOpen]             = useState(false);
  const [addError, setAddError]     = useState('');
  const [editingId, setEditingId]   = useState(null);
  const [editInitial, setEditInitial] = useState(null);
  const ref = useRef(null);

  useEffect(() => {
    if (!endpoint) return;
    api.get(endpoint).then(({ data }) => applyBanks(data.data ?? []));
  }, [endpoint]);

  useEffect(() => {
    function h(e) { if (ref.current && !ref.current.contains(e.target)) setOpen(false); }
    if (open) document.addEventListener('mousedown', h);
    return () => document.removeEventListener('mousedown', h);
  }, [open]);

  function applyBanks(list) {
    setBanks(list);
    const kwh = list.reduce((s, b) => s + Number(b.usable_capacity_kwh ?? 0), 0);
    const pw  = list.reduce((s, b) => s + Number(b.max_discharge_power_kw ?? 0) * 1000, 0);
    setTotalKwh(kwh);
    onTotalChange?.(pw);
  }

  async function handleAdd(f) {
    setAddError('');
    try {
      const { data } = await api.post(endpoint, {
        name:                 f.name.trim(),
        chemistry:            f.chemistry,
        nominal_voltage_v:    Number(f.nominal_voltage_v),
        capacity_ah_per_unit: Number(f.capacity_ah_per_unit),
        quantity:             Number(f.quantity),
        series_count:         Number(f.series_count) || 1,
        parallel_count:       Number(f.parallel_count) || 1,
        installation_date:    f.installation_date,
        solar_system_id:      f.solar_system_id ? Number(f.solar_system_id) : null,
      });
      applyBanks([...banks, data.data]);
    } catch {
      setAddError('Failed to add. Check all fields.');
      throw new Error('add failed');
    }
  }

  async function handleDelete(id) {
    await api.delete(`/api/batteries/${id}`);
    applyBanks(banks.filter(b => b.id !== id));
  }

  function startEdit(bank) {
    setEditingId(bank.id);
    setEditInitial({
      name:                 bank.name,
      chemistry:            bank.chemistry,
      nominal_voltage_v:    String(bank.nominal_voltage_v),
      capacity_ah_per_unit: String(bank.capacity_ah_per_unit),
      quantity:             String(bank.quantity),
      series_count:         String(bank.series_count),
      parallel_count:       String(bank.parallel_count),
      installation_date:    bank.installation_date
        ? bank.installation_date.split('T')[0]
        : new Date().toISOString().split('T')[0],
      solar_system_id:      bank.solar_system_id != null ? String(bank.solar_system_id) : '',
    });
  }

  async function handleSaveEdit(f) {
    const { data } = await api.put(`/api/batteries/${editingId}`, {
      name:                 f.name.trim(),
      chemistry:            f.chemistry,
      nominal_voltage_v:    Number(f.nominal_voltage_v),
      capacity_ah_per_unit: Number(f.capacity_ah_per_unit),
      quantity:             Number(f.quantity),
      series_count:         Number(f.series_count) || 1,
      parallel_count:       Number(f.parallel_count) || 1,
      installation_date:    f.installation_date,
      solar_system_id:      f.solar_system_id ? Number(f.solar_system_id) : null,
    });
    applyBanks(banks.map(b => b.id === editingId ? data.data : b));
    setEditingId(null);
  }

  async function handleResetSoc(id, soc) {
    const { data } = await api.post(`/api/batteries/${id}/reset-soc`, { soc });
    applyBanks(banks.map(b => b.id === id ? data.data : b));
  }

  const worstHealth = banks.reduce((worst, b) => {
    const order = { good: 0, fair: 1, degraded: 2, replace: 3 };
    return (order[b.health_status] ?? 0) > (order[worst] ?? 0) ? b.health_status : worst;
  }, 'good');

  const chemOptions = Object.entries(CHEM_LABELS).map(([v, l]) => (
    <option key={v} value={v}>{l}</option>
  ));

  if (!endpoint) return null;

  return (
    <div className="relative" ref={ref}>
      <button onClick={() => setOpen(o => !o)} className="flex items-center gap-2 group">
        <div className="w-7 h-7 bg-white/20 rounded-lg flex items-center justify-center flex-shrink-0">
          {/* Battery icon */}
          <svg className="w-4 h-4 text-violet-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M3 7h13a1 1 0 011 1v8a1 1 0 01-1 1H3a1 1 0 01-1-1V8a1 1 0 011-1zM20 10v4" />
          </svg>
        </div>
        <div className="text-left">
          <p className="text-xs text-emerald-100 leading-none mb-0.5">Battery</p>
          <p className="text-sm font-semibold leading-none">{fmtKwh(totalKwh)}</p>
          <p className="text-xs text-emerald-200 leading-none mt-0.5">
            {banks.length > 0 ? `${banks.length} bank${banks.length !== 1 ? 's' : ''}` : 'usable'}
          </p>
        </div>
        {banks.some(b => b.health_status === 'replace' || b.health_status === 'degraded') && (
          <span className="w-1.5 h-1.5 rounded-full bg-red-400 flex-shrink-0" />
        )}
        <svg className={`w-3.5 h-3.5 text-emerald-200 transition-transform duration-200 ${open ? 'rotate-180' : ''}`}
          fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
        </svg>
      </button>

      {open && (
        <div className="absolute right-0 top-full mt-2 w-80 bg-white rounded-2xl shadow-2xl border border-gray-100 z-50 overflow-hidden">
          {/* Header */}
          <div className="bg-gradient-to-r from-violet-600 to-violet-500 px-4 py-3">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-xs text-white/70 leading-none mb-0.5">Battery Storage</p>
                <p className="text-base font-bold text-white leading-none">{fmtKwh(totalKwh)} usable</p>
              </div>
              <span className="text-xs bg-white/20 text-white px-2 py-0.5 rounded-full">
                {banks.length} bank{banks.length !== 1 ? 's' : ''}
              </span>
            </div>
          </div>

          {/* Bank list */}
          <div className="max-h-64 overflow-y-auto">
            {banks.length === 0 ? (
              <div className="py-6 text-center">
                <p className="text-xs text-gray-400">No battery banks yet</p>
              </div>
            ) : (
              <ul className="divide-y divide-gray-50">
                {banks.map((bank, i) => {
                  const health = HEALTH[bank.health_status] ?? HEALTH.good;
                  const socPct = Math.round((bank.current_soc ?? 0) * 100);
                  return (
                    <li key={bank.id} className="px-4 py-2.5 hover:bg-gray-50 transition-colors group/bank">
                      {editingId === bank.id && editInitial ? (
                        <BatteryBankForm
                          key={`edit-${bank.id}`}
                          initialValues={editInitial}
                          onSubmit={handleSaveEdit}
                          onCancel={() => setEditingId(null)}
                          submitLabel="Save"
                          solarSystems={solarSystems}
                        />
                      ) : (
                        <div>
                          <div className="flex items-start justify-between">
                            <div className="flex items-center gap-2 min-w-0">
                              <span className="w-5 h-5 rounded-full bg-violet-100 text-violet-700 text-xs font-bold flex items-center justify-center flex-shrink-0">
                                {i + 1}
                              </span>
                              <div className="min-w-0">
                                <p className="text-sm font-medium text-gray-800 truncate">{bank.name}</p>
                                <p className="text-[10px] text-gray-400">{CHEM_LABELS[bank.chemistry] ?? bank.chemistry}</p>
                              </div>
                            </div>
                            <div className="flex items-center gap-1 opacity-0 group-hover/bank:opacity-100 flex-shrink-0 ml-2">
                              <button onClick={() => startEdit(bank)}
                                className="w-6 h-6 rounded-lg flex items-center justify-center text-gray-300 hover:text-violet-500 hover:bg-violet-50 transition-all">
                                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                    d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                              </button>
                              <button onClick={() => handleDelete(bank.id)}
                                className="w-6 h-6 rounded-lg flex items-center justify-center text-gray-300 hover:text-red-500 hover:bg-red-50 transition-all">
                                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                    d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                              </button>
                            </div>
                          </div>
                          {/* Capacity + health row */}
                          <div className="flex items-center gap-2 mt-1.5 ml-7">
                            <span className="text-xs font-semibold text-violet-600">{fmtKwh(bank.usable_capacity_kwh)}</span>
                            <span className="text-[10px] text-gray-400">of {fmtKwh(bank.nominal_capacity_kwh)}</span>
                            <span className={`text-[10px] font-semibold px-1.5 py-0.5 rounded-full ${health.cls}`}>
                              {health.label}
                            </span>
                            <span className="text-[10px] text-gray-400 ml-auto">{Number(bank.age_years ?? 0).toFixed(1)} yr</span>
                          </div>
                              {/* Solar system pairing badge */}
                          {bank.solar_system_id && (() => {
                            const sys = solarSystems.find(s => s.id === bank.solar_system_id);
                            return sys ? (
                              <div className="flex items-center gap-1 mt-1 ml-7">
                                <span className="text-yellow-500 text-xs">☀</span>
                                <span className="text-[10px] font-semibold text-amber-700 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded-full">
                                  {sys.name} ({Number(sys.capacity_kw).toFixed(1)} kW)
                                </span>
                              </div>
                            ) : null;
                          })()}
                          {/* SOC bar */}
                          <div className="ml-7 mt-1.5">
                            <div className="flex items-center justify-between mb-0.5">
                              <span className="text-[10px] text-gray-400">SOC</span>
                              <span className="text-[10px] font-semibold text-gray-600">{socPct}%</span>
                            </div>
                            <div className="h-1.5 rounded-full bg-gray-100 relative">
                              <div className="h-full rounded-full bg-violet-400 transition-all"
                                style={{ width: `${socPct}%` }} />
                            </div>
                            <div className="flex gap-1 mt-1">
                              {[0, 25, 50, 75, 100].map(v => (
                                <button key={v} onClick={() => handleResetSoc(bank.id, v / 100)}
                                  className="flex-1 py-0.5 text-[9px] font-semibold text-gray-400 hover:bg-violet-50 hover:text-violet-600 rounded transition-colors">
                                  {v}%
                                </button>
                              ))}
                            </div>
                          </div>
                        </div>
                      )}
                    </li>
                  );
                })}
              </ul>
            )}
          </div>

          {/* Add form */}
          <div className="px-4 py-3 border-t border-gray-100 bg-gray-50">
            <p className="text-xs font-semibold text-gray-500 mb-1 uppercase tracking-wide">Add Battery Bank</p>
            {addError && <p className="text-xs text-red-500 mb-1">{addError}</p>}
            <BatteryBankForm
              initialValues={EMPTY_ADD()}
              onSubmit={handleAdd}
              onCancel={null}
              submitLabel="Add Bank"
              solarSystems={solarSystems}
            />
          </div>
        </div>
      )}
    </div>
  );
}

// ── Reusable dropdown for managing generator / utility lines ─────────────────
function LinesDropdown({ label, icon, iconColor, accentFrom, accentTo, endpoint, deleteEndpoint, onTotalChange,
  generatorMode, onGeneratorModeChange, genNeeded }) {
  const [lines, setLines]     = useState([]);
  const [total, setTotal]     = useState(0);
  const [open, setOpen]       = useState(false);
  const [name, setName]       = useState('');
  const [power, setPower]     = useState('');
  const [phases, setPhases]   = useState('1phase');
  const [adding, setAdding]   = useState(false);
  const [error, setError]     = useState('');
  const [editingId, setEditingId]     = useState(null);
  const [editName, setEditName]       = useState('');
  const [editPower, setEditPower]     = useState('');
  const [editPhases, setEditPhases]   = useState('1phase');
  const [saving, setSaving]   = useState(false);
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

  function startEdit(line) {
    setEditingId(line.id);
    setEditName(line.name);
    setEditPower(String(line.power));
    setEditPhases(line.phases ?? '1phase');
  }

  async function handleSaveEdit() {
    if (!editName.trim() || !editPower) return;
    setSaving(true);
    try {
      const { data } = await api.put(`${deleteEndpoint}/${editingId}`, {
        name: editName.trim(),
        power: Number(editPower),
        phases: editPhases,
      });
      applyUpdate(lines.map(l => l.id === editingId ? data.data : l));
      setEditingId(null);
    } finally {
      setSaving(false);
    }
  }

  if (!endpoint) return null;

  return (
    <div className="relative" ref={ref}>
      <button onClick={() => setOpen(o => !o)} className="flex items-center gap-2 group">
        <div className="w-7 h-7 bg-white/20 rounded-lg flex items-center justify-center flex-shrink-0">{icon}</div>
        <div className="text-left">
          <p className="text-xs text-emerald-100 leading-none mb-0.5">{label}</p>
          <p className="text-sm font-semibold leading-none">
            {generatorMode === 'needed' ? fmt(genNeeded) : fmt(total)}
          </p>
          <p className="text-xs text-emerald-200 leading-none mt-0.5">
            {generatorMode === 'needed'
              ? 'sized for load'
              : lines.length > 0 ? `${lines.length} unit${lines.length !== 1 ? 's' : ''}` : ''}
          </p>
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
                <p className="text-base font-bold text-white leading-none">
                  {generatorMode === 'needed' ? fmt(genNeeded) : fmt(total)}
                </p>
                {generatorMode === 'needed' && (
                  <p className="text-xs text-white/60 leading-none mt-0.5">sized for load</p>
                )}
              </div>
              <span className="text-xs bg-white/20 text-white px-2 py-0.5 rounded-full">
                {lines.length} unit{lines.length !== 1 ? 's' : ''}
              </span>
            </div>
          </div>

          {/* Generator mode toggle — only for generator (not utility) */}
          {generatorMode !== undefined && (
            <div className="px-4 py-3 border-b border-gray-100">
              <p className="text-[10px] font-semibold text-gray-400 mb-2 uppercase tracking-wide">Capacity in Calculations</p>
              <div className="flex rounded-lg border border-gray-200 overflow-hidden text-xs font-semibold">
                <button
                  onClick={() => generatorMode !== 'existing' && onGeneratorModeChange?.('existing')}
                  className={`flex-1 px-3 py-2 transition-colors ${
                    generatorMode === 'existing' ? 'bg-orange-500 text-white' : 'text-gray-500 hover:bg-gray-50'
                  }`}>
                  Existing
                </button>
                <button
                  onClick={() => generatorMode !== 'needed' && onGeneratorModeChange?.('needed')}
                  className={`flex-1 px-3 py-2 border-l border-gray-200 transition-colors ${
                    generatorMode === 'needed' ? 'bg-orange-500 text-white' : 'text-gray-500 hover:bg-gray-50'
                  }`}>
                  Needed ×1.25
                </button>
              </div>
              {genNeeded > 0 && (
                <div className="flex items-center justify-between mt-2 px-1">
                  <span className="text-[10px] text-gray-400 uppercase tracking-wide">Required for loads</span>
                  <span className="text-xs font-bold text-orange-600">{fmt(genNeeded)}</span>
                </div>
              )}
              {generatorMode === 'existing' && total > 0 && genNeeded > 0 && (
                <p className={`text-[10px] font-medium mt-1.5 px-1 ${total >= genNeeded ? 'text-emerald-600' : 'text-amber-600'}`}>
                  {total >= genNeeded
                    ? `✓ Your generators cover the load (${fmt(total - genNeeded)} surplus)`
                    : `⚠ ${fmt(genNeeded - total)} short of required capacity`}
                </p>
              )}
            </div>
          )}

          <div className="max-h-52 overflow-y-auto">
            {lines.length === 0 ? (
              <div className="py-6 text-center">
                <p className="text-xs text-gray-400">No {label.toLowerCase()} units yet</p>
              </div>
            ) : (
              <ul className="divide-y divide-gray-50">
                {lines.map((line, i) => (
                  <li key={line.id} className="px-4 py-2.5 hover:bg-gray-50 transition-colors group/line">
                    {editingId === line.id ? (
                      /* ── Inline edit form ── */
                      <div className="flex flex-col gap-2">
                        <input type="text" value={editName} onChange={e => setEditName(e.target.value)}
                          autoFocus
                          className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-xs text-gray-800
                            focus:outline-none focus:ring-2 focus:ring-indigo-400 bg-white" />
                        <div className="flex gap-2 items-center">
                          <div className="relative flex-1 min-w-0">
                            <input type="number" value={editPower} onChange={e => setEditPower(e.target.value)}
                              min="0"
                              className="w-full border border-gray-200 rounded-lg pl-3 pr-7 py-1.5 text-xs text-gray-800
                                focus:outline-none focus:ring-2 focus:ring-indigo-400 bg-white" />
                            <span className="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-gray-400">VA</span>
                          </div>
                          <div className="flex rounded-lg border border-gray-200 overflow-hidden flex-shrink-0 bg-white">
                            <button type="button" onClick={() => setEditPhases('1phase')}
                              className={`px-2.5 py-1.5 text-xs font-semibold transition-colors ${
                                editPhases === '1phase' ? 'bg-blue-500 text-white' : 'text-gray-500 hover:bg-gray-50'
                              }`}>1Φ</button>
                            <button type="button" onClick={() => setEditPhases('3phase')}
                              className={`px-2.5 py-1.5 text-xs font-semibold transition-colors border-l border-gray-200 ${
                                editPhases === '3phase' ? 'bg-violet-500 text-white' : 'text-gray-500 hover:bg-gray-50'
                              }`}>3Φ</button>
                          </div>
                        </div>
                        <div className="flex gap-2">
                          <button onClick={handleSaveEdit} disabled={saving || !editName.trim() || !editPower}
                            className="flex-1 py-1.5 text-xs font-semibold bg-indigo-500 hover:bg-indigo-600
                              disabled:opacity-40 text-white rounded-lg transition-colors">
                            {saving ? 'Saving…' : 'Save'}
                          </button>
                          <button onClick={() => setEditingId(null)}
                            className="flex-1 py-1.5 text-xs font-semibold bg-gray-100 hover:bg-gray-200
                              text-gray-600 rounded-lg transition-colors">
                            Cancel
                          </button>
                        </div>
                      </div>
                    ) : (
                      /* ── Normal display row ── */
                      <div className="flex items-center justify-between">
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
                        <div className="flex items-center gap-1 opacity-0 group-hover/line:opacity-100 transition-all ml-2">
                          <button onClick={() => startEdit(line)}
                            className="w-6 h-6 rounded-lg flex items-center justify-center text-gray-300
                              hover:text-indigo-500 hover:bg-indigo-50 transition-all">
                            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                          </button>
                          <button onClick={() => handleDelete(line.id)}
                            className="w-6 h-6 rounded-lg flex items-center justify-center text-gray-300
                              hover:text-red-500 hover:bg-red-50 transition-all">
                            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                          </button>
                        </div>
                      </div>
                    )}
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
  solarSystemsEndpoint,
  generatorEndpoint,
  utilityEndpoint,
  batteryEndpoint,
  projectId,
  buildingsSolarSum = 0,
  maxLoad       = 0,
  optimizedLoad = 0,
}) {
  const [open, setOpen]               = useState(false);
  const [genTotal, setGenTotal]       = useState(0);
  const [utilTotal, setUtilTotal]     = useState(0);
  const [battTotal, setBattTotal]     = useState(0);
  const [battKwh, setBattKwh]         = useState(0);
  const [solarSystems, setSolarSystems] = useState([]);
  const [bldgGenTotal,  setBldgGenTotal]  = useState(0);
  const [bldgUtilTotal, setBldgUtilTotal] = useState(0);
  const [bldgGenLines,  setBldgGenLines]  = useState([]);
  const [bldgUtilLines, setBldgUtilLines] = useState([]);
  const [generatorMode, setGeneratorMode] = useState(entity?.generator_source ?? 'existing');

  useEffect(() => {
    if (entity?.generator_source) setGeneratorMode(entity.generator_source);
  }, [entity?.generator_source]);

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
  const solarExistingLegacy = Number(entity?.existing_solar_power ?? 0) + buildingsSolarSum;
  const solarFromSystems    = solarSystems.reduce((s, x) => s + Number(x.capacity_kw) * 1000, 0);
  const solarExisting       = solarSystems.length > 0 ? solarFromSystems : solarExistingLegacy;
  const solarMaxAvailable   = solarComputed !== undefined ? Number(solarComputed) : Number(entity?.solar_power ?? 0);
  const solar               = solarMode === 'existing' ? solarExisting : solarMaxAvailable;

  const genNeeded    = maxLoad * 1.25;
  const effectiveGen = generatorMode === 'needed' ? genNeeded : genTotal;

  const totalAvailable = solar + effectiveGen + utilTotal + battTotal + bldgGenTotal + bldgUtilTotal;
  const hasLoadData    = maxLoad > 0 || optimizedLoad > 0;

  async function handleGeneratorModeChange(mode) {
    setGeneratorMode(mode);
    if (!updateEndpoint) return;
    try {
      const { data } = await api.put(updateEndpoint, { generator_source: mode });
      onUpdate?.(data.data);
    } catch (_) { /* best-effort */ }
  }

  if (!entity) return null;

  const sourceCount = 1 + (batteryEndpoint ? 1 : 0) + (generatorEndpoint ? 1 : 0) + (utilityEndpoint ? 1 : 0);

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

        {/* Solar dropdown — max-available (roof area) unchanged; existing section has named systems */}
        <SolarDropdown
          solar={solar}
          solarMaxAvailable={solarMaxAvailable}
          solarComputed={solarComputed}
          entity={entity}
          updateEndpoint={updateEndpoint}
          buildingsSolarSum={buildingsSolarSum}
          onUpdate={onUpdate}
          solarSystemsEndpoint={solarSystemsEndpoint}
          onSystemsChange={setSolarSystems}
        />

        {/* Battery */}
        {batteryEndpoint && (
          <>
            <div className="w-px h-8 bg-white/20" />
            <BatteryDropdown
              endpoint={batteryEndpoint}
              onTotalChange={pw => { setBattTotal(pw); }}
              solarSystems={solarSystems}
            />
          </>
        )}

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
              generatorMode={generatorMode}
              onGeneratorModeChange={handleGeneratorModeChange}
              genNeeded={genNeeded}
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
            sourceCount >= 4 ? 'grid-cols-4' :
            sourceCount === 3 ? 'grid-cols-3' :
            sourceCount === 2 ? 'grid-cols-2' : 'grid-cols-1'
          }`}>
            <SourceCard
              label="Solar" dot="bg-yellow-300"
              capacity={solar}
              maxLoad={maxLoad} optLoad={optimizedLoad}
              fmtFn={fmtW}
            />
            {batteryEndpoint && battTotal > 0 && (
              <SourceCard
                label="Battery" dot="bg-violet-400"
                capacity={battTotal}
                maxLoad={maxLoad} optLoad={optimizedLoad}
              />
            )}
            {generatorEndpoint && (
              <SourceCard
                label={generatorMode === 'needed' ? 'Generator (×1.25)' : 'Generator'}
                dot="bg-orange-400"
                capacity={effectiveGen}
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
