/* eslint-disable react/prop-types */
import { useState, useEffect } from 'react';
import api from '../api/axios';

function fmt(v, unit = 'va') {
  const n = Number(v) || 0;
  if (n === 0) return unit === 'va' ? '0 VA' : '0 W';
  if (unit === 'va') {
    if (n >= 1_000_000) return `${(n / 1_000_000).toLocaleString(undefined, { maximumFractionDigits: 2 })} MVA`;
    if (n >= 1_000)     return `${(n / 1_000).toLocaleString(undefined,     { maximumFractionDigits: 2 })} kVA`;
    return `${n.toLocaleString(undefined, { maximumFractionDigits: 0 })} VA`;
  }
  if (n >= 1_000_000) return `${(n / 1_000_000).toLocaleString(undefined, { maximumFractionDigits: 2 })} MW`;
  if (n >= 1_000)     return `${(n / 1_000).toLocaleString(undefined,     { maximumFractionDigits: 2 })} kW`;
  return `${n.toLocaleString(undefined, { maximumFractionDigits: 0 })} W`;
}

const PRIORITIES = [
  { key: 'critical',  label: 'Critical',  dot: 'bg-red-400',     opt: 'text-red-300'     },
  { key: 'essential', label: 'Essential', dot: 'bg-amber-400',   opt: 'text-amber-300'   },
  { key: 'normal',    label: 'Normal',    dot: 'bg-emerald-400', opt: 'text-emerald-300' },
];

