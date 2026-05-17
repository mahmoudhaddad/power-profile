import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import api from '../api/axios';

export default function RoomPage() {
  const { projectId, buildingId, floorId } = useParams();
  const navigate = useNavigate();

  const [floor, setFloor]     = useState(null);
  const [building, setBuilding] = useState(null);
  const [project, setProject] = useState(null);
  const [rooms, setRooms]     = useState([]);
  const [loading, setLoading] = useState(true);

  const [showModal, setShowModal] = useState(false);
  const [newRoom, setNewRoom]     = useState({ name: '', area: '' });

  const [editingRoom, setEditingRoom] = useState(null);
  const [editForm, setEditForm]       = useState({ name: '', area: '' });

  const [editingName, setEditingName] = useState(false);
  const [nameInput, setNameInput]     = useState('');

  useEffect(() => {
    api.get(`/api/floors/${floorId}/rooms`)
      .then(({ data }) => {
        setFloor(data.floor);
        setBuilding(data.floor.building);
        setProject(data.floor.building.project);
        setNameInput(data.floor.name);
        setRooms(data.data);
      })
      .catch(() => navigate(`/projects/${projectId}/buildings/${buildingId}/floors`))
      .finally(() => setLoading(false));
  }, [floorId]);

  async function saveName() {
    if (!nameInput.trim() || nameInput === floor.name) { setEditingName(false); return; }
    const { data } = await api.put(
      `/api/buildings/${buildingId}/floors/${floorId}`,
      { name: nameInput.trim() }
    );
    setFloor(data.data);
    setEditingName(false);
  }

  async function handleAdd() {
    if (!newRoom.name.trim()) return;
    const { data } = await api.post(`/api/floors/${floorId}/rooms`, {
      name: newRoom.name.trim(),
      area: newRoom.area || 0,
    });
    setRooms([data.data, ...rooms]);
    setNewRoom({ name: '', area: '' });
    setShowModal(false);
  }

  function openEdit(room) {
    setEditingRoom(room);
    setEditForm({ name: room.name, area: room.area });
  }

  async function handleEdit() {
    if (!editForm.name.trim()) return;
    const { data } = await api.put(
      `/api/floors/${floorId}/rooms/${editingRoom.id}`,
      { name: editForm.name.trim(), area: editForm.area }
    );
    setRooms(rooms.map(r => r.id === editingRoom.id ? data.data : r));
    setEditingRoom(null);
  }

  async function handleDelete(roomId) {
    await api.delete(`/api/floors/${floorId}/rooms/${roomId}`);
    setRooms(rooms.filter(r => r.id !== roomId));
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
          onClick={() => navigate(`/projects/${projectId}/buildings/${buildingId}/floors`)}
          className="text-gray-400 hover:text-gray-600 transition-colors p-1.5 rounded-lg hover:bg-gray-100"
        >
          <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
          </svg>
        </button>

        <div>
          {/* Breadcrumb */}
          <div className="flex items-center gap-1.5 text-sm text-gray-400 mb-0.5 flex-wrap">
            <span onClick={() => navigate('/dashboard')}
              className="hover:text-blue-500 cursor-pointer transition-colors">Projects</span>
            <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
            </svg>
            <span onClick={() => navigate(`/projects/${projectId}/buildings`)}
              className="hover:text-blue-500 cursor-pointer transition-colors">{project?.name}</span>
            <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
            </svg>
            <span onClick={() => navigate(`/projects/${projectId}/buildings/${buildingId}/floors`)}
              className="hover:text-blue-500 cursor-pointer transition-colors">{building?.name}</span>
            <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
            </svg>
            <span className="text-gray-600 font-medium">{floor?.name}</span>
          </div>

          {/* Editable Floor Name */}
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
                  rounded hover:bg-blue-50 transition-colors">Save</button>
              <button onClick={() => setEditingName(false)}
                className="text-xs text-gray-400 hover:text-gray-600 font-medium px-2 py-1
                  rounded hover:bg-gray-100 transition-colors">Cancel</button>
            </div>
          ) : (
            <div className="flex items-center gap-2 mt-0.5">
              <h1 className="text-lg font-semibold text-gray-900">{floor?.name}</h1>
              <button
                onClick={() => { setNameInput(floor.name); setEditingName(true); }}
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

        {/* Add Room */}
        <section className="mb-8">
          <h2 className="text-base font-semibold text-gray-900 mb-4">Add Room</h2>
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
              <p className="font-semibold text-sm">Add New Room</p>
              <p className="text-xs text-blue-400 group-hover:text-blue-500 transition-colors">
                Add a room to this floor
              </p>
            </div>
          </button>
        </section>

        {/* Rooms List */}
        <section>
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-base font-semibold text-gray-900">Rooms</h2>
            <span className="text-xs text-gray-400 bg-gray-100 px-2.5 py-1 rounded-full">
              {rooms.length} room{rooms.length !== 1 ? 's' : ''}
            </span>
          </div>

          <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            {rooms.length === 0 ? (
              <div className="py-14 text-center text-gray-400">
                <svg className="w-10 h-10 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
                    d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                </svg>
                <p className="text-sm font-medium">No rooms yet. Add your first one!</p>
              </div>
            ) : (
              <div className="grid grid-cols-4 gap-4 p-4">
                {rooms.map(room => (
                  <RoomCard
                    key={room.id}
                    room={room}
                    onOpen={() => navigate(`/projects/${projectId}/buildings/${buildingId}/floors/${floorId}/rooms/${room.id}`)}
                    onEdit={() => openEdit(room)}
                    onDelete={() => handleDelete(room.id)}
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
          title="New Room"
          form={newRoom}
          onChange={setNewRoom}
          onSubmit={handleAdd}
          onClose={() => { setShowModal(false); setNewRoom({ name: '', area: '' }); }}
          submitLabel="Add Room"
        />
      )}

      {/* Edit Modal */}
      {editingRoom && (
        <Modal
          title="Edit Room"
          form={editForm}
          onChange={setEditForm}
          onSubmit={handleEdit}
          onClose={() => setEditingRoom(null)}
          submitLabel="Save Changes"
        />
      )}
    </div>
  );
}

function RoomCard({ room, onOpen, onEdit, onDelete }) {
  return (
    <div onClick={onOpen} className="aspect-square flex flex-col border border-blue-300 rounded-xl p-4 cursor-pointer
      group transition-all duration-200 hover:bg-blue-50
      hover:-translate-y-1 hover:shadow-md bg-white">

      {/* Icon */}
      <div className="w-10 h-10 bg-blue-50 group-hover:bg-blue-100 rounded-lg flex items-center
        justify-center mb-3 flex-shrink-0 transition-colors duration-200">
        <svg className="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
            d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
        </svg>
      </div>

      {/* Name */}
      <p className="font-semibold text-gray-900 text-sm truncate mb-1">{room.name}</p>

      {/* Area */}
      <p className="text-xs text-gray-400 mb-auto">{Number(room.area).toLocaleString()} m²</p>

      {/* Actions */}
      <div className="flex items-center gap-1.5 mt-3">
        <button
          onClick={e => { e.stopPropagation(); onEdit(); }}
          className="flex-1 text-xs font-medium text-gray-500 py-1.5
            rounded-lg border border-gray-200 bg-white hover:border-blue-400 hover:text-blue-600
            hover:bg-blue-50 transition-all duration-150"
        >
          Edit
        </button>
        <button
          onClick={e => { e.stopPropagation(); onDelete(); }}
          className="flex-1 text-xs font-medium text-gray-500 py-1.5
            rounded-lg border border-gray-200 bg-white hover:border-red-300 hover:text-red-600
            hover:bg-red-50 transition-all duration-150"
        >
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
            <label className="block text-sm font-medium text-gray-700 mb-1">Room Name</label>
            <input
              type="text"
              autoFocus
              value={form.name}
              onChange={e => onChange({ ...form, name: e.target.value })}
              onKeyDown={e => e.key === 'Enter' && onSubmit()}
              placeholder="e.g. Living Room"
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
              placeholder="e.g. 20"
              className="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm
                focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            />
          </div>
        </div>

        <div className="flex gap-3 mt-6">
          <button onClick={onClose}
            className="flex-1 border border-gray-300 text-gray-700 py-2.5 rounded-lg text-sm
              font-medium hover:bg-gray-50 transition-colors">
            Cancel
          </button>
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
