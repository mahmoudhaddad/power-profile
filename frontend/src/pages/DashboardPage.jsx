import { useState, useEffect, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import Navbar from '../components/Navbar';
import UserCard from '../components/UserCard';
import { useAuth } from '../contexts/AuthContext';
import api from '../api/axios';
import { setNav } from '../utils/navContext';
import { downloadJson } from '../utils/downloadJson';
import BackupChoiceModal from '../components/BackupChoiceModal';
import ServerBackupsList from '../components/ServerBackupsList';

export default function DashboardPage() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [projects, setProjects] = useState([]);
  const [loading, setLoading] = useState(true);
  const [showModal, setShowModal] = useState(false);
  const [newName, setNewName] = useState('');
  const [editingProject, setEditingProject] = useState(null);
  const [editName, setEditName] = useState('');
  const [editInterval, setEditInterval] = useState('never');

  // Backup state
  const [backupTarget, setBackupTarget] = useState(null); // project to backup

  // Restore state
  const [restoreFile, setRestoreFile]   = useState(null);
  const [restoring, setRestoring]       = useState(false);
  const [restoreError, setRestoreError] = useState('');
  const [dragOver, setDragOver]         = useState(false);
  const [confirmData, setConfirmData]   = useState(null);
  const [restoreTab, setRestoreTab]     = useState('computer');
  const [serverRestoreProjectId, setServerRestoreProjectId] = useState('');
  const fileInputRef = useRef(null);

  useEffect(() => {
    api.get('/api/projects')
      .then(({ data }) => setProjects(data.data))
      .finally(() => setLoading(false));
  }, []);

  async function handleAddProject() {
    if (!newName.trim()) return;
    const { data } = await api.post('/api/projects', { name: newName.trim() });
    setNewName('');
    setShowModal(false);
    setNav({ projectId: data.data.id });
    navigate('/project');
  }

  function openEdit(project) {
    setEditingProject(project);
    setEditName(project.name);
    setEditInterval(project.auto_backup_interval ?? 'never');
  }

  async function handleEditProject() {
    if (!editName.trim()) return;
    const { data } = await api.put(`/api/projects/${editingProject.id}`, {
      name: editName.trim(),
      auto_backup_interval: editInterval,
    });
    setProjects(projects.map(p => p.id === editingProject.id ? data.data : p));
    setEditingProject(null);
  }

  async function handleDeleteProject(id) {
    await api.delete(`/api/projects/${id}`);
    setProjects(projects.filter(p => p.id !== id));
  }

  // ── Backup ──────────────────────────────────────────────
  async function handleBackupDownload(project) {
    try {
      const { data } = await api.get(`/api/projects/${project.id}/backup`);
      downloadJson(data, `${project.name.replace(/\s+/g, '-')}-backup.json`);
    } catch (err) {
      alert('Backup failed: ' + (err.response?.data?.message || err.message || 'Unknown error'));
    }
  }

  async function handleBackupToServer(project) {
    await api.post(`/api/projects/${project.id}/save-backup`);
  }

  function handleServerRestore(serverData) {
    const fileData = { parsed: serverData, name: serverData.project?.name || 'Server Backup' };
    setRestoreFile(fileData);
    setRestoreTab('computer');
    doRestore(false, fileData);
  }

  // ── Restore upload ───────────────────────────────────────
  function handleFileDrop(e) {
    e.preventDefault();
    setDragOver(false);
    const file = e.dataTransfer.files[0];
    if (file) loadRestoreFile(file);
  }

  function handleFileInput(e) {
    const file = e.target.files[0];
    if (file) loadRestoreFile(file);
    e.target.value = '';
  }

  function loadRestoreFile(file) {
    setRestoreError('');
    if (!file.name.endsWith('.json')) {
      setRestoreError('Please select a .json backup file.');
      return;
    }
    const reader = new FileReader();
    reader.onload = e => {
      try {
        const parsed = JSON.parse(e.target.result);
        setRestoreFile({ name: file.name, parsed });
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
    setRestoreError('');
    try {
      const { data } = await api.post('/api/projects/restore', {
        data: file.parsed,
        overwrite,
      });
      setProjects(prev => [data.data, ...prev.filter(p => p.name !== data.data.name)]);
      setRestoreFile(null);
      setConfirmData(null);
    } catch (err) {
      if (err.response?.status === 409 && err.response.data?.conflict) {
        setConfirmData({ parsed: file.parsed, message: err.response.data.message });
      } else {
        setRestoreError(err.response?.data?.message || 'Restore failed.');
      }
    } finally {
      setRestoring(false);
    }
  }

  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar />

      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="mb-6">
          <h1 className="text-2xl font-bold text-gray-900">Dashboard</h1>
          <p className="text-gray-500 mt-1">Welcome back, {user?.name?.split(' ')[0]}!</p>
        </div>

        <div className="flex gap-6 items-start">

          {/* Left Column — User Info */}
          <div className="w-[360px] flex-shrink-0">
            <UserCard user={user} />
          </div>

          {/* Right Column — Projects */}
          <div className="flex-1 flex flex-col gap-6 min-w-0">

            {/* New Project Section */}
            <section className="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
              <h2 className="text-base font-semibold text-gray-900 mb-4">New Project</h2>
              <button
                onClick={() => setShowModal(true)}
                className="group flex items-center gap-3 border-2 border-dashed border-blue-300
                  hover:border-blue-500 hover:bg-blue-50 text-blue-500 hover:text-blue-700
                  rounded-xl px-5 py-4 transition-all duration-200 hover:shadow-sm w-full"
              >
                <span className="w-9 h-9 rounded-full bg-blue-100 group-hover:bg-blue-200 flex items-center
                  justify-center flex-shrink-0 transition-colors duration-200">
                  <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                  </svg>
                </span>
                <div className="text-left">
                  <p className="font-semibold text-sm">Add New Project</p>
                  <p className="text-xs text-blue-400 group-hover:text-blue-500 transition-colors">
                    Start analysing a new building group
                  </p>
                </div>
              </button>
            </section>

            {/* Restore Backup Section */}
            <section className="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
              <h2 className="text-base font-semibold text-gray-900 mb-4">Restore Project from Backup</h2>

              {/* Tabs */}
              <div className="flex gap-1 mb-4 bg-gray-100 p-1 rounded-lg w-fit">
                {['computer', 'server'].map(tab => (
                  <button key={tab} onClick={() => setRestoreTab(tab)}
                    className={`px-4 py-1.5 rounded-md text-sm font-medium transition-colors ${
                      restoreTab === tab
                        ? 'bg-white text-gray-900 shadow-sm'
                        : 'text-gray-500 hover:text-gray-700'
                    }`}>
                    {tab === 'computer' ? 'From Computer' : 'From Server'}
                  </button>
                ))}
              </div>

              {restoreTab === 'computer' && <>
                {/* Drop zone */}
                <div
                  onDragOver={e => { e.preventDefault(); setDragOver(true); }}
                  onDragLeave={() => setDragOver(false)}
                  onDrop={handleFileDrop}
                  onClick={() => fileInputRef.current?.click()}
                  className={`flex flex-col items-center justify-center gap-2 border-2 border-dashed rounded-xl
                    px-6 py-8 cursor-pointer transition-all duration-200
                    ${dragOver
                      ? 'border-emerald-500 bg-emerald-50 text-emerald-700'
                      : 'border-gray-300 hover:border-emerald-400 hover:bg-emerald-50 text-gray-400 hover:text-emerald-600'
                    }`}
                >
                  <svg className="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
                      d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                  </svg>
                  <p className="text-sm font-medium">
                    {dragOver ? 'Drop the backup file here' : 'Drag & drop a backup file, or click to browse'}
                  </p>
                  <p className="text-xs opacity-60">.json files only</p>
                  <input ref={fileInputRef} type="file" accept=".json" className="hidden" onChange={handleFileInput} />
                </div>

                {/* Selected file + restore button */}
                {restoreFile && restoreTab === 'computer' && (
                  <div className="mt-3 flex items-center gap-3 bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3">
                    <svg className="w-5 h-5 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                        d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <div className="flex-1 min-w-0">
                      <p className="text-sm font-medium text-emerald-800 truncate">{restoreFile.name}</p>
                      <p className="text-xs text-emerald-600">
                        Project: <span className="font-semibold">{restoreFile.parsed?.project?.name ?? restoreFile.parsed?.name ?? '—'}</span>
                      </p>
                    </div>
                    <button onClick={() => doRestore(false)} disabled={restoring}
                      className="flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white
                        text-sm font-medium px-4 py-2 rounded-lg transition-colors disabled:opacity-50">
                      {restoring
                        ? <span className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
                        : <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                          </svg>}
                      Restore
                    </button>
                    <button onClick={() => { setRestoreFile(null); setRestoreError(''); }}
                      className="text-gray-400 hover:text-gray-600 p-1">
                      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                      </svg>
                    </button>
                  </div>
                )}
              </>}

              {restoreTab === 'server' && (
                <div className="space-y-3">
                  <select value={serverRestoreProjectId}
                    onChange={e => setServerRestoreProjectId(e.target.value)}
                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm bg-white
                      focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">Select a project…</option>
                    {projects.filter(p => p.user_role === 'admin' || p.user_role === 'main').map(p => (
                      <option key={p.id} value={p.id}>{p.name}</option>
                    ))}
                  </select>
                  {serverRestoreProjectId && (
                    <ServerBackupsList
                      projectId={serverRestoreProjectId}
                      entityType="project"
                      entityId={serverRestoreProjectId}
                      onRestore={handleServerRestore}
                    />
                  )}
                </div>
              )}

              {restoreError && (
                <p className="mt-2 text-sm text-red-600 flex items-center gap-1.5">
                  <svg className="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                      d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                  </svg>
                  {restoreError}
                </p>
              )}
            </section>

            {/* Previous Projects Section */}
            <section className="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
              <div className="flex items-center justify-between mb-4">
                <h2 className="text-base font-semibold text-gray-900">Previous Projects</h2>
                <span className="text-xs text-gray-400 bg-gray-100 px-2.5 py-1 rounded-full">
                  {projects.length} project{projects.length !== 1 ? 's' : ''}
                </span>
              </div>

              {loading ? (
                <div className="flex justify-center py-10">
                  <div className="w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
                </div>
              ) : projects.length === 0 ? (
                <div className="py-10 text-center text-gray-400">
                  <svg className="w-10 h-10 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
                      d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                  </svg>
                  <p className="text-sm">No projects yet. Create your first one!</p>
                </div>
              ) : (
                <div className="flex flex-col gap-2">
                  {projects.map(project => (
                    <ProjectRow
                      key={project.id}
                      project={project}
                      onOpen={() => { setNav({ projectId: project.id }); navigate('/project'); }}
                      onEdit={() => openEdit(project)}
                      onDelete={() => handleDeleteProject(project.id)}
                      onBackup={() => setBackupTarget(project)}
                    />
                  ))}
                </div>
              )}
            </section>

          </div>
        </div>
      </main>

      {/* Backup Choice Modal */}
      {backupTarget && (
        <BackupChoiceModal
          entityName={backupTarget.name}
          onDownload={() => { handleBackupDownload(backupTarget); setBackupTarget(null); }}
          onSaveToServer={() => handleBackupToServer(backupTarget)}
          onClose={() => setBackupTarget(null)}
        />
      )}

      {/* Edit Project Modal */}
      {editingProject && (
        <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6">
            <h3 className="text-lg font-semibold text-gray-900 mb-4">Edit Project</h3>
            <div className="space-y-4 mb-5">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Project Name</label>
                <input type="text" autoFocus value={editName}
                  onChange={e => setEditName(e.target.value)}
                  onKeyDown={e => e.key === 'Enter' && handleEditProject()}
                  placeholder="Project name"
                  className="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm
                    focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">Auto Backup</label>
                <div className="grid grid-cols-4 gap-1.5">
                  {[
                    { value: 'never',   label: 'Never' },
                    { value: 'daily',   label: 'Daily' },
                    { value: 'weekly',  label: 'Weekly' },
                    { value: 'monthly', label: 'Monthly' },
                  ].map(opt => (
                    <button key={opt.value} type="button"
                      onClick={() => setEditInterval(opt.value)}
                      className={`py-2 rounded-lg text-xs font-semibold border transition-colors ${
                        editInterval === opt.value
                          ? 'bg-blue-600 border-blue-600 text-white'
                          : 'border-gray-300 text-gray-600 hover:border-blue-400 hover:text-blue-600'
                      }`}>
                      {opt.label}
                    </button>
                  ))}
                </div>
                {editInterval !== 'never' && (
                  <p className="text-xs text-gray-400 mt-2">
                    A project backup will be saved to the server automatically every {editInterval === 'daily' ? 'day' : editInterval === 'weekly' ? 'week' : 'month'}.
                  </p>
                )}
              </div>
            </div>
            <div className="flex gap-3">
              <button onClick={() => setEditingProject(null)}
                className="flex-1 border border-gray-300 text-gray-700 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-50 transition-colors">
                Cancel
              </button>
              <button onClick={handleEditProject} disabled={!editName.trim()}
                className="flex-1 bg-blue-600 text-white py-2.5 rounded-lg text-sm font-medium
                  hover:bg-blue-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
                Save
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Add Project Modal */}
      {showModal && (
        <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6">
            <h3 className="text-lg font-semibold text-gray-900 mb-4">New Project</h3>
            <input type="text" autoFocus value={newName}
              onChange={e => setNewName(e.target.value)}
              onKeyDown={e => e.key === 'Enter' && handleAddProject()}
              placeholder="Project name"
              className="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm
                focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent mb-4" />
            <div className="flex gap-3">
              <button onClick={() => { setShowModal(false); setNewName(''); }}
                className="flex-1 border border-gray-300 text-gray-700 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-50 transition-colors">
                Cancel
              </button>
              <button onClick={handleAddProject} disabled={!newName.trim()}
                className="flex-1 bg-blue-600 text-white py-2.5 rounded-lg text-sm font-medium
                  hover:bg-blue-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
                Create
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Overwrite Confirm Modal */}
      {confirmData && (
        <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6">
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 bg-amber-100 rounded-full flex items-center justify-center flex-shrink-0">
                <svg className="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                </svg>
              </div>
              <div>
                <h3 className="text-base font-semibold text-gray-900">Project Already Exists</h3>
                <p className="text-sm text-gray-500 mt-0.5">{confirmData.message}</p>
              </div>
            </div>
            <p className="text-sm text-gray-600 mb-5">
              This will replace the existing project and all its data with the backup. This cannot be undone.
            </p>
            <div className="flex gap-3">
              <button onClick={() => setConfirmData(null)}
                className="flex-1 border border-gray-300 text-gray-700 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-50 transition-colors">
                Cancel
              </button>
              <button
                onClick={() => doRestore(true)}
                disabled={restoring}
                className="flex-1 bg-amber-600 text-white py-2.5 rounded-lg text-sm font-medium
                  hover:bg-amber-700 transition-colors disabled:opacity-50"
              >
                {restoring ? 'Restoring…' : 'Yes, Overwrite'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function fmtPower(w) {
  if (!w || Number(w) === 0) return '0 VA';
  const v = Number(w);
  if (v >= 1000000) return `${(v / 1000000).toLocaleString(undefined, { maximumFractionDigits: 2 })} MVA`;
  if (v >= 1000)    return `${(v / 1000).toLocaleString(undefined,    { maximumFractionDigits: 2 })} kVA`;
  return `${v.toLocaleString(undefined, { maximumFractionDigits: 2 })} VA`;
}

const ROLE_ROW = {
  admin:  { label: null },
  main:   { label: 'Main User',  bg: 'bg-blue-100',  text: 'text-blue-700'  },
  normal: { label: 'View Only',  bg: 'bg-gray-100',  text: 'text-gray-500'  },
};

const INTERVAL_BADGE = {
  daily:   { label: 'Daily backup',   bg: 'bg-emerald-100', text: 'text-emerald-700' },
  weekly:  { label: 'Weekly backup',  bg: 'bg-emerald-100', text: 'text-emerald-700' },
  monthly: { label: 'Monthly backup', bg: 'bg-emerald-100', text: 'text-emerald-700' },
};

function ProjectRow({ project, onOpen, onEdit, onDelete, onBackup }) {
  const role     = project.user_role ?? 'admin';
  const canEdit  = role === 'admin' || role === 'main';
  const meta     = ROLE_ROW[role] ?? ROLE_ROW.normal;
  const interval = project.auto_backup_interval ?? 'never';
  const badge    = INTERVAL_BADGE[interval];

  return (
    <div
      onClick={onOpen}
      className="flex items-center justify-between py-4 px-4 border border-blue-300
        rounded-xl cursor-pointer group transition-all duration-200
        hover:bg-blue-50 hover:-translate-y-1 hover:shadow-md"
    >

      {/* Icon + Name */}
      <div className="flex items-center gap-3 min-w-0 w-56">
        <div className="w-9 h-9 bg-blue-50 group-hover:bg-blue-100 rounded-lg flex items-center
          justify-center flex-shrink-0 transition-colors duration-150">
          <svg className="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
          </svg>
        </div>
        <div className="min-w-0">
          <span className="font-medium text-gray-900 text-sm truncate block">{project.name}</span>
          <div className="flex items-center gap-1 flex-wrap">
            {meta.label && (
              <span className={`text-xs font-semibold px-1.5 py-0.5 rounded-full ${meta.bg} ${meta.text}`}>
                {meta.label}
              </span>
            )}
            {badge && (
              <span className={`text-xs font-semibold px-1.5 py-0.5 rounded-full ${badge.bg} ${badge.text}`}>
                {badge.label}
              </span>
            )}
          </div>
        </div>
      </div>

      {/* Buildings */}
      <div className="flex items-center gap-1.5 w-32 text-sm text-gray-500">
        <svg className="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
            d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
        </svg>
        <span>{project.buildings_count} building{project.buildings_count !== 1 ? 's' : ''}</span>
      </div>

      {/* Total Power */}
      <div className="flex items-center gap-1.5 w-36 text-sm text-gray-500">
        <svg className="w-4 h-4 text-yellow-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
        </svg>
        <span>{fmtPower(project.total_power)}</span>
      </div>

      {/* Last Modified */}
      <div className="flex items-center gap-1.5 text-sm text-gray-400 w-36">
        <svg className="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
            d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
        <span>
          {new Date(project.updated_at).toLocaleDateString('en-US', {
            month: 'short', day: 'numeric', year: 'numeric',
          })}
        </span>
      </div>

      {/* Actions */}
      <div className="flex items-center gap-2 flex-shrink-0">
        {/* Backup — admin and main only */}
        {canEdit && <button
          onClick={e => { e.stopPropagation(); onBackup(); }}
          title="Download backup"
          className="flex items-center gap-1.5 text-xs font-medium text-gray-500 px-3 py-1.5
            rounded-lg border border-gray-200 bg-white hover:border-emerald-400 hover:text-emerald-600
            hover:bg-emerald-50 transition-all duration-150"
        >
          <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
          </svg>
          Backup
        </button>}

        {canEdit && (
          <>
            <button
              onClick={e => { e.stopPropagation(); onEdit(); }}
              className="flex items-center gap-1.5 text-xs font-medium text-gray-500 px-3 py-1.5
                rounded-lg border border-gray-200 bg-white hover:border-blue-400 hover:text-blue-600
                hover:bg-blue-50 transition-all duration-150"
            >
              <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
              </svg>
              Edit
            </button>
            {role === 'admin' && (
              <button
                onClick={e => { e.stopPropagation(); onDelete(); }}
                className="flex items-center gap-1.5 text-xs font-medium text-gray-500 px-3 py-1.5
                  rounded-lg border border-gray-200 bg-white hover:border-red-300 hover:text-red-600
                  hover:bg-red-50 transition-all duration-150"
              >
                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
                Delete
              </button>
            )}
          </>
        )}
      </div>
    </div>
  );
}
