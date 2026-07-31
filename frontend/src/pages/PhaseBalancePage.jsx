import { useState, useEffect, useCallback } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import api from '../api/axios';
import PowerBanner from '../components/PowerBanner';
import ProjectSidebar from '../components/ProjectSidebar';
import ErrorBoundary from '../components/ErrorBoundary';

const PHASE_COLORS = {
  A: { bar: 'bg-accent',       text: 'text-accent',        bg: 'bg-accent-soft',   border: 'border-accent-border',        badge: 'bg-accent-soft text-accent' },
  B: { bar: 'bg-accent-light', text: 'text-accent-light',  bg: 'bg-accent-softer', border: 'border-accent-border',        badge: 'bg-accent-softer text-accent-light' },
  C: { bar: 'bg-accent-bright',text: 'text-accent-bright',  bg: 'bg-accent-tint',   border: 'border-accent-border-strong', badge: 'bg-accent-tint text-accent-bright' },
};

const STATUS_META = {
  balanced:   { label: 'Balanced',   cls: 'bg-success-soft text-success', dot: 'bg-success' },
  warning:    { label: 'Warning',    cls: 'bg-accent-soft text-accent',   dot: 'bg-accent' },
  critical:   { label: 'Critical',   cls: 'bg-danger-soft text-danger',   dot: 'bg-danger' },
  unassigned: { label: 'Unassigned', cls: 'bg-surface-inset text-ink-muted', dot: 'bg-ink-muted' },
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
      <div className="flex-1 bg-surface-inset rounded-full h-2.5 overflow-hidden">
        <div className={`h-full rounded-full transition-all duration-500 ${color.bar}`} style={{ width: `${pct}%` }} />
      </div>
      <div className="text-right w-40 flex-shrink-0">
        <span className="text-xs font-semibold text-ink-body2 font-mono">{data.percentage_of_total}%</span>
        <span className="text-xs text-ink-muted ml-2 font-mono">{data.va.toLocaleString()} VA</span>
        <span className="text-xs text-ink-muted ml-1 font-mono">/ {data.current_a} A</span>
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
                ? `${color.bg} ${color.border} ${color.text}`
                : 'bg-surface-card border-line text-ink-muted hover:border-line-strong hover:text-ink-body'}
              disabled:opacity-40 disabled:cursor-not-allowed`}>
            {ph}
          </button>
        );
      })}
      <button onClick={() => onAssign(roomId, null)} disabled={loading} title="Clear phase"
        className="w-7 h-7 rounded-full text-xs font-bold border bg-surface-card border-line text-ink-muted
          hover:border-danger-border hover:text-danger transition-all disabled:opacity-40 disabled:cursor-not-allowed">
        —
      </button>
    </div>
  );
}

function PhasePill({ phase }) {
  if (!phase) return <span className="text-xs text-ink-muted2">—</span>;
  if (phase === 'mixed') return <span className="text-xs text-accent font-semibold">Mixed</span>;
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
        <div className="flex items-center gap-2 text-xs text-ink-muted">
          <span className="w-6 h-6 rounded-full bg-surface-inset flex items-center justify-center font-bold flex-shrink-0">?</span>
          <span>Unassigned: {unassignedVa.toLocaleString()} VA</span>
        </div>
      )}
      <div className="flex items-center gap-4 pt-1 text-xs text-ink-body border-t border-line-subtle">
        <span>Imbalance: <strong className={imbalance >= 20 ? 'text-danger' : imbalance >= 10 ? 'text-accent' : 'text-success'}>{imbalance}%</strong></span>
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
    <div className="bg-surface-card rounded-xl border border-line overflow-hidden">
      {/* Header / toggle */}
      <button
        onClick={() => setOpen(o => !o)}
        className="w-full flex items-center justify-between px-4 py-3 hover:bg-surface-inset transition-colors">
        <span className="text-xs font-semibold text-ink-muted uppercase tracking-wider">
          Buildings <span className="text-ink-muted2 font-normal normal-case">({buildings.length})</span>
        </span>
        <svg className={`w-4 h-4 text-ink-muted transition-transform duration-200 ${open ? 'rotate-90' : ''}`}
          fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M9 5l7 7-7 7" />
        </svg>
      </button>

      {open && (
        <div className="border-t border-line-subtle max-h-72 overflow-y-auto">
          {buildings.length === 0 ? (
            <p className="text-xs text-ink-muted px-4 py-3">No buildings found.</p>
          ) : (
            buildings.map(b => {
              const isActive = b.id === selectedId;
              return (
                <button key={b.id} onClick={() => onSelect(b.id)}
                  className={`w-full flex items-center gap-3 px-4 py-3 text-left transition-colors border-r-2
                    ${isActive
                      ? 'bg-accent-soft border-accent'
                      : 'border-transparent hover:bg-surface-inset'}`}>
                  <StatusDot status={b.optimal.status} />
                  <div className="min-w-0 flex-1">
                    <p className={`text-sm font-medium truncate ${isActive ? 'text-accent' : 'text-ink-body2'}`}>
                      {b.name}
                    </p>
                    <p className="text-xs text-ink-muted mt-0.5">{b.optimal.imbalance_percentage}% imbalance</p>
                  </div>
                  {isActive && (
                    <svg className="w-3.5 h-3.5 text-accent flex-shrink-0" fill="currentColor" viewBox="0 0 24 24">
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
      <div className="bg-surface-card rounded-xl border border-line px-6 py-4 flex items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 bg-accent-soft rounded-xl flex items-center justify-center flex-shrink-0">
            <svg className="w-5 h-5 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8}
                d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2M5 21H3M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 8v-4a1 1 0 011-1h2a1 1 0 011 1v4" />
            </svg>
          </div>
          <div>
            <h2 className="text-base font-semibold text-ink-heading">{building.name}</h2>
            <div className="flex items-center gap-2 mt-0.5">
              <StatusBadge status={building.optimal.status} />
              <span className="text-xs text-ink-muted">Optimal imbalance: {building.optimal.imbalance_percentage}%</span>
            </div>
          </div>
        </div>
        {hasRooms && (
          <button onClick={() => onApplyOptimal(building.id)} disabled={applying}
            className="flex items-center gap-1.5 text-sm font-semibold text-base bg-accent-gradient
              hover:shadow-accent px-4 py-2 rounded-xl transition-shadow disabled:opacity-50 disabled:cursor-not-allowed">
            {applying
              ? <span className="inline-block w-4 h-4 border-2 border-base border-t-transparent rounded-full animate-spin" />
              : <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M5 13l4 4L19 7" />
                </svg>}
            Apply Optimal
          </button>
        )}
      </div>

      {/* Distribution tabs */}
      <div className="bg-surface-card rounded-xl border border-line p-5">
        <div className="flex gap-1 mb-5 bg-surface-inset p-1 rounded-lg w-fit">
          {['optimal', 'actual'].map(t => (
            <button key={t} onClick={() => setTab(t)}
              className={`px-4 py-1.5 rounded-md text-xs font-semibold transition-colors
                ${tab === t ? 'bg-surface-card text-ink-heading' : 'text-ink-muted hover:text-ink-body2'}`}>
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
        <div key={floor.id} className="bg-surface-card rounded-xl border border-line overflow-hidden">
          <div className="px-5 py-3 bg-surface-alt border-b border-line-subtle">
            <p className="text-sm font-semibold text-ink-body2">{floor.name}</p>
          </div>
          {floor.rooms.length === 0 ? (
            <p className="text-xs text-ink-muted px-5 py-4">No rooms on this floor.</p>
          ) : (
            <table className="w-full text-sm">
              <thead>
                <tr className="text-xs text-ink-muted font-medium border-b border-line-subtle">
                  <th className="text-left px-5 py-3">Room</th>
                  <th className="text-right px-4 py-3 w-28">1-ph VA</th>
                  <th className="text-center px-4 py-3 w-24">Saved</th>
                  <th className="text-center px-4 py-3 w-24">Optimal</th>
                  <th className="text-center px-5 py-3 w-44">Assign Phase</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line-subtle">
                {floor.rooms.map(room => (
                  <tr key={room.id} className="hover:bg-surface-inset transition-colors">
                    {/* Room name — badge if it was split into sections */}
                    <td className="px-5 py-3">
                      <div className="flex items-center gap-2">
                        <span className="font-medium text-ink-body2">{room.name}</span>
                        {room.is_split && (
                          <span className="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-accent-soft text-accent">
                            Split ×{room.split_sections.length}
                          </span>
                        )}
                      </div>
                    </td>
                    <td className="px-4 py-3 text-right text-xs text-ink-muted font-mono">
                      {room.va_1ph > 0 ? room.va_1ph.toLocaleString() : '—'}
                    </td>
                    {/* Saved phase — "Mixed" for split rooms after Apply Optimal */}
                    <td className="px-4 py-3 text-center"><PhasePill phase={room.actual_phase} /></td>
                    {/* Optimal phase — per-phase pills for split rooms */}
                    <td className="px-4 py-3 text-center">
                      {room.is_split ? (
                        room.split_sections.length > 0 ? (
                          <div className="flex flex-col items-center gap-0.5">
                            {room.split_sections.map(s => (
                              <div key={s.phase} className="flex items-center gap-1.5">
                                <PhasePill phase={s.phase} />
                                <span className="text-[10px] text-ink-muted tabular-nums font-mono">{s.va.toLocaleString()} VA</span>
                              </div>
                            ))}
                          </div>
                        ) : <span className="text-xs text-ink-muted2">—</span>
                      ) : room.va_1ph > 0 ? (
                        <PhasePill phase={room.optimal_phase} />
                      ) : (
                        <span className="text-xs text-ink-muted2">—</span>
                      )}
                    </td>
                    {/* Manual assign still works — overrides the split */}
                    <td className="px-5 py-3 text-center">
                      {room.va_1ph > 0
                        ? <PhaseAssignButtons roomId={room.id} current={room.actual_phase}
                            onAssign={onAssignRoom} loading={!!assigningRooms[room.id]} />
                        : <span className="text-xs text-ink-muted2">No 1-ph loads</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      ))}

      {/* Non-room blocks (floor-own, socket demand, building-level) */}
      {building.block_assignments.filter(b => b.type !== 'room' && b.type !== 'room_section').length > 0 && (
        <div className="bg-surface-card rounded-xl border border-line p-5">
          <p className="text-xs font-semibold text-ink-muted uppercase tracking-wider mb-3">Other loads</p>
          <div className="space-y-1.5">
            {building.block_assignments
              .filter(b => b.type !== 'room' && b.type !== 'room_section')
              .map((b, i) => (
                <div key={i} className="flex items-center justify-between text-xs px-3 py-2 bg-surface-inset rounded-lg">
                  <span className="text-ink-body2">{b.name}</span>
                  <div className="flex items-center gap-2">
                    <span className="text-ink-muted font-mono">{b.va.toLocaleString()} VA</span>
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
      <div className="min-h-screen bg-base flex items-center justify-center">
        <div className="w-10 h-10 border-4 border-accent border-t-transparent rounded-full animate-spin" />
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-base">
      <div className="sticky top-0 z-40">
        <ErrorBoundary label="power summary">
          <PowerBanner endpoint={`/api/projects/${projectId}/total-power`} refreshKey={0} />
        </ErrorBoundary>
      </div>

      <header className="bg-surface-card border-b border-line px-6 py-4 flex items-center gap-4">
        <button onClick={() => navigate(`/projects/${projectId}`)}
          className="text-ink-muted hover:text-ink-body2 transition-colors p-1.5 rounded-lg hover:bg-surface-inset">
          <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
          </svg>
        </button>
        <div>
          <div className="flex items-center gap-2 text-sm text-ink-muted mb-0.5">
            <span onClick={() => navigate('/dashboard')} className="hover:text-accent cursor-pointer">Projects</span>
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
            </svg>
            <span onClick={() => navigate(`/projects/${projectId}`)} className="hover:text-accent cursor-pointer">{projectName}</span>
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
            </svg>
            <span className="text-ink-body2 font-medium">Phase Balance</span>
          </div>
          <h1 className="text-lg font-semibold text-ink-heading flex items-center gap-2">
            <svg className="w-5 h-5 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
            <div className="bg-danger-soft border border-danger-border rounded-xl p-4 text-sm text-danger mb-5">{error}</div>
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
            <div className="bg-surface-card rounded-xl border border-line p-12 text-center">
              <svg className="w-12 h-12 mx-auto mb-3 text-ink-muted2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
                  d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5" />
              </svg>
              <p className="text-ink-muted text-sm">No buildings found. Add buildings to this project first.</p>
            </div>
          )}

          {/* Legend */}
          {buildings.length > 0 && (
            <div className="mt-5 bg-surface-card rounded-xl border border-line p-4">
              <p className="text-xs font-semibold text-ink-muted uppercase tracking-wider mb-3">How it works</p>
              <div className="grid grid-cols-2 gap-x-6 gap-y-2 text-xs text-ink-body">
                <div className="flex items-start gap-1.5">
                  <span className="font-semibold text-accent flex-shrink-0 mt-px">Optimal</span>
                  <span>Derived directly from the Electrical Design panel schedule — the same circuit-level LPT phase assignment that the panel shows. Per-phase VA totals and imbalance % are identical on both pages.</span>
                </div>
                <div className="flex items-start gap-1.5">
                  <span className="font-semibold text-ink-body2 flex-shrink-0 mt-px">Actual</span>
                  <span>Distribution computed from the saved phase field on each component (set by Apply Optimal or manual assignment).</span>
                </div>
                <div className="flex items-start gap-1.5">
                  <span className="font-semibold text-success flex-shrink-0 mt-px">Apply Optimal</span>
                  <span>Writes the circuit-level phase assignment to every 1-phase component — sockets, lighting, and auxiliary separately so each circuit type in a room can land on its correct phase.</span>
                </div>
                <div className="flex items-start gap-1.5">
                  <span className="font-semibold text-ink-body2 flex-shrink-0 mt-px">A / B / C / —</span>
                  <span>Manually assign all 1-phase components in a room to a phase. — clears it.</span>
                </div>
                <div className="flex items-start gap-1.5">
                  <span className="font-semibold text-accent flex-shrink-0 mt-px">Split rooms</span>
                  <span>A room whose circuits land on more than one phase shows a split indicator with each phase and its VA share (e.g. A: 432 VA · B: 432 VA · C: 300 VA) — panels are wired per circuit, not per room.</span>
                </div>
                <div className="flex items-start gap-1.5">
                  <span className="font-semibold text-ink-body2 flex-shrink-0 mt-px">Source of truth</span>
                  <span>The Electrical Design page is the single source of truth. Phase Balance derives from it — there is no separate room-level phase calculation.</span>
                </div>
              </div>
            </div>
          )}
        </div>

      </main>
    </div>
  );
}
