/* eslint-disable react/prop-types */
import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import {
  ResponsiveContainer,
  LineChart, Line,
  XAxis, YAxis, CartesianGrid,
  Tooltip, Legend, ReferenceLine, ReferenceDot,
  PieChart, Pie, Cell,
} from 'recharts';
import api from '../api/axios';

const MONTHS = [
  'January','February','March','April','May','June',
  'July','August','September','October','November','December',
];

// ── Formatters ────────────────────────────────────────────────────────────────
function fmtCurrency(val, symbol = '$') {
  if (val == null) return '—';
  const n = Number(val);
  if (Math.abs(n) >= 1_000_000) return `${symbol}${(n / 1_000_000).toFixed(2)}M`;
  if (Math.abs(n) >= 1_000)     return `${symbol}${(n / 1_000).toFixed(1)}k`;
  return `${symbol}${n.toFixed(2)}`;
}
function fmtKwh(v) {
  const n = Number(v) || 0;
  if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(2)} GWh`;
  if (n >= 1_000)     return `${(n / 1_000).toFixed(1)} MWh`;
  return `${n.toFixed(0)} kWh`;
}
function fmtPct(v) { return `${Number(v).toFixed(1)} %`; }

// ── Summary card ──────────────────────────────────────────────────────────────
function SummaryCard({ label, value, sub, badge, badgeColor = 'green', icon }) {
  const badgeClasses = {
    green:  'bg-success-soft text-success',
    red:    'bg-danger-soft text-danger',
    amber:  'bg-accent-soft text-accent',
    blue:   'bg-accent-soft text-accent',
    gray:   'bg-surface-inset text-ink-muted',
  };
  return (
    <div className="bg-surface-card rounded-2xl border border-line p-5 flex flex-col gap-2">
      <div className="flex items-center justify-between">
        <p className="text-xs font-semibold text-ink-muted uppercase tracking-wide font-mono">{label}</p>
        {icon && <span className="text-ink-muted2">{icon}</span>}
      </div>
      <p className="text-2xl font-bold text-ink-heading leading-none font-mono">{value}</p>
      <div className="flex items-center gap-2 flex-wrap">
        {badge != null && (
          <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${badgeClasses[badgeColor]}`}>
            {badge}
          </span>
        )}
        {sub && <p className="text-xs text-ink-muted">{sub}</p>}
      </div>
    </div>
  );
}

// ── Custom tooltip for line chart ─────────────────────────────────────────────
function ProjectionTooltip({ active, payload, label, currency }) {
  if (!active || !payload?.length) return null;
  const val = payload[0]?.value;
  return (
    <div className="bg-surface-card border border-line rounded-xl shadow-lg px-4 py-3">
      <p className="text-xs font-semibold text-ink-muted mb-1">Year {label}</p>
      <p className={`text-sm font-bold font-mono ${val >= 0 ? 'text-success' : 'text-danger'}`}>
        {fmtCurrency(val, currency)}
      </p>
    </div>
  );
}

// ── Custom label for payback dot ──────────────────────────────────────────────
function PaybackLabel({ viewBox, value }) {
  if (!viewBox) return null;
  const { cx, cy } = viewBox;
  return (
    <text x={cx} y={cy - 12} fill="var(--success)" fontSize={11} fontWeight="700" textAnchor="middle">
      Payback Yr {value}
    </text>
  );
}

// ─────────────────────────────────────────────────────────────────────────────

