/* eslint-disable react/prop-types */
import { useState, useEffect } from 'react';
import api from '../api/axios';
import { printPowerReport } from '../utils/printPowerReport';
import { exportScopedExcel } from '../utils/exportToExcel';

const SCOPES = [
  { key: 'project',  label: 'Whole Project', icon: '🏗️' },
  { key: 'building', label: 'Building',       icon: '🏢' },
  { key: 'floor',    label: 'Floor',          icon: '📐' },
  { key: 'room',     label: 'Room',           icon: '🚪' },
];

export default function ReportScopeModal({ projectId, projectName, capApplied, engineerName, onClose }) {
  const [scope, setScope]                   = useState('project');
  const [buildings, setBuildings]           = useState([]);
  const [floors, setFloors]                 = useState([]);
  const [rooms, setRooms]                   = useState([]);
  const [selectedBuilding, setSelectedBuilding] = useState(null);
  const [selectedFloor, setSelectedFloor]       = useState(null);
  const [selectedRoom, setSelectedRoom]         = useState(null);
  const [loadingEntities, setLoadingEntities]   = useState(false);
  const [generating, setGenerating]             = useState(null); // 'pdf' | 'excel' | null
  const [error, setError]                       = useState(null);

  // Fetch buildings whenever scope needs them
  useEffect(() => {
    if (scope === 'project') return;
    setLoadingEntities(true);
    setBuildings([]);
    setFloors([]);
    setRooms([]);
    setSelectedBuilding(null);
    setSelectedFloor(null);
    setSelectedRoom(null);
    api.get(`/api/projects/${projectId}/buildings`)
      .then(({ data }) => setBuildings(data.data ?? []))
      .catch(() => setBuildings([]))
      .finally(() => setLoadingEntities(false));
  }, [scope, projectId]);

  // Fetch floors when building selected
  useEffect(() => {
    if (!selectedBuilding || scope === 'building') return;
    setLoadingEntities(true);
    setFloors([]);
    setRooms([]);
    setSelectedFloor(null);
    setSelectedRoom(null);
    api.get(`/api/buildings/${selectedBuilding.id}/floors`)
      .then(({ data }) => setFloors(data.data ?? []))
      .catch(() => setFloors([]))
      .finally(() => setLoadingEntities(false));
  }, [selectedBuilding, scope]);

  // Fetch rooms when floor selected
  useEffect(() => {
    if (!selectedFloor || scope !== 'room') return;
    setLoadingEntities(true);
    setRooms([]);
    setSelectedRoom(null);
    api.get(`/api/floors/${selectedFloor.id}/rooms`)
      .then(({ data }) => setRooms(data.data ?? []))
      .catch(() => setRooms([]))
      .finally(() => setLoadingEntities(false));
  }, [selectedFloor, scope]);

  function getReportInfo() {
    switch (scope) {
      case 'project':
        return { endpoint: `/api/projects/${projectId}/total-power`, title: projectName, entityId: projectId, entityType: 'project' };
      case 'building':
        return selectedBuilding
          ? { endpoint: `/api/buildings/${selectedBuilding.id}/total-power`, title: selectedBuilding.name, entityId: selectedBuilding.id, entityType: 'building' }
          : null;
      case 'floor':
        return selectedBuilding && selectedFloor
          ? { endpoint: `/api/floors/${selectedFloor.id}/total-power`, title: `${selectedBuilding.name} — ${selectedFloor.name}`, entityId: selectedFloor.id, entityType: 'floor' }
          : null;
      case 'room':
        return selectedBuilding && selectedFloor && selectedRoom
          ? { endpoint: `/api/rooms/${selectedRoom.id}/total-power`, title: `${selectedBuilding.name} — ${selectedFloor.name} — ${selectedRoom.name}`, entityId: selectedRoom.id, entityType: 'room' }
          : null;
      default:
        return null;
    }
  }

  const canGenerate = scope === 'project'
    || (scope === 'building' && selectedBuilding)
    || (scope === 'floor'    && selectedBuilding && selectedFloor)
    || (scope === 'room'     && selectedBuilding && selectedFloor && selectedRoom);

  async function handleGenerate(type) {
    const info = getReportInfo();
    if (!info || !canGenerate) return;
    setGenerating(type);
    setError(null);
    try {
      // Fetch power data + financial data (project scope only) in parallel
      const requests = [api.get(info.endpoint)];
      if (info.entityType === 'project') {
        requests.push(api.get(`/api/projects/${projectId}/financial-analysis`).catch(() => null));
      }
      const [powerRes, finRes] = await Promise.all(requests);
      const powerData     = powerRes.data;
      const financialData = finRes?.data ?? null;

      if (type === 'pdf') {
        printPowerReport(powerData, info.title, { capApplied, engineerName, financialData });
      } else {
        await exportScopedExcel({
          projectId,
          projectName,
          title: info.title,
          powerData,
          financialData,
          engineerName,
          scope: info.entityType,
          entityId: info.entityId,
        });
      }
      onClose();
    } catch {
      setError('Failed to fetch power data. Please try again.');
    } finally {
      setGenerating(null);
    }
  }

  function handleScopeChange(key) {
    setScope(key);
    setError(null);
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      {/* Backdrop */}
      <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />

      {/* Modal */}
      <div className="relative bg-gray-900 border border-white/10 rounded-2xl shadow-2xl w-full max-w-md">

        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-white/10">
          <div className="flex items-center gap-3">
            <div className="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center">
              <svg className="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414A1 1 0 0121 9.414V19a2 2 0 01-2 2z" />
              </svg>
            </div>
            <div>
              <h2 className="text-sm font-semibold text-white">Generate Report</h2>
              <p className="text-[11px] text-gray-400">Select the scope of the report</p>
            </div>
          </div>
          <button onClick={onClose}
            className="w-7 h-7 flex items-center justify-center rounded-lg text-gray-400 hover:text-white hover:bg-white/10 transition-colors">
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        {/* Scope selector */}
        <div className="px-6 pt-4 pb-3">
          <p className="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-2">Report Scope</p>
          <div className="grid grid-cols-4 gap-2">
            {SCOPES.map(({ key, label, icon }) => (
              <button
                key={key}
                onClick={() => handleScopeChange(key)}
                className={`flex flex-col items-center gap-1.5 p-3 rounded-xl border text-center transition-all
                  ${scope === key
                    ? 'bg-blue-600/20 border-blue-500/60 text-blue-300'
                    : 'border-white/10 text-gray-400 hover:border-white/20 hover:text-gray-200 hover:bg-white/5'}`}
              >
                <span className="text-lg">{icon}</span>
                <span className="text-[10px] font-semibold leading-tight">{label}</span>
              </button>
            ))}
          </div>
        </div>

        {/* Entity selectors */}
        {scope !== 'project' && (
          <div className="px-6 pb-3 space-y-3">

            {/* Building selector */}
            <div>
              <label className="block text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-1.5">Building</label>
              {loadingEntities && !buildings.length ? (
                <div className="h-9 bg-white/5 rounded-lg animate-pulse" />
              ) : (
                <select
                  value={selectedBuilding?.id ?? ''}
                  onChange={e => {
                    const b = buildings.find(x => x.id === Number(e.target.value));
                    setSelectedBuilding(b ?? null);
                  }}
                  className="w-full h-9 bg-gray-800 border border-white/10 rounded-lg px-3 text-sm text-white
                    focus:outline-none focus:border-blue-500 transition-colors"
                >
                  <option value="">— Select a building —</option>
                  {buildings.map(b => (
                    <option key={b.id} value={b.id}>{b.name}</option>
                  ))}
                </select>
              )}
            </div>

            {/* Floor selector */}
            {(scope === 'floor' || scope === 'room') && selectedBuilding && (
              <div>
                <label className="block text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-1.5">Floor</label>
                {loadingEntities && !floors.length ? (
                  <div className="h-9 bg-white/5 rounded-lg animate-pulse" />
                ) : (
                  <select
                    value={selectedFloor?.id ?? ''}
                    onChange={e => {
                      const f = floors.find(x => x.id === Number(e.target.value));
                      setSelectedFloor(f ?? null);
                    }}
                    className="w-full h-9 bg-gray-800 border border-white/10 rounded-lg px-3 text-sm text-white
                      focus:outline-none focus:border-blue-500 transition-colors"
                  >
                    <option value="">— Select a floor —</option>
                    {floors.map(f => (
                      <option key={f.id} value={f.id}>{f.name}</option>
                    ))}
                  </select>
                )}
              </div>
            )}

            {/* Room selector */}
            {scope === 'room' && selectedFloor && (
              <div>
                <label className="block text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-1.5">Room</label>
                {loadingEntities && !rooms.length ? (
                  <div className="h-9 bg-white/5 rounded-lg animate-pulse" />
                ) : (
                  <select
                    value={selectedRoom?.id ?? ''}
                    onChange={e => {
                      const r = rooms.find(x => x.id === Number(e.target.value));
                      setSelectedRoom(r ?? null);
                    }}
                    className="w-full h-9 bg-gray-800 border border-white/10 rounded-lg px-3 text-sm text-white
                      focus:outline-none focus:border-blue-500 transition-colors"
                  >
                    <option value="">— Select a room —</option>
                    {rooms.map(r => (
                      <option key={r.id} value={r.id}>{r.name}</option>
                    ))}
                  </select>
                )}
              </div>
            )}
          </div>
        )}

        {/* Error */}
        {error && (
          <div className="mx-6 mb-3 px-3 py-2 bg-red-500/15 border border-red-500/30 rounded-lg text-xs text-red-300">
            {error}
          </div>
        )}

        {/* Actions */}
        <div className="px-6 pb-5 pt-2 flex gap-3">
          {/* PDF button */}
          <button
            disabled={!canGenerate || !!generating}
            onClick={() => handleGenerate('pdf')}
            className="flex-1 flex items-center justify-center gap-2 h-10 rounded-xl text-sm font-semibold
              bg-blue-600 hover:bg-blue-500 disabled:opacity-40 disabled:cursor-not-allowed transition-colors text-white"
          >
            {generating === 'pdf' ? (
              <div className="w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin" />
            ) : (
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
              </svg>
            )}
            PDF Report
          </button>

          {/* Excel button */}
          <button
            disabled={!canGenerate || !!generating}
            onClick={() => handleGenerate('excel')}
            className="flex-1 flex items-center justify-center gap-2 h-10 rounded-xl text-sm font-semibold
              bg-emerald-700 hover:bg-emerald-600 disabled:opacity-40 disabled:cursor-not-allowed transition-colors text-white"
          >
            {generating === 'excel' ? (
              <div className="w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin" />
            ) : (
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M3 10h18M3 14h18M10 3v18M14 3v18M3 3h18v18H3z" />
              </svg>
            )}
            Excel
          </button>
        </div>
      </div>
    </div>
  );
}
