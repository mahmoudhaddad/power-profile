import { useState, useEffect, useRef } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import api from '../api/axios';
import { downloadJson } from '../utils/downloadJson';
import EntityComponents from '../components/EntityComponents';
import EntitySockets from '../components/EntitySockets';
import PowerBanner from '../components/PowerBanner';
import PowerSourcesBanner from '../components/PowerSourcesBanner';
import ProjectMembersModal from '../components/ProjectMembersModal';
import BackupChoiceModal from '../components/BackupChoiceModal';
import ServerBackupsList from '../components/ServerBackupsList';
import TimeScheduleModal from '../components/TimeScheduleModal';
import ProjectSidebar from '../components/ProjectSidebar';
import ErrorBoundary from '../components/ErrorBoundary';

export default function ProjectPage() {
  const navigate = useNavigate();
  const { projectId } = useParams();

  const [project, setProject]     = useState(null);
  const [buildings, setBuildings] = useState([]);
  const [loading, setLoading]     = useState(true);

  const [showModal, setShowModal]     = useState(false);
  const [newBuilding, setNewBuilding] = useState({ name: '', area: '' });
  const [addFieldErrors, setAddFieldErrors] = useState({});

  const [editingBuilding, setEditingBuilding] = useState(null);
  const [editForm, setEditForm]               = useState({ name: '', area: '' });
  const [editFieldErrors, setEditFieldErrors] = useState({});

  const [editingName, setEditingName] = useState(false);
  const [nameInput, setNameInput]     = useState('');
  const [componentTypes, setComponentTypes] = useState([]);
  const [powerKey, setPowerKey] = useState(0);
  const [powerSources, setPowerSources] = useState({ solar_computed: null, generator_computed: null, max_va: 0, total_va: 0 });
  const [showMembers, setShowMembers]   = useState(false);
  const [showSchedule, setShowSchedule]         = useState(false);
  const [showTimeSchedule, setShowTimeSchedule] = useState(false);

  const [backupTarget, setBackupTarget] = useState(null);
  const [restoreFile, setRestoreFile]   = useState(null);
  const [restoring, setRestoring]       = useState(false);
  const [restoreError, setRestoreError] = useState(null);
  const [dragOver, setDragOver]         = useState(false);
  const [confirmData, setConfirmData]   = useState(null);
  const [restoreTab, setRestoreTab]     = useState('computer');
  const fileInputRef = useRef(null);

  const userRole = project?.user_role ?? null;
  const canEdit  = userRole === 'admin' || userRole === 'main';
  const [openBuildings, setOpenBuildings] = useState(true);

  useEffect(() => {
    if (!projectId) { navigate('/dashboard'); return; }
    Promise.all([
      api.get(`/api/projects/${projectId}/buildings`),
      api.get('/api/component-types'),
    ]).then(([buildingsRes, typesRes]) => {
        setProject(buildingsRes.data.project);
        setNameInput(buildingsRes.data.project.name);
        setBuildings(buildingsRes.data.data);
        setComponentTypes(typesRes.data.data);
      })
      .catch(() => navigate('/dashboard'))
      .finally(() => setLoading(false));
  }, [projectId]);

  async function saveName() {
    if (!nameInput.trim() || nameInput === project.name) { setEditingName(false); return; }
    const { data } = await api.put(`/api/projects/${project.id}`, { name: nameInput.trim() });
    setProject(data.data);
    setEditingName(false);
  }

  async function handleAdd() {
    if (!newBuilding.name.trim()) return;
    try {
      const { data } = await api.post(`/api/projects/${project.id}/buildings`, {
        name: newBuilding.name.trim(),
        area: newBuilding.area || 0,
      });
      setShowModal(false);
      navigate(`/projects/${projectId}/buildings/${data.data.id}`);
    } catch (err) {
      if (err.response?.status === 422) setAddFieldErrors(err.response.data.errors ?? {});
    }
  }

  function openEdit(building) {
    setEditingBuilding(building);
    setEditForm({ name: building.name, area: building.area });
  }

  async function handleEdit() {
    if (!editForm.name.trim()) return;
    try {
      const { data } = await api.put(
        `/api/projects/${project.id}/buildings/${editingBuilding.id}`,
        { name: editForm.name.trim(), area: editForm.area }
      );
      setBuildings(buildings.map(b => b.id === editingBuilding.id ? data.data : b));
      setEditingBuilding(null);
    } catch (err) {
      if (err.response?.status === 422) setEditFieldErrors(err.response.data.errors ?? {});
    }
  }

  async function handleDelete(buildingId) {
    await api.delete(`/api/projects/${project.id}/buildings/${buildingId}`);
    setBuildings(buildings.filter(b => b.id !== buildingId));
  }

  async function handleDuplicate(building) {
    const { data } = await api.post(
      `/api/projects/${project.id}/buildings/${building.id}/duplicate`
    );
    setBuildings(prev => [data.data, ...prev]);
  }

  async function handleDuplicateFromSuggestion(buildingId) {
    const { data } = await api.post(
      `/api/projects/${project.id}/buildings/${buildingId}/duplicate`
    );
    setBuildings(prev => [data.data, ...prev]);
    setShowModal(false);
    setNewBuilding({ name: '', area: '' });
  }

  async function handleBackupDownload(building) {
    try {
      const { data } = await api.get(`/api/buildings/${building.id}/backup`);
      downloadJson(data, `${building.name.replace(/\s+/g, '-')}-backup.json`);
    } catch (err) {
      alert('Backup failed: ' + (err.response?.data?.message || err.message || 'Unknown error'));
    }
  }

  async function handleBackupToServer(building) {
    await api.post(`/api/buildings/${building.id}/save-backup`);
  }

  function handleServerRestore(serverData) {
    const fileData = { raw: serverData, name: serverData.building?.name || 'Server Backup' };
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
    if (!file) return;
    setRestoring(true);
    setRestoreError(null);
    try {
      const { data } = await api.post(
        `/api/projects/${project.id}/buildings/restore`,
        { data: file.raw, overwrite }
      );
      setBuildings(prev => [data.data, ...prev]);
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
        <ErrorBoundary label="power summary">
          <PowerBanner
            endpoint={project ? `/api/projects/${project.id}/total-power` : null}
            refreshKey={powerKey}
            reportTitle={project ? `${project.name} — Power Analysis` : undefined}
            onData={d => setPowerSources({ solar_computed: d.solar_computed, generator_computed: d.generator_computed, max_va: d.max_va ?? 0, total_va: d.total_va ?? 0 })}
          />
        </ErrorBoundary>
        <ErrorBoundary label="power sources">
          <PowerSourcesBanner
            entity={project}
            updateEndpoint={project ? `/api/projects/${project.id}` : null}
            onUpdate={updated => setProject(updated)}
            solarComputed={buildings.reduce((sum, b) => sum + Number(b.area), 0) * 0.17 * 1000 * 0.75}
            buildingsSolarSum={buildings.reduce((sum, b) => sum + Number(b.existing_solar_power ?? 0), 0)}
            generatorEndpoint={project ? `/api/projects/${project.id}/generator-lines` : null}
            utilityEndpoint={project ? `/api/projects/${project.id}/utility-lines` : null}
            maxLoad={powerSources.max_va}
            optimizedLoad={powerSources.total_va}
          />
        </ErrorBoundary>
      </div>

      <header className="bg-white border-b border-gray-200 px-6 py-4 flex items-center gap-4">
        <button onClick={() => navigate('/dashboard')}
          className="text-gray-400 hover:text-gray-600 transition-colors p-1.5 rounded-lg hover:bg-gray-100">
          <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
          </svg>
        </button>
        <div className="flex-1">
          <div className="flex items-center gap-2 text-sm text-gray-400 mb-0.5">
            <span onClick={() => navigate('/dashboard')}
              className="hover:text-blue-500 cursor-pointer transition-colors">Projects</span>
            <Chevron />
            <span className="text-gray-600 font-medium">{project?.name}</span>
            {userRole && userRole !== 'admin' && (
              <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ml-1 ${
                userRole === 'main' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-500'
              }`}>
                {userRole === 'main' ? 'Main User' : 'View Only'}
              </span>
            )}
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
              <h1 className="text-lg font-semibold text-gray-900">{project?.name}</h1>
              {canEdit && (
                <button onClick={() => { setNameInput(project.name); setEditingName(true); }}
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

        {/* Schedule settings */}
        {canEdit && (
          <button onClick={() => setShowSchedule(true)}
            className="flex items-center gap-1.5 text-sm font-medium text-gray-600 px-3 py-2
              rounded-lg border border-gray-200 hover:border-indigo-400 hover:text-indigo-600
              hover:bg-indigo-50 transition-all duration-150">
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            Schedule
          </button>
        )}

        {/* Time schedule chart */}
        <button onClick={() => setShowTimeSchedule(true)}
          className="flex items-center gap-1.5 text-sm font-medium text-gray-600 px-3 py-2
            rounded-lg border border-gray-200 hover:border-violet-400 hover:text-violet-600
            hover:bg-violet-50 transition-all duration-150">
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
          </svg>
          Time Schedule
        </button>

        {/* Members button — admin only */}
        {userRole === 'admin' && (
          <button onClick={() => setShowMembers(true)}
            className="flex items-center gap-1.5 text-sm font-medium text-gray-600 px-3 py-2
              rounded-lg border border-gray-200 hover:border-blue-400 hover:text-blue-600
              hover:bg-blue-50 transition-all duration-150">
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            Members
          </button>
        )}
      </header>

      <main className="px-8 sm:px-12 py-8 flex gap-6 items-start">
        <ProjectSidebar />
        <div className="flex-1 min-w-0">

        <section>
          <div className={`flex items-center justify-between ${openBuildings ? 'mb-4' : 'mb-0'}`}>
            <button onClick={() => setOpenBuildings(o => !o)} className="flex items-center gap-2 group">
              <svg className={`w-4 h-4 text-gray-400 transition-transform duration-200 ${openBuildings ? 'rotate-90' : ''}`}
                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M9 5l7 7-7 7" />
              </svg>
              <h2 className="text-base font-semibold text-gray-900 group-hover:text-gray-700">
                Buildings
                <span className="ml-2 text-xs font-normal text-gray-400">({buildings.length})</span>
              </h2>
            </button>
          </div>

          {openBuildings && <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            {buildings.length === 0 ? (
              <div className="py-14 text-center text-gray-400">
                <svg className="w-10 h-10 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
                    d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                </svg>
                <p className="text-sm font-medium">No buildings yet. Add your first one!</p>
              </div>
            ) : (
              <div className="flex flex-col gap-2 p-3">
                {buildings.map(building => (
                  <BuildingRow
                    key={building.id}
                    building={building}
                    canEdit={canEdit}
                    onOpen={() => navigate(`/projects/${projectId}/buildings/${building.id}`)}
                    onEdit={() => openEdit(building)}
                    onDelete={() => handleDelete(building.id)}
                    onBackup={() => setBackupTarget(building)}
                    onDuplicate={() => handleDuplicate(building)}
                  />
                ))}
              </div>
            )}
          </div>}
        </section>

        <ErrorBoundary label="components">
          <EntityComponents
            endpoint={project ? `/api/projects/${project.id}/components` : null}
            componentTypes={componentTypes}
            onTypesUpdated={t => setComponentTypes(prev => [...prev, t])}
            onChanged={() => setPowerKey(k => k + 1)}
            canEdit={canEdit}
          />
        </ErrorBoundary>

        <ErrorBoundary label="sockets">
          <EntitySockets
            endpoint={project ? `/api/projects/${project.id}/sockets` : null}
            onChanged={() => setPowerKey(k => k + 1)}
            canEdit={canEdit}
          />
        </ErrorBoundary>
        </div>

        {canEdit && (
          <aside className="w-72 flex-shrink-0 flex flex-col gap-4">
            <div className="bg-white rounded-xl shadow-lg p-4">
              <h2 className="text-sm font-semibold text-gray-900 mb-3">Add Building</h2>
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
                  <p className="font-semibold text-xs">Add New Building</p>
                  <p className="text-xs text-blue-400 group-hover:text-blue-500 transition-colors">Add a building to this project</p>
                </div>
              </button>
            </div>

            <div className="bg-white rounded-xl shadow-lg p-4">
              <h2 className="text-sm font-semibold text-gray-900 mb-3">Restore Building</h2>
              <div className="flex gap-1 mb-3 bg-gray-100 p-1 rounded-lg">
                {['computer', 'server'].map(tab => (
                  <button key={tab} onClick={() => setRestoreTab(tab)}
                    className={`flex-1 px-2 py-1.5 rounded-md text-xs font-medium transition-colors ${
                      restoreTab === tab ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'
                    }`}>
                    {tab === 'computer' ? 'Computer' : 'Server'}
                  </button>
                ))}
              </div>
              {restoreTab === 'computer' && <>
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
                {restoreFile && restoreTab === 'computer' && (
                  <div className="mt-2 flex items-center justify-between bg-emerald-50 border border-emerald-200 rounded-xl px-3 py-2">
                    <p className="text-xs font-semibold text-gray-900 truncate min-w-0">
                      {restoreFile.raw?.building?.name ?? restoreFile.raw?.name ?? restoreFile.name}
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
              </>}
              {restoreTab === 'server' && project && (
                <ErrorBoundary label="backups">
                  <ServerBackupsList
                    projectId={project.id}
                    entityType="building"
                    entityId={null}
                    onRestore={handleServerRestore}
                  />
                </ErrorBoundary>
              )}
              {restoreError && <p className="mt-2 text-xs text-red-500">{restoreError}</p>}
            </div>
          </aside>
        )}
      </main>

      {showModal && (
        <Modal title="New Building" form={newBuilding} onChange={setNewBuilding}
          onSubmit={handleAdd}
          onClose={() => { setShowModal(false); setNewBuilding({ name: '', area: '' }); setAddFieldErrors({}); }}
          submitLabel="Add Building"
          suggestions={buildings}
          nameLabel="Building Name"
          namePlaceholder="e.g. Block A"
          onDuplicateFrom={handleDuplicateFromSuggestion}
          fieldErrors={addFieldErrors}
          onClearError={f => setAddFieldErrors(p => ({ ...p, [f]: null }))} />
      )}
      {editingBuilding && (
        <Modal title="Edit Building" form={editForm} onChange={setEditForm}
          onSubmit={handleEdit} onClose={() => { setEditingBuilding(null); setEditFieldErrors({}); }}
          submitLabel="Save Changes"
          fieldErrors={editFieldErrors}
          onClearError={f => setEditFieldErrors(p => ({ ...p, [f]: null }))} />
      )}
      {showMembers && project && (
        <ProjectMembersModal projectId={project.id} onClose={() => setShowMembers(false)} />
      )}
      {showSchedule && project && (
        <ProjectScheduleModal
          project={project}
          onSave={updated => { setProject(updated); setShowSchedule(false); }}
          onClose={() => setShowSchedule(false)}
        />
      )}
      {showTimeSchedule && project && (
        <TimeScheduleModal
          project={project}
          projectId={projectId}
          onClose={() => setShowTimeSchedule(false)}
        />
      )}
      {backupTarget && (
        <BackupChoiceModal
          entityName={backupTarget.name}
          onDownload={() => { handleBackupDownload(backupTarget); setBackupTarget(null); }}
          onSaveToServer={() => handleBackupToServer(backupTarget)}
          onClose={() => setBackupTarget(null)}
        />
      )}
      {confirmData && (
        <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6">
            <div className="w-12 h-12 bg-amber-100 rounded-full flex items-center justify-center mx-auto mb-4">
              <svg className="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
              </svg>
            </div>
            <h3 className="text-base font-semibold text-gray-900 text-center mb-2">Building Already Exists</h3>
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
    </div>
  );
}

function Chevron() {
  return (
    <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
    </svg>
  );
}

function BuildingRow({ building, canEdit, onOpen, onEdit, onDelete, onBackup, onDuplicate }) {
  return (
    <div onClick={onOpen} className="flex items-center justify-between px-5 py-4 border border-blue-300 rounded-xl
      cursor-pointer group transition-all duration-200 hover:bg-blue-50 hover:-translate-y-1 hover:shadow-md">
      <div className="flex items-center gap-3 min-w-0 w-52">
        <div className="w-9 h-9 bg-blue-50 group-hover:bg-blue-100 rounded-lg flex items-center
          justify-center flex-shrink-0 transition-colors duration-150">
          <svg className="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
          </svg>
        </div>
        <span className="font-medium text-gray-900 text-sm truncate">{building.name}</span>
      </div>
      <div className="flex items-center gap-1.5 w-28 text-sm text-gray-500">
        <svg className="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10h18M3 14h18M3 6h18M3 18h18" />
        </svg>
        <span>{building.floors_count} floor{building.floors_count !== 1 ? 's' : ''}</span>
      </div>
      <div className="flex items-center gap-1.5 w-32 text-sm text-gray-500">
        <svg className="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
            d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
        </svg>
        <span>{Number(building.area).toLocaleString()} m²</span>
      </div>
      <div className="text-xs text-gray-400 w-28 hidden md:block">
        {new Date(building.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
      </div>
      <div className="flex items-center gap-2 flex-shrink-0">
        {canEdit && (
        <button onClick={e => { e.stopPropagation(); onBackup(); }}
          className="flex items-center gap-1.5 text-xs font-medium text-gray-500 px-3 py-1.5
            rounded-lg border border-gray-200 bg-white hover:border-emerald-400 hover:text-emerald-600
            hover:bg-emerald-50 transition-all duration-150">
          <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
          </svg>
          Backup
        </button>)}
        {canEdit && (
          <>
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

const DAYS = [
  { key: 'monday',    short: 'Mo' },
  { key: 'tuesday',   short: 'Tu' },
  { key: 'wednesday', short: 'We' },
  { key: 'thursday',  short: 'Th' },
  { key: 'friday',    short: 'Fr' },
  { key: 'saturday',  short: 'Sa' },
  { key: 'sunday',    short: 'Su' },
];

const MONTHS = [
  { v: '01', l: 'Jan' }, { v: '02', l: 'Feb' }, { v: '03', l: 'Mar' },
  { v: '04', l: 'Apr' }, { v: '05', l: 'May' }, { v: '06', l: 'Jun' },
  { v: '07', l: 'Jul' }, { v: '08', l: 'Aug' }, { v: '09', l: 'Sep' },
  { v: '10', l: 'Oct' }, { v: '11', l: 'Nov' }, { v: '12', l: 'Dec' },
];

const DEFAULT_WORK_DAYS      = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
const DEFAULT_TIME_INTERVALS = [{ start: '08:00', end: '17:00' }];

function mmdd(month, day) {
  if (!month || !day) return '';
  return `${month}-${String(day).padStart(2, '0')}`;
}
function parseMmdd(str) {
  if (!str || !str.includes('-')) return { month: '', day: '' };
  const [m, d] = str.split('-');
  return { month: m, day: String(parseInt(d, 10)) };
}
function fmtSeasonInterval(iv) {
  const f = parseMmdd(iv.from);
  const t = parseMmdd(iv.to);
  const mLabel = v => MONTHS.find(m => m.v === v)?.l ?? v;
  if (!f.month || !t.month) return '—';
  return `${mLabel(f.month)} ${f.day} → ${mLabel(t.month)} ${t.day}`;
}

function ProjectScheduleModal({ project, onSave, onClose }) {
  const [workDays,  setWorkDays]  = useState(project.work_days            ?? DEFAULT_WORK_DAYS);
  const [timeIvs,   setTimeIvs]   = useState(project.work_time_intervals  ?? DEFAULT_TIME_INTERVALS);
  const [seasonIvs, setSeasonIvs] = useState(project.working_season_intervals ?? []);
  const [saving,    setSaving]    = useState(false);

  function toggleDay(day) {
    setWorkDays(prev => prev.includes(day) ? prev.filter(d => d !== day) : [...prev, day]);
  }

  // time intervals
  function addTimeIv()         { setTimeIvs(prev => [...prev, { start: '', end: '' }]); }
  function removeTimeIv(i)     { if (timeIvs.length <= 1) return; setTimeIvs(prev => prev.filter((_, j) => j !== i)); }
  function updateTimeIv(i, f, v) { setTimeIvs(prev => prev.map((iv, j) => j === i ? { ...iv, [f]: v } : iv)); }

  // season intervals
  function addSeasonIv()       { setSeasonIvs(prev => [...prev, { from: '', to: '' }]); }
  function removeSeasonIv(i)   { setSeasonIvs(prev => prev.filter((_, j) => j !== i)); }
  function updateSeasonIv(i, endpoint, field, value) {
    setSeasonIvs(prev => prev.map((iv, j) => {
      if (j !== i) return iv;
      const parsed = parseMmdd(iv[endpoint]);
      const updated = { ...parsed, [field]: value };
      return { ...iv, [endpoint]: mmdd(updated.month, updated.day) };
    }));
  }

  const weekdays    = DAYS.filter(d => workDays.includes(d.key));
  const weekends    = DAYS.filter(d => !workDays.includes(d.key));
  const timeOk      = timeIvs.length >= 1 && timeIvs.every(iv => iv.start && iv.end);
  const seasonOk    = seasonIvs.every(iv => iv.from && iv.to);

  async function handleSave() {
    if (!timeOk || !seasonOk) return;
    setSaving(true);
    try {
      const { data } = await api.put(`/api/projects/${project.id}`, {
        work_days:                 workDays,
        work_time_intervals:       timeIvs,
        working_season_intervals:  seasonIvs,
      });
      onSave(data.data);
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm flex flex-col max-h-[90vh]">

        {/* Header */}
        <div className="px-6 pt-6 pb-4 flex-shrink-0 border-b border-gray-100">
          <h3 className="text-lg font-semibold text-gray-900">Project Schedule</h3>
          <p className="text-xs text-gray-400 mt-0.5">Define when this project operates</p>
        </div>

        <div className="overflow-y-auto flex-1 px-6 py-5 space-y-6" style={{ minHeight: 0 }}>

          {/* Day picker */}
          <div>
            <p className="text-sm font-medium text-gray-700 mb-3">Working Days</p>
            <div className="flex gap-1.5 justify-between mb-3">
              {DAYS.map(d => {
                const selected = workDays.includes(d.key);
                return (
                  <button key={d.key} type="button" onClick={() => toggleDay(d.key)}
                    className={`flex-1 py-2 rounded-lg text-xs font-semibold transition-all duration-150 ${
                      selected ? 'bg-blue-500 text-white shadow-sm' : 'bg-gray-100 text-gray-400 hover:bg-gray-200'
                    }`}>
                    {d.short}
                  </button>
                );
              })}
            </div>
            <div className="flex gap-4 text-xs text-gray-400">
              <span>
                <span className="inline-block w-2 h-2 rounded-full bg-blue-500 mr-1.5 align-middle" />
                Weekday: {weekdays.length > 0 ? weekdays.map(d => d.short).join(', ') : '—'}
              </span>
              <span>
                <span className="inline-block w-2 h-2 rounded-full bg-gray-300 mr-1.5 align-middle" />
                Weekend: {weekends.length > 0 ? weekends.map(d => d.short).join(', ') : '—'}
              </span>
            </div>
          </div>

          {/* Work time intervals */}
          <div>
            <div className="flex items-center justify-between mb-2">
              <p className="text-sm font-medium text-gray-700">Work Hours</p>
              <button type="button" onClick={addTimeIv}
                className="text-xs text-blue-500 font-medium hover:text-blue-700">+ Add</button>
            </div>
            <div className="space-y-2">
              {timeIvs.map((iv, i) => (
                <div key={i} className="flex items-center gap-2">
                  <input type="time" value={iv.start}
                    onChange={e => updateTimeIv(i, 'start', e.target.value)}
                    className="flex-1 border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-blue-400" />
                  <span className="text-xs text-gray-400 flex-shrink-0">to</span>
                  <input type="time" value={iv.end}
                    onChange={e => updateTimeIv(i, 'end', e.target.value)}
                    className="flex-1 border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-blue-400" />
                  <button type="button" onClick={() => removeTimeIv(i)}
                    disabled={timeIvs.length <= 1}
                    className="w-6 h-6 flex items-center justify-center rounded text-gray-400 hover:text-red-500 hover:bg-red-50 disabled:opacity-30 disabled:cursor-not-allowed transition-colors text-base leading-none flex-shrink-0">
                    ×
                  </button>
                </div>
              ))}
            </div>
          </div>

          {/* Operating season intervals */}
          <div>
            <div className="flex items-center justify-between mb-2">
              <div>
                <p className="text-sm font-medium text-gray-700">Operating Season</p>
                <p className="text-xs text-gray-400">Leave empty to operate all year</p>
              </div>
              <button type="button" onClick={addSeasonIv}
                className="text-xs text-blue-500 font-medium hover:text-blue-700 flex-shrink-0">+ Add period</button>
            </div>

            {seasonIvs.length === 0 ? (
              <div className="text-center py-4 border border-dashed border-gray-200 rounded-xl">
                <p className="text-xs text-gray-400">All year — no seasonal restriction</p>
              </div>
            ) : (
              <div className="space-y-3">
                {seasonIvs.map((iv, i) => {
                  const f = parseMmdd(iv.from);
                  const t = parseMmdd(iv.to);
                  return (
                    <div key={i} className="border border-gray-200 rounded-xl p-3 space-y-2">
                      <div className="flex items-center justify-between">
                        <span className="text-xs font-medium text-gray-500">Period {i + 1}</span>
                        <button type="button" onClick={() => removeSeasonIv(i)}
                          className="text-xs text-gray-400 hover:text-red-500 transition-colors">Remove</button>
                      </div>
                      {/* From row */}
                      <div className="flex items-center gap-2">
                        <span className="text-xs text-gray-400 w-8 flex-shrink-0">From</span>
                        <select value={f.month}
                          onChange={e => updateSeasonIv(i, 'from', 'month', e.target.value)}
                          className="flex-1 border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-blue-400 bg-white">
                          <option value="">Month</option>
                          {MONTHS.map(m => <option key={m.v} value={m.v}>{m.l}</option>)}
                        </select>
                        <input type="number" min="1" max="31" placeholder="Day"
                          value={f.day}
                          onChange={e => updateSeasonIv(i, 'from', 'day', e.target.value)}
                          className="w-16 border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-blue-400" />
                      </div>
                      {/* To row */}
                      <div className="flex items-center gap-2">
                        <span className="text-xs text-gray-400 w-8 flex-shrink-0">To</span>
                        <select value={t.month}
                          onChange={e => updateSeasonIv(i, 'to', 'month', e.target.value)}
                          className="flex-1 border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-blue-400 bg-white">
                          <option value="">Month</option>
                          {MONTHS.map(m => <option key={m.v} value={m.v}>{m.l}</option>)}
                        </select>
                        <input type="number" min="1" max="31" placeholder="Day"
                          value={t.day}
                          onChange={e => updateSeasonIv(i, 'to', 'day', e.target.value)}
                          className="w-16 border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-blue-400" />
                      </div>
                      {/* Preview */}
                      {iv.from && iv.to && (
                        <p className="text-xs text-indigo-600 font-medium">{fmtSeasonInterval(iv)}</p>
                      )}
                    </div>
                  );
                })}
              </div>
            )}
          </div>

        </div>

        {/* Footer */}
        <div className="flex gap-3 px-6 py-4 flex-shrink-0 border-t border-gray-100">
          <button onClick={onClose}
            className="flex-1 border border-gray-300 text-gray-700 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-50 transition-colors">
            Cancel
          </button>
          <button onClick={handleSave} disabled={saving || !timeOk || !seasonOk}
            className="flex-1 bg-indigo-600 text-white py-2.5 rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
            {saving ? 'Saving…' : 'Save Schedule'}
          </button>
        </div>
      </div>
    </div>
  );
}

function Modal({ title, form, onChange, onSubmit, onClose, submitLabel, suggestions = [], nameLabel = 'Building Name', namePlaceholder = 'e.g. Block A', onDuplicateFrom, fieldErrors = {}, onClearError }) {
  const wrapperRef = useRef(null);
  const [showSuggestions, setShowSuggestions] = useState(false);

  useEffect(() => {
    function handleOutside(e) {
      if (wrapperRef.current && !wrapperRef.current.contains(e.target)) setShowSuggestions(false);
    }
    document.addEventListener('mousedown', handleOutside);
    return () => document.removeEventListener('mousedown', handleOutside);
  }, []);

  const filtered = suggestions.filter(s => !form.name || s.name.toLowerCase().includes(form.name.toLowerCase()));

  return (
    <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6">
        <h3 className="text-lg font-semibold text-gray-900 mb-5">{title}</h3>
        <div className="space-y-4">
          <div className="relative" ref={wrapperRef}>
            <label className="block text-sm font-medium text-gray-700 mb-1">{nameLabel}</label>
            <input type="text" autoFocus value={form.name}
              onChange={e => { onChange({ ...form, name: e.target.value }); setShowSuggestions(true); onClearError?.('name'); }}
              onFocus={() => setShowSuggestions(true)}
              onKeyDown={e => { if (e.key === 'Escape') setShowSuggestions(false); if (e.key === 'Enter') onSubmit(); }}
              placeholder={namePlaceholder}
              className={`w-full border rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent ${fieldErrors?.name ? 'border-red-400' : 'border-gray-300'}`} />
            {fieldErrors?.name?.[0] && <p className="text-red-500 text-xs mt-1">{fieldErrors.name[0]}</p>}
            {showSuggestions && filtered.length > 0 && (
              <ul className="absolute z-10 w-full bg-white border border-gray-200 rounded-lg shadow-lg mt-1 max-h-48 overflow-y-auto">
                {filtered.map((s, i) => (
                  <li key={i} className="px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 flex items-center justify-between gap-2">
                    <div className="flex-1 min-w-0">
                      <span className="font-medium truncate block">{s.name}</span>
                      {Number(s.area) > 0 && <span className="text-xs text-gray-400">{Number(s.area).toLocaleString()} m²</span>}
                    </div>
                    <div className="flex gap-1 flex-shrink-0">
                      <button onMouseDown={() => { onChange({ ...form, name: s.name, area: s.area ?? form.area }); setShowSuggestions(false); }}
                        className="text-xs px-2 py-1 rounded-md border border-gray-200 text-gray-600 hover:bg-gray-100 transition-colors">
                        Empty
                      </button>
                      {onDuplicateFrom && (
                        <button onMouseDown={() => { setShowSuggestions(false); onDuplicateFrom(s.id); }}
                          className="text-xs px-2 py-1 rounded-md border border-blue-200 text-blue-600 hover:bg-blue-50 transition-colors">
                          Copy all
                        </button>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Area (m²)</label>
            <input type="number" min="0.01" step="0.01" value={form.area}
              onChange={e => { onChange({ ...form, area: e.target.value }); onClearError?.('area'); }}
              placeholder="e.g. 100"
              className={`w-full border rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent ${fieldErrors?.area ? 'border-red-400' : 'border-gray-300'}`} />
            {fieldErrors?.area?.[0] && <p className="text-red-500 text-xs mt-1">{fieldErrors.area[0]}</p>}
          </div>
        </div>
        <div className="flex gap-3 mt-6">
          <button onClick={onClose}
            className="flex-1 border border-gray-300 text-gray-700 py-2.5 rounded-lg text-sm
              font-medium hover:bg-gray-50 transition-colors">Cancel</button>
          <button onClick={onSubmit} disabled={!form.name.trim() || Number(form.area) <= 0}
            className="flex-1 bg-blue-600 text-white py-2.5 rounded-lg text-sm font-medium
              hover:bg-blue-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
            {submitLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
