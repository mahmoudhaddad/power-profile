/* eslint-disable react/prop-types */

const fmtKvar = v => `${Number(v ?? 0).toFixed(2)} kVAR`;
const fmtPf   = v => Number(v ?? 0).toFixed(3);
const fmtA    = v => `${Number(v ?? 0).toFixed(2)} A`;
const fmtPct  = v => `${Number(v ?? 0).toFixed(1)}%`;

function PfBar({ pf, color }) {
  const pct = Math.round(Math.min(1, Math.max(0, Number(pf) || 0)) * 100);
  return (
    <div>
      <div className="h-1.5 rounded-full bg-white/10 overflow-hidden">
        <div className="h-full rounded-full transition-all duration-500"
          style={{ width: `${pct}%`, background: color }} />
      </div>
      <div className="flex justify-between text-[9px] opacity-40 mt-0.5 text-white">
        <span>0</span><span>1.0</span>
      </div>
    </div>
  );
}

function Metric({ label, value, sub, highlight }) {
  return (
    <div className="text-center">
      <p className="text-[10px] text-white/50 uppercase tracking-wider mb-0.5">{label}</p>
      <p className={`text-sm font-bold ${highlight ? 'text-emerald-300' : 'text-white'}`}>{value}</p>
      {sub && <p className="text-[10px] text-white/40 mt-0.5">{sub}</p>}
    </div>
  );
}