export default function PowerBanner({ endpoint, refreshKey, onData }) {
  const [data, setData]       = useState(null);
  const [loading, setLoading] = useState(true);
  const [open, setOpen]       = useState(false);
  const [unit, setUnit]       = useState('va');

  useEffect(() => {
    if (!endpoint) return;
    setLoading(true);
    api.get(endpoint)
      .then(({ data }) => { setData(data); onData?.(data); })
      .catch(() => setData(null))
      .finally(() => setLoading(false));
  }, [endpoint, refreshKey]);

  const maxVal = unit === 'va' ? (data?.max_va  ?? 0) : (data?.max_w    ?? 0);
  const optVal = unit === 'va' ? (data?.total_va ?? 0) : (data?.total   ?? 0);

  function pMax(key) { return unit === 'va' ? (data?.[`${key}_max_va`] ?? 0) : (data?.[`${key}_max_w`] ?? 0); }
  function pOpt(key) { return unit === 'va' ? (data?.[`${key}_va`]     ?? 0) : (data?.[`${key}_w`]     ?? 0); }

  const socketConnected = data?.socket_connected_va ?? 0;
  const socketDemand    = data?.socket_demand_va    ?? 0;
  const hasSocket       = socketConnected > 0;

  const visiblePriorities = PRIORITIES.filter(p => pMax(p.key) > 0 || pOpt(p.key) > 0);

  return (
    <div className="bg-gradient-to-r from-blue-700 to-blue-600 text-white shadow-lg">

      {/* ── Always-visible summary row ── */}
      <div className="px-6 py-2.5 flex items-center gap-4 flex-wrap">

        {/* Icon */}
        <div className="w-8 h-8 bg-white/15 rounded-lg flex items-center justify-center flex-shrink-0">
          <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
          </svg>
        </div>

        {loading ? (
          <div className="flex gap-6 flex-1">
            <div className="h-8 w-28 bg-white/20 rounded-lg animate-pulse" />
            <div className="h-8 w-28 bg-white/10 rounded-lg animate-pulse" />
          </div>
        ) : (
          <>
            {/* Max */}
            <div>
              <p className="text-[10px] text-blue-300 uppercase tracking-wider leading-none mb-1">Max Load</p>
              <p className="text-lg font-bold leading-none">{fmt(maxVal, unit)}</p>
            </div>

            {/* Arrow */}
            <svg className="w-4 h-4 text-blue-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 8l4 4m0 0l-4 4m4-4H3" />
            </svg>

            {/* Optimized */}
            <div>
              <p className="text-[10px] text-blue-300 uppercase tracking-wider leading-none mb-1">Optimized</p>
              <p className="text-lg font-bold leading-none text-emerald-300">{fmt(optVal, unit)}</p>
            </div>
          </>
        )}

        <div className="flex-1" />

        {/* VA / W toggle */}
        <div className="flex rounded-lg border border-white/25 overflow-hidden text-xs font-semibold flex-shrink-0">
          <button onClick={() => setUnit('va')}
            className={`px-3 py-1.5 transition-colors ${unit === 'va' ? 'bg-white/25 text-white' : 'text-blue-300 hover:bg-white/10'}`}>
            VA
          </button>
          <button onClick={() => setUnit('w')}
            className={`px-3 py-1.5 border-l border-white/25 transition-colors ${unit === 'w' ? 'bg-white/25 text-white' : 'text-blue-300 hover:bg-white/10'}`}>
            W
          </button>
        </div>

        {/* Expand chevron */}
        <button onClick={() => setOpen(o => !o)}
          className="w-7 h-7 flex items-center justify-center rounded-lg border border-white/25 text-blue-300
            hover:bg-white/10 hover:text-white transition-colors flex-shrink-0">
          <svg className={`w-3.5 h-3.5 transition-transform duration-200 ${open ? 'rotate-180' : ''}`}
            fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M19 9l-7 7-7-7" />
          </svg>
        </button>
      </div>

      {/* ── Breakdown dropdown ── */}
      {open && !loading && data && (
        <div className="px-6 pb-4 pt-3 border-t border-white/10 space-y-3">

          {/* Priority + sockets grid */}
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
            {visiblePriorities.map(({ key, label, dot, opt }) => (
              <div key={key} className="bg-white/10 hover:bg-white/15 rounded-xl p-3 transition-colors">
                <div className="flex items-center gap-2 mb-2.5">
                  <span className={`w-2 h-2 rounded-full flex-shrink-0 ${dot}`} />
                  <span className="text-xs font-semibold">{label}</span>
                </div>
                <div className="space-y-1.5">
                  <div className="flex items-center justify-between">
                    <span className="text-[10px] text-blue-300 uppercase tracking-wide">Max</span>
                    <span className="text-xs font-medium">{fmt(pMax(key), unit)}</span>
                  </div>
                  <div className="flex items-center justify-between">
                    <span className="text-[10px] text-blue-300 uppercase tracking-wide">Optimized</span>
                    <span className={`text-xs font-bold ${opt}`}>{fmt(pOpt(key), unit)}</span>
                  </div>
                </div>
              </div>
            ))}

            {/* Sockets card — always VA since sockets have no PF */}
            {hasSocket && (
              <div className="bg-white/10 hover:bg-white/15 rounded-xl p-3 transition-colors">
                <div className="flex items-center gap-2 mb-2.5">
                  <span className="w-2 h-2 rounded-full flex-shrink-0 bg-orange-400" />
                  <span className="text-xs font-semibold">Sockets</span>
                </div>
                <div className="space-y-1.5">
                  <div className="flex items-center justify-between">
                    <span className="text-[10px] text-blue-300 uppercase tracking-wide">Capacity</span>
                    <span className="text-xs font-medium">{fmt(socketConnected, 'va')}</span>
                  </div>
                  <div className="flex items-center justify-between">
                    <span className="text-[10px] text-blue-300 uppercase tracking-wide">Demand</span>
                    <span className="text-xs font-bold text-orange-300">{fmt(socketDemand, 'va')}</span>
                  </div>
                </div>
              </div>
            )}

            {visiblePriorities.length === 0 && !hasSocket && (
              <div className="col-span-4 text-center text-blue-300 text-xs py-2">
                No load data yet.
              </div>
            )}
          </div>

        </div>
      )}
    </div>
  );
}
