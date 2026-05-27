import { useState, useEffect, useCallback } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import api from '../api/axios';
import PowerBanner from '../components/PowerBanner';
import ProjectSidebar from '../components/ProjectSidebar';
import ErrorBoundary from '../components/ErrorBoundary';

const PHASE_COLORS = {
  A: { bar: 'bg-indigo-500', text: 'text-indigo-700', bg: 'bg-indigo-50', border: 'border-indigo-300', badge: 'bg-indigo-100 text-indigo-700' },
  B: { bar: 'bg-emerald-500', text: 'text-emerald-700', bg: 'bg-emerald-50', border: 'border-emerald-300', badge: 'bg-emerald-100 text-emerald-700' },
  C: { bar: 'bg-amber-500',   text: 'text-amber-700',   bg: 'bg-amber-50',   border: 'border-amber-300',   badge: 'bg-amber-100 text-amber-700' },
};

const STATUS_META = {
  balanced:   { label: 'Balanced',   cls: 'bg-emerald-100 text-emerald-700', dot: 'bg-emerald-500' },
  warning:    { label: 'Warning',    cls: 'bg-amber-100 text-amber-700',     dot: 'bg-amber-500' },
  critical:   { label: 'Critical',   cls: 'bg-red-100 text-red-700',         dot: 'bg-red-500' },
  unassigned: { label: 'Unassigned', cls: 'bg-gray-100 text-gray-500',       dot: 'bg-gray-400' },
};

