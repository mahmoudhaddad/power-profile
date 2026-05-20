/* eslint-disable react/prop-types */
import { useState, useEffect, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api/axios';
import { getNav } from '../utils/navContext';
import { downloadJson } from '../utils/downloadJson';
import BackupChoiceModal from '../components/BackupChoiceModal';
import EntitySockets from '../components/EntitySockets';
import PowerBanner from '../components/PowerBanner';
import PowerSourcesBanner from '../components/PowerSourcesBanner';
import EntityScheduleModal from '../components/EntityScheduleModal';
import ProjectSidebar from '../components/ProjectSidebar';

export default function RoomPage() {
  const navigate = useNavigate();
  const { roomId, floorId } = getNav();

  const [project, setProject]   = useState(null);
  const [building, setBuilding] = useState(null);
  const [floor, setFloor]       = useState(null);
  const [room, setRoom]         = useState(null);
  const [components, setComponents]         = useState([]);
  const [componentTypes, setComponentTypes] = useState([]);
  const [loading, setLoading]   = useState(true);

  const [showSchedule, setShowSchedule] = useState(false);
  const [backupTarget, setBackupTarget] = useState(null);
  const [powerKey, setPowerKey]     = useState(0);
  const [powerSources, setPowerSources] = useState({ solar_computed: null, generator_computed: null, max_va: 0, total_va: 0 });

  const userRole = project?.user_role ?? null;
  const canEdit  = userRole === 'admin' || userRole === 'main';
  const [openComponents, setOpenComponents] = useState(true);

  const emptyComp = { name: '', power: '', quantity: '1', priority: 'normal', phases: '1phase', power_factor: '1', group_name: '', needs_socket: false, usage_season: 'all', usage_day_type: 'all', usage_time_intervals: [{ start: '08:00', end: '18:00' }] };
  const [showModal, setShowModal]     = useState(false);
  const [newComp, setNewComp]         = useState(emptyComp);
  const [editingComp, setEditingComp] = useState(null);
  const [editForm, setEditForm]       = useState(emptyComp);

  useEffect(() => {
    if (!roomId) { navigate('/dashboard'); return; }
    Promise.all([
      api.get(`/api/rooms/${roomId}/components`),
      api.get('/api/component-types'),
    ]).then(([compRes, typesRes]) => {
      setRoom(compRes.data.room);
      setFloor(compRes.data.room.floor);
      setBuilding(compRes.data.room.floor.building);
      setProject(compRes.data.room.floor.building.project);
      setComponents(compRes.data.data);
      setComponentTypes(typesRes.data.data);
    })
    .catch(() => navigate('/project/building/floor'))
    .finally(() => setLoading(false));
  }, [roomId, navigate]);

  async function handleAdd() {
    if (!newComp.name.trim() || Number(newComp.power) <= 0) return;
    const { data } = await api.post(`/api/rooms/${room.id}/components`, {
      component_name: newComp.name.trim(),
      power:        newComp.power,
      quantity:     newComp.quantity,
      priority:     newComp.priority,
      phases:       newComp.phases,
      power_factor: newComp.power_factor,
      group_name:           newComp.group_name || null,
      needs_socket:         newComp.needs_socket,
      usage_season:         newComp.usage_season,
      usage_day_type:       newComp.usage_day_type,
      usage_time_intervals: newComp.usage_time_intervals,
    });
    setComponents([data.data, ...components]);
    if (!componentTypes.find(t => t.name === newComp.name.trim())) {
      setComponentTypes([...componentTypes, data.data.component_type]);
    }
    setNewComp(emptyComp);
    setShowModal(false);
    setPowerKey(k => k + 1);
  }

  function openEdit(comp) {
    setEditingComp(comp);
    setEditForm({
      name: comp.component_type.name, power: comp.power, quantity: String(comp.quantity ?? 1),
      priority:             comp.priority             ?? 'normal',
      phases: comp.phases ?? '1phase', power_factor: comp.power_factor ?? '1',
      group_name:           comp.group_name           ?? '',
      needs_socket:         comp.needs_socket         ?? false,
      usage_season:         comp.usage_season         ?? 'all',
      usage_day_type:       comp.usage_day_type       ?? 'all',
      usage_time_intervals: (() => { const r = comp.usage_time_intervals; return r ? (typeof r === 'string' ? JSON.parse(r) : r) : [{ start: '08:00', end: '18:00' }]; })(),
    });
  }

  async function handleEdit() {
    if (!editForm.name.trim() || Number(editForm.power) <= 0) return;
    const { data } = await api.put(`/api/rooms/${room.id}/components/${editingComp.id}`, {
      component_name: editForm.name.trim(),
      power:        editForm.power,
      quantity:     editForm.quantity,
      priority:     editForm.priority,
      phases:       editForm.phases,
      power_factor: editForm.power_factor,
      group_name:           editForm.group_name || null,
      needs_socket:         editForm.needs_socket,
      usage_season:         editForm.usage_season,
      usage_day_type:       editForm.usage_day_type,
      usage_time_intervals: editForm.usage_time_intervals,
    });
    setComponents(components.map(c => c.id === editingComp.id ? data.data : c));
    setEditingComp(null);
    setPowerKey(k => k + 1);
  }

  async function handleDelete(compId) {
    await api.delete(`/api/rooms/${room.id}/components/${compId}`);
    setComponents(components.filter(c => c.id !== compId));
    setPowerKey(k => k + 1);
  }

  async function handleDuplicate(comp) {
    const { data } = await api.post(`/api/rooms/${room.id}/components`, {
      component_name: comp.component_type.name,
      power:         comp.power,
      quantity:      comp.quantity,
      priority:      comp.priority ?? 'normal',
      phases:        comp.phases,
      power_factor:  comp.power_factor,
      group_name:           comp.group_name           ?? null,
      needs_socket:         comp.needs_socket,
      usage_season:         comp.usage_season         ?? 'all',
      usage_day_type:       comp.usage_day_type       ?? 'all',
      usage_time_intervals: (() => { const r = comp.usage_time_intervals; return r ? (typeof r === 'string' ? JSON.parse(r) : r) : [{ start: '08:00', end: '18:00' }]; })(),
    });
    setComponents(prev => [data.data, ...prev]);
    setPowerKey(k => k + 1);
  }

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
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        <div className="w-10 h-10 border-4 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-50">

      <div className="sticky top-0 z-40">
        <PowerBanner
          endpoint={room ? `/api/rooms/${room.id}/total-power` : null}
          refreshKey={powerKey}
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

      <header className="bg-white border-b border-gray-200 px-6 py-4 flex items-center gap-4">
        <button onClick={() => navigate('/project/building/floor')}
          className="text-gray-400 hover:text-gray-600 transition-colors p-1.5 rounded-lg hover:bg-gray-100">
          <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
          </svg>
        </button>
        <div className="flex-1">
          <div className="flex items-center gap-1.5 text-sm text-gray-400 mb-0.5 flex-wrap">
            <span onClick={() => navigate('/dashboard')} className="hover:text-blue-500 cursor-pointer transition-colors">{project?.name}</span>
            <Chevron />
            <span onClick={() => navigate('/project')} className="hover:text-blue-500 cursor-pointer transition-colors">{building?.name}</span>
            <Chevron />
            <span onClick={() => navigate('/project/building/floor')} className="hover:text-blue-500 cursor-pointer transition-colors">{floor?.name}</span>
            <Chevron />
            <span className="text-gray-600 font-medium">{room?.name}</span>
          </div>
          <h1 className="text-lg font-semibold text-gray-900">{room?.name}</h1>
        </div>
        {canEdit && <button onClick={() => setShowSchedule(true)}
          className="flex items-center gap-1.5 text-xs font-medium text-gray-500 px-3 py-1.5
            rounded-lg border border-gray-200 bg-white hover:border-indigo-400 hover:text-indigo-600
            hover:bg-indigo-50 transition-all duration-150 flex-shrink-0">
          <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
          </svg>
          Schedule
        </button>}
        {canEdit && <button onClick={() => setBackupTarget(room)}
          className="flex items-center gap-1.5 text-xs font-medium text-gray-500 px-3 py-1.5
            rounded-lg border border-gray-200 bg-white hover:border-emerald-400 hover:text-emerald-600
            hover:bg-emerald-50 transition-all duration-150 flex-shrink-0">
          <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
          </svg>
          Backup
        </button>}
      </header>

      <main className="px-8 sm:px-12 py-8 flex gap-6 items-start">
        <ProjectSidebar />
        <div className="flex-1 min-w-0">
        <section>
          <div className={`flex items-center justify-between ${openComponents ? 'mb-4' : 'mb-0'}`}>
            <button onClick={() => setOpenComponents(o => !o)} className="flex items-center gap-2 group">
              <svg className={`w-4 h-4 text-gray-400 transition-transform duration-200 ${openComponents ? 'rotate-90' : ''}`}
                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M9 5l7 7-7 7" />
              </svg>
              <h2 className="text-base font-semibold text-gray-900 group-hover:text-gray-700">
                Components
                <span className="ml-2 text-xs font-normal text-gray-400">({components.length})</span>
              </h2>
            </button>
          </div>
          {openComponents && (components.length === 0 ? (
            <div className="bg-white rounded-xl border border-gray-200 shadow-sm py-14 text-center text-gray-400">
              <svg className="w-10 h-10 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M13 10V3L4 14h7v7l9-11h-7z" />
              </svg>
              <p className="text-sm font-medium">No components yet. Add your first one!</p>
            </div>
          ) : (
            <div className="grid grid-cols-2 gap-3">
              {components.map(comp => (
                <ComponentCard key={comp.id} comp={comp}
                  canEdit={canEdit}
                  onEdit={() => openEdit(comp)}
                  onDelete={() => handleDelete(comp.id)}
                  onDuplicate={() => handleDuplicate(comp)} />
              ))}
            </div>
          ))}
        </section>

        <EntitySockets
          endpoint={room ? `/api/rooms/${room.id}/sockets` : null}
          onChanged={() => setPowerKey(k => k + 1)}
          canEdit={canEdit}
        />
        </div>

        {canEdit && (
          <aside className="w-72 flex-shrink-0 flex flex-col gap-4">
            <div className="bg-white rounded-xl shadow-lg p-4">
              <h2 className="text-sm font-semibold text-gray-900 mb-3">Add Component</h2>
              <button onClick={() => setShowModal(true)}
                className="group flex items-center gap-3 border-2 border-dashed border-blue-300
                  hover:border-blue-500 hover:bg-blue-50 text-blue-500 hover:text-blue-700
                  rounded-xl px-4 py-3 w-full transition-all duration-200 hover:shadow-sm">
                <span className="w-8 h-8 rounded-full bg-blue-100 group-hover:bg-blue-200 flex items-center justify-center flex-shrink-0 transition-colors duration-200">
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                  </svg>
                </span>
                <div className="text-left">
                  <p className="font-semibold text-xs">Add New Component</p>
                  <p className="text-xs text-blue-400 group-hover:text-blue-500 transition-colors">Add an electrical component to this room</p>
                </div>
              </button>
            </div>
          </aside>
        )}
      </main>

      {showModal && (
        <ComponentModal title="New Component" form={newComp} onChange={setNewComp}
          onSubmit={handleAdd}
          onClose={() => { setShowModal(false); setNewComp(emptyComp); }}
          submitLabel="Add Component" componentTypes={componentTypes}
          existingGroups={[...new Set(components.filter(c => c.group_name).map(c => c.group_name))]} />
      )}
      {editingComp && (
        <ComponentModal title="Edit Component" form={editForm} onChange={setEditForm}
          onSubmit={handleEdit} onClose={() => setEditingComp(null)}
          submitLabel="Save Changes" componentTypes={componentTypes}
          existingGroups={[...new Set(components.filter(c => c.group_name).map(c => c.group_name))]} />
      )}
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
            work_days: floor.work_days ?? building?.work_days ?? project?.work_days ?? null,
            work_time_intervals: floor.work_time_intervals ?? building?.work_time_intervals ?? project?.work_time_intervals ?? null,
            working_season_intervals: floor.working_season_intervals ?? building?.working_season_intervals ?? project?.working_season_intervals ?? null,
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

const PRIORITY_LABELS = {
  critical:  { label: 'Critical',  bg: 'bg-red-100',   text: 'text-red-700'   },
  essential: { label: 'Essential', bg: 'bg-amber-100', text: 'text-amber-700' },
  normal:    { label: 'Normal',    bg: 'bg-gray-100',  text: 'text-gray-500'  },
};

function ComponentCard({ comp, canEdit, onEdit, onDelete, onDuplicate }) {
  const va    = Number(comp.power);
  const qty   = Number(comp.quantity ?? 1);
  const pf    = Number(comp.power_factor ?? 1);
  const realW = va * pf * qty;
  const totalVA = va * qty;
  const phases = comp.phases === '3phase' ? '3Φ' : '1Φ';
  const fmtVA = v => v >= 1000 ? `${(v/1000).toLocaleString(undefined,{maximumFractionDigits:2})} kVA` : `${v.toLocaleString(undefined,{maximumFractionDigits:2})} VA`;
  const fmtW  = v => v >= 1000 ? `${(v/1000).toLocaleString(undefined,{maximumFractionDigits:2})} kW`  : `${v.toLocaleString(undefined,{maximumFractionDigits:2})} W`;
  const { bg, text, label } = PRIORITY_LABELS[comp.priority] ?? PRIORITY_LABELS.normal;

  return (
    <div className="flex items-center gap-3 border border-yellow-300 rounded-xl px-4 bg-white cursor-pointer
      group transition-all duration-200 hover:bg-yellow-50 hover:-translate-y-1 hover:shadow-md"
      style={{ minHeight: '70px', paddingTop: '10px', paddingBottom: '10px' }}>
      <div className="w-8 h-8 bg-yellow-50 group-hover:bg-yellow-100 rounded-lg flex items-center
        justify-center flex-shrink-0 transition-colors duration-200">
        <svg className="w-4 h-4 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
        </svg>
      </div>
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-1.5 mb-0.5 flex-wrap">
          <p className="font-semibold text-gray-900 text-sm truncate">{comp.component_type.name}</p>
          <span className={`text-xs px-1.5 py-0.5 rounded flex-shrink-0 ${bg} ${text}`}>{label}</span>
          <span className={`text-xs font-semibold px-1.5 py-0.5 rounded-full flex-shrink-0 ${
            comp.phases === '3phase' ? 'bg-violet-100 text-violet-700' : 'bg-blue-100 text-blue-700'
          }`}>{phases}</span>
          {qty > 1 && (
            <span className="text-xs font-semibold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 flex-shrink-0">×{qty}</span>
          )}
          {comp.needs_socket && (
            <span className="text-xs font-semibold px-1.5 py-0.5 rounded-full flex-shrink-0 bg-orange-100 text-orange-600 flex items-center gap-0.5">
              <svg className="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" />
              </svg>
              Socket
            </span>
          )}
          {comp.group_name && (
            <span className="text-xs font-semibold px-1.5 py-0.5 rounded-full flex-shrink-0 bg-teal-100 text-teal-700 flex items-center gap-0.5">
              <svg className="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0" />
              </svg>
              {comp.group_name}
            </span>
          )}
        </div>
        <p className="text-xs text-gray-400">{fmtVA(va)} ea &nbsp;·&nbsp; PF {pf} &nbsp;·&nbsp; {fmtVA(totalVA)} · {fmtW(realW)} real</p>
        {(comp.usage_season !== 'all' || comp.usage_day_type !== 'all' || (comp.usage_time_intervals?.length > 0)) && (
          <div className="flex gap-1 mt-0.5 flex-wrap">
            {comp.usage_season !== 'all' && (
              <span className={`text-xs px-1.5 py-0.5 rounded-full font-medium ${comp.usage_season === 'summer' ? 'bg-amber-100 text-amber-700' : 'bg-sky-100 text-sky-700'}`}>
                {comp.usage_season === 'summer' ? 'Summer' : 'Winter'}
              </span>
            )}
            {comp.usage_day_type !== 'all' && (
              <span className={`text-xs px-1.5 py-0.5 rounded-full font-medium ${comp.usage_day_type === 'weekday' ? 'bg-slate-100 text-slate-600' : 'bg-green-100 text-green-700'}`}>
                {comp.usage_day_type === 'weekday' ? 'Weekday' : 'Weekend'}
              </span>
            )}
            {comp.usage_time_intervals?.map((iv, i) => (
              <span key={i} className="text-xs px-1.5 py-0.5 rounded-full font-medium bg-indigo-100 text-indigo-700">
                {iv.start}–{iv.end}
              </span>
            ))}
          </div>
        )}
      </div>
      {canEdit && (
        <div className="flex items-center gap-1.5 flex-shrink-0">
          <button onClick={e => { e.stopPropagation(); onDuplicate(); }}
            className="text-xs font-medium text-gray-500 px-2.5 py-1 rounded-lg border border-gray-200 bg-white
              hover:border-violet-400 hover:text-violet-600 hover:bg-violet-50 transition-all duration-150">Dup</button>
          <button onClick={e => { e.stopPropagation(); onEdit(); }}
            className="text-xs font-medium text-gray-500 px-2.5 py-1 rounded-lg border border-gray-200 bg-white
              hover:border-blue-400 hover:text-blue-600 hover:bg-blue-50 transition-all duration-150">Edit</button>
          <button onClick={e => { e.stopPropagation(); onDelete(); }}
            className="text-xs font-medium text-gray-500 px-2.5 py-1 rounded-lg border border-gray-200 bg-white
              hover:border-red-300 hover:text-red-600 hover:bg-red-50 transition-all duration-150">Delete</button>
        </div>
      )}
    </div>
  );
}

function ScheduleRow({ label, value, options, onChange }) {
  return (
    <div>
      <p className="text-xs text-gray-400 mb-1">{label}</p>
      <div className="flex rounded-lg border border-gray-200 overflow-hidden">
        {options.map((opt, i) => (
          <button key={opt.value} type="button"
            onClick={() => onChange(opt.value)}
            className={`flex-1 py-1.5 text-xs font-medium transition-colors ${i > 0 ? 'border-l border-gray-200' : ''} ${
              value === opt.value ? opt.on : 'text-gray-500 hover:bg-gray-50'
            }`}>
            {opt.label}
          </button>
        ))}
      </div>
    </div>
  );
}

function ComponentModal({ title, form, onChange, onSubmit, onClose, submitLabel, componentTypes, existingGroups = [] }) {
  const inputRef   = useRef(null);
  const wrapperRef = useRef(null);
  const [showSuggestions, setShowSuggestions] = useState(false);
  const [showGroupList, setShowGroupList]     = useState(false);

  const intervals = form.usage_time_intervals ?? [{ start: '08:00', end: '18:00' }];
  function addInterval() {
    onChange({ ...form, usage_time_intervals: [...intervals, { start: '', end: '' }] });
  }
  function removeInterval(idx) {
    if (intervals.length <= 1) return;
    onChange({ ...form, usage_time_intervals: intervals.filter((_, i) => i !== idx) });
  }
  function updateInterval(idx, field, value) {
    onChange({ ...form, usage_time_intervals: intervals.map((iv, i) => i === idx ? { ...iv, [field]: value } : iv) });
  }

  useEffect(() => {
    function handleClickOutside(e) {
      if (wrapperRef.current && !wrapperRef.current.contains(e.target)) {
        setShowSuggestions(false);
      }
    }
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const filtered = form.name.trim()
    ? componentTypes.filter(t => t.name.toLowerCase().includes(form.name.toLowerCase()))
    : componentTypes;

  function selectType(type) {
    const next = { ...form, name: type.name };
    if (type.default_power)        next.power        = String(type.default_power);
    if (type.default_phases)       next.phases       = type.default_phases;
    if (type.default_power_factor) next.power_factor = String(type.default_power_factor);
    if (type.default_needs_socket    != null) next.needs_socket      = type.default_needs_socket;
    if (type.default_usage_season)          next.usage_season      = type.default_usage_season;
    if (type.default_usage_day_type)        next.usage_day_type    = type.default_usage_day_type;
    if (type.default_usage_time_intervals) {
      const raw = type.default_usage_time_intervals;
      next.usage_time_intervals = typeof raw === 'string' ? JSON.parse(raw) : raw;
    }
    onChange(next);
    setShowSuggestions(false);
    inputRef.current?.blur();
  }

  const intervalsOk = Array.isArray(form.usage_time_intervals) && form.usage_time_intervals.length >= 1 && form.usage_time_intervals.every(iv => iv.start && iv.end);
  const isValid = form.name.trim() && Number(form.power) > 0 && intervalsOk;

  return (
    <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm flex flex-col max-h-[90vh]">
        <h3 className="text-lg font-semibold text-gray-900 px-6 pt-6 pb-4 flex-shrink-0">{title}</h3>
        <div className="space-y-4 overflow-y-auto px-6 flex-1" style={{ minHeight: 0 }}>
          <div className="relative" ref={wrapperRef}>
            <label className="block text-sm font-medium text-gray-700 mb-1">Component Name</label>
            <input ref={inputRef} type="text" autoFocus value={form.name}
              onChange={e => { onChange({ ...form, name: e.target.value }); setShowSuggestions(true); }}
              onFocus={() => setShowSuggestions(true)}
              onKeyDown={e => {
                if (e.key === 'Escape') setShowSuggestions(false);
                if (e.key === 'Enter' && isValid) onSubmit();
              }}
              placeholder="Select or type a component name"
              className="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm
                focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" />
            {showSuggestions && filtered.length > 0 && (
              <ul className="absolute z-10 w-full bg-white border border-gray-200 rounded-lg shadow-lg mt-1 max-h-40 overflow-y-auto">
                {filtered.map(t => (
                  <li key={t.id} onMouseDown={() => selectType(t)}
                    className="px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 hover:text-blue-700 cursor-pointer">
                    <div className="flex items-center justify-between">
                      <span>{t.name}</span>
                      {t.is_preset && <span className="text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded">preset</span>}
                    </div>
                    {!t.is_preset && t.default_power && (
                      <p className="text-xs text-gray-400 mt-0.5">
                        {t.default_power} VA · {t.default_phases === '3phase' ? '3Φ' : '1Φ'} · PF {t.default_power_factor}
                      </p>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </div>
          {/* Priority */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Priority</label>
            <select value={form.priority ?? 'normal'} onChange={e => {
                const p = e.target.value;
                onChange({
                  ...form,
                  priority: p,
                  ...(p === 'critical' ? {
                    usage_season: 'all',
                    usage_day_type: 'all',
                    usage_time_intervals: [{ start: '00:00', end: '23:59' }],
                  } : {}),
                });
              }}
              className="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm
                focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white">
              <option value="normal">Normal</option>
              <option value="essential">Essential</option>
              <option value="critical">Critical</option>
            </select>
          </div>

          {/* Power + Quantity */}
          <div className="flex gap-3">
            <div className="flex-[2]">
              <label className="block text-sm font-medium text-gray-700 mb-1">Apparent Power (VA)</label>
              <input type="number" min="1" step="1" value={form.power}
                onChange={e => onChange({ ...form, power: e.target.value })}
                onKeyDown={e => e.key === 'Enter' && isValid && onSubmit()}
                placeholder="e.g. 60"
                className="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm
                  focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" />
            </div>
            <div className="flex-1">
              <label className="block text-sm font-medium text-gray-700 mb-1">Quantity</label>
              <input type="number" min="1" step="1" value={form.quantity}
                onChange={e => onChange({ ...form, quantity: e.target.value })}
                onKeyDown={e => e.key === 'Enter' && isValid && onSubmit()}
                placeholder="1"
                className="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm
                  focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" />
            </div>
          </div>

          {/* Phases + Power Factor */}
          <div className="flex gap-3">
            <div className="flex-1">
              <label className="block text-sm font-medium text-gray-700 mb-1">Phases</label>
              <div className="flex rounded-lg border border-gray-300 overflow-hidden">
                <button type="button" onClick={() => onChange({ ...form, phases: '1phase' })}
                  className={`flex-1 py-2.5 text-sm font-semibold transition-colors ${
                    form.phases === '1phase' ? 'bg-blue-500 text-white' : 'text-gray-600 hover:bg-gray-50'
                  }`}>1Φ</button>
                <button type="button" onClick={() => onChange({ ...form, phases: '3phase' })}
                  className={`flex-1 py-2.5 text-sm font-semibold transition-colors border-l border-gray-300 ${
                    form.phases === '3phase' ? 'bg-violet-500 text-white' : 'text-gray-600 hover:bg-gray-50'
                  }`}>3Φ</button>
              </div>
            </div>
            <div className="flex-1">
              <label className="block text-sm font-medium text-gray-700 mb-1">Power Factor</label>
              <input type="number" min="0.01" max="1" step="0.01" value={form.power_factor}
                onChange={e => onChange({ ...form, power_factor: e.target.value })}
                placeholder="0.01 – 1.00"
                className="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm
                  focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" />
            </div>
          </div>

          {/* Group */}
          <div className="relative">
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Load Group <span className="text-gray-400 font-normal text-xs">(optional)</span>
            </label>
            <div className="flex gap-2">
              <input type="text" value={form.group_name ?? ''}
                onChange={e => { onChange({ ...form, group_name: e.target.value }); setShowGroupList(true); }}
                onFocus={() => setShowGroupList(true)}
                onBlur={() => setTimeout(() => setShowGroupList(false), 150)}
                placeholder="Select or create a group…"
                className="flex-1 border border-gray-300 rounded-lg px-4 py-2.5 text-sm
                  focus:outline-none focus:ring-2 focus:ring-teal-400 focus:border-transparent" />
              {form.group_name && (
                <button type="button" onClick={() => onChange({ ...form, group_name: '' })}
                  className="px-3 py-2 rounded-lg border border-gray-200 text-gray-400 hover:text-gray-600 hover:bg-gray-50 text-sm">
                  ✕
                </button>
              )}
            </div>
            {showGroupList && (
              <ul className="absolute z-10 w-full bg-white border border-gray-200 rounded-lg shadow-lg mt-1 overflow-hidden">
                {existingGroups.filter(g => !form.group_name || g.toLowerCase().includes(form.group_name.toLowerCase())).map(g => (
                  <li key={g} onMouseDown={() => { onChange({ ...form, group_name: g }); setShowGroupList(false); }}
                    className="px-4 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 cursor-pointer flex items-center gap-2">
                    <svg className="w-3.5 h-3.5 text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                        d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0" />
                    </svg>
                    {g}
                  </li>
                ))}
                {form.group_name && !existingGroups.includes(form.group_name) && (
                  <li onMouseDown={() => setShowGroupList(false)}
                    className="px-4 py-2 text-sm text-teal-600 font-medium bg-teal-50 cursor-default">
                    Create &quot;{form.group_name}&quot;
                  </li>
                )}
                {existingGroups.length === 0 && !form.group_name && (
                  <li className="px-4 py-2 text-xs text-gray-400 cursor-default">
                    Type a name to create a new group
                  </li>
                )}
              </ul>
            )}
          </div>

          {/* Needs Socket */}
          <button type="button"
            onClick={() => onChange({ ...form, needs_socket: !form.needs_socket })}
            className={`w-full flex items-center justify-between px-4 py-3 rounded-xl border-2 transition-all duration-150 ${
              form.needs_socket
                ? 'border-orange-400 bg-orange-50'
                : 'border-gray-200 bg-white hover:border-gray-300'
            }`}>
            <div className="flex items-center gap-2.5">
              <svg className={`w-4 h-4 ${form.needs_socket ? 'text-orange-500' : 'text-gray-400'}`}
                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" />
              </svg>
              <span className={`text-sm font-medium ${form.needs_socket ? 'text-orange-700' : 'text-gray-600'}`}>
                Needs socket outlet
              </span>
            </div>
            <div className={`w-10 h-5 rounded-full transition-colors duration-200 flex items-center px-0.5 ${
              form.needs_socket ? 'bg-orange-400 justify-end' : 'bg-gray-200 justify-start'
            }`}>
              <div className="w-4 h-4 bg-white rounded-full shadow-sm" />
            </div>
          </button>

          {/* Usage Schedule */}
          <div className="space-y-2 border border-gray-200 rounded-xl p-3">
            <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide">Usage Schedule</p>
            {form.priority === 'critical' ? (
              <div className="flex items-center gap-2.5 px-3 py-2.5 bg-red-50 border border-red-100 rounded-lg">
                <svg className="w-4 h-4 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
                <p className="text-xs text-red-600 font-medium">Always on — critical loads run 24 / 7</p>
              </div>
            ) : (
            <>
            <ScheduleRow label="Season"
              value={form.usage_season}
              options={[
                { value: 'summer',  label: 'Summer',   on: 'bg-amber-400 text-white' },
                { value: 'winter',  label: 'Winter',   on: 'bg-sky-500 text-white' },
                { value: 'all',     label: 'All Year', on: 'bg-gray-400 text-white' },
              ]}
              onChange={v => onChange({ ...form, usage_season: v })} />
            <ScheduleRow label="Days"
              value={form.usage_day_type}
              options={[
                { value: 'weekday', label: 'Weekday',  on: 'bg-slate-500 text-white' },
                { value: 'weekend', label: 'Weekend',  on: 'bg-green-500 text-white' },
                { value: 'all',     label: 'All Days', on: 'bg-gray-400 text-white' },
              ]}
              onChange={v => onChange({ ...form, usage_day_type: v })} />
            <div>
              <div className="flex items-center justify-between mb-1.5">
                <p className="text-xs text-gray-400">Time Intervals</p>
                <button type="button" onClick={addInterval}
                  className="text-xs text-blue-500 font-medium hover:text-blue-700 flex items-center gap-0.5">
                  + Add
                </button>
              </div>
              <div className="space-y-1.5">
                {intervals.map((iv, i) => (
                  <div key={i} className="flex items-center gap-1.5">
                    <input type="time" value={iv.start}
                      onChange={e => updateInterval(i, 'start', e.target.value)}
                      className="flex-1 border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-blue-400" />
                    <span className="text-xs text-gray-400">–</span>
                    <input type="time" value={iv.end}
                      onChange={e => updateInterval(i, 'end', e.target.value)}
                      className="flex-1 border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-blue-400" />
                    <button type="button" onClick={() => removeInterval(i)}
                      disabled={intervals.length <= 1}
                      className="w-5 h-5 flex items-center justify-center rounded text-gray-400 hover:text-red-500 hover:bg-red-50 disabled:opacity-30 disabled:cursor-not-allowed transition-colors text-base leading-none">
                      ×
                    </button>
                  </div>
                ))}
              </div>
            </div>
            </>
            )}
          </div>
        </div>
        <div className="flex gap-3 px-6 py-5 flex-shrink-0 border-t border-gray-100">
          <button onClick={onClose} className="flex-1 border border-gray-300 text-gray-700 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-50 transition-colors">Cancel</button>
          <button onClick={onSubmit} disabled={!isValid}
            className="flex-1 bg-blue-600 text-white py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
            {submitLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
