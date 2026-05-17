import { useState, useEffect, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api/axios';
import { getNav, setNav } from '../utils/navContext';
import EntityComponents from '../components/EntityComponents';
import EntitySockets from '../components/EntitySockets';
import PowerBanner from '../components/PowerBanner';
import PowerSourcesBanner from '../components/PowerSourcesBanner';
import { downloadJson } from '../utils/downloadJson';
import BackupChoiceModal from '../components/BackupChoiceModal';
import ServerBackupsList from '../components/ServerBackupsList';
import EntityScheduleModal from '../components/EntityScheduleModal';
import ProjectSidebar from '../components/ProjectSidebar';

export default function BuildingPage() {
  const navigate = useNavigate();
  const { projectId, buildingId } = getNav();

  const [project, setProject]   = useState(null);
  const [building, setBuilding] = useState(null);
  const [floors, setFloors]     = useState([]);
  const [loading, setLoading]   = useState(true);

  const [showModal, setShowModal] = useState(false);
  const [newFloor, setNewFloor]   = useState({ name: '', area: '' });

  const [editingFloor, setEditingFloor] = useState(null);
  const [editForm, setEditForm]         = useState({ name: '', area: '' });

  const [editingName, setEditingName] = useState(false);
  const [nameInput, setNameInput]     = useState('');
  const [componentTypes, setComponentTypes] = useState([]);
  const [powerKey, setPowerKey]     = useState(0);
  const [powerSources, setPowerSources] = useState({ solar_computed: null, generator_computed: null, max_va: 0, total_va: 0 });

  const [showSchedule, setShowSchedule] = useState(false);
  const [backupTarget, setBackupTarget] = useState(null); // { type: 'building'|'floor', entity }
  const [restoreFile, setRestoreFile]   = useState(null);
  const [restoring, setRestoring]       = useState(false);
  const [restoreError, setRestoreError] = useState(null);
  const [dragOver, setDragOver]         = useState(false);
  const [confirmData, setConfirmData]   = useState(null);
  const [restoreTab, setRestoreTab]     = useState('computer');
  const fileInputRef = useRef(null);

  const userRole = project?.user_role ?? null;
  const canEdit  = userRole === 'admin' || userRole === 'main';
  const [openFloors, setOpenFloors] = useState(true);

  useEffect(() => {
    if (!buildingId) { navigate('/dashboard'); return; }
    Promise.all([
      api.get(`/api/buildings/${buildingId}/floors`),
      api.get('/api/component-types'),
    ]).then(([floorsRes, typesRes]) => {
        setBuilding(floorsRes.data.building);
        setProject(floorsRes.data.building.project);
        setNameInput(floorsRes.data.building.name);
        setFloors(floorsRes.data.data);
        setComponentTypes(typesRes.data.data);
      })
      .catch(() => navigate('/project'))
      .finally(() => setLoading(false));
  }, [buildingId]);

  async function saveName() {
    if (!nameInput.trim() || nameInput === building.name) { setEditingName(false); return; }
    const { data } = await api.put(
      `/api/projects/${projectId}/buildings/${building.id}`,
      { name: nameInput.trim() }
    );
    setBuilding(data.data);
    setEditingName(false);
  }

  async function handleAdd() {
    if (!newFloor.name.trim()) return;
    const { data } = await api.post(`/api/buildings/${building.id}/floors`, {
      name: newFloor.name.trim(),
      area: newFloor.area || 0,
    });
    setShowModal(false);
    setNav({ floorId: data.data.id });
    navigate('/project/building/floor');
  }

  function openEdit(floor) {
    setEditingFloor(floor);
    setEditForm({ name: floor.name, area: floor.area });
  }

  async function handleEdit() {
    if (!editForm.name.trim()) return;
    const { data } = await api.put(
      `/api/buildings/${building.id}/floors/${editingFloor.id}`,
      { name: editForm.name.trim(), area: editForm.area }
    );
    setFloors(floors.map(f => f.id === editingFloor.id ? data.data : f));
    setEditingFloor(null);
  }

  async function handleDelete(floorId) {
    await api.delete(`/api/buildings/${building.id}/floors/${floorId}`);
    setFloors(floors.filter(f => f.id !== floorId));
  }

  async function handleDuplicate(floor) {
    const { data } = await api.post(
      `/api/buildings/${building.id}/floors/${floor.id}/duplicate`
    );
    setFloors(prev => [data.data, ...prev]);
  }

  async function handleBackupDownload(type, entity) {
    try {
      const url = type === 'building' ? `/api/buildings/${entity.id}/backup` : `/api/floors/${entity.id}/backup`;
      const { data } = await api.get(url);
      downloadJson(data, `${entity.name.replace(/\s+/g, '-')}-backup.json`);
    } catch (err) {
      alert('Backup failed: ' + (err.response?.data?.message || err.message || 'Unknown error'));
    }
  }

  async function handleBackupToServer(type, entity) {
    const url = type === 'building' ? `/api/buildings/${entity.id}/save-backup` : `/api/floors/${entity.id}/save-backup`;
    await api.post(url);
  }

  function handleServerRestore(serverData) {
    const fileData = { raw: serverData, name: serverData.floor?.name || 'Server Backup' };
    setRestoreFile(fileData);
    setRestoreTab('computer');
    doRestore(false, fileData);
  }

  function loadRestoreFile(file) {
    if (!file) return;
    setRestoreError(null);
    const reader = new FileReader();
    reader.onload = e => {
      try {
        const parsed = JSON.parse(e.target.result);
        setRestoreFile({ raw: parsed, name: file.name });
      } catch {
        setRestoreError('Invalid JSON file.');
      }
    };
    reader.readAsText(file);
  }

  async function doRestore(overwrite = false, fileOverride = null) {
    const file = fileOverride || restoreFile;
    if (!file || !building) return;
    setRestoring(true);
    setRestoreError(null);
    try {
      const { data } = await api.post(
        `/api/buildings/${building.id}/floors/restore`,
        { data: file.raw, overwrite }
      );
      setFloors(prev => [data.data, ...prev]);
      setRestoreFile(null);
      setConfirmData(null);
      if (fileInputRef.current) fileInputRef.current.value = '';
    } catch (err) {
      if (err.response?.data?.conflict) {
        setConfirmData(err.response.data);
      } else {
        setRestoreError(err.response?.data?.message || 'Restore failed.');
      }
    } finally {
      setRestoring(false);
    }
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
          endpoint={building ? `/api/buildings/${building.id}/total-power` : null}
          refreshKey={powerKey}
          onData={d => setPowerSources({ solar_computed: d.solar_computed, generator_computed: d.generator_computed, max_va: d.max_va ?? 0, total_va: d.total_va ?? 0 })}
        />
        <PowerSourcesBanner
          entity={building}
          updateEndpoint={building ? `/api/projects/${projectId}/buildings/${building.id}` : null}
          onUpdate={updated => setBuilding(updated)}
          solarComputed={powerSources.solar_computed}
          maxLoad={powerSources.max_va}
          optimizedLoad={powerSources.total_va}
        />
      </div>

      <header className="bg-white border-b border-gray-200 px-6 py-4 flex items-center gap-4">
        <button onClick={() => navigate('/project')}
          className="text-gray-400 hover:text-gray-600 transition-colors p-1.5 rounded-lg hover:bg-gray-100">
          <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
          </svg>
        </button>
        <div className="flex-1">
          <div className="flex items-center gap-1.5 text-sm text-gray-400 mb-0.5 flex-wrap">
            <span onClick={() => navigate('/dashboard')} className="hover:text-blue-500 cursor-pointer transition-colors">Projects</span>
            <Chevron />
            <span onClick={() => navigate('/project')} className="hover:text-blue-500 cursor-pointer transition-colors">{project?.name}</span>
            <Chevron />
            <span className="text-gray-600 font-medium">{building?.name}</span>
          </div>

          {editingName ? (
            <div className="flex items-center gap-2 mt-0.5">
              <input autoFocus value={nameInput}
                onChange={e => setNameInput(e.target.value)}
                onKeyDown={e => { if (e.key === 'Enter') saveName(); if (e.key === 'Escape') setEditingName(false); }}
                className="text-lg font-semibold text-gray-900 border-b-2 border-blue-500 outline-none bg-transparent w-56" />
              <button onClick={saveName}
                className="text-xs text-blue-600 hover:text-blue-800 font-medium px-2 py-1 rounded hover:bg-blue-50 transition-colors">Save</button>
              <button onClick={() => setEditingName(false)}
                className="text-xs text-gray-400 hover:text-gray-600 font-medium px-2 py-1 rounded hover:bg-gray-100 transition-colors">Cancel</button>
            </div>
          ) : (
            <div className="flex items-center gap-2 mt-0.5">
              <h1 className="text-lg font-semibold text-gray-900">{building?.name}</h1>
              {canEdit && (
                <button onClick={() => { setNameInput(building.name); setEditingName(true); }}
                  className="text-gray-400 hover:text-blue-500 transition-colors p-1 rounded hover:bg-blue-50">
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                      d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                  </svg>
                </button>
              )}
            </div>
          )}
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
        {canEdit && <button onClick={() => setBackupTarget({ type: 'building', entity: building })}
          className="flex items-center gap-1.5 text-xs font-medium text-gray-500 px-3 py-1.5
            rounded-lg border border-gray-200 bg-white hover:border-emerald-400 hover:text-emerald-600
            hover:bg-emerald-50 transition-all duration-150 flex-shrink-0">
          <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
          </svg>
          Backup Building
        </button>}
      </header>

      <main className="px-8 sm:px-12 py-8 flex gap-6 items-start">
        <ProjectSidebar />
        <div className="flex-1 min-w-0">

        <section>
          <div className={`flex items-center justify-between ${openFloors ? 'mb-4' : 'mb-0'}`}>
            <button onClick={() => setOpenFloors(o => !o)} className="flex items-center gap-2 group">
              <svg className={`w-4 h-4 text-gray-400 transition-transform duration-200 ${openFloors ? 'rotate-90' : ''}`}
                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M9 5l7 7-7 7" />
              </svg>
              <h2 className="text-base font-semibold text-gray-900 group-hover:text-gray-700">
                Floors
                <span className="ml-2 text-xs font-normal text-gray-400">({floors.length})</span>
              </h2>
            </button>
          </div>
          {openFloors && <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            {floors.length === 0 ? (
              <div className="py-14 text-center text-gray-400">
                <svg className="w-10 h-10 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M3 10h18M3 14h18M3 6h18M3 18h18" />
                </svg>
                <p className="text-sm font-medium">No floors yet. Add your first one!</p>
              </div>
            ) : (
              <div className="flex flex-col gap-2 p-3">
                {floors.map(floor => (
                  <FloorRow
                    key={floor.id}
                    floor={floor}
                    canEdit={canEdit}
                    onOpen={() => { setNav({ floorId: floor.id }); navigate('/project/building/floor'); }}
                    onEdit={() => openEdit(floor)}
                    onDelete={() => handleDelete(floor.id)}
                    onBackup={() => setBackupTarget({ type: 'floor', entity: floor })}
                    onDuplicate={() => handleDuplicate(floor)}
                  />
                ))}
              </div>
            )}
          </div>}
        </section>

        <EntityComponents
          endpoint={building ? `/api/buildings/${building.id}/components` : null}
          componentTypes={componentTypes}
          onTypesUpdated={t => setComponentTypes(prev => [...prev, t])}
          onChanged={() => setPowerKey(k => k + 1)}
          canEdit={canEdit}
        />
        <EntitySockets
          endpoint={building ? `/api/buildings/${building.id}/sockets` : null}
          onChanged={() => setPowerKey(k => k + 1)}
          canEdit={canEdit}
        />
        </div>

        {canEdit && (
          <aside className="w-72 flex-shrink-0 flex flex-col gap-4">
            <div className="bg-white rounded-xl shadow-lg p-4">
              <h2 className="text-sm font-semibold text-gray-900 mb-3">Add Floor</h2>
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
                  <p className="font-semibold text-xs">Add New Floor</p>
                  <p className="text-xs text-blue-400 group-hover:text-blue-500 transition-colors">Add a floor to this building</p>
                </div>
              </button>
            </div>

            <div className="bg-white rounded-xl shadow-lg p-4">
              <h2 className="text-sm font-semibold text-gray-900 mb-3">Restore Floor</h2>
              <div className="flex gap-1 mb-3 bg-gray-100 rounded-lg p-1">
                <button onClick={() => setRestoreTab('computer')}
                  className={`flex-1 px-2 py-1.5 text-xs font-medium rounded-md transition-colors ${restoreTab === 'computer' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'}`}>
                  Computer
                </button>
                <button onClick={() => setRestoreTab('server')}
                  className={`flex-1 px-2 py-1.5 text-xs font-medium rounded-md transition-colors ${restoreTab === 'server' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'}`}>
                  Server
                </button>
              </div>
              {restoreTab === 'computer' ? (
                <>
                  <div
                    onDragOver={e => { e.preventDefault(); setDragOver(true); }}
                    onDragLeave={() => setDragOver(false)}
                    onDrop={e => { e.preventDefault(); setDragOver(false); loadRestoreFile(e.dataTransfer.files[0]); }}
                    onClick={() => fileInputRef.current?.click()}
                    className={`border-2 border-dashed rounded-xl px-4 py-5 text-center cursor-pointer transition-all duration-200
                      ${dragOver ? 'border-emerald-500 bg-emerald-50' : 'border-emerald-300 hover:border-emerald-500 hover:bg-emerald-50'}`}>
                    <input ref={fileInputRef} type="file" accept=".json" className="hidden"
                      onChange={e => loadRestoreFile(e.target.files[0])} />
                    <svg className="w-7 h-7 mx-auto mb-1.5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
                        d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10" />
                    </svg>
                    <p className="text-xs font-medium text-emerald-700">Drop backup or click to browse</p>
                    <p className="text-xs text-emerald-500 mt-0.5">Accepts .json files</p>
                  </div>
                  {restoreFile && (
                    <div className="mt-2 flex items-center justify-between bg-emerald-50 border border-emerald-200 rounded-xl px-3 py-2">
                      <p className="text-xs font-semibold text-gray-900 truncate min-w-0">
                        {restoreFile.raw?.floor?.name ?? restoreFile.raw?.name ?? restoreFile.name}
                      </p>
                      <div className="flex items-center gap-1.5 ml-2 flex-shrink-0">
                        <button onClick={() => { setRestoreFile(null); setRestoreError(null); if (fileInputRef.current) fileInputRef.current.value = ''; }}
                          className="text-xs text-gray-400 hover:text-gray-600 px-1.5 py-0.5 rounded hover:bg-gray-100 transition-colors">Clear</button>
                        <button onClick={() => doRestore(false)} disabled={restoring}
                          className="text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-700 px-3 py-1 rounded-lg transition-colors disabled:opacity-50">
                          {restoring ? '…' : 'Restore'}
                        </button>
                      </div>
                    </div>
                  )}
                  {restoreError && <p className="mt-2 text-xs text-red-500">{restoreError}</p>}
                </>
              ) : (
                <ServerBackupsList
                  projectId={project?.id}
                  entityType="floor"
                  onRestore={handleServerRestore}
                />
              )}
            </div>
          </aside>
        )}
      </main>

      {showModal && (
        <Modal title="New Floor" form={newFloor} onChange={setNewFloor}
          onSubmit={handleAdd}
          onClose={() => { setShowModal(false); setNewFloor({ name: '', area: '' }); }}
          submitLabel="Add Floor" />
      )}
      {editingFloor && (
        <Modal title="Edit Floor" form={editForm} onChange={setEditForm}
          onSubmit={handleEdit} onClose={() => setEditingFloor(null)}
          submitLabel="Save Changes" />
      )}
      {confirmData && (
        <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6">
            <div className="w-12 h-12 bg-amber-100 rounded-full flex items-center justify-center mx-auto mb-4">
              <svg className="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
              </svg>
            </div>
            <h3 className="text-base font-semibold text-gray-900 text-center mb-2">Floor Already Exists</h3>
            <p className="text-sm text-gray-500 text-center mb-6">{confirmData.message}</p>
            <div className="flex gap-3">
              <button onClick={() => setConfirmData(null)}
                className="flex-1 border border-gray-300 text-gray-700 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-50 transition-colors">Cancel</button>
              <button onClick={() => doRestore(true)}
                className="flex-1 bg-amber-500 hover:bg-amber-600 text-white py-2.5 rounded-lg text-sm font-semibold transition-colors">Overwrite</button>
            </div>
          </div>
        </div>
      )}
      {backupTarget && (
        <BackupChoiceModal
          entityName={backupTarget.entity.name}
          onDownload={() => handleBackupDownload(backupTarget.type, backupTarget.entity)}
          onSaveToServer={() => handleBackupToServer(backupTarget.type, backupTarget.entity)}
          onClose={() => setBackupTarget(null)}
        />
      )}
      {showSchedule && building && (
        <EntityScheduleModal
          entity={building}
          updateEndpoint={`/api/projects/${projectId}/buildings/${building.id}`}
          parentSchedule={project}
          parentLabel="Project"
          onUpdate={updated => setBuilding(updated)}
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

function FloorRow({ floor, canEdit, onOpen, onEdit, onDelete, onBackup, onDuplicate }) {
  return (
    <div onClick={onOpen} className="flex items-center justify-between px-5 py-4 border border-blue-300 rounded-xl
      cursor-pointer group transition-all duration-200 hover:bg-blue-50 hover:-translate-y-1 hover:shadow-md">
      <div className="flex items-center gap-3 min-w-0 w-52">
        <div className="w-9 h-9 bg-blue-50 group-hover:bg-blue-100 rounded-lg flex items-center
          justify-center flex-shrink-0 transition-colors duration-150">
          <svg className="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10h18M3 14h18M3 6h18M3 18h18" />
          </svg>
        </div>
        <span className="font-medium text-gray-900 text-sm truncate">{floor.name}</span>
      </div>
      <div className="flex items-center gap-1.5 w-28 text-sm text-gray-500">
        <svg className="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
            d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
        </svg>
        <span>{floor.rooms_count ?? 0} room{(floor.rooms_count ?? 0) !== 1 ? 's' : ''}</span>
      </div>
      <div className="flex items-center gap-1.5 w-32 text-sm text-gray-500">
        <svg className="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
            d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
        </svg>
        <span>{Number(floor.area).toLocaleString()} m²</span>
      </div>
      <div className="text-xs text-gray-400 w-28 hidden md:block">
        {new Date(floor.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
      </div>
      <div className="flex items-center gap-2 flex-shrink-0">
        {canEdit && (
          <>
            <button onClick={e => { e.stopPropagation(); onBackup(); }}
              className="flex items-center gap-1.5 text-xs font-medium text-gray-500 px-3 py-1.5
                rounded-lg border border-gray-200 bg-white hover:border-emerald-400 hover:text-emerald-600
                hover:bg-emerald-50 transition-all duration-150">
              <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
              </svg>
              Backup
            </button>
            <button onClick={e => { e.stopPropagation(); onDuplicate(); }}
              className="flex items-center gap-1.5 text-xs font-medium text-gray-500 px-3 py-1.5
                rounded-lg border border-gray-200 bg-white hover:border-violet-400 hover:text-violet-600
                hover:bg-violet-50 transition-all duration-150">
              <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
              </svg>
              Duplicate
            </button>
            <button onClick={e => { e.stopPropagation(); onEdit(); }}
              className="flex items-center gap-1.5 text-xs font-medium text-gray-500 px-3 py-1.5
                rounded-lg border border-gray-200 bg-white hover:border-blue-400 hover:text-blue-600
                hover:bg-blue-50 transition-all duration-150">
              <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
              </svg>
              Edit
            </button>
            <button onClick={e => { e.stopPropagation(); onDelete(); }}
              className="flex items-center gap-1.5 text-xs font-medium text-gray-500 px-3 py-1.5
                rounded-lg border border-gray-200 bg-white hover:border-red-300 hover:text-red-600
                hover:bg-red-50 transition-all duration-150">
              <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
              </svg>
              Delete
            </button>
          </>
        )}
      </div>
    </div>
  );
}

function Modal({ title, form, onChange, onSubmit, onClose, submitLabel }) {
  return (
    <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6">
        <h3 className="text-lg font-semibold text-gray-900 mb-5">{title}</h3>
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Floor Name</label>
            <input type="text" autoFocus value={form.name}
              onChange={e => onChange({ ...form, name: e.target.value })}
              onKeyDown={e => e.key === 'Enter' && onSubmit()}
              placeholder="e.g. Floor 1"
              className="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm
                focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Area (m²)</label>
            <input type="number" min="0.01" step="0.01" value={form.area}
              onChange={e => onChange({ ...form, area: e.target.value })}
              placeholder="e.g. 20"
              className="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm
                focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" />
          </div>
        </div>
        <div className="flex gap-3 mt-6">
          <button onClick={onClose}
            className="flex-1 border border-gray-300 text-gray-700 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-50 transition-colors">Cancel</button>
          <button onClick={onSubmit} disabled={!form.name.trim() || Number(form.area) <= 0}
            className="flex-1 bg-blue-600 text-white py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
            {submitLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
