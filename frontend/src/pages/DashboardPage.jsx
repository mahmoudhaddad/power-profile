import { useState, useEffect, useRef } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import Navbar from '../components/Navbar';
import UserCard from '../components/UserCard';
import { useAuth } from '../contexts/AuthContext';
import api from '../api/axios';
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
  const [addErrors, setAddErrors] = useState({});
  const [editingProject, setEditingProject] = useState(null);
  const [editName, setEditName] = useState('');
  const [editInterval, setEditInterval] = useState('never');
  const [editErrors, setEditErrors] = useState({});

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
    try {
      const { data } = await api.post('/api/projects', { name: newName.trim() });
      setNewName('');
      setShowModal(false);
      navigate(`/projects/${data.data.id}`);
    } catch (err) {
      if (err.response?.status === 422) setAddErrors(err.response.data.errors ?? {});
    }
  }

  function openEdit(project) {
    setEditingProject(project);
    setEditName(project.name);
    setEditInterval(project.auto_backup_interval ?? 'never');
  }

  async function handleEditProject() {
    if (!editName.trim()) return;
    try {
      const { data } = await api.put(`/api/projects/${editingProject.id}`, {
        name: editName.trim(),
        auto_backup_interval: editInterval,
      });
      setProjects(projects.map(p => p.id === editingProject.id ? data.data : p));
      setEditingProject(null);
    } catch (err) {
      if (err.response?.status === 422) setEditErrors(err.response.data.errors ?? {});
    }
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
    <div className="min-h-screen bg-base">
      <Navbar />

      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="mb-6">
          <h1 className="text-2xl font-bold text-ink-heading">Dashboard</h1>
          <p className="text-ink-body mt-1">Welcome back, {user?.name?.split(' ')[0]}!</p>
        </div>

        <div className="flex gap-6 items-start">

          {/* Left Column — User Info */}
          <div className="w-[360px] flex-shrink-0">
            <UserCard user={user} />
          </div>

          {/* Right Column — Projects */}
          <div className="flex-1 flex flex-col gap-6 min-w-0">

            {/* System Validation — visible to admins */}
            {user?.is_admin && (
              <section className="bg-accent-soft border border-accent-border rounded-xl p-4 flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <div className="w-9 h-9 bg-accent-softer rounded-lg flex items-center justify-center flex-shrink-0">
                    <svg className="w-5 h-5 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8}
                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                  </div>
                  <div>
                    <p className="text-sm font-semibold text-ink-heading">System Validation</p>
                    <p className="text-xs text-ink-body2">Verify calculation accuracy against hand-computed reference values</p>
                  </div>
                </div>
                <Link to="/validation"
                  className="flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-base
                    bg-accent-gradient hover:shadow-accent rounded-xl transition-shadow">
                  Open
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                  </svg>
                </Link>
              </section>
            )}

            {/* New Project Section */}
            <section className="bg-surface-card rounded-xl border border-line p-5">
              <h2 className="text-base font-semibold text-ink-heading mb-4">New Project</h2>
              <button
                onClick={() => setShowModal(true)}
                className="group flex items-center gap-3 border-2 border-dashed border-line
                  hover:border-accent-border-strong hover:bg-accent-soft text-accent hover:text-accent-bright
                  rounded-xl px-5 py-4 transition-all duration-200 hover:shadow-accent w-full"
              >
                <span className="w-9 h-9 rounded-full bg-accent-soft group-hover:bg-accent-softer flex items-center
                  justify-center flex-shrink-0 transition-colors duration-200">
                  <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                  </svg>
                </span>
                <div className="text-left">
                  <p className="font-semibold text-sm">Add New Project</p>
                  <p className="text-xs text-ink-muted group-hover:text-accent transition-colors">
                    Start analysing a new building group
                  </p>
                </div>
              </button>
            </section>

            {/* Restore Backup Section */}
            <section className="bg-surface-card rounded-xl border border-line p-5">
              <h2 className="text-base font-semibold text-ink-heading mb-4">Restore Project from Backup</h2>

              {/* Tabs */}
              <div className="flex gap-1 mb-4 bg-surface-inset p-1 rounded-lg w-fit">
                {['computer', 'server'].map(tab => (
                  <button key={tab} onClick={() => setRestoreTab(tab)}
                    className={`px-4 py-1.5 rounded-md text-sm font-medium transition-colors ${
                      restoreTab === tab
                        ? 'bg-surface-card text-ink-heading'
                        : 'text-ink-body2 hover:text-ink-heading'
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
                      ? 'border-accent-border-strong bg-accent-soft text-accent'
                      : 'border-line hover:border-accent-border hover:bg-accent-soft text-ink-muted hover:text-accent'
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
                  <div className="mt-3 flex items-center gap-3 bg-accent-soft border border-accent-border rounded-xl px-4 py-3">
                    <svg className="w-5 h-5 text-accent flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                        d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <div className="flex-1 min-w-0">
                      <p className="text-sm font-medium text-ink-heading truncate">{restoreFile.name}</p>
                      <p className="text-xs text-ink-body2">
                        Project: <span className="font-semibold">{restoreFile.parsed?.project?.name ?? restoreFile.parsed?.name ?? '—'}</span>
                      </p>
                    </div>
                    <button onClick={() => doRestore(false)} disabled={restoring}
                      className="flex items-center gap-1.5 bg-accent-gradient text-base
                        text-sm font-medium px-4 py-2 rounded-lg hover:shadow-accent transition-shadow disabled:opacity-50">
                      {restoring
                        ? <span className="w-4 h-4 border-2 border-base border-t-transparent rounded-full animate-spin" />
                        : <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                          </svg>}
                      Restore
                    </button>
                    <button onClick={() => { setRestoreFile(null); setRestoreError(''); }}
                      className="text-ink-muted hover:text-ink-body2 p-1">
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
                    className="w-full border border-line rounded-lg px-3 py-2 text-sm bg-surface-card text-ink-body
                      focus:outline-none focus:ring-2 focus:ring-accent/40 focus:border-accent">
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
                <p className="mt-2 text-sm text-danger flex items-center gap-1.5">
                  <svg className="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                      d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                  </svg>
                  {restoreError}
                </p>
              )}
            </section>

            {/* Previous Projects Section */}
            <section className="bg-surface-card rounded-xl border border-line p-5">
              <div className="flex items-center justify-between mb-4">
                <h2 className="text-base font-semibold text-ink-heading">Previous Projects</h2>
                <span className="text-xs text-ink-muted bg-surface-inset px-2.5 py-1 rounded-full">
                  {projects.length} project{projects.length !== 1 ? 's' : ''}
                </span>
              </div>

              {loading ? (
                <div className="flex justify-center py-10">
                  <div className="w-8 h-8 border-4 border-accent border-t-transparent rounded-full animate-spin"></div>
                </div>
              ) : projects.length === 0 ? (
                <div className="py-10 text-center text-ink-muted">
                  <svg className="w-10 h-10 mx-auto mb-3 text-ink-muted2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                      onOpen={() => navigate(`/projects/${project.id}`)}
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
          <div className="bg-surface-card border border-line rounded-2xl w-full max-w-sm p-6">
            <h3 className="text-lg font-semibold text-ink-heading mb-4">Edit Project</h3>
            <div className="space-y-4 mb-5">
              <div>
                <label className="block text-sm font-medium text-ink-body2 mb-1">Project Name</label>
                <input type="text" autoFocus value={editName}
                  onChange={e => { setEditName(e.target.value); setEditErrors(p => ({ ...p, name: null })); }}
                  onKeyDown={e => e.key === 'Enter' && handleEditProject()}
                  placeholder="Project name"
                  className={`w-full border rounded-lg px-4 py-2.5 text-sm bg-surface-inset text-ink-heading focus:outline-none focus:ring-2 focus:ring-accent/40 focus:border-accent ${editErrors.name ? 'border-danger-border' : 'border-line'}`} />
                {editErrors.name?.[0] && <p className="text-danger text-xs mt-1">{editErrors.name[0]}</p>}
              </div>
              <div>
                <label className="block text-sm font-medium text-ink-body2 mb-2">Auto Backup</label>
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
                          ? 'bg-accent-gradient border-accent text-base'
                          : 'border-line text-ink-body2 hover:border-accent-border hover:text-accent'
                      }`}>
                      {opt.label}
                    </button>
                  ))}
                </div>
                {editInterval !== 'never' && (
                  <p className="text-xs text-ink-muted mt-2">
                    A project backup will be saved to the server automatically every {editInterval === 'daily' ? 'day' : editInterval === 'weekly' ? 'week' : 'month'}.
                  </p>
                )}
              </div>
            </div>
            <div className="flex gap-3">
              <button onClick={() => { setEditingProject(null); setEditErrors({}); }}
                className="flex-1 border border-line text-ink-body2 py-2.5 rounded-lg text-sm font-medium hover:bg-surface-inset hover:border-line-strong transition-colors">
                Cancel
              </button>
              <button onClick={handleEditProject} disabled={!editName.trim()}
                className="flex-1 bg-accent-gradient text-base py-2.5 rounded-lg text-sm font-medium
                  hover:shadow-accent transition-shadow disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:shadow-none">
                Save
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Add Project Modal */}
      {showModal && (
        <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
          <div className="bg-surface-card border border-line rounded-2xl w-full max-w-sm p-6">
            <h3 className="text-lg font-semibold text-ink-heading mb-4">New Project</h3>
            <input type="text" autoFocus value={newName}
              onChange={e => { setNewName(e.target.value); setAddErrors(p => ({ ...p, name: null })); }}
              onKeyDown={e => e.key === 'Enter' && handleAddProject()}
              placeholder="Project name"
              className={`w-full border rounded-lg px-4 py-2.5 text-sm bg-surface-inset text-ink-heading focus:outline-none focus:ring-2 focus:ring-accent/40 focus:border-accent ${addErrors.name ? 'border-danger-border' : 'border-line'}`} />
            {addErrors.name?.[0] && <p className="text-danger text-xs mt-1">{addErrors.name[0]}</p>}
            <div className="flex gap-3 mt-4">
              <button onClick={() => { setShowModal(false); setNewName(''); setAddErrors({}); }}
                className="flex-1 border border-line text-ink-body2 py-2.5 rounded-lg text-sm font-medium hover:bg-surface-inset hover:border-line-strong transition-colors">
                Cancel
              </button>
              <button onClick={handleAddProject} disabled={!newName.trim()}
                className="flex-1 bg-accent-gradient text-base py-2.5 rounded-lg text-sm font-medium
                  hover:shadow-accent transition-shadow disabled:opacity-40 disabled:cursor-not-allowed">
                Create
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Overwrite Confirm Modal */}
      {confirmData && (
        <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
          <div className="bg-surface-card border border-line rounded-2xl w-full max-w-sm p-6">
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 bg-accent-soft rounded-full flex items-center justify-center flex-shrink-0">
                <svg className="w-5 h-5 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                </svg>
              </div>
              <div>
                <h3 className="text-base font-semibold text-ink-heading">Project Already Exists</h3>
                <p className="text-sm text-ink-body mt-0.5">{confirmData.message}</p>
              </div>
            </div>
            <p className="text-sm text-ink-body mb-5">
              This will replace the existing project and all its data with the backup. This cannot be undone.
            </p>
            <div className="flex gap-3">
              <button onClick={() => setConfirmData(null)}
                className="flex-1 border border-line text-ink-body2 py-2.5 rounded-lg text-sm font-medium hover:bg-surface-inset hover:border-line-strong transition-colors">
                Cancel
              </button>
              <button
                onClick={() => doRestore(true)}
                disabled={restoring}
                className="flex-1 bg-accent-gradient text-base py-2.5 rounded-lg text-sm font-medium
                  hover:shadow-accent transition-shadow disabled:opacity-50"
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

function fmtVA(va) {
  const v = Number(va) || 0;
  if (v >= 1_000_000) return `${(v / 1_000_000).toLocaleString(undefined, { maximumFractionDigits: 2 })} MVA`;
  if (v >= 1_000)     return `${(v / 1_000).toLocaleString(undefined,     { maximumFractionDigits: 2 })} kVA`;
  return `${v.toLocaleString(undefined, { maximumFractionDigits: 0 })} VA`;
}

function fmtKW(kw) {
  const v = Number(kw) || 0;
  if (v >= 1_000) return `${(v / 1_000).toLocaleString(undefined, { maximumFractionDigits: 2 })} MW`;
  return `${v.toLocaleString(undefined, { maximumFractionDigits: 2 })} kW`;
}

const ROLE_ROW = {
  admin:  { label: null },
  main:   { label: 'Main User',  bg: 'bg-accent-soft',    text: 'text-accent'     },
  normal: { label: 'View Only',  bg: 'bg-surface-inset',  text: 'text-ink-body2'  },
};

const INTERVAL_BADGE = {
  daily:   { label: 'Daily backup',   bg: 'bg-accent-soft', text: 'text-accent' },
  weekly:  { label: 'Weekly backup',  bg: 'bg-accent-soft', text: 'text-accent' },
  monthly: { label: 'Monthly backup', bg: 'bg-accent-soft', text: 'text-accent' },
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
      className="flex items-center justify-between py-4 px-4 border border-line
        rounded-xl cursor-pointer group transition-all duration-200
        hover:bg-surface-inset hover:-translate-y-1 hover:shadow-accent"
    >

      {/* Icon + Name */}
      <div className="flex items-center gap-3 min-w-0 w-56">
        <div className="w-9 h-9 bg-surface-inset group-hover:bg-accent-soft rounded-lg flex items-center
          justify-center flex-shrink-0 transition-colors duration-150">
          <svg className="w-4 h-4 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
          </svg>
        </div>
        <div className="min-w-0">
          <span className="font-medium text-ink-heading text-sm truncate block">{project.name}</span>
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
      <div className="flex items-center gap-1.5 w-32 text-sm text-ink-body2">
        <svg className="w-4 h-4 text-ink-muted flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
            d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
        </svg>
        <span>{project.buildings_count} building{project.buildings_count !== 1 ? 's' : ''}</span>
      </div>

      {/* Total Power */}
      <div className="flex items-center gap-1.5 w-40 text-sm text-ink-body2">
        <svg className="w-4 h-4 text-accent flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
        </svg>
        <div className="flex flex-col leading-tight">
          <span className="font-medium text-ink-data font-mono">{fmtKW(project.total_kw)}</span>
          <span className="text-xs text-ink-muted font-mono">{fmtVA(project.total_power)}</span>
        </div>
      </div>

      {/* Last Modified */}
      <div className="flex items-center gap-1.5 text-sm text-ink-muted w-36">
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
          className="flex items-center gap-1.5 text-xs font-medium text-ink-body2 px-3 py-1.5
            rounded-lg border border-line bg-surface-card hover:border-accent-border hover:text-accent
            hover:bg-accent-soft transition-all duration-150"
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
              className="flex items-center gap-1.5 text-xs font-medium text-ink-body2 px-3 py-1.5
                rounded-lg border border-line bg-surface-card hover:border-accent-border hover:text-accent
                hover:bg-accent-soft transition-all duration-150"
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
                className="flex items-center gap-1.5 text-xs font-medium text-ink-body2 px-3 py-1.5
                  rounded-lg border border-line bg-surface-card hover:border-danger-border hover:text-danger
                  hover:bg-danger-soft transition-all duration-150"
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
