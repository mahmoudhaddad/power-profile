import { useState, useEffect, useMemo } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import api from '../api/axios';
import { getNav, setNav } from '../utils/navContext';

// ─── icons ────────────────────────────────────────────────────────────────────

function IconProject() {
  return (
    <svg className="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8}
        d="M3 7a2 2 0 012-2h14a2 2 0 012 2v1H3V7zm0 4h18M3 15h18M5 19h14a2 2 0 002-2v-8H3v8a2 2 0 002 2z" />
    </svg>
  );
}

function IconBuilding() {
  return (
    <svg className="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8}
        d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2M5 21H3M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 8v-4a1 1 0 011-1h2a1 1 0 011 1v4" />
    </svg>
  );
}

function IconFloor() {
  return (
    <svg className="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8}
        d="M4 6h16M4 12h16M4 18h16" />
    </svg>
  );
}

function IconRoom() {
  return (
    <svg className="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8}
        d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
    </svg>
  );
}

function IconSchedule() {
  return (
    <svg className="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8}
        d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
    </svg>
  );
}

function Chevron({ open }) {
  return (
    <svg
      className={`w-3 h-3 flex-shrink-0 transition-transform duration-150 ${open ? 'rotate-90' : ''}`}
      fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M9 5l7 7-7 7" />
    </svg>
  );
}

function Spinner() {
  return (
    <div className="flex items-center justify-center py-1.5">
      <div className="w-3.5 h-3.5 border-2 border-gray-300 border-t-indigo-500 rounded-full animate-spin" />
    </div>
  );
}

// ─── sidebar ─────────────────────────────────────────────────────────────────

