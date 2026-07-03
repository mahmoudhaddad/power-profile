/* eslint-disable react/prop-types */
import { useState, useEffect, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api/axios';
import { useAuth } from '../contexts/AuthContext';

// ── helpers ───────────────────────────────────────────────────────────────────
function fmt(v, unit = '') {
  if (v === null || v === undefined) return '—';
  if (typeof v === 'boolean') return v ? 'Yes' : 'No';
  const n = Number(v);
  if (isNaN(n)) return String(v);
  const s = Math.abs(n) >= 1000
    ? n.toLocaleString(undefined, { maximumFractionDigits: 2 })
    : n.toFixed(4).replace(/\.?0+$/, '');
  return unit ? `${s} ${unit}` : s;
}

function StatusBadge({ status, large = false }) {
  const base = large
    ? 'inline-flex items-center gap-1.5 px-4 py-1.5 rounded-full font-bold text-sm'
    : 'inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full font-semibold text-xs';
  if (status === 'PASS')
    return <span className={`${base} bg-emerald-100 text-emerald-700`}>✓ PASS</span>;
  if (status === 'FAIL')
    return <span className={`${base} bg-red-100 text-red-700`}>✗ FAIL</span>;
  return <span className={`${base} bg-gray-100 text-gray-500`}>— SKIP</span>;
}

// ── Case study description card ──────────────────────────────────────────────
function CaseStudyCard({ reference }) {
  const b = reference?.breakdown;
  if (!b) return null;

  return (
    <div className="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 print:shadow-none">
      <h2 className="text-base font-bold text-gray-900 mb-4">
        Reference Case Study — Structure &amp; Inputs
      </h2>

      {/* Building structure */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <div className="rounded-xl bg-blue-50 border border-blue-100 p-4">
          <p className="text-xs font-bold text-blue-700 uppercase tracking-wide mb-2">
            Floor 1 — Ground Floor
          </p>
          <p className="text-sm font-semibold text-gray-800 mb-1">
            Open Office  <span className="font-normal text-xs text-gray-500">(room_type=office_open)</span>
          </p>
          <p className="text-xs text-gray-600 mb-1">
            Room coincidence DF = 0.80 &nbsp;|&nbsp; Effective DF = <strong>{b.df_open_office}</strong>
          </p>
          <ul className="text-xs text-gray-600 space-y-0.5 mt-2">
            <li>• LED Lighting — 2000 VA, PF 1.00</li>
            <li>• Desktop Computers — 3000 VA, PF 0.85</li>
            <li>• Air Conditioning — 5000 VA, PF 0.90</li>
            <li>• 20 socket outlets → {fmt(b.floor1_socket_demand)} VA demand</li>
          </ul>
        </div>

        <div className="rounded-xl bg-violet-50 border border-violet-100 p-4">
          <p className="text-xs font-bold text-violet-700 uppercase tracking-wide mb-2">
            Floor 2 — First Floor
          </p>
          <p className="text-sm font-semibold text-gray-800 mb-1">
            Meeting Room  <span className="font-normal text-xs text-gray-500">(room_type=meeting_room)</span>
          </p>
          <p className="text-xs text-gray-600 mb-1">
            Room coincidence DF = 0.70 &nbsp;|&nbsp; Effective DF = <strong>{b.df_meeting_room}</strong>
          </p>
          <ul className="text-xs text-gray-600 space-y-0.5 mt-2">
            <li>• LED Lighting — 800 VA, PF 1.00</li>
            <li>• Projector — 500 VA, PF 0.95</li>
            <li>• 8 socket outlets → {fmt(b.floor2_socket_demand)} VA demand</li>
          </ul>
        </div>
      </div>

      {/* Component breakdown table */}
      <div className="overflow-x-auto mb-4">
        <table className="w-full text-xs">
          <thead>
            <tr className="bg-gray-50 border-b border-gray-200">
              {['Component', 'Rated VA', 'PF', 'Effective DF', 'W demand', 'Q demand (VAR)'].map(h => (
                <th key={h} className="px-3 py-2 text-left font-semibold text-gray-600">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {b.components.map((c, i) => (
              <tr key={i} className={i % 2 === 0 ? 'bg-white' : 'bg-gray-50'}>
                <td className="px-3 py-2 font-medium text-gray-800">{c.name}</td>
                <td className="px-3 py-2 text-gray-600">{c.va.toLocaleString()}</td>
                <td className="px-3 py-2 text-gray-600">{c.pf.toFixed(2)}</td>
                <td className="px-3 py-2 text-gray-600 font-mono">{c.df}</td>
                <td className="px-3 py-2 text-emerald-700 font-semibold">{fmt(c.w_demand)}</td>
                <td className="px-3 py-2 text-amber-700 font-semibold">{fmt(c.q_demand)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Formulas */}
      <div className="space-y-1.5 text-[11px] text-gray-500 font-mono bg-gray-50 rounded-lg p-3 border border-gray-100">
        <p><span className="text-blue-600 font-bold">DF formula:</span> {b.df_formula}</p>
        <p><span className="text-blue-600 font-bold">Socket formula:</span> {b.socket_formula}</p>
        <p><span className="text-blue-600 font-bold">Total formula:</span> {b.total_formula}</p>
        <p><span className="text-blue-600 font-bold">Socket coincidence (CF):</span> {b.socket_coincidence_cf} &nbsp;(raw demand {fmt((b.floor1_socket_demand + b.floor2_socket_demand) / 1000)} kVA &lt; 50 kVA)</p>
      </div>
    </div>
  );
}

// ── Comparison table ─────────────────────────────────────────────────────────
function ComparisonTable({ comparison, overall }) {
  return (
    <div className="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden print:shadow-none">
      <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
        <h2 className="text-base font-bold text-gray-900">Field-by-Field Comparison</h2>
        <StatusBadge status={overall} large />
      </div>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="bg-gray-50 border-b border-gray-200">
              {['Parameter', 'Hand-Calculated (Reference)', 'System Result', 'Difference %', 'Status'].map(h => (
                <th key={h} className="px-4 py-3 text-left font-semibold text-gray-600 text-xs uppercase tracking-wide whitespace-nowrap">
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {comparison.map((row, i) => {
              const rowBg = row.status === 'PASS'
                ? 'bg-emerald-50/50'
                : row.status === 'FAIL'
                ? 'bg-red-50/60'
                : 'bg-white';
              return (
                <tr key={i} className={`${rowBg} border-b border-gray-100`}>
                  <td className="px-4 py-3 font-medium text-gray-800 text-xs">{row.field}</td>
                  <td className="px-4 py-3 font-mono text-xs text-indigo-700 font-semibold">
                    {fmt(row.reference_value)}{row.unit !== '—' && row.unit !== 'bool' ? ` ${row.unit}` : ''}
                  </td>
                  <td className="px-4 py-3 font-mono text-xs text-gray-700">
                    {fmt(row.system_value)}{row.unit !== '—' && row.unit !== 'bool' ? ` ${row.unit}` : ''}
                  </td>
                  <td className="px-4 py-3 font-mono text-xs text-gray-500">
                    {row.difference_percent !== null ? `${Number(row.difference_percent).toFixed(4)} %` : '—'}
                  </td>
                  <td className="px-4 py-3"><StatusBadge status={row.status} /></td>
                </tr>
              );
            })}

            {/* Overall row */}
            <tr className={`font-bold border-t-2 ${overall === 'PASS' ? 'border-emerald-400 bg-emerald-50' : 'border-red-400 bg-red-50'}`}>
              <td className="px-4 py-3 text-sm font-bold text-gray-900" colSpan={4}>OVERALL — All fields within 0.1 % tolerance</td>
              <td className="px-4 py-3"><StatusBadge status={overall} large /></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  );
}

// ── Electrical Design Validation card ────────────────────────────────────────
function ElectricalDesignValidation({ data }) {
  if (!data) return null;
  const overall = data.overall_status;

  return (
    <div className="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden print:shadow-none">
      <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between flex-wrap gap-2">
        <div>
          <h2 className="text-base font-bold text-gray-900">Electrical Design Module — Cable &amp; Voltage-Drop Verification</h2>
          <p className="text-xs text-gray-500 mt-0.5">IEC 60364-5-52 Table B.52.2 · Method A1 (thermally insulated wall) · Cu 70°C PVC · 30°C ambient · most conservative</p>
        </div>
        <StatusBadge status={overall} large />
      </div>

      {/* Derating row */}
      <div className="px-6 py-3 bg-gray-50 border-b border-gray-100 flex flex-wrap items-center gap-6 text-xs text-gray-600">
        <span>
          <span className="font-semibold">Derating 40°C (formula):</span>{' '}
          <span className="font-mono text-indigo-700">
            √((70−40)/(70−30)) = {data.derating_40c}
          </span>
        </span>
        <span>
          <span className="font-semibold">IEC tabled value:</span>{' '}
          <span className="font-mono text-indigo-700">{data.derating_tabled}</span>
        </span>
        <StatusBadge status={data.derating_match} />
      </div>

      {/* Ampacity table */}
      <div className="px-6 pt-4 pb-2">
        <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">
          Cable Ampacity — 12 Standard Sizes
        </p>
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr className="bg-gray-50 border-b border-gray-200">
                {['Size (mm²)', 'IEC Ref (A)', 'System (A)', 'Status'].map(h => (
                  <th key={h} className="px-3 py-2 text-left font-semibold text-gray-600 whitespace-nowrap">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.ampacity_rows.map((row, i) => (
                <tr key={row.mm2} className={i % 2 === 0 ? 'bg-white' : 'bg-gray-50'}>
                  <td className="px-3 py-1.5 font-mono font-semibold text-gray-800">{row.mm2}</td>
                  <td className="px-3 py-1.5 font-mono text-indigo-700 font-semibold">{row.expected}</td>
                  <td className="px-3 py-1.5 font-mono text-gray-700">{row.actual ?? '—'}</td>
                  <td className="px-3 py-1.5"><StatusBadge status={row.status} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {/* VD spot checks */}
      <div className="px-6 pt-2 pb-4">
        <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2 mt-2">
          Voltage-Drop Spot Checks — ΔU(%) = mV/A/m × I<sub>b</sub> × L / 1000 / V<sub>nom</sub> × 100
        </p>
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr className="bg-gray-50 border-b border-gray-200">
                {['Test Case', 'ΔU (V)', 'ΔU (%)', 'Limit (%)', 'Warn?', 'Status'].map(h => (
                  <th key={h} className="px-3 py-2 text-left font-semibold text-gray-600 whitespace-nowrap">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.vd_rows.map((row, i) => (
                <tr key={i} className={i % 2 === 0 ? 'bg-white' : 'bg-gray-50'}>
                  <td className="px-3 py-1.5 font-medium text-gray-800">{row.label}</td>
                  <td className="px-3 py-1.5 font-mono text-gray-700">{row.vd_v ?? '—'}</td>
                  <td className={`px-3 py-1.5 font-mono font-semibold ${row.warn ? 'text-red-600' : 'text-emerald-600'}`}>{row.vd_pct ?? '—'}</td>
                  <td className="px-3 py-1.5 font-mono text-gray-500">{row.limit_pct}</td>
                  <td className="px-3 py-1.5">{row.warn ? <span className="text-red-600 font-semibold">⚠ YES</span> : <span className="text-gray-400">No</span>}</td>
                  <td className="px-3 py-1.5"><StatusBadge status={row.status} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {/* Method note */}
      <div className="mx-6 mb-4 p-3 bg-gray-50 rounded-lg border border-gray-100 text-[11px] text-gray-500 font-mono space-y-1">
        <p><span className="text-blue-600 font-bold">Table:</span> {data.notes.table}</p>
        <p><span className="text-blue-600 font-bold">Column:</span> {data.notes.column}</p>
        <p><span className="text-blue-600 font-bold">Ambient:</span> {data.notes.ambient}</p>
        <p><span className="text-blue-600 font-bold">VD formula:</span> {data.notes.vd_method}</p>
      </div>
    </div>
  );
}

// ── Main page ─────────────────────────────────────────────────────────────────
export default function ValidationPage() {
  const navigate     = useNavigate();
  const { user }     = useAuth();
  const [data, setData]         = useState(null);
  const [loading, setLoading]   = useState(true);
  const [error, setError]       = useState(null);
  const [rerunning, setRerunning] = useState(false);
  const [edData, setEdData]     = useState(null);
  const printRef = useRef(null);

  async function fetchValidation() {
    setError(null);
    try {
      const [csRes, edRes] = await Promise.allSettled([
        api.get('/api/validation/case-study'),
        api.get('/api/validation/electrical-design'),
      ]);
      if (csRes.status === 'fulfilled') setData(csRes.value.data);
      else setError(csRes.reason?.response?.data?.error ?? 'Failed to fetch validation results.');
      if (edRes.status === 'fulfilled') setEdData(edRes.value.data);
    } catch (e) {
      setError(e.response?.data?.error ?? 'Failed to fetch validation results.');
    }
  }

  useEffect(() => {
    fetchValidation().finally(() => setLoading(false));
  }, []);

  async function rerun() {
    setRerunning(true);
    await fetchValidation();
    setRerunning(false);
  }

  function handlePrint() {
    window.print();
  }

  const overall = data?.overall_status;

  return (
    <div className="min-h-screen bg-gray-50">

      {/* ── Print stylesheet injected inline ── */}
      <style>{`
        @media print {
          body { background: white !important; }
          .no-print { display: none !important; }
          .print\\:shadow-none { box-shadow: none !important; }
          header, nav { display: none !important; }
        }
      `}</style>

      {/* Header */}
      <header className="bg-white border-b border-gray-200 px-6 py-4 flex items-center gap-4 no-print">
        <button onClick={() => navigate('/dashboard')}
          className="text-gray-400 hover:text-gray-600 p-1.5 rounded-lg hover:bg-gray-100 transition-colors">
          <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
          </svg>
        </button>
        <div className="flex-1">
          <p className="text-xs text-gray-400 mb-0.5">Dashboard › System Validation</p>
          <h1 className="text-lg font-bold text-gray-900">System Validation — Reference Case Study</h1>
        </div>

        <div className="flex items-center gap-2 no-print">
          <button onClick={rerun} disabled={rerunning || loading}
            className="flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-indigo-700
              bg-indigo-50 hover:bg-indigo-100 border border-indigo-200 rounded-xl
              transition-colors disabled:opacity-50">
            {rerunning
              ? <div className="w-4 h-4 border-2 border-indigo-400 border-t-transparent rounded-full animate-spin" />
              : <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
            }
            Re-run Validation
          </button>

          <button onClick={handlePrint}
            className="flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-gray-700
              bg-white hover:bg-gray-50 border border-gray-200 rounded-xl transition-colors">
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
            </svg>
            Print / Export
          </button>
        </div>
      </header>

      <main className="max-w-5xl mx-auto px-6 py-8 space-y-6" ref={printRef}>

        {/* Print header */}
        <div className="hidden print:block mb-6">
          <h1 className="text-2xl font-bold text-gray-900">Power Profile — System Validation Report</h1>
          <p className="text-sm text-gray-500 mt-1">Reference Case Study: "Validation Reference — 2-Floor Office"</p>
          <p className="text-xs text-gray-400 mt-0.5">Generated: {new Date().toLocaleDateString('en-GB', { day:'2-digit', month:'long', year:'numeric' })}</p>
          <hr className="mt-4 border-gray-300" />
        </div>

        {/* Subtitle */}
        <p className="text-sm text-gray-500 no-print">
          Verifies calculation accuracy by running the production code against an
          independently hand-computed reference, then comparing each output value
          within a <strong>0.1 % tolerance</strong>.
        </p>

        {/* Loading */}
        {loading && (
          <div className="flex items-center justify-center py-20">
            <div className="w-10 h-10 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin" />
          </div>
        )}

        {/* Error */}
        {!loading && error && (
          <div className="rounded-2xl bg-red-50 border border-red-200 p-6">
            <p className="font-semibold text-red-800 mb-1">Validation Error</p>
            <p className="text-sm text-red-700">{error}</p>
            {error.includes('not found') && (
              <div className="mt-4 bg-red-100 rounded-lg p-3 font-mono text-xs text-red-800">
                php artisan db:seed --class=ValidationCaseStudySeeder
              </div>
            )}
          </div>
        )}

        {/* Results */}
        {!loading && data && (
          <>
            {/* Overall status banner */}
            <div className={`rounded-2xl p-5 flex items-center gap-4 ${
              overall === 'PASS'
                ? 'bg-emerald-50 border border-emerald-200'
                : 'bg-red-50 border border-red-200'
            }`}>
              <div className={`w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0 ${
                overall === 'PASS' ? 'bg-emerald-100' : 'bg-red-100'
              }`}>
                {overall === 'PASS'
                  ? <svg className="w-7 h-7 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M5 13l4 4L19 7" />
                    </svg>
                  : <svg className="w-7 h-7 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                    </svg>
                }
              </div>
              <div className="flex-1">
                <p className={`text-lg font-bold ${overall === 'PASS' ? 'text-emerald-800' : 'text-red-800'}`}>
                  Overall Status: {overall}
                </p>
                <p className="text-sm text-gray-600 mt-0.5">
                  Project: <span className="font-semibold">{data.project_name}</span>
                  &nbsp;·&nbsp; Tolerance: {data.tolerance_used}
                  &nbsp;·&nbsp; Project ID: #{data.project_id}
                </p>
              </div>
              <div className="text-right no-print">
                <p className="text-xs text-gray-400">{data.comparison.filter(c => c.status === 'PASS').length} / {data.comparison.length} fields passed</p>
              </div>
            </div>

            {/* Case study description */}
            <CaseStudyCard reference={data.reference_answer} />

            {/* Comparison table */}
            <ComparisonTable comparison={data.comparison} overall={overall} />

            {/* Electrical Design Module validation */}
            {edData && <ElectricalDesignValidation data={edData} />}

            {/* Standard callout */}
            <div className="bg-gray-50 rounded-xl border border-gray-200 p-4 text-xs text-gray-500 space-y-1">
              <p><strong>Standards applied:</strong> IEC 60364-8-1 (diversity factors) · BS 7671 (room coincidence) · CIBSE Guide C · PENRA (PF target 0.95)</p>
              <p><strong>Methodology:</strong> Two independent calculation paths — production services vs. inline reference math — compared field by field.</p>
              <p><strong>Socket formula:</strong> first 10 outlets × 100 %, next 10 × 75 %, remainder × 40 % (IEC 60364-5-52 demand factor schedule).</p>
            </div>
          </>
        )}
      </main>
    </div>
  );
}
