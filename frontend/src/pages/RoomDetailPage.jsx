import { useState, useEffect, useRef } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import api from '../api/axios';

export default function RoomDetailPage() {
  const { projectId, buildingId, floorId, roomId } = useParams();
  const navigate = useNavigate();

  const [room, setRoom]           = useState(null);
  const [floor, setFloor]         = useState(null);
  const [building, setBuilding]   = useState(null);
  const [project, setProject]     = useState(null);
  const [components, setComponents] = useState([]);
  const [componentTypes, setComponentTypes] = useState([]);
  const [loading, setLoading]     = useState(true);

  const [showModal, setShowModal]   = useState(false);
  const [newComp, setNewComp]       = useState({ name: '', power: '' });

  const [editingComp, setEditingComp] = useState(null);
  const [editForm, setEditForm]       = useState({ name: '', power: '' });

  useEffect(() => {
    Promise.all([
      api.get(`/api/rooms/${roomId}/components`),
      api.get('/api/component-types'),
    ]).then(([compRes, typesRes]) => {
      const r = compRes.data.room;
      setRoom(r);
      setFloor(r.floor);
      setBuilding(r.floor.building);
      setProject(r.floor.building.project);
      setComponents(compRes.data.data);
      setComponentTypes(typesRes.data.data);
    })
    .catch(() => navigate(`/projects/${projectId}/buildings/${buildingId}/floors/${floorId}/rooms`))
    .finally(() => setLoading(false));
  }, [roomId]);

  async function handleAdd() {
    if (!newComp.name.trim() || Number(newComp.power) <= 0) return;
    const { data } = await api.post(`/api/rooms/${roomId}/components`, {
      component_name: newComp.name.trim(),
      power: newComp.power,
    });
    setComponents([data.data, ...components]);
    // add to types list if new
    if (!componentTypes.find(t => t.name === newComp.name.trim())) {
      setComponentTypes([...componentTypes, data.data.component_type]);
    }
    setNewComp({ name: '', power: '' });
    setShowModal(false);
  }

  function openEdit(comp) {
    setEditingComp(comp);
    setEditForm({ name: comp.component_type.name, power: comp.power });
  }

  async function handleEdit() {
    if (!editForm.name.trim() || Number(editForm.power) <= 0) return;
    const { data } = await api.put(`/api/rooms/${roomId}/components/${editingComp.id}`, {
      component_name: editForm.name.trim(),
      power: editForm.power,
    });
    setComponents(components.map(c => c.id === editingComp.id ? data.data : c));
    setEditingComp(null);
  }

  async function handleDelete(compId) {
    await api.delete(`/api/rooms/${roomId}/components/${compId}`);
    setComponents(components.filter(c => c.id !== compId));
  }

  if (loading) {
    return (
      <div className="min-h-screen bg-base flex items-center justify-center">
        <div className="w-10 h-10 border-4 border-accent border-t-transparent rounded-full animate-spin"></div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-base">

      {/* Header */}
      <header className="bg-surface-card border-b border-line px-6 py-4 flex items-center gap-4">
        <button
          onClick={() => navigate(`/projects/${projectId}/buildings/${buildingId}/floors/${floorId}/rooms`)}
          className="text-ink-muted hover:text-ink-heading transition-colors p-1.5 rounded-lg hover:bg-surface-inset"
        >
          <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
          </svg>
        </button>

        <div>
          {/* Breadcrumb */}
          <div className="flex items-center gap-1.5 text-sm text-ink-muted mb-0.5 flex-wrap">
            <span onClick={() => navigate('/dashboard')}
              className="hover:text-accent cursor-pointer transition-colors">{project?.name}</span>
            <Chevron />
            <span onClick={() => navigate(`/projects/${projectId}/buildings`)}
              className="hover:text-accent cursor-pointer transition-colors">{building?.name}</span>
            <Chevron />
            <span onClick={() => navigate(`/projects/${projectId}/buildings/${buildingId}/floors`)}
              className="hover:text-accent cursor-pointer transition-colors">{floor?.name}</span>
            <Chevron />
            <span onClick={() => navigate(`/projects/${projectId}/buildings/${buildingId}/floors/${floorId}/rooms`)}
              className="hover:text-accent cursor-pointer transition-colors">Rooms</span>
            <Chevron />
            <span className="text-ink-body2 font-medium">{room?.name}</span>
          </div>
          <h1 className="text-lg font-semibold text-ink-heading">{room?.name}</h1>
        </div>
      </header>

      <main className="max-w-5xl mx-auto px-4 sm:px-6 py-8">

        {/* Add Component */}
        <section className="mb-8">
          <h2 className="text-base font-semibold text-ink-heading mb-4">Electrical Components</h2>
          <button
            onClick={() => setShowModal(true)}
            className="group flex items-center gap-3 border-2 border-dashed border-line-strong
              hover:border-accent hover:bg-accent-soft text-accent hover:text-accent-light
              rounded-xl px-5 py-4 w-full transition-all duration-200 hover:shadow-accent"
          >
            <span className="w-9 h-9 rounded-full bg-accent-soft group-hover:bg-accent-softer flex items-center
              justify-center flex-shrink-0 transition-colors duration-200">
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
              </svg>
            </span>
            <div className="text-left">
              <p className="font-semibold text-sm">Add New Component</p>
              <p className="text-xs text-ink-muted group-hover:text-accent-light transition-colors">
                Add an electrical component to this room
              </p>
            </div>
          </button>
        </section>

        {/* Components Grid */}
        <section>
          <div className="flex items-center justify-between mb-4">
            <span className="text-xs text-ink-muted bg-surface-inset px-2.5 py-1 rounded-full">
              {components.length} component{components.length !== 1 ? 's' : ''}
            </span>
          </div>

          {components.length === 0 ? (
            <div className="bg-surface-card rounded-xl border border-line py-14 text-center text-ink-muted">
              <svg className="w-10 h-10 mx-auto mb-3 text-ink-muted2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
                  d="M13 10V3L4 14h7v7l9-11h-7z" />
              </svg>
              <p className="text-sm font-medium">No components yet. Add your first one!</p>
            </div>
          ) : (
            <div className="grid grid-cols-3 gap-3">
              {components.map(comp => (
                <ComponentCard
                  key={comp.id}
                  comp={comp}
                  onEdit={() => openEdit(comp)}
                  onDelete={() => handleDelete(comp.id)}
                />
              ))}
            </div>
          )}
        </section>
      </main>

      {/* Add Modal */}
      {showModal && (
        <ComponentModal
          title="New Component"
          form={newComp}
          onChange={setNewComp}
          onSubmit={handleAdd}
          onClose={() => { setShowModal(false); setNewComp({ name: '', power: '' }); }}
          submitLabel="Add Component"
          componentTypes={componentTypes}
        />
      )}

      {/* Edit Modal */}
      {editingComp && (
        <ComponentModal
          title="Edit Component"
          form={editForm}
          onChange={setEditForm}
          onSubmit={handleEdit}
          onClose={() => setEditingComp(null)}
          submitLabel="Save Changes"
          componentTypes={componentTypes}
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

function ComponentCard({ comp, onEdit, onDelete }) {
  return (
    <div className="flex items-center gap-3 border border-line rounded-xl px-4 bg-surface-card
      cursor-pointer group transition-all duration-200 hover:border-accent-border hover:bg-surface-inset hover:-translate-y-1 hover:shadow-accent"
      style={{ height: '70px' }}>

      {/* Icon */}
      <div className="w-8 h-8 bg-accent-soft group-hover:bg-accent-softer rounded-lg flex items-center
        justify-center flex-shrink-0 transition-colors duration-200">
        <svg className="w-4 h-4 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
        </svg>
      </div>

      {/* Name + Power */}
      <div className="flex-1 min-w-0">
        <p className="font-semibold text-ink-heading text-sm truncate">{comp.component_type.name}</p>
        <p className="text-xs text-ink-muted">
          {Number(comp.power) > 1000
            ? `${(Number(comp.power) / 1000).toLocaleString(undefined, { maximumFractionDigits: 2 })} kVA`
            : `${Number(comp.power).toLocaleString()} VA`}
        </p>
      </div>

      {/* Actions */}
      <div className="flex items-center gap-1.5 flex-shrink-0">
        <button
          onClick={e => { e.stopPropagation(); onEdit(); }}
          className="text-xs font-medium text-ink-body2 px-2.5 py-1
            rounded-lg border border-line bg-surface-card hover:border-accent-border hover:text-accent
            hover:bg-accent-soft transition-all duration-150"
        >
          Edit
        </button>
        <button
          onClick={e => { e.stopPropagation(); onDelete(); }}
          className="text-xs font-medium text-ink-body2 px-2.5 py-1
            rounded-lg border border-line bg-surface-card hover:border-danger-border hover:text-danger
            hover:bg-danger-soft transition-all duration-150"
        >
          Delete
        </button>
      </div>
    </div>
  );
}

function ComponentModal({ title, form, onChange, onSubmit, onClose, submitLabel, componentTypes }) {
  const inputRef = useRef(null);
  const wrapperRef = useRef(null);
  const [showSuggestions, setShowSuggestions] = useState(false);

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

  function selectType(name) {
    onChange({ ...form, name });
    setShowSuggestions(false);
    inputRef.current?.blur();
  }

  const isValid = form.name.trim() && Number(form.power) > 0;

  return (
    <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div className="bg-surface-card border border-line rounded-2xl w-full max-w-sm p-6">
        <h3 className="text-lg font-semibold text-ink-heading mb-5">{title}</h3>

        <div className="space-y-4">
          {/* Component name with suggestions */}
          <div className="relative" ref={wrapperRef}>
            <label className="block text-sm font-medium text-ink-body2 mb-1">Component Name</label>
            <input
              ref={inputRef}
              type="text"
              autoFocus
              value={form.name}
              onChange={e => { onChange({ ...form, name: e.target.value }); setShowSuggestions(true); }}
              onFocus={() => setShowSuggestions(true)}
              onKeyDown={e => {
                if (e.key === 'Escape') setShowSuggestions(false);
                if (e.key === 'Enter' && isValid) onSubmit();
              }}
              placeholder="Select or type a component name"
              className="w-full border border-line rounded-lg px-4 py-2.5 text-sm
                focus:outline-none focus:ring-2 focus:ring-accent/40 focus:border-accent"
            />
            {showSuggestions && filtered.length > 0 && (
              <ul className="absolute z-10 w-full bg-surface-card border border-line rounded-lg
                mt-1 max-h-28 overflow-y-auto">
                {filtered.map(t => (
                  <li
                    key={t.id}
                    onMouseDown={() => selectType(t.name)}
                    className="px-4 py-2 text-sm text-ink-body2 hover:bg-accent-soft hover:text-accent
                      cursor-pointer flex items-center justify-between"
                  >
                    <span>{t.name}</span>
                    {t.is_preset && (
                      <span className="text-xs text-ink-muted bg-surface-inset px-1.5 py-0.5 rounded">preset</span>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </div>

          {/* Power */}
          <div>
            <label className="block text-sm font-medium text-ink-body2 mb-1">Power (W)</label>
            <input
              type="number"
              min="1"
              step="1"
              value={form.power}
              onChange={e => onChange({ ...form, power: e.target.value })}
              onKeyDown={e => e.key === 'Enter' && isValid && onSubmit()}
              placeholder="e.g. 60"
              className="w-full border border-line rounded-lg px-4 py-2.5 text-sm
                focus:outline-none focus:ring-2 focus:ring-accent/40 focus:border-accent"
            />
          </div>
        </div>

        <div className="flex gap-3 mt-6">
          <button onClick={onClose}
            className="flex-1 border border-line text-ink-body2 py-2.5 rounded-lg text-sm
              font-medium hover:bg-surface-inset transition-colors">
            Cancel
          </button>
          <button onClick={onSubmit} disabled={!isValid}
            className="flex-1 bg-accent-gradient text-base py-2.5 rounded-lg text-sm font-medium
              hover:shadow-accent transition-shadow disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:shadow-none">
            {submitLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
