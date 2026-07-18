import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import api from '../api/axios';
import PanelScheduleTable from '../components/PanelScheduleTable';

// ── Icons ──────────────────────────────────────────────────────────────────────

function IconBolt() {
  return (
    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8}
        d="M13 10V3L4 14h7v7l9-11h-7z" />
    </svg>
  );
}

function IconBuilding() {
  return (
    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8}
        d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2M5 21H3M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 8v-4a1 1 0 011-1h2a1 1 0 011 1v4" />
    </svg>
  );
}

// ── Stat card ──────────────────────────────────────────────────────────────────

function StatCard({ label, value, sub, accent }) {
  const border = {
    indigo: 'border-indigo-200 bg-indigo-50',
    amber:  'border-amber-200 bg-amber-50',
    green:  'border-green-200 bg-green-50',
    red:    'border-red-200 bg-red-50',
    violet: 'border-violet-200 bg-violet-50',
  }[accent] ?? 'border-gray-200 bg-gray-50';

  return (
    <div className={`border rounded-xl px-4 py-3 ${border}`}>
      <p className="text-xs text-gray-500">{label}</p>
      <p className="text-lg font-bold text-gray-900 mt-0.5">{value}</p>
      {sub && <p className="text-xs text-gray-500 mt-0.5">{sub}</p>}
    </div>
  );
}

// ── Building section ───────────────────────────────────────────────────────────

function BuildingSection({ building, vdTable }) {
  const [openFloors, setOpenFloors] = useState(() => new Set([0]));
  const [mdbOpen,    setMdbOpen]    = useState(true);

  const toggleFloor = i =>
    setOpenFloors(prev => {
      const next = new Set(prev);
      next.has(i) ? next.delete(i) : next.add(i);
      return next;
    });

  const mdb = building.mdb;

  return (
    <div className="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden mb-6">
      {/* Building header */}
      <div className="px-6 py-4 bg-gradient-to-r from-indigo-50 to-violet-50 border-b border-gray-200">
        <div className="flex items-center gap-3 mb-3">
          <div className="w-8 h-8 rounded-lg bg-indigo-600 flex items-center justify-center text-white">
            <IconBuilding />
          </div>
          <div>
            <h2 className="text-base font-bold text-gray-900">{building.name}</h2>
            <p className="text-xs text-gray-500">
              {building.type ?? 'General'} · {building.rules?.standard_ref}
            </p>
          </div>
        </div>

        {/* MDB summary cards */}
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <StatCard
            label="Nameplate Total"
            value={`${(mdb.total_va_nameplate / 1000).toFixed(1)} kVA`}
            accent="indigo"
          />
          <StatCard
            label="Diversified Demand"
            value={`${(mdb.total_va_diversified / 1000).toFixed(1)} kVA`}
            sub={`${Math.round(mdb.total_va_diversified / mdb.total_va_nameplate * 100)}% of nameplate`}
            accent="violet"
          />
          <StatCard
            label="MDB Incomer"
            value={`${mdb.incomer_in_a} A MCB-${mdb.incomer_curve}`}
            sub={`${mdb.incomer_cable_mm2} mm² cable · ${mdb.incomer_pe_mm2} mm² PE`}
            accent="amber"
          />
          <StatCard
            label="Phase Imbalance"
            value={`${mdb.phase_imbalance_pct}%`}
            sub={`A:${Math.round(mdb.phase_balance_va?.A)} / B:${Math.round(mdb.phase_balance_va?.B)} / C:${Math.round(mdb.phase_balance_va?.C)} VA`}
            accent={mdb.phase_imbalance_pct > 20 ? 'red' : mdb.phase_imbalance_pct > 10 ? 'amber' : 'green'}
          />
        </div>
      </div>

      <div className="px-6 py-4 space-y-3">
        {/* MDB circuit table (floor feeders + building-direct) */}
        {building.mdb_circuits?.length > 0 && (
          <PanelScheduleTable
            title="Main Distribution Board (MDB)"
            circuits={building.mdb_circuits}
            incomer={{
              ib_a:              mdb.incomer_ib_a,
              in_a:              mdb.incomer_in_a,
              cable_mm2:         mdb.incomer_cable_mm2,
              pe_mm2:            mdb.incomer_pe_mm2,
              curve:             mdb.incomer_curve,
              phase_balance_va:  mdb.phase_balance_va,
              phase_imbalance_pct: mdb.phase_imbalance_pct,
            }}
            collapsed={!mdbOpen}
            onToggle={() => setMdbOpen(o => !o)}
            vdTable={vdTable}
          />
        )}

        {/* Essential panel warning */}
        {building.essential_panel && (
          <div className="border border-orange-300 bg-orange-50 rounded-xl p-4">
            <p className="text-sm font-semibold text-orange-800 mb-1">
              Essential Panel Required
            </p>
            <p className="text-xs text-orange-700 mb-2">
              {building.essential_panel.note} — Incomer: {building.essential_panel.incomer_in_a} A ·
              {building.essential_panel.incomer_cable_mm2} mm² cable
            </p>
            <p className="text-xs text-orange-600">
              {building.essential_panel.circuits?.length ?? 0} circuit(s) with critical-priority loads identified.
              These must be supplied from an ATS-backed essential busbar per {building.rules?.standard_ref}.
            </p>
          </div>
        )}

        {/* Per-floor DB tables */}
        {building.floors?.map((floor, fi) => (
          <div key={floor.id ?? fi}>
            <PanelScheduleTable
              title={`${floor.name} — Distribution Board`}
              circuits={floor.circuits}
              incomer={{
                ib_a:               floor.db.incomer_ib_a,
                in_a:               floor.db.incomer_in_a,
                cable_mm2:          floor.db.incomer_cable_mm2,
                pe_mm2:             floor.db.incomer_pe_mm2,
                curve:              floor.db.incomer_curve,
                phase_balance_va:   floor.db.phase_balance_va,
                phase_imbalance_pct:floor.db.phase_imbalance_pct,
              }}
              collapsed={!openFloors.has(fi)}
              onToggle={() => toggleFloor(fi)}
              vdTable={vdTable}
            />
          </div>
        ))}
      </div>
    </div>
  );
}