export default function ReactivePowerPanel({ data, capApplied, onToggleCap }) {
  const totalKvar  = data?.total_kvar               ?? 0;
  const maxKvar    = data?.max_kvar                  ?? 0;
  const kvarAfter  = data?.kvar_after_correction     ?? 0;
  const pfBefore   = data?.system_power_factor       ?? 1;
  const pfAfter    = data?.capacitor_bank_target_pf  ?? 0.95;
  const bankKvar   = data?.capacitor_bank_kvar       ?? 0;
  const bankUf     = data?.capacitor_bank_uf         ?? 0;
  const iBefore    = data?.current_before_correction_a ?? 0;
  const iAfter     = data?.current_after_correction_a  ?? 0;
  const reduction  = data?.current_reduction_percent   ?? 0;
  const corrNote   = data?.correction_note             ?? '';
  const needsBank  = !!data?.pf_correction_recommended;

  return (
    <div className="border-t border-white/10 px-6 py-4 space-y-4">

      {/* ── Capacitor bank required callout — always visible ────────────── */}
      {needsBank ? (
        <div className="flex items-center gap-4 bg-amber-500/20 border border-amber-400/35
          rounded-xl px-4 py-3 flex-wrap">
          <svg className="w-5 h-5 text-amber-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M13 10V3L4 14h7v7l9-11h-7z" />
          </svg>
          <div className="flex-1">
            <p className="text-[10px] text-amber-300/70 uppercase tracking-wider">Capacitor Bank Required</p>
            <p className="text-xl font-bold text-white leading-tight">{fmtKvar(bankKvar)}</p>
            {bankUf > 0 && (
              <p className="text-sm font-semibold text-amber-200 mt-0.5">
                C = {Number(bankUf).toFixed(2)} μF
              </p>
            )}
          </div>
          <div className="text-right">
            <p className="text-[10px] text-amber-300/70 uppercase tracking-wider">Target PF after install</p>
            <p className="text-lg font-bold text-emerald-300">{fmtPf(pfAfter)}</p>
          </div>
          {reduction > 0 && (
            <div className="text-right">
              <p className="text-[10px] text-amber-300/70 uppercase tracking-wider">Current Reduction</p>
              <p className="text-lg font-bold text-emerald-300">↓ {fmtPct(reduction)}</p>
            </div>
          )}
        </div>
      ) : (
        <div className="flex items-center gap-3 bg-emerald-500/15 border border-emerald-400/25
          rounded-xl px-4 py-3">
          <svg className="w-5 h-5 text-emerald-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <div>
            <p className="text-[10px] text-emerald-300/70 uppercase tracking-wider">Capacitor Bank</p>
            <p className="text-sm font-semibold text-emerald-200">Not required — PF {fmtPf(pfBefore)} is acceptable</p>
          </div>
        </div>
      )}

      {/* ── Main comparison row ─────────────────────────────────────────── */}
      <div className="grid grid-cols-2 gap-4">

        {/* Before bank */}
        <div className={`rounded-xl p-3 space-y-3 transition-colors
          ${!capApplied ? 'bg-white/15 ring-1 ring-white/25' : 'bg-white/8'}`}>
          <div className="flex items-center gap-2">
            <span className="text-[10px] font-semibold text-white/70 uppercase tracking-wider">
              Current System
            </span>
            {!capApplied && (
              <span className="text-[9px] bg-white/20 rounded-full px-2 py-0.5 text-white/80">Viewing</span>
            )}
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Metric label="Power Factor" value={fmtPf(pfBefore)} />
            <Metric label="Reactive Load" value={fmtKvar(totalKvar)} />
          </div>
          <PfBar pf={pfBefore} color={needsBank
            ? 'linear-gradient(to right,#ef4444,#f59e0b)'
            : 'linear-gradient(to right,#f59e0b,#10b981)'} />
          <Metric label="Max kVAR" value={fmtKvar(maxKvar)} />
          {iBefore > 0 && <Metric label="Line Current" value={fmtA(iBefore)} />}
        </div>

        {/* After bank */}
        <div className={`rounded-xl p-3 space-y-3 transition-colors
          ${capApplied ? 'bg-white/15 ring-1 ring-emerald-400/40' : 'bg-white/8'}`}>
          <div className="flex items-center gap-2">
            <span className="text-[10px] font-semibold text-white/70 uppercase tracking-wider">
              With Cap Bank
            </span>
            {capApplied && (
              <span className="text-[9px] bg-emerald-500/30 rounded-full px-2 py-0.5 text-emerald-300">Viewing</span>
            )}
          </div>
          {needsBank ? (
            <>
              <div className="grid grid-cols-2 gap-3">
                <Metric label="Power Factor" value={fmtPf(pfAfter)} highlight />
                <Metric label="Reactive Load" value={fmtKvar(kvarAfter)} highlight />
              </div>
              <PfBar pf={pfAfter} color="linear-gradient(to right,#10b981,#059669)" />
              <Metric label="Bank Size" value={fmtKvar(bankKvar)} sub="to install" />
              {iAfter > 0 && (
                <Metric
                  label="Line Current"
                  value={fmtA(iAfter)}
                  sub={`↓ ${fmtPct(reduction)} savings`}
                  highlight
                />
              )}
            </>
          ) : (
            <div className="flex flex-col items-center justify-center h-24 gap-2">
              <p className="text-xs text-emerald-300 font-medium text-center">
                System already at<br />optimal power factor
              </p>
            </div>
          )}
        </div>

      </div>

      {/* ── Apply cap bank toggle ───────────────────────────────────────── */}
      {needsBank && (
        <div className="flex items-center justify-between">
          <div className="text-xs text-white/60">
            {capApplied
              ? 'Showing corrected values with cap bank installed'
              : 'Simulate installing the capacitor bank'}
          </div>
          <button
            onClick={onToggleCap}
            className={`flex items-center gap-2 px-4 py-1.5 rounded-full text-xs font-semibold
              transition-all duration-200
              ${capApplied
                ? 'bg-emerald-500/30 border border-emerald-400/50 text-emerald-200 hover:bg-emerald-500/20'
                : 'bg-amber-500/25 border border-amber-400/40 text-amber-200 hover:bg-amber-500/35'}`}
          >
            <span className={`w-2 h-2 rounded-full flex-shrink-0 ${capApplied ? 'bg-emerald-400' : 'bg-amber-400'}`} />
            {capApplied ? 'Remove Cap Bank' : 'Apply Cap Bank'}
          </button>
        </div>
      )}

      {/* ── Correction note (visible only when bank is NOT yet applied) ── */}
      {needsBank && !capApplied && corrNote && (
        <p className="text-[11px] italic text-amber-200/60 leading-relaxed border-l-2 border-amber-400/40 pl-3">
          {corrNote}
        </p>
      )}

    </div>
  );
}