export default function ProjectSidebar() {
  const navigate  = useNavigate();
  const location  = useLocation();

  // Re-derive active IDs from sessionStorage on every navigation
  const { projectId, buildingId: activeBuildingId, floorId: activeFloorId, roomId: activeRoomId } =
    useMemo(() => getNav(), [location.pathname]);

  const [project,   setProject]   = useState(null);
  const [buildings, setBuildings] = useState([]);

  // { [buildingId]: Floor[] }
  const [floorMap,      setFloorMap]      = useState({});
  // { [floorId]: Room[] }
  const [roomMap,       setRoomMap]       = useState({});
  // Sets of expanded IDs
  const [openBuildings, setOpenBuildings] = useState(new Set());
  const [openFloors,    setOpenFloors]    = useState(new Set());
  // Loading state
  const [loadingFloors, setLoadingFloors] = useState(new Set());
  const [loadingRooms,  setLoadingRooms]  = useState(new Set());

  // ── initial load ──
  useEffect(() => {
    if (!projectId) return;

    api.get(`/api/projects/${projectId}/buildings`).then(async res => {
      setProject(res.data.project);
      setBuildings(res.data.data);

      if (activeBuildingId) {
        const bId = Number(activeBuildingId);
        setOpenBuildings(new Set([bId]));

        const fRes = await api.get(`/api/buildings/${bId}/floors`);
        const fData = fRes.data.data;
        setFloorMap(prev => ({ ...prev, [bId]: fData }));

        if (activeFloorId) {
          const fId = Number(activeFloorId);
          setOpenFloors(new Set([fId]));

          const rRes = await api.get(`/api/floors/${fId}/rooms`);
          setRoomMap(prev => ({ ...prev, [fId]: rRes.data.data }));
        }
      }
    });
  }, [projectId]);

  // ── auto-expand when navigating outside the sidebar ──
  useEffect(() => {
    if (!activeBuildingId) return;
    const bId = Number(activeBuildingId);
    setOpenBuildings(prev => new Set([...prev, bId]));
    if (!floorMap[bId]) fetchFloors(bId);
  }, [activeBuildingId]);

  useEffect(() => {
    if (!activeFloorId || !activeBuildingId) return;
    const fId = Number(activeFloorId);
    setOpenFloors(prev => new Set([...prev, fId]));
    if (!roomMap[fId]) fetchRooms(fId);
  }, [activeFloorId]);

  // ── fetch helpers ──
  async function fetchFloors(bId) {
    if (loadingFloors.has(bId)) return;
    setLoadingFloors(prev => new Set([...prev, bId]));
    try {
      const res = await api.get(`/api/buildings/${bId}/floors`);
      setFloorMap(prev => ({ ...prev, [bId]: res.data.data }));
    } finally {
      setLoadingFloors(prev => { const n = new Set(prev); n.delete(bId); return n; });
    }
  }

  async function fetchRooms(fId) {
    if (loadingRooms.has(fId)) return;
    setLoadingRooms(prev => new Set([...prev, fId]));
    try {
      const res = await api.get(`/api/floors/${fId}/rooms`);
      setRoomMap(prev => ({ ...prev, [fId]: res.data.data }));
    } finally {
      setLoadingRooms(prev => { const n = new Set(prev); n.delete(fId); return n; });
    }
  }

  // ── toggle expand/collapse ──
  function toggleBuilding(bId) {
    if (openBuildings.has(bId)) {
      setOpenBuildings(prev => { const n = new Set(prev); n.delete(bId); return n; });
    } else {
      setOpenBuildings(prev => new Set([...prev, bId]));
      if (!floorMap[bId]) fetchFloors(bId);
    }
  }

  function toggleFloor(fId) {
    if (openFloors.has(fId)) {
      setOpenFloors(prev => { const n = new Set(prev); n.delete(fId); return n; });
    } else {
      setOpenFloors(prev => new Set([...prev, fId]));
      if (!roomMap[fId]) fetchRooms(fId);
    }
  }

  // ── navigation ──
  function goProject() {
    navigate('/project');
  }

  function goBuilding(building) {
    setNav({ buildingId: building.id, floorId: null, roomId: null });
    navigate('/project/building');
  }

  function goFloor(floor, buildingId) {
    setNav({ buildingId, floorId: floor.id, roomId: null });
    navigate('/project/building/floor');
  }

  function goRoom(room, floorId, buildingId) {
    setNav({ buildingId, floorId, roomId: room.id });
    navigate('/project/building/floor/room');
  }

  // ── active checks ──
  const isOnSchedule = location.pathname === '/project/schedule';
  const isOnProject  = !activeBuildingId && !isOnSchedule;
  const isOnBuilding = bId => Number(activeBuildingId) === Number(bId) && !activeFloorId;
  const isOnFloor    = fId => Number(activeFloorId)    === Number(fId) && !activeRoomId;
  const isOnRoom     = rId => Number(activeRoomId)     === Number(rId);

  return (
    <aside className="w-60 flex-shrink-0 flex flex-col bg-white rounded-xl overflow-hidden max-h-[70vh] shadow-lg">

      {/* Project header */}
      <div className="px-3 pt-4 pb-2 flex-shrink-0">
        <button
          onClick={goProject}
          className={`w-full flex items-center gap-2 px-2.5 py-2 rounded-lg text-left text-sm font-semibold transition-colors
            ${isOnProject
              ? 'bg-indigo-50 text-indigo-700'
              : 'text-gray-700 hover:bg-gray-100'}`}
        >
          <IconProject />
          <span className="truncate">{project?.name ?? '…'}</span>
        </button>
      </div>

      <div className="mx-3 border-t border-gray-100 flex-shrink-0" />

      {/* Schedule link */}
      <div className="px-3 pt-2 flex-shrink-0">
        <button
          onClick={() => navigate('/project/schedule')}
          className={`w-full flex items-center gap-2 px-2.5 py-2 rounded-lg text-left text-sm font-medium transition-colors
            ${isOnSchedule
              ? 'bg-amber-50 text-amber-700'
              : 'text-gray-600 hover:bg-gray-100'}`}
        >
          <IconSchedule />
          <span>Load Schedule</span>
        </button>
      </div>

      <div className="mx-3 border-t border-gray-100 flex-shrink-0 mt-2" />

      {/* Tree */}
      <nav className="flex-1 overflow-y-auto px-2 py-2 space-y-px">
        {buildings.map(building => {
          const bId      = building.id;
          const bOpen    = openBuildings.has(bId);
          const bLoading = loadingFloors.has(bId);
          const bFloors  = floorMap[bId] ?? [];
          const bActive  = isOnBuilding(bId);

          return (
            <div key={bId}>
              {/* Building row */}
              <div className={`flex items-center rounded-lg transition-colors
                ${bActive ? 'bg-indigo-50' : 'hover:bg-gray-50'}`}>

                {/* chevron button */}
                <button
                  onClick={() => toggleBuilding(bId)}
                  className={`flex items-center justify-center w-6 h-7 ml-1 flex-shrink-0 rounded
                    ${bActive ? 'text-indigo-500' : 'text-gray-400 hover:text-gray-600'}`}
                  aria-label={bOpen ? 'Collapse' : 'Expand'}
                >
                  <Chevron open={bOpen} />
                </button>

                {/* label */}
                <button
                  onClick={() => goBuilding(building)}
                  className={`flex-1 flex items-center gap-1.5 px-1 py-1.5 text-sm font-medium text-left min-w-0
                    ${bActive ? 'text-indigo-700' : 'text-gray-700'}`}
                >
                  <IconBuilding />
                  <span className="truncate">{building.name}</span>
                </button>
              </div>

              {/* Floors */}
              {bOpen && (
                <div className="mt-px ml-5">
                  {bLoading
                    ? <Spinner />
                    : bFloors.map(floor => {
                        const fId      = floor.id;
                        const fOpen    = openFloors.has(fId);
                        const fLoading = loadingRooms.has(fId);
                        const fRooms   = roomMap[fId] ?? [];
                        const fActive  = isOnFloor(fId);

                        return (
                          <div key={fId}>
                            {/* Floor row */}
                            <div className={`flex items-center rounded-lg transition-colors
                              ${fActive ? 'bg-indigo-50' : 'hover:bg-gray-50'}`}>

                              <button
                                onClick={() => toggleFloor(fId)}
                                className={`flex items-center justify-center w-6 h-7 ml-1 flex-shrink-0 rounded
                                  ${fActive ? 'text-indigo-500' : 'text-gray-400 hover:text-gray-600'}`}
                                aria-label={fOpen ? 'Collapse' : 'Expand'}
                              >
                                <Chevron open={fOpen} />
                              </button>

                              <button
                                onClick={() => goFloor(floor, bId)}
                                className={`flex-1 flex items-center gap-1.5 px-1 py-1.5 text-sm text-left min-w-0
                                  ${fActive ? 'text-indigo-700 font-medium' : 'text-gray-600'}`}
                              >
                                <IconFloor />
                                <span className="truncate">{floor.name}</span>
                              </button>
                            </div>

                            {/* Rooms */}
                            {fOpen && (
                              <div className="mt-px ml-5">
                                {fLoading
                                  ? <Spinner />
                                  : fRooms.map(room => {
                                      const rActive = isOnRoom(room.id);
                                      return (
                                        <button
                                          key={room.id}
                                          onClick={() => goRoom(room, fId, bId)}
                                          className={`w-full flex items-center gap-1.5 pl-2 pr-2 py-1.5 rounded-lg
                                            text-sm text-left transition-colors
                                            ${rActive
                                              ? 'bg-indigo-50 text-indigo-700 font-medium'
                                              : 'text-gray-500 hover:bg-gray-50 hover:text-gray-700'}`}
                                        >
                                          <IconRoom />
                                          <span className="truncate">{room.name}</span>
                                        </button>
                                      );
                                    })
                                }
                                {!fLoading && fRooms.length === 0 && (
                                  <p className="pl-2 py-1 text-xs text-gray-400">No rooms</p>
                                )}
                              </div>
                            )}
                          </div>
                        );
                      })
                  }
                  {!bLoading && bFloors.length === 0 && (
                    <p className="pl-2 py-1 text-xs text-gray-400">No floors</p>
                  )}
                </div>
              )}
            </div>
          );
        })}
      </nav>
    </aside>
  );
}
