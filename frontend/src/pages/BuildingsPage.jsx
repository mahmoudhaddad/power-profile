import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import api from '../api/axios';

export default function BuildingsPage() {
  const { id } = useParams();
  const navigate = useNavigate();

  const [project, setProject]     = useState(null);
  const [buildings, setBuildings] = useState([]);
  const [loading, setLoading]     = useState(true);

  const [showModal, setShowModal]         = useState(false);
  const [newBuilding, setNewBuilding]     = useState({ name: '', area: '' });

  const [editingBuilding, setEditingBuilding] = useState(null);
  const [editForm, setEditForm]               = useState({ name: '', area: '' });

  const [editingName, setEditingName] = useState(false);
  const [nameInput, setNameInput]     = useState('');

  async function saveName() {
    if (!nameInput.trim() || nameInput === project.name) { setEditingName(false); return; }
    const { data } = await api.put(`/api/projects/${id}`, { name: nameInput.trim() });
    setProject(data.data);
    setEditingName(false);
  }

  useEffect(() => {
    api.get(`/api/projects/${id}/buildings`)
      .then(({ data }) => {
        setProject(data.project);
        setNameInput(data.project.name);
        setBuildings(data.data);
      })
      .catch(() => navigate('/dashboard'))
      .finally(() => setLoading(false));
  }, [id]);

  async function handleAdd() {
    if (!newBuilding.name.trim()) return;
    const { data } = await api.post(`/api/projects/${id}/buildings`, {
      name: newBuilding.name.trim(),
      area: newBuilding.area || 0,
    });
    setShowModal(false);
    navigate(`/projects/${id}/buildings/${data.data.id}/floors`);
  }

  function openEdit(building) {
    setEditingBuilding(building);
    setEditForm({ name: building.name, area: building.area });
  }

  async function handleEdit() {
    if (!editForm.name.trim()) return;
    const { data } = await api.put(
      `/api/projects/${id}/buildings/${editingBuilding.id}`,
      { name: editForm.name.trim(), area: editForm.area }
    );
    setBuildings(buildings.map(b => b.id === editingBuilding.id ? data.data : b));
    setEditingBuilding(null);
  }

  async function handleDelete(buildingId) {
    await api.delete(`/api/projects/${id}/buildings/${buildingId}`);
    setBuildings(buildings.filter(b => b.id !== buildingId));
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

      {/* Header */}
      <header className="bg-white border-b border-gray-200 px-6 py-4 flex items-center gap-4">
        <button
          onClick={() => navigate('/dashboard')}
          className="text-gray-400 hover:text-gray-600 transition-colors p-1.5 rounded-lg hover:bg-gray-100"
        >
          <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
          </svg>
        </button>
        <div>
          <div className="flex items-center gap-2 text-sm text-gray-400 mb-0.5">
            <span
              onClick={() => navigate('/dashboard')}
              className="hover:text-blue-500 cursor-pointer transition-colors"
            >
              Projects
            </span>
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
            </svg>
            <span className="text-gray-600 font-medium">{project?.name}</span>
          </div>

          {editingName ? (
            <div className="flex items-center gap-2 mt-0.5">
              <input
                autoFocus
                value={nameInput}
                onChange={e => setNameInput(e.target.value)}
                onKeyDown={e => { if (e.key === 'Enter') saveName(); if (e.key === 'Escape') setEditingName(false); }}
                className="text-lg font-semibold text-gray-900 border-b-2 border-blue-500
                  outline-none bg-transparent w-56"
              />
              <button onClick={saveName}
                className="text-xs text-blue-600 hover:text-blue-800 font-medium px-2 py-1
                  rounded hover:bg-blue-50 transition-colors">
                Save
              </button>
              <button onClick={() => setEditingName(false)}
                className="text-xs text-gray-400 hover:text-gray-600 font-medium px-2 py-1
                  rounded hover:bg-gray-100 transition-colors">
                Cancel
              </button>
            </div>
          ) : (
            <div className="flex items-center gap-2 mt-0.5">
              <h1 className="text-lg font-semibold text-gray-900">{project?.name}</h1>
              <button
                onClick={() => { setNameInput(project.name); setEditingName(true); }}
                className="text-gray-400 hover:text-blue-500 transition-colors p-1 rounded hover:bg-blue-50"
              >
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
              </button>
            </div>
          )}
        </div>
      </header>

      <main className="max-w-4xl mx-auto px-4 sm:px-6 py-8">

        {/* Add Project */}
        <section className="mb-8">
          <h2 className="text-base font-semibold text-gray-900 mb-4">Add Project</h2>
          <button
            onClick={() => setShowModal(true)}
            className="group flex items-center gap-3 border-2 border-dashed border-blue-300
              hover:border-blue-500 hover:bg-blue-50 text-blue-500 hover:text-blue-700
              rounded-xl px-5 py-4 w-full transition-all duration-200 hover:shadow-sm"
          >
            <span className="w-9 h-9 rounded-full bg-blue-100 group-hover:bg-blue-200 flex items-center
              justify-center flex-shrink-0 transition-colors duration-200">
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
              </svg>
            </span>
            <div className="text-left">
              <p className="font-semibold text-sm">Add New Building</p>
              <p className="text-xs text-blue-400 group-hover:text-blue-500 transition-colors">
                Add a project to this entry
              </p>
            </div>
          </button>
        </section>

        {/* Buildings List */}
        <section>
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-base font-semibold text-gray-900">Projects</h2>
            <span className="text-xs text-gray-400 bg-gray-100 px-2.5 py-1 rounded-full">
              {buildings.length} project{buildings.length !== 1 ? 's' : ''}
            </span>
          </div>

          <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            {buildings.length === 0 ? (
              <div className="py-14 text-center text-gray-400">
                <svg className="w-10 h-10 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
                    d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                </svg>
                <p className="text-sm font-medium">No projects yet. Add your first one!</p>
              </div>
            ) : (
              <div className="flex flex-col gap-2 p-3">
                {buildings.map(building => (
                  <BuildingRow
                    key={building.id}
                    building={building}
                    onOpen={() => navigate(`/projects/${id}/buildings/${building.id}/floors`)}
                    onEdit={() => openEdit(building)}
                    onDelete={() => handleDelete(building.id)}
                  />
                ))}
              </div>
            )}
          </div>
        </section>
      </main>

      {/* Add Modal */}
      {showModal && (
        <Modal
          title="New Project"
          form={newBuilding}
          onChange={setNewBuilding}
          onSubmit={handleAdd}
          onClose={() => { setShowModal(false); setNewBuilding({ name: '', area: '' }); }}
          submitLabel="Add Project"
        />
      )}

      {/* Edit Modal */}
      {editingBuilding && (
        <Modal
          title="Edit Project"
          form={editForm}
          onChange={setEditForm}
          onSubmit={handleEdit}
          onClose={() => setEditingBuilding(null)}
          submitLabel="Save Changes"
        />
      )}
    </div>
  );
}