function StatusBadge({ status }) {
  const m = STATUS_META[status] ?? STATUS_META.unassigned;
  return <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${m.cls}`}>{m.label}</span>;
}

function StatusDot({ status }) {
  const m = STATUS_META[status] ?? STATUS_META.unassigned;
  return <span className={`w-2 h-2 rounded-full flex-shrink-0 ${m.dot}`} />;
}

function PhaseBar({ label, data, total }) {
  const pct  = total > 0 ? Math.min(100, (data.va / total) * 100) : 0;
  const color = PHASE_COLORS[label];
  return (
    <div className="flex items-center gap-3">
      <span className={`w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0 ${color.badge}`}>
        {label}
      </span>
      <div className="flex-1 bg-gray-100 rounded-full h-2.5 overflow-hidden">
        <div className={`h-full rounded-full transition-all duration-500 ${color.bar}`} style={{ width: `${pct}%` }} />
      </div>
      <div className="text-right w-40 flex-shrink-0">
        <span className="text-xs font-semibold text-gray-700">{data.percentage_of_total}%</span>
        <span className="text-xs text-gray-400 ml-2">{data.va.toLocaleString()} VA</span>
        <span className="text-xs text-gray-400 ml-1">/ {data.current_a} A</span>
      </div>
    </div>
  );
}

function PhaseAssignButtons({ roomId, current, onAssign, loading }) {
  return (
    <div className="flex items-center gap-1">
      {['A', 'B', 'C'].map(ph => {
        const color  = PHASE_COLORS[ph];
        const active = current === ph;
        return (
          <button key={ph} onClick={() => onAssign(roomId, ph)} disabled={loading} title={`Assign Phase ${ph}`}
            className={`w-7 h-7 rounded-full text-xs font-bold transition-all border
              ${active
                ? `${color.bg} ${color.border} ${color.text} shadow-sm`
                : 'bg-white border-gray-200 text-gray-400 hover:border-gray-400 hover:text-gray-600'}
              disabled:opacity-40 disabled:cursor-not-allowed`}>
            {ph}
          </button>
        );
      })}
      <button onClick={() => onAssign(roomId, null)} disabled={loading} title="Clear phase"
        className="w-7 h-7 rounded-full text-xs font-bold border bg-white border-gray-200 text-gray-400
          hover:border-red-300 hover:text-red-500 transition-all disabled:opacity-40 disabled:cursor-not-allowed">
        —
      </button>
    </div>
  );
}

function PhasePill({ phase }) {
  if (!phase) return <span className="text-xs text-gray-300">—</span>;
  if (phase === 'mixed') return <span className="text-xs text-orange-500 font-semibold">Mixed</span>;
  const color = PHASE_COLORS[phase];
  return <span className={`text-xs font-bold px-2 py-0.5 rounded-full ${color.badge}`}>{phase}</span>;
}

function DistributionPanel({ dist, unassignedVa, imbalance, neutral, status }) {
  if (!dist) return null;
  const totalAll = (dist.A?.va ?? 0) + (dist.B?.va ?? 0) + (dist.C?.va ?? 0) + (unassignedVa ?? 0);
  return (
    <div className="space-y-2.5">
      {['A', 'B', 'C'].map(ph => (
        <PhaseBar key={ph} label={ph} data={dist[ph]} total={totalAll} />
      ))}
      {unassignedVa > 0 && (
        <div className="flex items-center gap-2 text-xs text-gray-400">
          <span className="w-6 h-6 rounded-full bg-gray-100 flex items-center justify-center font-bold flex-shrink-0">?</span>
          <span>Unassigned: {unassignedVa.toLocaleString()} VA</span>
        </div>
      )}
      <div className="flex items-center gap-4 pt-1 text-xs text-gray-500 border-t border-gray-100">
        <span>Imbalance: <strong className={imbalance >= 20 ? 'text-red-600' : imbalance >= 10 ? 'text-amber-600' : 'text-emerald-600'}>{imbalance}%</strong></span>
        <span>Neutral: <strong>{neutral} A</strong></span>
        <StatusBadge status={status} />
      </div>
    </div>
  );
}

// ── Collapsible building picker ───────────────────────────────────────────────

function BuildingPicker({ buildings, selectedId, onSelect }) {
  const [open, setOpen] = useState(true);

  return (
    <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
      {/* Header / toggle */}
      <button
        onClick={() => setOpen(o => !o)}
        className="w-full flex items-center justify-between px-4 py-3 hover:bg-gray-50 transition-colors">
        <span className="text-xs font-semibold text-gray-500 uppercase tracking-wider">
          Buildings <span className="text-gray-400 font-normal normal-case">({buildings.length})</span>
        </span>
        <svg className={`w-4 h-4 text-gray-400 transition-transform duration-200 ${open ? 'rotate-90' : ''}`}
          fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M9 5l7 7-7 7" />
        </svg>
      </button>

      {open && (
        <div className="border-t border-gray-100 max-h-72 overflow-y-auto">
          {buildings.length === 0 ? (
            <p className="text-xs text-gray-400 px-4 py-3">No buildings found.</p>
          ) : (
            buildings.map(b => {
              const isActive = b.id === selectedId;
              return (
                <button key={b.id} onClick={() => onSelect(b.id)}
                  className={`w-full flex items-center gap-3 px-4 py-3 text-left transition-colors border-r-2
                    ${isActive
                      ? 'bg-violet-50 border-violet-500'
                      : 'border-transparent hover:bg-gray-50'}`}>
                  <StatusDot status={b.optimal.status} />
                  <div className="min-w-0 flex-1">
                    <p className={`text-sm font-medium truncate ${isActive ? 'text-violet-700' : 'text-gray-700'}`}>
                      {b.name}
                    </p>
                    <p className="text-xs text-gray-400 mt-0.5">{b.optimal.imbalance_percentage}% imbalance</p>
                  </div>
                  {isActive && (
                    <svg className="w-3.5 h-3.5 text-violet-500 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24">
                      <path d="M9 5l7 7-7 7" strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5}
                        stroke="currentColor" fill="none" />
                    </svg>
                  )}
                </button>
              );
            })
          )}
        </div>
      )}
    </div>
  );
}

// ── Selected building detail ──────────────────────────────────────────────────

function BuildingDetail({ building, onAssignRoom, onApplyOptimal, assigningRooms, applying }) {
  const [tab, setTab] = useState('optimal');
  const activeData = tab === 'actual' ? building.actual : building.optimal;
  const hasRooms   = building.floors.some(f => f.rooms.length > 0);

  return (
    <div className="flex flex-col gap-5">
      {/* Building header */}
      <div className="bg-white rounded-xl border border-gray-200 shadow-sm px-6 py-4 flex items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 bg-violet-50 rounded-xl flex items-center justify-center flex-shrink-0">
            <svg className="w-5 h-5 text-violet-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8}
                d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2M5 21H3M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 8v-4a1 1 0 011-1h2a1 1 0 011 1v4" />
            </svg>
          </div>
          <div>
            <h2 className="text-base font-semibold text-gray-900">{building.name}</h2>
            <div className="flex items-center gap-2 mt-0.5">
              <StatusBadge status={building.optimal.status} />
              <span className="text-xs text-gray-400">Optimal imbalance: {building.optimal.imbalance_percentage}%</span>
            </div>
          </div>
        </div>
        {hasRooms && (
          <button onClick={() => onApplyOptimal(building.id)} disabled={applying}
            className="flex items-center gap-1.5 text-sm font-semibold text-white bg-violet-600
              hover:bg-violet-700 px-4 py-2 rounded-xl transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
            {applying
              ? <span className="inline-block w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
              : <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M5 13l4 4L19 7" />
                </svg>}
            Apply Optimal
          </button>
        )}
      </div>

      {/* Distribution tabs */}
      <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
        <div className="flex gap-1 mb-5 bg-gray-100 p-1 rounded-lg w-fit">
          {['optimal', 'actual'].map(t => (
            <button key={t} onClick={() => setTab(t)}
              className={`px-4 py-1.5 rounded-md text-xs font-semibold transition-colors
                ${tab === t ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'}`}>
              {t === 'optimal' ? 'Optimal (Simulated)' : 'Actual (Saved)'}
            </button>
          ))}
        </div>
        <DistributionPanel
          dist={activeData.distribution}
          unassignedVa={tab === 'actual' ? building.actual.unassigned_va : 0}
          imbalance={activeData.imbalance_percentage}
          neutral={activeData.neutral_current_a}
          status={activeData.status}
        />
      </div>

      {/* Floors + rooms */}
      {building.floors.map(floor => (
        <div key={floor.id} className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
          <div className="px-5 py-3 bg-gray-50 border-b border-gray-100">
            <p className="text-sm font-semibold text-gray-700">{floor.name}</p>
          </div>
          {floor.rooms.length === 0 ? (
            <p className="text-xs text-gray-400 px-5 py-4">No rooms on this floor.</p>
          ) : (
            <table className="w-full text-sm">
              <thead>
                <tr className="text-xs text-gray-500 font-medium border-b border-gray-100">
                  <th className="text-left px-5 py-3">Room</th>
                  <th className="text-right px-4 py-3 w-28">1-ph VA</th>
                  <th className="text-center px-4 py-3 w-24">Saved</th>
                  <th className="text-center px-4 py-3 w-24">Optimal</th>
                  <th className="text-center px-5 py-3 w-44">Assign Phase</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {floor.rooms.map(room => (
                  <tr key={room.id} className="hover:bg-gray-50 transition-colors">
                    <td className="px-5 py-3 font-medium text-gray-800">{room.name}</td>
                    <td className="px-4 py-3 text-right text-xs text-gray-500">
                      {room.va_1ph > 0 ? room.va_1ph.toLocaleString() : '—'}
                    </td>
                    <td className="px-4 py-3 text-center"><PhasePill phase={room.actual_phase} /></td>
                    <td className="px-4 py-3 text-center">
                      {room.va_1ph > 0 ? <PhasePill phase={room.optimal_phase} /> : <span className="text-xs text-gray-300">—</span>}
                    </td>
                    <td className="px-5 py-3 text-center">
                      {room.va_1ph > 0
                        ? <PhaseAssignButtons roomId={room.id} current={room.actual_phase}
                            onAssign={onAssignRoom} loading={!!assigningRooms[room.id]} />
                        : <span className="text-xs text-gray-300">No 1-ph loads</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      ))}

      {/* Non-room blocks */}
      {building.block_assignments.filter(b => b.type !== 'room').length > 0 && (
        <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
          <p className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Other loads</p>
          <div className="space-y-1.5">
            {building.block_assignments.filter(b => b.type !== 'room').map((b, i) => (
              <div key={i} className="flex items-center justify-between text-xs px-3 py-2 bg-gray-50 rounded-lg">
                <span className="text-gray-600">{b.name}</span>
                <div className="flex items-center gap-2">
                  <span className="text-gray-400">{b.va.toLocaleString()} VA</span>
                  <PhasePill phase={b.optimal_phase} />
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function PhaseBalancePage() {
  const navigate      = useNavigate();
  const { projectId } = useParams();

  const [buildings,         setBuildings]         = useState([]);
  const [projectName,       setProjectName]       = useState('');
  const [loading,           setLoading]           = useState(true);
  const [error,             setError]             = useState(null);
  const [selectedId,        setSelectedId]        = useState(null);
  const [assigningRooms,    setAssigningRooms]    = useState({});
  const [applyingBuildings, setApplyingBuildings] = useState({});

  const load = useCallback(async () => {
    try {
      const [phaseRes, projRes] = await Promise.all([
        api.get(`/api/projects/${projectId}/phase-balance`),
        api.get(`/api/projects/${projectId}/buildings`),
      ]);
      const blds = phaseRes.data.buildings ?? [];
      setBuildings(blds);
      setProjectName(projRes.data.project?.name ?? '');
      setSelectedId(prev => prev ?? (blds[0]?.id ?? null));
    } catch {
      setError('Failed to load phase balance data.');
    } finally {
      setLoading(false);
    }
  }, [projectId]);

  useEffect(() => { load(); }, [load]);

  async function handleAssignRoom(roomId, phase) {
    setAssigningRooms(prev => ({ ...prev, [roomId]: true }));
    try {
      await api.post(`/api/rooms/${roomId}/assign-phase`, { phase });
      await load();
    } finally {
      setAssigningRooms(prev => ({ ...prev, [roomId]: false }));
    }
  }

  async function handleApplyOptimal(buildingId) {
    setApplyingBuildings(prev => ({ ...prev, [buildingId]: true }));
    try {
      await api.post(`/api/buildings/${buildingId}/apply-optimal-phase`);
      await load();
    } finally {
      setApplyingBuildings(prev => ({ ...prev, [buildingId]: false }));
    }
  }

  const selected = buildings.find(b => b.id === selectedId) ?? null;

  if (loading) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        <div className="w-10 h-10 border-4 border-violet-500 border-t-transparent rounded-full animate-spin" />
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-50">
      <div className="sticky top-0 z-40">
        <ErrorBoundary label="power summary">
          <PowerBanner endpoint={`/api/projects/${projectId}/total-power`} refreshKey={0} />
        </ErrorBoundary>
      </div>

      <header className="bg-white border-b border-gray-200 px-6 py-4 flex items-center gap-4">
        <button onClick={() => navigate(`/projects/${projectId}`)}
          className="text-gray-400 hover:text-gray-600 transition-colors p-1.5 rounded-lg hover:bg-gray-100">
          <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
          </svg>
        </button>
        <div>
          <div className="flex items-center gap-2 text-sm text-gray-400 mb-0.5">
            <span onClick={() => navigate('/dashboard')} className="hover:text-blue-500 cursor-pointer">Projects</span>
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
            </svg>
            <span onClick={() => navigate(`/projects/${projectId}`)} className="hover:text-blue-500 cursor-pointer">{projectName}</span>
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
            </svg>
            <span className="text-gray-600 font-medium">Phase Balance</span>
          </div>
          <h1 className="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <svg className="w-5 h-5 text-violet-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
            Phase Balance Analysis
          </h1>
        </div>
      </header>

      <main className="px-8 sm:px-12 py-8 flex gap-6 items-start">

        {/* Left column: nav sidebar + building picker stacked */}
        <div className="flex-shrink-0 flex flex-col gap-4 w-60">
          <ProjectSidebar />
          <BuildingPicker
            buildings={buildings}
            selectedId={selectedId}
            onSelect={setSelectedId}
          />
        </div>

        {/* Right column: detail panel */}
        <div className="flex-1 min-w-0">
          {error && (
            <div className="bg-red-50 border border-red-200 rounded-xl p-4 text-sm text-red-700 mb-5">{error}</div>
          )}

          {selected ? (
            <BuildingDetail
              building={selected}
              onAssignRoom={handleAssignRoom}
              onApplyOptimal={handleApplyOptimal}
              assigningRooms={assigningRooms}
              applying={!!applyingBuildings[selected.id]}
            />
          ) : !error && (
            <div className="bg-white rounded-xl border border-gray-200 p-12 text-center">
              <svg className="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
                  d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5" />
              </svg>
              <p className="text-gray-400 text-sm">No buildings found. Add buildings to this project first.</p>
            </div>
          )}

          {/* Legend */}
          {buildings.length > 0 && (
            <div className="mt-5 bg-white rounded-xl border border-gray-200 p-4">
              <p className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">How it works</p>
              <div className="grid grid-cols-2 gap-x-6 gap-y-2 text-xs text-gray-600">
                <div className="flex items-start gap-1.5">
                  <span className="font-semibold text-violet-600 flex-shrink-0 mt-px">Optimal</span>
                  <span>Greedy simulation assigns each room's 1-phase VA to the lightest phase to minimise imbalance.</span>
                </div>
                <div className="flex items-start gap-1.5">
                  <span className="font-semibold text-gray-700 flex-shrink-0 mt-px">Actual</span>
                  <span>Distribution computed from the saved phase field on each component.</span>
                </div>
                <div className="flex items-start gap-1.5">
                  <span className="font-semibold text-emerald-700 flex-shrink-0 mt-px">Apply Optimal</span>
                  <span>Writes the simulated assignment to all 1-phase components in the building at once.</span>
                </div>
                <div className="flex items-start gap-1.5">
                  <span className="font-semibold text-gray-700 flex-shrink-0 mt-px">A / B / C / —</span>
                  <span>Manually assign all 1-phase components in a room to a phase. — clears it.</span>
                </div>
              </div>
            </div>
          )}
        </div>

      </main>
    </div>
  );
}
