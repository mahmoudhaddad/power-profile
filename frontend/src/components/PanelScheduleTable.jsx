import { useState, useCallback } from 'react';

/**
 * PanelScheduleTable
 * Renders a standard IEC 60364 panel schedule for one floor DB or building MDB.
 *
 * Props:
 *   circuits  — array of circuit objects from ElectricalDesignService
 *   title     — string label for the panel (e.g. "Ground Floor — DB")
 *   incomer   — { in_a, cable_mm2, pe_mm2, curve, ib_a, phase_balance_va, phase_imbalance_pct }
 *   collapsed — boolean (controlled by parent)
 *   onToggle  — () => void
 *   vdTable   — cable_vd_table from analyzeProject (mV/A/m per cable size, optional)
 */
export default function PanelScheduleTable({ circuits = [], title, incomer, collapsed, onToggle, vdTable = {} }) {
  // Local state: keyed by circuit index, holds the user-entered length in metres
  const [lengths, setLengths] = useState({});

  const setLength = useCallback((idx, val) => {
    setLengths(prev => ({ ...prev, [idx]: val }));
  }, []);

  const typeColor = {
    LIGHTING:     'bg-amber-100 text-amber-800',
    SOCKET:       'bg-blue-100 text-blue-800',
    HEAVY:        'bg-red-100 text-red-800',
    CRITICAL:     'bg-red-200 text-red-900',
    AUXILIARY:    'bg-teal-100 text-teal-800',
    MIXED:        'bg-purple-100 text-purple-800',
    FLOOR_FEEDER: 'bg-indigo-100 text-indigo-800',
  };

  const typeLabel = {
    LIGHTING:     'Lighting',
    SOCKET:       'Socket',
    HEAVY:        'Heavy',
    CRITICAL:     'Critical',
    AUXILIARY:    'Auxiliary',
    MIXED:        'Mixed',
    FLOOR_FEEDER: 'Feeder',
  };

  const phaseColor = { A: 'text-red-600', B: 'text-yellow-600', C: 'text-blue-600', '3PH': 'text-purple-600' };

  const utilColour = (pct) => {
    if (pct == null) return 'text-gray-400';
    if (pct > 95)   return 'text-red-600 font-semibold';
    if (pct > 80)   return 'text-amber-600 font-medium';
    return 'text-green-700';
  };

  const imbalancePct = incomer?.phase_imbalance_pct ?? 0;
  const imbalanceBadge = imbalancePct > 20
    ? 'bg-red-100 text-red-700'
    : imbalancePct > 10
      ? 'bg-amber-100 text-amber-700'
      : 'bg-green-100 text-green-700';

  /**
   * Compute voltage drop for a circuit given a user-entered length.
   * ΔU (V)  = (mV/A/m) × Ib × length / 1000
   * ΔU (%)  = ΔU / V_nominal × 100   (230 1-phase, 400 3-phase)
   * Returns null when length or mv_a_m are unavailable.
   */
  const computeVD = (circuit, lengthM) => {
    if (!lengthM || lengthM <= 0) return null;
    const mv = circuit.mv_a_m ?? vdTable?.[String(circuit.cable_mm2)];
    if (!mv) return null;
    const vNominal = circuit.is3ph ? 400 : 230;
    const vd_v   = mv * circuit.ib_a * lengthM / 1000;
    const vd_pct = parseFloat((vd_v / vNominal * 100).toFixed(2));
    const limit  = circuit.vd_limit_pct ?? (circuit.type === 'LIGHTING' ? 3.0 : 5.0);
    const warn   = vd_pct > limit;

    // Find next cable size up (remedy) if over limit
    let remedy = null;
    if (warn && vdTable) {
      const sizes = Object.keys(vdTable).map(Number).sort((a, b) => a - b);
      for (const size of sizes) {
        if (size <= circuit.cable_mm2) continue;
        const mvR  = vdTable[String(size)];
        const pctR = mvR * circuit.ib_a * lengthM / 1000 / vNominal * 100;
        if (pctR <= limit) { remedy = size; break; }
      }
    }

    return { vd_v: vd_v.toFixed(2), vd_pct, limit, warn, remedy };
  };

  return (
    <div className="border border-gray-200 rounded-xl overflow-hidden">

      {/* Header row */}
      <button
        onClick={onToggle}
        className="w-full flex items-center justify-between px-4 py-3 bg-gray-50 hover:bg-gray-100 transition-colors text-left"
      >
        <div className="flex items-center gap-3">
          <svg className={`w-4 h-4 text-gray-400 transition-transform ${collapsed ? '' : 'rotate-90'}`}
            fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
          </svg>
          <span className="font-semibold text-gray-800 text-sm">{title}</span>
          <span className="text-xs text-gray-500">{circuits.length} circuit{circuits.length !== 1 ? 's' : ''}</span>
        </div>

        {incomer && (
          <div className="flex items-center gap-3 text-xs text-gray-600">
            <span className="hidden sm:inline">
              Incomer: <strong>{incomer.in_a} A</strong> MCB-{incomer.curve} · {incomer.cable_mm2} mm²
            </span>
            {incomer.phase_balance_va && (
              <span className={`px-2 py-0.5 rounded-full font-medium ${imbalanceBadge}`}>
                ±{imbalancePct}% imbalance
              </span>
            )}
          </div>
        )}
      </button>

      {!collapsed && (
        <>
          {/* Incomer detail strip */}
          {incomer && (
            <div className="px-4 py-2 bg-indigo-50 border-b border-gray-200 grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
              <div>
                <span className="text-gray-500">Incomer Ib</span>
                <p className="font-semibold text-gray-800">{incomer.ib_a} A</p>
              </div>
              <div>
                <span className="text-gray-500">Incomer In / Curve</span>
                <p className="font-semibold text-gray-800">{incomer.in_a} A MCB-{incomer.curve}</p>
              </div>
              <div>
                <span className="text-gray-500">Cable / PE</span>
                <p className="font-semibold text-gray-800">{incomer.cable_mm2} mm² / {incomer.pe_mm2} mm²</p>
              </div>
              {incomer.phase_balance_va && (
                <div>
                  <span className="text-gray-500">Phase VA (A / B / C)</span>
                  <p className={`font-semibold text-xs ${imbalanceBadge.replace('bg-', 'text-').replace('100', '700')}`}>
                    {Math.round(incomer.phase_balance_va.A)} / {Math.round(incomer.phase_balance_va.B)} / {Math.round(incomer.phase_balance_va.C)}
                  </p>
                </div>
              )}
            </div>
          )}

          {/* Circuit table */}
          {circuits.length === 0 ? (
            <p className="px-4 py-6 text-sm text-gray-400 text-center">No circuits on this panel.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead className="bg-gray-50 border-b border-gray-200">
                  <tr>
                    {['#', 'Type', 'Phase', 'Rooms / Description', 'Loads', 'Total VA', 'Ib (A)', 'In (A)', 'Curve', 'Cable mm²', 'PE mm²', 'Util %', 'RCD', 'L (m)', 'ΔU %', 'Note'].map(h => (
                      <th key={h} className="px-3 py-2 text-left font-semibold text-gray-600 whitespace-nowrap">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {circuits.map((c, idx) => {
                    const lengthVal = lengths[idx];
                    const lengthNum = lengthVal !== undefined && lengthVal !== '' ? parseFloat(lengthVal) : null;
                    const vd        = computeVD(c, lengthNum);

                    return (
                      <tr key={idx} className={`hover:bg-gray-50 ${c.has_critical ? 'bg-orange-50' : ''}`}>
                        <td className="px-3 py-2 font-mono text-gray-500">{c.circuit_no ?? idx + 1}</td>

                        <td className="px-3 py-2">
                          <span className={`px-1.5 py-0.5 rounded text-xs font-medium ${typeColor[c.type] ?? 'bg-gray-100 text-gray-700'}`}>
                            {typeLabel[c.type] ?? c.type}
                          </span>
                        </td>

                        <td className={`px-3 py-2 font-bold ${phaseColor[c.phase] ?? 'text-gray-600'}`}>
                          {c.phase ?? '—'}
                        </td>

                        <td className="px-3 py-2 text-gray-700 max-w-[140px]">
                          {c.label ?? (c.room_names?.join(', ') || '—')}
                        </td>

                        <td className="px-3 py-2 text-gray-600 max-w-[160px]">
                          {(c.loads ?? []).map((l, i) => (
                            <span key={i} className="block truncate">
                              {l.name} ×{l.qty}
                              {l.is_motor && <span className="ml-1 text-red-500 font-semibold">M</span>}
                            </span>
                          ))}
                        </td>

                        <td className="px-3 py-2 font-mono text-gray-800">{Math.round(c.total_va)}</td>
                        <td className="px-3 py-2 font-mono text-gray-700">{c.ib_a}</td>
                        <td className="px-3 py-2 font-mono font-semibold text-gray-900">{c.in_a}</td>
                        <td className="px-3 py-2 font-mono text-gray-700">{c.curve}</td>
                        <td className="px-3 py-2 font-mono text-gray-800">{c.cable_mm2}</td>
                        <td className="px-3 py-2 font-mono text-gray-700">{c.pe_mm2}</td>

                        <td className={`px-3 py-2 font-mono ${utilColour(c.utilisation_pct)}`}>
                          {c.utilisation_pct != null ? `${c.utilisation_pct}%` : '—'}
                        </td>

                        <td className="px-3 py-2">
                          {c.rcd === '30mA' ? (
                            <span className="px-1.5 py-0.5 bg-green-100 text-green-700 rounded text-xs font-medium">30mA</span>
                          ) : (
                            <span className="text-gray-400">—</span>
                          )}
                        </td>

                        {/* Cable length input */}
                        <td className="px-3 py-2">
                          <input
                            type="number"
                            min="0"
                            step="0.5"
                            placeholder="m"
                            value={lengthVal ?? ''}
                            onChange={e => setLength(idx, e.target.value)}
                            className="w-16 px-1.5 py-0.5 border border-gray-300 rounded text-xs font-mono text-gray-800 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                          />
                        </td>

                        {/* Voltage drop */}
                        <td className="px-3 py-2 min-w-[90px]">
                          {vd ? (
                            <div>
                              <span className={`font-mono font-semibold ${vd.warn ? 'text-red-600' : 'text-green-700'}`}>
                                {vd.vd_pct}%
                              </span>
                              <span className="text-gray-400 ml-1">(lim {vd.limit}%)</span>
                              {vd.warn && vd.remedy && (
                                <p className="text-amber-600 text-[10px] mt-0.5">
                                  → use {vd.remedy} mm²
                                </p>
                              )}
                            </div>
                          ) : (
                            <span className="text-gray-400 text-[10px]">enter L</span>
                          )}
                        </td>

                        <td className="px-3 py-2 text-amber-600 text-xs">
                          {c.has_critical && <span className="mr-1 font-semibold">⚠ Critical</span>}
                          {!vd && c.vd_note}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </div>
  );
}