// ── Main page ──────────────────────────────────────────────────────────────────

export default function ElectricalDesignPage() {
  const { projectId } = useParams();
  const navigate = useNavigate();
  const [data,    setData]    = useState(null);
  const [loading, setLoading] = useState(true);
  const [error,   setError]   = useState(null);

  useEffect(() => {
    setLoading(true);
    setError(null);
    api.get(`/api/projects/${projectId}/electrical-design`)
      .then(res => setData(res.data))
      .catch(err => setError(err.response?.data?.message ?? 'Failed to load electrical design.'))
      .finally(() => setLoading(false));
  }, [projectId]);

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-[40vh]">
        <div className="flex flex-col items-center gap-3">
          <div className="w-10 h-10 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin" />
          <p className="text-sm text-gray-500">Computing panel schedules…</p>
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="max-w-2xl mx-auto mt-12 text-center px-4">
        <div className="w-12 h-12 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-3">
          <svg className="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
          </svg>
        </div>
        <p className="text-red-600 font-medium">{error}</p>
      </div>
    );
  }

  if (!data) return null;

  const totalBuildings = data.buildings?.length ?? 0;
  const totalFloors    = data.buildings?.reduce((s, b) => s + (b.floors?.length ?? 0), 0) ?? 0;
  const totalCircuits  = data.buildings?.reduce(
    (s, b) => s + (b.floors?.reduce((fs, f) => fs + (f.circuits?.length ?? 0), 0) ?? 0), 0
  ) ?? 0;

  return (
    <div className="max-w-6xl mx-auto px-4 py-6">

      {/* Page header */}
      <div className="flex items-center gap-3 mb-6">
        <button
          onClick={() => navigate(-1)}
          className="w-9 h-9 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 flex items-center justify-center text-gray-500 hover:text-gray-700 transition-colors shadow-sm"
          title="Go back"
        >
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
          </svg>
        </button>
        <div className="w-10 h-10 rounded-xl bg-indigo-600 flex items-center justify-center text-white shadow">
          <IconBolt />
        </div>
        <div>
          <h1 className="text-xl font-bold text-gray-900">Electrical Design</h1>
          <p className="text-sm text-gray-500">
            {data.standard_ref} · {data.system_voltage} · {data.frequency_hz} Hz ·
            40 °C ambient · {data.install_method}
          </p>
        </div>
      </div>

      {/* Project summary strip */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
        <StatCard label="Buildings" value={totalBuildings} accent="indigo" />
        <StatCard label="Floor DBs" value={totalFloors}    accent="violet" />
        <StatCard label="Total Circuits" value={totalCircuits} accent="amber" />
        <StatCard
          label="Derating Factor"
          value={data.derating_factor}
          sub={`${data.ambient_temp_c} °C · PVC/Cu`}
          accent="green"
        />
      </div>

      {/* Engineering notice */}
      <div className="mb-6 p-3 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-800">
        <strong>Engineering notice:</strong> Cable ampacity values are from IEC 60364-5-52 Table B.52.2,
        Method A1 (conductors in conduit in thermally insulated wall) — the most conservative reference
        method, adopted to give a built-in safety margin. Enter cable run lengths in the L (m) column
        to compute live voltage-drop per circuit. Verify all values against the current standard edition
        before submitting a licensed design.
      </div>

      {/* Per-building sections */}
      {(data.buildings ?? []).map((building, bi) => (
        <BuildingSection key={building.id ?? bi} building={building} vdTable={data.cable_vd_table} />
      ))}

      {totalBuildings === 0 && (
        <div className="text-center py-16 text-gray-400">
          <p className="text-lg font-medium mb-1">No buildings found</p>
          <p className="text-sm">Add buildings with floors and rooms to generate the panel schedule.</p>
        </div>
      )}
    </div>
  );
}