function BuildingRow({ building, onOpen, onEdit, onDelete }) {
  return (
    <div onClick={onOpen} className="flex items-center justify-between px-5 py-4 border border-blue-300 rounded-xl
      cursor-pointer group transition-all duration-200
      hover:bg-blue-50 hover:-translate-y-1 hover:shadow-md">

      {/* Icon + Name */}
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

      {/* Floors */}
      <div className="flex items-center gap-1.5 w-28 text-sm text-gray-500">
        <svg className="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
            d="M3 10h18M3 14h18M3 6h18M3 18h18" />
        </svg>
        <span>{building.floors_count} floor{building.floors_count !== 1 ? 's' : ''}</span>
      </div>

      {/* Area */}
      <div className="flex items-center gap-1.5 w-32 text-sm text-gray-500">
        <svg className="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
            d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
        </svg>
        <span>{Number(building.area).toLocaleString()} m²</span>
      </div>

      {/* Power */}
      <div className="flex items-center gap-1.5 w-32 text-sm text-gray-500">
        <svg className="w-4 h-4 text-yellow-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
        </svg>
        <span>{building.power_consumption}</span>
      </div>

      {/* Added */}
      <div className="text-xs text-gray-400 w-28 hidden md:block">
        {new Date(building.created_at).toLocaleDateString('en-US', {
          month: 'short', day: 'numeric', year: 'numeric',
        })}
      </div>

      {/* Actions */}
      <div className="flex items-center gap-2 flex-shrink-0">
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
            <label className="block text-sm font-medium text-gray-700 mb-1">Project Name</label>
            <input
              type="text"
              autoFocus
              value={form.name}
              onChange={e => onChange({ ...form, name: e.target.value })}
              onKeyDown={e => e.key === 'Enter' && onSubmit()}
              placeholder="e.g. Block A"
              className="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm
                focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Area (m²)</label>
            <input
              type="number"
              min="0.01"
              step="0.01"
              value={form.area}
              onChange={e => onChange({ ...form, area: e.target.value })}
              placeholder="e.g. 100"
              className="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm
                focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            />
          </div>
        </div>

        <div className="flex gap-3 mt-6">
          <button
            onClick={onClose}
            className="flex-1 border border-gray-300 text-gray-700 py-2.5 rounded-lg text-sm
              font-medium hover:bg-gray-50 transition-colors"
          >
            Cancel
          </button>
          <button
            onClick={onSubmit}
            disabled={!form.name.trim() || Number(form.area) <= 0}
            className="flex-1 bg-blue-600 text-white py-2.5 rounded-lg text-sm font-medium
              hover:bg-blue-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
          >
            {submitLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