export default function FinancialPage() {
  const { projectId } = useParams();
  const navigate      = useNavigate();
  const [month, setMonth] = useState(new Date().getMonth() + 1);
  const [data,  setData]  = useState(null);
  const [loading, setLoading] = useState(false);
  const [error,   setError]   = useState(null);

  useEffect(() => {
    if (!projectId) return;
    setLoading(true);
    setError(null);
    api.get(`/api/projects/${projectId}/financial-analysis?month=${month}`)
      .then(({ data: d }) => setData(d))
      .catch(e => setError(e.response?.data?.message || 'Failed to load financial analysis.'))
      .finally(() => setLoading(false));
  }, [projectId, month]);

  const sym = data?.currency_symbol ?? '$';

  // ── Derived display values ────────────────────────────────────────────────
  const savings     = data?.savings;
  const investment  = data?.investment;
  const payback     = data?.payback;
  const proj        = data?.projection_25yr;
  const costs       = data?.annual_costs;
  const energy      = data?.annual_energy;
  const genInfo     = data?.generator_info;

  // Build chart data for 25-year projection
  const projData = (proj?.cumulative_net_by_year ?? []).map((val, i) => ({
    year: i + 1,
    cumulative: val,
  }));

  // Payback dot position for ReferenceDot
  const paybackDot = proj?.payback_year
    ? projData.find(d => d.year === proj.payback_year)
    : null;

  // Energy mix pie data (includes BESS when present) — amber tone for the
  // dominant/emphasized series (Solar), neutral grays for the rest.
  const pieData = energy ? [
    { name: 'Solar',     value: energy.solar_percent,     color: 'var(--accent)' },
    { name: 'Grid',      value: energy.grid_percent,      color: 'var(--text-muted)' },
    { name: 'Generator', value: energy.generator_percent, color: 'var(--surface-inset)' },
    { name: 'BESS',      value: energy.battery_percent,   color: 'var(--text-muted-2)' },
  ].filter(d => (d.value ?? 0) > 0) : [];

  // Y-axis formatter for projection chart
  const yFmt = v => {
    if (Math.abs(v) >= 1_000_000) return `${sym}${(v/1_000_000).toFixed(1)}M`;
    if (Math.abs(v) >= 1_000)     return `${sym}${(v/1_000).toFixed(0)}k`;
    return `${sym}${v.toFixed(0)}`;
  };

  return (
    <div className="min-h-screen bg-base pb-16">

      {/* ── Header ── */}
      <header className="bg-surface-card border-b border-line px-6 py-4 flex items-center gap-4 sticky top-0 z-10">
        <button onClick={() => navigate(-1)}
          className="text-ink-muted hover:text-ink-body2 transition-colors p-1.5 rounded-lg hover:bg-surface-inset">
          <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
          </svg>
        </button>

        <div className="flex-1">
          <div className="flex items-center gap-2">
            <svg className="w-5 h-5 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
            </svg>
            <h1 className="text-lg font-bold text-ink-heading">Financial Analysis</h1>
          </div>
          <p className="text-xs text-ink-muted mt-0.5">25-year economic projection · Solar + BESS vs. grid-only baseline</p>
        </div>

        {/* Month selector */}
        <select value={month} onChange={e => setMonth(Number(e.target.value))}
          className="border border-line rounded-xl px-4 py-2 text-sm font-medium text-ink-body2
            bg-surface-card focus:outline-none focus:ring-2 focus:ring-accent/40 focus:border-accent">
          {MONTHS.map((m, i) => (
            <option key={i + 1} value={i + 1}>{m}</option>
          ))}
        </select>
      </header>

      <main className="px-6 py-8 max-w-7xl mx-auto space-y-8">

        {/* ── Loading ── */}
        {loading && (
          <div className="flex items-center justify-center py-24">
            <div className="w-10 h-10 border-4 border-accent-soft border-t-accent rounded-full animate-spin" />
          </div>
        )}

        {/* ── Error ── */}
        {error && !loading && (
          <div className="bg-danger-soft border border-danger-border rounded-2xl p-6 text-sm text-danger">
            {error}
          </div>
        )}

        {data && !loading && (
          <>
            {/* ── 1. Summary Cards ── */}
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
              <SummaryCard
                label="Annual Savings"
                value={fmtCurrency(savings?.annual_savings, sym)}
                badge={savings?.savings_percent != null ? `${savings.savings_percent}% saved` : null}
                badgeColor={savings?.annual_savings >= 0 ? 'green' : 'red'}
                sub="vs. grid-only baseline"
                icon={
                  <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                }
              />
              <SummaryCard
                label="Total Investment"
                value={fmtCurrency(investment?.total_investment, sym)}
                sub={investment?.solar_installation > 0 ? `Solar ${fmtCurrency(investment.solar_installation, sym)} · Battery ${fmtCurrency(investment.battery_purchase, sym)}` : 'No cost data entered'}
                badgeColor="blue"
                icon={
                  <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                  </svg>
                }
              />
              <SummaryCard
                label="Payback Period"
                value={payback?.simple_payback_years != null ? `${payback.simple_payback_years} yrs` : 'No savings'}
                badge={proj?.payback_year != null ? `Year ${proj.payback_year} (projection)` : null}
                badgeColor={proj?.payback_year != null ? 'amber' : 'gray'}
                sub={payback?.lcoe_solar_per_kwh > 0 ? `LCOE ${sym}${payback.lcoe_solar_per_kwh}/kWh` : null}
                icon={
                  <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                }
              />
              <SummaryCard
                label="25-Year Net Benefit"
                value={fmtCurrency(proj?.total_25yr_benefit, sym)}
                badge={proj?.payback_year != null ? `Pays back year ${proj.payback_year}` : 'No payback'}
                badgeColor={proj?.total_25yr_benefit >= 0 ? 'green' : 'red'}
                sub="After all costs & degradation"
                icon={
                  <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                  </svg>
                }
              />
            </div>

            {/* ── Generator sizing warning ── */}
            {genInfo?.is_oversized && (
              <div className="flex items-start gap-4 bg-accent-soft border border-accent-border rounded-2xl px-5 py-4">
                <div className="w-9 h-9 rounded-xl bg-accent-softer flex items-center justify-center flex-shrink-0 mt-0.5">
                  <svg className="w-5 h-5 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                  </svg>
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-semibold text-ink-heading">Generator is oversized for the actual load</p>
                  <p className="text-xs text-ink-body mt-1 leading-relaxed">
                    The generator is running at an average of <strong>{genInfo.efficiency_avg_pct}%</strong> of its rated capacity
                    ({genInfo.current_rated_kw} kW rated). ISO 8528 recommends 70–85% average loading for optimal fuel efficiency.
                    At low load fractions the no-load fuel burn dominates, inflating the effective cost per kWh
                    and making the baseline cost — and therefore the apparent savings — look larger than they really are.
                  </p>
                  <div className="mt-3 flex flex-wrap gap-4">
                    <div className="bg-surface-card border border-accent-border rounded-xl px-4 py-2 text-center">
                      <p className="text-xs text-accent font-medium uppercase tracking-wide font-mono">Current</p>
                      <p className="text-lg font-bold text-ink-heading font-mono">{genInfo.current_rated_kw} kW</p>
                      <p className="text-xs text-ink-muted">avg load {genInfo.efficiency_avg_pct}%</p>
                    </div>
                    <div className="flex items-center text-ink-muted2">
                      <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 8l4 4m0 0l-4 4m4-4H3" />
                      </svg>
                    </div>
                    <div className="bg-surface-card border border-success-border rounded-xl px-4 py-2 text-center">
                      <p className="text-xs text-success font-medium uppercase tracking-wide font-mono">Recommended</p>
                      <p className="text-lg font-bold text-success font-mono">{genInfo.recommended_kw} kW</p>
                      <p className="text-xs text-success">peak {genInfo.peak_load_kw} kW ÷ 0.75</p>
                    </div>
                  </div>
                </div>
              </div>
            )}

            {/* ── 2. Cost Comparison Table ── */}
            <div className="bg-surface-card rounded-2xl border border-line overflow-hidden">
              <div className="px-6 py-4 border-b border-line-subtle">
                <h2 className="text-sm font-semibold text-ink-heading">Annual Cost Breakdown</h2>
                <p className="text-xs text-ink-muted mt-0.5">Comparison between current system and grid-only baseline · {MONTHS[month - 1]}</p>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="bg-surface-alt border-b border-line-subtle">
                      <th className="px-6 py-3 text-left text-xs font-semibold text-ink-muted uppercase tracking-wide font-mono">Source</th>
                      <th className="px-6 py-3 text-right text-xs font-semibold text-ink-muted uppercase tracking-wide font-mono">Annual kWh</th>
                      <th className="px-6 py-3 text-right text-xs font-semibold text-ink-muted uppercase tracking-wide font-mono">Cost / kWh</th>
                      <th className="px-6 py-3 text-right text-xs font-semibold text-ink-muted uppercase tracking-wide font-mono">Annual Cost</th>
                    </tr>
                  </thead>
                  <tbody>
                    {/* Grid row */}
                    <tr className="border-b border-line-subtle hover:bg-surface-inset transition-colors">
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-2.5">
                          <span className="w-2.5 h-2.5 rounded-full bg-ink-muted flex-shrink-0" />
                          <div>
                            <p className="font-medium text-ink-body2">Utility Grid</p>
                            <p className="text-xs text-ink-muted">With solar & battery offset</p>
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4 text-right font-medium text-ink-data font-mono">{fmtKwh(energy?.grid_kwh)}</td>
                      <td className="px-6 py-4 text-right text-ink-muted font-mono">{costs?.weighted_tariff ? `${sym}${costs.weighted_tariff}/kWh` : '—'}</td>
                      <td className="px-6 py-4 text-right font-semibold text-ink-data font-mono">{fmtCurrency(costs?.grid_cost, sym)}</td>
                    </tr>

                    {/* Generator row */}
                    {(energy?.generator_kwh ?? 0) > 0 && (
                      <tr className="border-b border-line-subtle hover:bg-surface-inset transition-colors">
                        <td className="px-6 py-4">
                          <div className="flex items-center gap-2.5">
                            <span className="w-2.5 h-2.5 rounded-full bg-ink-muted2 flex-shrink-0" />
                            <div>
                              <p className="font-medium text-ink-body2">Generator</p>
                              <p className="text-xs text-ink-muted">Diesel fuel cost</p>
                            </div>
                          </div>
                        </td>
                        <td className="px-6 py-4 text-right font-medium text-ink-data font-mono">{fmtKwh(energy?.generator_kwh)}</td>
                        <td className="px-6 py-4 text-right text-ink-muted font-mono">{costs?.generator_cost_per_kwh ? `${sym}${costs.generator_cost_per_kwh}/kWh` : '—'}</td>
                        <td className="px-6 py-4 text-right font-semibold text-ink-data font-mono">{fmtCurrency(costs?.generator_cost, sym)}</td>
                      </tr>
                    )}

                    {/* Maintenance row */}
                    {(costs?.maintenance_cost ?? 0) > 0 && (
                      <tr className="border-b border-line-subtle hover:bg-accent-soft/30 transition-colors">
                        <td className="px-6 py-4">
                          <div className="flex items-center gap-2.5">
                            <span className="w-2.5 h-2.5 rounded-full bg-accent-light flex-shrink-0" />
                            <div>
                              <p className="font-medium text-ink-body2">O&M Maintenance</p>
                              <p className="text-xs text-ink-muted">Solar system annual maintenance</p>
                            </div>
                          </div>
                        </td>
                        <td className="px-6 py-4 text-right text-ink-muted">—</td>
                        <td className="px-6 py-4 text-right text-ink-muted">—</td>
                        <td className="px-6 py-4 text-right font-semibold text-ink-data font-mono">{fmtCurrency(costs?.maintenance_cost, sym)}</td>
                      </tr>
                    )}

                    {/* Total with solar */}
                    <tr className="bg-success-soft border-b border-success-border">
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-2.5">
                          <span className="w-2.5 h-2.5 rounded-full bg-success flex-shrink-0" />
                          <p className="font-bold text-success">Total (with solar)</p>
                        </div>
                      </td>
                      <td className="px-6 py-4 text-right font-bold text-success font-mono">{fmtKwh(energy?.total_load_kwh)}</td>
                      <td className="px-6 py-4 text-right text-success">
                        {energy?.solar_percent > 0 && <span className="text-xs bg-accent-soft text-accent px-1.5 py-0.5 rounded-full">{fmtPct(energy.solar_percent)} solar</span>}
                      </td>
                      <td className="px-6 py-4 text-right font-bold text-success font-mono">{fmtCurrency(costs?.total_with_solar, sym)}</td>
                    </tr>

                    {/* Baseline without solar */}
                    <tr className="border-b border-line-subtle bg-surface-alt/50">
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-2.5">
                          <span className="w-2.5 h-2.5 rounded-full bg-ink-muted flex-shrink-0" />
                          <div>
                            <p className="font-medium text-ink-body2">Grid-only baseline</p>
                            <p className="text-xs text-ink-muted">No solar, no battery — counterfactual</p>
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4 text-right text-ink-muted font-mono">{fmtKwh(energy?.total_load_kwh)}</td>
                      <td className="px-6 py-4 text-right text-ink-muted">—</td>
                      <td className="px-6 py-4 text-right font-semibold text-ink-body2 font-mono">{fmtCurrency(costs?.total_without_solar, sym)}</td>
                    </tr>

                    {/* Annual savings highlight row */}
                    <tr className={savings?.annual_savings >= 0 ? 'bg-success' : 'bg-danger'}>
                      <td className="px-6 py-4" colSpan={3}>
                        <p className="font-bold text-base text-sm">Annual Savings</p>
                        <p className="text-xs text-base/70">Baseline cost minus current system cost</p>
                      </td>
                      <td className="px-6 py-4 text-right font-bold text-base text-lg font-mono">
                        {fmtCurrency(savings?.annual_savings, sym)}
                        {savings?.savings_percent != null && (
                          <span className="ml-2 text-sm font-normal text-base/80">({savings.savings_percent}%)</span>
                        )}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            {/* ── 3. Charts row ── */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">

              {/* 25-year cumulative projection — 2/3 width */}
              <div className="lg:col-span-2 bg-surface-card rounded-2xl border border-line p-6">
                <div className="mb-4">
                  <h2 className="text-sm font-semibold text-ink-heading">25-Year Cumulative Net Benefit</h2>
                  <p className="text-xs text-ink-muted mt-0.5">Includes panel degradation (0.5 %/yr) and battery replacements · {MONTHS[month - 1]}</p>
                </div>
                {projData.length > 0 ? (
                  <ResponsiveContainer width="100%" height={280}>
                    <LineChart data={projData} margin={{ top: 20, right: 20, left: 10, bottom: 0 }}>
                      <CartesianGrid strokeDasharray="3 3" stroke="var(--line)" />
                      <XAxis dataKey="year" tick={{ fontSize: 11, fill: 'var(--text-muted)' }} tickFormatter={v => `Yr ${v}`} />
                      <YAxis tickFormatter={yFmt} tick={{ fontSize: 11, fill: 'var(--text-muted)' }} width={70} />
                      <Tooltip content={<ProjectionTooltip currency={sym} />} />
                      {/* Zero baseline */}
                      <ReferenceLine y={0} stroke="var(--line-strong)" strokeDasharray="4 3" strokeWidth={1.5} label={{ value: 'Break-even', position: 'insideTopRight', fontSize: 10, fill: 'var(--text-muted)' }} />
                      {/* Payback year vertical marker */}
                      {proj?.payback_year && (
                        <ReferenceLine x={proj.payback_year} stroke="var(--success)" strokeDasharray="4 3" strokeWidth={1.5} />
                      )}
                      <Line
                        type="monotone" dataKey="cumulative" name="Cumulative Net"
                        stroke="var(--accent)" strokeWidth={2.5} dot={false}
                        activeDot={{ r: 5, fill: 'var(--accent)' }}
                      />
                      {/* Payback dot */}
                      {paybackDot && (
                        <ReferenceDot
                          x={paybackDot.year} y={paybackDot.cumulative}
                          r={6} fill="var(--success)" stroke="var(--surface-card)" strokeWidth={2}
                          label={<PaybackLabel value={paybackDot.year} />}
                        />
                      )}
                    </LineChart>
                  </ResponsiveContainer>
                ) : (
                  <div className="flex items-center justify-center h-56 text-ink-muted text-sm">No projection data</div>
                )}

                {/* Summary strip below chart */}
                <div className="mt-4 pt-4 border-t border-line-subtle flex gap-6 flex-wrap text-sm">
                  <div>
                    <p className="text-xs text-ink-muted uppercase tracking-wide font-mono">Total 25-yr benefit</p>
                    <p className={`font-bold font-mono ${proj?.total_25yr_benefit >= 0 ? 'text-success' : 'text-danger'}`}>
                      {fmtCurrency(proj?.total_25yr_benefit, sym)}
                    </p>
                  </div>
                  <div>
                    <p className="text-xs text-ink-muted uppercase tracking-wide font-mono">Simple payback</p>
                    <p className="font-bold text-ink-body2 font-mono">
                      {payback?.simple_payback_years != null ? `${payback.simple_payback_years} years` : '—'}
                    </p>
                  </div>
                  <div>
                    <p className="text-xs text-ink-muted uppercase tracking-wide font-mono">Solar LCOE</p>
                    <p className="font-bold text-ink-body2 font-mono">
                      {payback?.lcoe_solar_per_kwh > 0 ? `${sym}${payback.lcoe_solar_per_kwh}/kWh` : '—'}
                    </p>
                  </div>
                  <div>
                    <p className="text-xs text-ink-muted uppercase tracking-wide font-mono">Annual investment</p>
                    <p className="font-bold text-ink-body2 font-mono">{fmtCurrency(investment?.total_investment, sym)}</p>
                  </div>
                </div>
              </div>

              {/* Energy mix pie — 1/3 width */}
              <div className="bg-surface-card rounded-2xl border border-line p-6 flex flex-col">
                <div className="mb-4">
                  <h2 className="text-sm font-semibold text-ink-heading">Annual Energy Mix</h2>
                  <p className="text-xs text-ink-muted mt-0.5">Share of total load served by each source</p>
                </div>
                {pieData.length > 0 ? (
                  <>
                    <ResponsiveContainer width="100%" height={200}>
                      <PieChart>
                        <Pie
                          data={pieData} cx="50%" cy="50%"
                          innerRadius={55} outerRadius={85}
                          paddingAngle={3} dataKey="value"
                        >
                          {pieData.map((entry, i) => (
                            <Cell key={i} fill={entry.color} />
                          ))}
                        </Pie>
                        <Tooltip
                          formatter={(v) => [`${v.toFixed(1)} %`, '']}
                          contentStyle={{ background: 'var(--surface-card)', border: '1px solid var(--line)', borderRadius: 8, color: 'var(--text-heading)' }}
                        />
                      </PieChart>
                    </ResponsiveContainer>
                    {/* Legend */}
                    <div className="space-y-2 mt-2">
                      {pieData.map((d) => (
                        <div key={d.name} className="flex items-center justify-between">
                          <div className="flex items-center gap-2">
                            <span className="w-3 h-3 rounded-full flex-shrink-0" style={{ background: d.color }} />
                            <span className="text-sm text-ink-body2">{d.name}</span>
                          </div>
                          <span className="text-sm font-semibold text-ink-data font-mono">{d.value.toFixed(1)} %</span>
                        </div>
                      ))}
                    </div>
                    {/* Total load */}
                    <div className="mt-4 pt-3 border-t border-line-subtle">
                      <p className="text-xs text-ink-muted uppercase tracking-wide font-mono">Total Annual Load</p>
                      <p className="font-bold text-ink-data mt-0.5 font-mono">{fmtKwh(energy?.total_load_kwh)}</p>
                    </div>
                  </>
                ) : (
                  <div className="flex items-center justify-center flex-1 text-ink-muted text-sm text-center">
                    <div>
                      <svg className="w-10 h-10 mx-auto mb-2 text-ink-muted2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z" />
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z" />
                      </svg>
                      No energy data yet
                    </div>
                  </div>
                )}
              </div>
            </div>

            {/* ── 4. Energy detail strip ── */}
            {energy && (
              <div className="bg-surface-card rounded-2xl border border-line px-6 py-4">
                <div className="flex items-center gap-2 mb-3">
                  <h2 className="text-sm font-semibold text-ink-heading">Annual Energy Detail</h2>
                  <span className="text-xs bg-surface-inset text-ink-muted px-2 py-0.5 rounded-full">{MONTHS[month - 1]} × 365 days</span>
                </div>
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 text-sm">
                  {[
                    { label: 'Solar Used',      value: fmtKwh(energy.solar_kwh),              dot: 'bg-accent' },
                    { label: 'BESS Discharge',  value: fmtKwh(energy.battery_discharge_kwh),  dot: 'bg-accent-light' },
                    { label: 'Grid Used',       value: fmtKwh(energy.grid_kwh),               dot: 'bg-ink-muted'   },
                    { label: 'Generator',       value: fmtKwh(energy.generator_kwh),          dot: 'bg-ink-muted2'    },
                    { label: 'Battery Losses',  value: fmtKwh(energy.battery_loss_kwh),       dot: 'bg-accent-bright' },
                    { label: 'Total Load',      value: fmtKwh(energy.total_load_kwh),         dot: 'bg-ink-muted3'   },
                  ].filter(({ value }) => value !== '0 kWh').map(({ label, value, dot }) => (
                    <div key={label}>
                      <div className="flex items-center gap-1.5 mb-0.5">
                        <span className={`w-2 h-2 rounded-full flex-shrink-0 ${dot}`} />
                        <span className="text-xs text-ink-muted">{label}</span>
                      </div>
                      <p className="font-semibold text-ink-data font-mono">{value}</p>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* ── 5. No-cost-data notice ── */}
            {investment?.total_investment === 0 && (
              <div className="flex items-start gap-3 bg-accent-soft border border-accent-border rounded-2xl px-5 py-4">
                <svg className="w-5 h-5 text-accent flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                </svg>
                <div>
                  <p className="text-sm font-semibold text-ink-heading">Cost data not configured</p>
                  <p className="text-xs text-ink-body mt-0.5">
                    No installation cost, maintenance cost, fuel cost, or tariff data found.
                    Open each solar system, battery, utility line, and generator line and fill in the cost fields
                    to get meaningful financial results.
                  </p>
                </div>
              </div>
            )}
          </>
        )}
      </main>
    </div>
  );
}
