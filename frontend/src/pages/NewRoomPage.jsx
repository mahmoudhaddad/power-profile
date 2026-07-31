/* eslint-disable react/prop-types */
import { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import api from '../api/axios';
import { downloadJson } from '../utils/downloadJson';
import BackupChoiceModal from '../components/BackupChoiceModal';
import EntitySockets from '../components/EntitySockets';
import EntityComponents from '../components/EntityComponents';
import PowerBanner from '../components/PowerBanner';
import PowerSourcesBanner from '../components/PowerSourcesBanner';
import EntityScheduleModal from '../components/EntityScheduleModal';
import ProjectSidebar from '../components/ProjectSidebar';

export default function RoomPage() {
  const navigate = useNavigate();
  const { projectId, buildingId, floorId, roomId } = useParams();

  const [project, setProject]   = useState(null);
  const [building, setBuilding] = useState(null);
  const [floor, setFloor]       = useState(null);
  const [room, setRoom]         = useState(null);
  const [componentTypes, setComponentTypes] = useState([]);
  const [loading, setLoading]   = useState(true);

  const [showSchedule, setShowSchedule] = useState(false);
  const [backupTarget, setBackupTarget] = useState(null);
  const [powerKey, setPowerKey]         = useState(0);
  const [powerSources, setPowerSources] = useState({ solar_computed: null, generator_computed: null, max_va: 0, total_va: 0 });

  const userRole = project?.user_role ?? null;
  const canEdit  = userRole === 'admin' || userRole === 'main';

  useEffect(() => {
    if (!roomId) { navigate('/dashboard'); return; }
    Promise.all([
      api.get(`/api/rooms/${roomId}/components`),
      api.get('/api/component-types'),
    ]).then(([compRes, typesRes]) => {
      const r = compRes.data.room;
      setRoom(r);
      setFloor(r.floor);
      setBuilding(r.floor.building);
      setProject(r.floor.building.project);
      setComponentTypes(typesRes.data.data);
    })
    .catch(() => navigate(`/projects/${projectId}/buildings/${buildingId}/floors/${floorId}`))
    .finally(() => setLoading(false));
  }, [roomId]);

  async function handleBackupDownload() {
    if (!room) return;
    try {
      const { data } = await api.get(`/api/rooms/${room.id}/backup`);
      downloadJson(data, `${room.name.replace(/\s+/g, '-')}-backup.json`);
    } catch (err) {
      alert('Backup failed: ' + (err.response?.data?.message || err.message || 'Unknown error'));
    }
  }

  async function handleBackupToServer() {
    await api.post(`/api/rooms/${room.id}/save-backup`);
  }

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
        <PowerBanner
          endpoint={room ? `/api/rooms/${room.id}/total-power` : null}
          refreshKey={powerKey}
          reportTitle={room ? `${room.name} — Power Analysis` : undefined}
          onData={d => setPowerSources({ solar_computed: d.solar_computed, generator_computed: d.generator_computed, max_va: d.max_va ?? 0, total_va: d.total_va ?? 0 })}
        />
        <PowerSourcesBanner
          entity={room}
          updateEndpoint={room ? `/api/floors/${floorId}/rooms/${room.id}` : null}
          onUpdate={updated => setRoom(updated)}
          solarComputed={powerSources.solar_computed}
          projectId={project?.id}
          maxLoad={powerSources.max_va}
          optimizedLoad={powerSources.total_va}
        />
      </div>

      <header className="bg-surface-card border-b border-line px-6 py-4 flex items-center gap-4">
        <button onClick={() => navigate(`/projects/${projectId}/buildings/${buildingId}/floors/${floorId}`)}
          className="text-ink-muted hover:text-ink-heading transition-colors p-1.5 rounded-lg hover:bg-surface-inset">
          <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
          </svg>
        </button>
        <div className="flex-1">
          <div className="flex items-center gap-1.5 text-sm text-ink-muted mb-0.5 flex-wrap">
            <span onClick={() => navigate('/dashboard')} className="hover:text-accent cursor-pointer transition-colors">Projects</span>
            <Chevron />
            <span onClick={() => navigate(`/projects/${projectId}`)} className="hover:text-accent cursor-pointer transition-colors">{project?.name}</span>
            <Chevron />
            <span onClick={() => navigate(`/projects/${projectId}/buildings/${buildingId}`)} className="hover:text-accent cursor-pointer transition-colors">{building?.name}</span>
            <Chevron />
            <span onClick={() => navigate(`/projects/${projectId}/buildings/${buildingId}/floors/${floorId}`)} className="hover:text-accent cursor-pointer transition-colors">{floor?.name}</span>
            <Chevron />
            <span className="text-ink-body2 font-medium">{room?.name}</span>
          </div>
          <h1 className="text-lg font-semibold text-ink-heading">{room?.name}</h1>
        </div>
        {canEdit && (
          <button onClick={() => setShowSchedule(true)}
            className="flex items-center gap-1.5 text-xs font-medium text-ink-body2 px-3 py-1.5
              rounded-lg border border-line bg-surface-card hover:border-accent-border hover:text-accent
              hover:bg-accent-soft transition-all duration-150 flex-shrink-0">
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            Schedule
          </button>
        )}
        {canEdit && (
          <button onClick={() => setBackupTarget(room)}
            className="flex items-center gap-1.5 text-xs font-medium text-ink-body2 px-3 py-1.5
              rounded-lg border border-line bg-surface-card hover:border-accent-border hover:text-accent
              hover:bg-accent-soft transition-all duration-150 flex-shrink-0">
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
            Backup
          </button>
        )}
      </header>

      <main className="px-8 sm:px-12 py-8 flex gap-6 items-start">
        <ProjectSidebar />
        <div className="flex-1 min-w-0">
          <EntityComponents
            endpoint={room ? `/api/rooms/${room.id}/components` : null}
            componentTypes={componentTypes}
            onTypesUpdated={t => setComponentTypes(prev => [...prev, t])}
            onChanged={() => setPowerKey(k => k + 1)}
            canEdit={canEdit}
          />
          <EntitySockets
            endpoint={room ? `/api/rooms/${room.id}/sockets` : null}
            onChanged={() => setPowerKey(k => k + 1)}
            canEdit={canEdit}
          />
        </div>
      </main>

      {backupTarget && (
        <BackupChoiceModal
          entityName={backupTarget.name}
          onDownload={handleBackupDownload}
          onSaveToServer={handleBackupToServer}
          onClose={() => setBackupTarget(null)}
        />
      )}
      {showSchedule && room && floor && (
        <EntityScheduleModal
          entity={room}
          updateEndpoint={`/api/floors/${floor.id}/rooms/${room.id}`}
          parentSchedule={{
            work_days:               floor.work_days               ?? building?.work_days               ?? project?.work_days               ?? null,
            work_time_intervals:     floor.work_time_intervals     ?? building?.work_time_intervals     ?? project?.work_time_intervals     ?? null,
            working_season_intervals:floor.working_season_intervals ?? building?.working_season_intervals ?? project?.working_season_intervals ?? null,
          }}
          parentLabel="Floor"
          onUpdate={updated => setRoom(updated)}
          onClose={() => setShowSchedule(false)}
        />
      )}
    </div>
  );
}

function Chevron() {
  return (
    <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
    </svg>
  );
}
