/* eslint-disable react/prop-types */
import { useState, useEffect, useRef } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import api from '../api/axios';

// ── Layout constants ──────────────────────────────────────────────────────────
const W       = 1200;
const SRC_Y   = 100;  // y centre of source nodes
const BUS_Y   = 310;  // y of main busbar
const BLD_Y   = 500;  // y centre of building panels
const NODE_R  = 46;
const BUS_H   = 8;

// Hybrid inverter box dimensions (used when solar + battery are DC-coupled)
const HYBW = 180;
const HYBH = 110;

// Standard IEC breaker sizes (A)
const BREAKER_STEPS = [6, 10, 16, 20, 25, 32, 40, 50, 63, 80, 100, 125, 160, 200, 250, 315, 400, 500, 630, 800, 1000, 1250];

/**
 * Next standard breaker rating for a 3-phase 400 V source.
 * 125 % continuous-load margin per IEC 60898-1 / IEC 60364-4-43.
 */
function nextBreaker(kW, volt = 400, pf = 0.9) {
  if (!kW || kW <= 0) return null;
  const I     = (kW * 1000) / (Math.sqrt(3) * volt * pf);
  const sized = I * 1.25;
  return BREAKER_STEPS.find(s => s >= sized) ?? Math.round(sized);
}

// ── Colour palette ────────────────────────────────────────────────────────────
const C = {
  solar:   { fill: '#fef9c3', stroke: '#ca8a04', text: '#713f12', label: 'Solar PV'       },
  battery: { fill: '#ede9fe', stroke: '#7c3aed', text: '#4c1d95', label: 'BESS'           },
  utility: { fill: '#dbeafe', stroke: '#2563eb', text: '#1e3a8a', label: 'Utility Grid'   },
  gen:     { fill: '#ffedd5', stroke: '#ea580c', text: '#7c2d12', label: 'Generator'      },
  bus:     { fill: '#1e3a8a', stroke: '#1e3a8a' },
  bldg:    { fill: '#f0fdf4', stroke: '#16a34a', text: '#14532d', label: 'Building Panel' },
  hybrid:  { fill: '#ecfeff', stroke: '#0891b2', text: '#164e63', label: 'Hybrid Inv.'    },
};

// ── Helpers ───────────────────────────────────────────────────────────────────
const fmtVA = v => { const n = Number(v)||0; if(n>=1e6) return `${(n/1e6).toFixed(1)} MVA`; if(n>=1e3) return `${(n/1e3).toFixed(1)} kVA`; return `${Math.round(n)} VA`; };
const fmtKW = v => { const n = Number(v)||0; if(n>=1e3) return `${(n/1e3).toFixed(1)} MW`; if(n>=1) return `${n.toFixed(1)} kW`; return `${Math.round(n*1000)} W`; };

// ── SVG node components ───────────────────────────────────────────────────────
function SourceNode({ cx, cy, r, col, title, line1, line2, icon }) {
  return (
    <g>
      <circle cx={cx} cy={cy} r={r + 5} fill={col.fill} stroke={col.stroke} strokeWidth={1.5} opacity={0.4} />
      <circle cx={cx} cy={cy} r={r}     fill={col.fill} stroke={col.stroke} strokeWidth={2.5} />
      <text x={cx} y={cy - 8}  textAnchor="middle" fill={col.text} fontSize={20} fontWeight="bold">{icon}</text>
      <text x={cx} y={cy + 10} textAnchor="middle" fill={col.text} fontSize={11} fontWeight="700">{title}</text>
      {line1 && <text x={cx} y={cy + r + 18} textAnchor="middle" fill="#374151" fontSize={12} fontWeight="700">{line1}</text>}
      {line2 && <text x={cx} y={cy + r + 33} textAnchor="middle" fill="#6b7280" fontSize={10}>{line2}</text>}
    </g>
  );
}

/**
 * Hybrid Inverter group — Solar PV + BESS share a DC bus and one inverter.
 * Rendered as a single rectangular node so it's clear they're not independent branches.
 */
function HybridGroup({ cx, cy, solarKw, solarCount, battUsable, battNominal, battCount }) {
  const bX = cx - HYBW / 2;
  const bY = cy - HYBH / 2;

  return (
    <g>
      {/* Outer glow */}
      <rect x={bX - 4} y={bY - 4} width={HYBW + 8} height={HYBH + 8}
        rx={13} fill={C.hybrid.fill} stroke={C.hybrid.stroke} strokeWidth={1} opacity={0.35} />
      {/* Main box */}
      <rect x={bX} y={bY} width={HYBW} height={HYBH}
        rx={9} fill="white" stroke={C.hybrid.stroke} strokeWidth={1.8} />

      {/* Icon row */}
      <text x={cx - 42} y={bY + 24} textAnchor="middle" fill={C.solar.text} fontSize={20}>☀</text>
      <text x={cx + 42} y={bY + 24} textAnchor="middle" fill={C.battery.text} fontSize={20}>⚡</text>

      {/* DC-bus coupling dashed line between icons */}
      <line x1={cx - 20} y1={bY + 18} x2={cx + 20} y2={bY + 18}
        stroke="#9ca3af" strokeWidth={1.5} strokeDasharray="4 3" />
      <text x={cx} y={bY + 16} textAnchor="middle" fill="#9ca3af" fontSize={7}
        style={{ letterSpacing: '0.08em' }}>DC BUS</text>

      {/* Source names */}
      <text x={cx - 42} y={bY + 38} textAnchor="middle" fill={C.solar.text} fontSize={10} fontWeight="700">Solar PV</text>
      <text x={cx + 42} y={bY + 38} textAnchor="middle" fill={C.battery.text} fontSize={10} fontWeight="700">BESS</text>

      {/* Capacity values */}
      <text x={cx - 42} y={bY + 55} textAnchor="middle" fill="#374151" fontSize={12} fontWeight="800">{solarKw.toFixed(1)} kW</text>
      <text x={cx + 42} y={bY + 55} textAnchor="middle" fill="#374151" fontSize={12} fontWeight="800">{battUsable.toFixed(1)} kWh</text>
      <text x={cx + 42} y={bY + 68} textAnchor="middle" fill="#9ca3af" fontSize={9}>{battNominal.toFixed(1)} kWh nom.</text>

      {/* Count sub-labels */}
      <text x={cx - 42} y={bY + 82} textAnchor="middle" fill="#6b7280" fontSize={9}>{solarCount} system{solarCount !== 1 ? 's' : ''}</text>
      <text x={cx + 42} y={bY + 82} textAnchor="middle" fill="#6b7280" fontSize={9}>{battCount} bank{battCount !== 1 ? 's' : ''}</text>

      {/* Divider */}
      <line x1={bX + 12} y1={bY + 90} x2={bX + HYBW - 12} y2={bY + 90} stroke="#e0f2fe" strokeWidth={1} />

      {/* Hybrid Inverter label */}
      <text x={cx} y={bY + 104} textAnchor="middle" fill={C.hybrid.text} fontSize={11} fontWeight="700">Hybrid Inverter</text>
    </g>
  );
}

function BuildingNode({ cx, cy, w, h, col, name, line1, line2, active }) {
  return (
    <g>
      {active && <rect x={cx - w/2 - 4} y={cy - h/2 - 4} width={w + 8} height={h + 8}
        rx={10} fill={col.fill} stroke={col.stroke} strokeWidth={1} opacity={0.3} />}
      <rect x={cx - w/2} y={cy - h/2} width={w} height={h}
        rx={8} fill={col.fill} stroke={col.stroke} strokeWidth={active ? 2 : 1.5} />
      <text x={cx} y={cy - 14} textAnchor="middle" fill={col.text} fontSize={13} fontWeight="700"
        style={{textTransform:'uppercase',letterSpacing:'0.04em'}}>{name}</text>
      {line1 && <text x={cx} y={cy + 6}  textAnchor="middle" fill={col.text} fontSize={16} fontWeight="800">{line1}</text>}
      {line2 && <text x={cx} y={cy + 24} textAnchor="middle" fill="#6b7280" fontSize={11}>{line2}</text>}
    </g>
  );
}

function Breaker({ cx, cy, ratingA }) {
  const s = 10;
  return (
    <g>
      <rect x={cx - s} y={cy - s} width={s * 2} height={s * 2}
        rx={3} fill="white" stroke="#374151" strokeWidth={2} />
      <line x1={cx - s + 4} y1={cy + s - 4} x2={cx + s - 4} y2={cy - s + 4}
        stroke="#374151" strokeWidth={2} />
      {ratingA != null && (
        <text x={cx + s + 5} y={cy + 4} fill="#374151" fontSize={10} fontWeight="700">{ratingA} A</text>
      )}
    </g>
  );
}

// ── Main page ─────────────────────────────────────────────────────────────────
export default function SingleLineDiagramPage() {
  const { projectId } = useParams();
  const navigate      = useNavigate();
  const svgRef        = useRef(null);

  const [project,   setProject]   = useState(null);
  const [buildings, setBuildings] = useState([]);
  const [sources,   setSources]   = useState({ solar: [], gen: [], util: [], batt: [] });
  const [powerData, setPowerData] = useState(null);
  const [loading,   setLoading]   = useState(true);

  useEffect(() => {
    if (!projectId) return;
    setLoading(true);

    Promise.all([
      api.get(`/api/projects/${projectId}/buildings`),
      api.get(`/api/projects/${projectId}/solar-systems`).catch(() => ({ data: { data: [] } })),
      api.get(`/api/projects/${projectId}/batteries`).catch(() => ({ data: { data: [] } })),
      api.get(`/api/projects/${projectId}/generator-lines`).catch(() => ({ data: { data: [] } })),
      api.get(`/api/projects/${projectId}/utility-lines`).catch(() => ({ data: { data: [] } })),
      api.get(`/api/projects/${projectId}/total-power`).catch(() => ({ data: null })),
    ]).then(([bRes, sRes, batRes, gRes, uRes, pRes]) => {
      setProject(bRes.data.project ?? null);
      setBuildings(bRes.data.data ?? []);
      setSources({
        solar: sRes.data.data  ?? [],
        batt:  batRes.data.data ?? [],
        gen:   gRes.data.data  ?? [],
        util:  uRes.data.data  ?? [],
      });
      setPowerData(pRes.data ?? null);
    }).finally(() => setLoading(false));
  }, [projectId]);

  function downloadSVG() {
    const svg = svgRef.current;
    if (!svg) return;
    const blob = new Blob([svg.outerHTML], { type: 'image/svg+xml' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = `${project?.name ?? 'diagram'}_SLD.svg`;
    a.click();
    URL.revokeObjectURL(url);
  }

  // ── Topology: detect solar-coupled batteries ─────────────────────────────────
  // A battery with solar_system_id set shares a hybrid inverter with that solar
  // system (DC bus coupling). These are grouped into a single Hybrid Inverter node.
  // Standalone batteries (null solar_system_id) remain independent AC-source branches.
  const activeBatt         = sources.batt.filter(b => b.is_active !== false);
  const coupledBatt        = activeBatt.filter(b => b.solar_system_id != null);
  const standaloneBatt     = activeBatt.filter(b => b.solar_system_id == null);
  const activeSolar        = sources.solar.filter(s => s.is_active !== false);
  const hasSolarCoupling   = coupledBatt.length > 0 && activeSolar.length > 0;

  const totalSolarKw       = activeSolar.reduce((a, s) => a + Number(s.capacity_kw  || 0), 0);
  const coupledBattUsable  = coupledBatt.reduce( (a, b) => a + Number(b.usable_capacity_kwh  || 0), 0);
  const coupledBattNominal = coupledBatt.reduce( (a, b) => a + Number(b.nominal_capacity_kwh || 0), 0);
  const coupledBattMaxDch  = coupledBatt.reduce( (a, b) => a + Number(b.max_discharge_power_kw || 0), 0);
  const standBattUsable    = standaloneBatt.reduce((a, b) => a + Number(b.usable_capacity_kwh  || 0), 0);
  const standBattNominal   = standaloneBatt.reduce((a, b) => a + Number(b.nominal_capacity_kwh || 0), 0);
  const standBattMaxDch    = standaloneBatt.reduce((a, b) => a + Number(b.max_discharge_power_kw || 0), 0);
  const totalBattUsable    = activeBatt.reduce((a, b) => a + Number(b.usable_capacity_kwh  || 0), 0);
  const totalBattNominal   = activeBatt.reduce((a, b) => a + Number(b.nominal_capacity_kwh || 0), 0);
  const totalBattMaxDch    = activeBatt.reduce((a, b) => a + Number(b.max_discharge_power_kw || 0), 0);
  const totalGenKw         = sources.gen.reduce( (a, g) => a + Number(g.power || 0), 0) / 1000;
  const totalUtilKva       = sources.util.reduce((a, u) => a + Number(u.power || 0), 0) / 1000;

  // ── Build layout ─────────────────────────────────────────────────────────────
  const srcNodes    = [];   // independent circle-style source nodes
  const hybridNodes = [];   // hybrid inverter rectangle nodes (each covers solar+battery)

  if (hasSolarCoupling) {
    hybridNodes.push({
      solarKw:     totalSolarKw,
      solarCount:  activeSolar.length,
      battUsable:  coupledBattUsable,
      battNominal: coupledBattNominal,
      battMaxDch:  coupledBattMaxDch,
      battCount:   coupledBatt.length,
    });
    // Any batteries NOT linked to solar remain as independent branches
    if (standaloneBatt.length > 0) {
      srcNodes.push({
        type:      'battery',
        label:     'BESS',
        val:       `${standBattUsable.toFixed(1)} kWh usable`,
        sub:       `${standBattNominal.toFixed(1)} kWh nominal`,
        breakerKw: standBattMaxDch,
      });
    }
  } else {
    // No coupling — solar and batteries are separate independent branches
    if (activeSolar.length > 0 || totalSolarKw > 0) {
      srcNodes.push({ type: 'solar', label: 'Solar PV', val: `${totalSolarKw.toFixed(1)} kW`, sub: `${activeSolar.length} system${activeSolar.length!==1?'s':''}`, breakerKw: totalSolarKw });
    }
    if (activeBatt.length > 0 || totalBattUsable > 0) {
      srcNodes.push({ type: 'battery', label: 'BESS', val: `${totalBattUsable.toFixed(1)} kWh usable`, sub: `${totalBattNominal.toFixed(1)} kWh nominal`, breakerKw: totalBattMaxDch });
    }
  }

  if (sources.util.length > 0 || totalUtilKva > 0) {
    srcNodes.push({ type: 'utility', label: 'Utility Grid', val: fmtVA(totalUtilKva * 1000), sub: `${sources.util.length} line${sources.util.length!==1?'s':''}`, breakerKw: totalUtilKva * 0.9 });
  }
  if (sources.gen.length > 0 || totalGenKw > 0) {
    srcNodes.push({ type: 'gen', label: 'Generator', val: fmtKW(totalGenKw), sub: `${sources.gen.length} unit${sources.gen.length!==1?'s':''}`, breakerKw: totalGenKw });
  }

  if (srcNodes.length === 0 && hybridNodes.length === 0) {
    srcNodes.push({ type: 'utility', label: 'No sources', val: '—', sub: 'Add sources', breakerKw: 0 });
  }

  // Hybrid groups take 1 slot each; standalone source nodes take 1 slot each.
  // Hybrid groups are positioned first (left), independent sources follow.
  const totalSlots = hybridNodes.length + srcNodes.length;
  const srcSpacing = W / (totalSlots + 1);
  hybridNodes.forEach((n, i) => { n.cx = srcSpacing * (i + 1);                      n.cy = SRC_Y; });
  srcNodes.forEach(   (n, i) => { n.cx = srcSpacing * (hybridNodes.length + i + 1); n.cy = SRC_Y; });

  // Buildings
  const bldgNodes   = buildings.map(b => ({ id: b.id, name: b.name, type: b.type, floors: b.floors_count ?? 0 }));
  const bldgW       = Math.min(160, Math.max(110, (W - 80) / Math.max(bldgNodes.length, 1) - 20));
  const bldgSpacing = W / (bldgNodes.length + 1);
  bldgNodes.forEach((n, i) => { n.cx = bldgSpacing * (i + 1); n.cy = BLD_Y; });

  // Bus bar x extents
  const allXs = [...hybridNodes.map(n => n.cx), ...srcNodes.map(n => n.cx), ...bldgNodes.map(n => n.cx)];
  const busX1 = Math.min(...allXs, 60) - 20;
  const busX2 = Math.max(...allXs, W - 60) + 20;
  const busCx = (busX1 + busX2) / 2;

  const diagramH = bldgNodes.length > 0 ? BLD_Y + 100 : BUS_Y + 80;
  const viewBox  = `0 0 ${W} ${diagramH}`;

  const icons = { solar: '☀', battery: '⚡', utility: '🔌', gen: '⚙' };
  const cols  = { solar: C.solar, battery: C.battery, utility: C.utility, gen: C.gen };

  // ── Legend: conditional on what is actually present ──────────────────────────
  const legendItems = [];
  if (hasSolarCoupling || activeSolar.length > 0) legendItems.push({ col: C.solar.stroke,   label: 'Solar PV'       });
  if (activeBatt.length > 0 || totalBattUsable > 0)  legendItems.push({ col: C.battery.stroke, label: 'BESS'           });
  if (hasSolarCoupling)                               legendItems.push({ col: C.hybrid.stroke,  label: 'Hybrid Inv.'    });
  if (sources.util.length > 0 || totalUtilKva > 0)   legendItems.push({ col: C.utility.stroke, label: 'Utility Grid'   });
  if (sources.gen.length > 0  || totalGenKw > 0)     legendItems.push({ col: C.gen.stroke,     label: 'Generator'      });
  legendItems.push({ col: C.bldg.stroke, label: 'Building Panel' });

  if (loading) {
    return (
      <div className="flex-1 flex items-center justify-center text-gray-400 text-sm">
        <div className="flex flex-col items-center gap-3">
          <div className="w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
          Loading diagram…
        </div>
      </div>
    );
  }

  return (
    <div className="flex-1 flex flex-col min-h-0 bg-gray-50 p-4 md:p-6 gap-4">

      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <button
            onClick={() => navigate(`/projects/${projectId}`)}
            className="text-gray-400 hover:text-gray-600 transition-colors p-1.5 rounded-lg hover:bg-gray-100"
            title="Back to project"
          >
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
            </svg>
          </button>
          <div>
            <h1 className="text-lg font-bold text-gray-900">Single-Line Diagram</h1>
            <p className="text-xs text-gray-400 mt-0.5">{project?.name} — simplified electrical topology</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <span className="text-xs text-gray-400 bg-white border border-gray-200 px-2 py-1 rounded-lg">
            Auto-generated from project data
          </span>
          <button
            onClick={downloadSVG}
            className="flex items-center gap-1.5 text-xs font-semibold text-blue-700 bg-blue-50 border border-blue-200
              hover:bg-blue-100 px-3 py-1.5 rounded-lg transition-colors"
          >
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
            Download SVG
          </button>
        </div>
      </div>

      {/* Summary strip */}
      {powerData && (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
          {[
            ['Max Load',     fmtVA(powerData.max_va),   'Unoptimized demand'],
            ['Opt. Load',    fmtVA(powerData.total_va), 'Diversity-factored'],
            ['Power Factor', Number(powerData.system_power_factor||0).toFixed(3), 'System PF'],
            ['Buildings',    buildings.length,           'In this project'],
          ].map(([label, value, sub]) => (
            <div key={label} className="bg-white border border-gray-200 rounded-xl p-3">
              <div className="text-xs text-gray-400 font-medium">{label}</div>
              <div className="text-base font-bold text-gray-900 mt-0.5">{value}</div>
              <div className="text-xs text-gray-400">{sub}</div>
            </div>
          ))}
        </div>
      )}

      {/* SVG diagram */}
      <div className="bg-white border border-gray-200 rounded-xl overflow-x-auto shadow-sm">
        <svg
          ref={svgRef}
          viewBox={viewBox}
          width="100%"
          style={{ minWidth: Math.min(W, 600), maxWidth: W, display: 'block', margin: 'auto' }}
          xmlns="http://www.w3.org/2000/svg"
          fontFamily="'Segoe UI', Arial, sans-serif"
        >
          {/* Background grid */}
          <defs>
            <pattern id="grid" width="40" height="40" patternUnits="userSpaceOnUse">
              <path d="M 40 0 L 0 0 0 40" fill="none" stroke="#f1f5f9" strokeWidth="1"/>
            </pattern>
          </defs>
          <rect width={W} height={diagramH} fill="url(#grid)" />
          <rect width={W} height={diagramH} fill="white" opacity="0.7" />

          {/* Title */}
          <text x={W/2} y={20} textAnchor="middle" fill="#94a3b8" fontSize={12}
            fontWeight="500" style={{textTransform:'uppercase',letterSpacing:'0.1em'}}>
            {project?.name ?? 'Project'} — Simplified Single-Line Diagram
          </text>

          {/* ── Hybrid inverter nodes → bus wires ── */}
          {hybridNodes.map((n, i) => {
            const wireY1  = n.cy + HYBH / 2;   // bottom edge of the hybrid box
            const wireY2  = BUS_Y - BUS_H / 2;
            const midY    = (wireY1 + wireY2) / 2;
            const ratingA = nextBreaker(n.solarKw + n.battMaxDch);
            return (
              <g key={`hwire-${i}`}>
                <line x1={n.cx} y1={wireY1} x2={n.cx} y2={midY - 10} stroke="#9ca3af" strokeWidth={1.5} />
                <Breaker cx={n.cx} cy={midY} ratingA={ratingA} />
                <line x1={n.cx} y1={midY + 10} x2={n.cx} y2={wireY2} stroke="#9ca3af" strokeWidth={1.5} />
              </g>
            );
          })}

          {/* ── Standalone source → bus wires ── */}
          {srcNodes.map(n => {
            const wireY1  = n.cy + NODE_R + 4;
            const wireY2  = BUS_Y - BUS_H / 2;
            const midY    = (wireY1 + wireY2) / 2;
            const ratingA = nextBreaker(n.breakerKw ?? 0);
            return (
              <g key={n.type + n.cx}>
                <line x1={n.cx} y1={wireY1} x2={n.cx} y2={midY - 10} stroke="#9ca3af" strokeWidth={1.5} />
                <Breaker cx={n.cx} cy={midY} ratingA={ratingA} />
                <line x1={n.cx} y1={midY + 10} x2={n.cx} y2={wireY2} stroke="#9ca3af" strokeWidth={1.5} />
              </g>
            );
          })}

          {/* ── Main busbar ── */}
          <rect x={busX1} y={BUS_Y - BUS_H/2} width={busX2 - busX1} height={BUS_H}
            rx={3} fill={C.bus.fill} />
          {/* Bus voltage annotation */}
          <text x={busX1 + 12} y={BUS_Y - BUS_H/2 - 7} textAnchor="start"
            fill="#1e3a8a" fontSize={11} fontWeight="700">230 / 400 V · 3-phase</text>
          <text x={busCx} y={BUS_Y + BUS_H/2 + 18} textAnchor="middle"
            fill="#1e3a8a" fontSize={12} fontWeight="700"
            style={{textTransform:'uppercase',letterSpacing:'0.06em'}}>
            MAIN DISTRIBUTION BUS
          </text>
          {powerData && (
            <text x={busCx} y={BUS_Y + BUS_H/2 + 33} textAnchor="middle"
              fill="#6b7280" fontSize={11}>
              {fmtVA(powerData.max_va)} max · {fmtVA(powerData.total_va)} optimized
            </text>
          )}

          {/* ── Bus → building panel wires ── */}
          {bldgNodes.map(n => {
            const wireY1 = BUS_Y + BUS_H / 2;
            const wireY2 = n.cy - 42;
            const midY   = (wireY1 + wireY2) / 2 + 10;
            return (
              <g key={n.id}>
                <line x1={n.cx} y1={wireY1} x2={n.cx} y2={midY - 10} stroke="#9ca3af" strokeWidth={1.5} />
                <Breaker cx={n.cx} cy={midY} ratingA={null} />
                <line x1={n.cx} y1={midY + 10} x2={n.cx} y2={wireY2} stroke="#9ca3af" strokeWidth={1.5} />
              </g>
            );
          })}

          {/* ── Hybrid inverter group nodes ── */}
          {hybridNodes.map((n, i) => (
            <HybridGroup
              key={`hnode-${i}`}
              cx={n.cx}           cy={n.cy}
              solarKw={n.solarKw} solarCount={n.solarCount}
              battUsable={n.battUsable} battNominal={n.battNominal} battCount={n.battCount}
            />
          ))}

          {/* ── Standalone source nodes ── */}
          {srcNodes.map(n => (
            <SourceNode
              key={n.type + n.cx}
              cx={n.cx} cy={n.cy} r={NODE_R}
              col={cols[n.type] ?? C.utility}
              title={n.label}
              line1={n.val}
              line2={n.sub}
              icon={icons[n.type] ?? '⚡'}
            />
          ))}

          {/* ── Building panels ── */}
          {bldgNodes.map(n => (
            <BuildingNode
              key={n.id}
              cx={n.cx} cy={n.cy}
              w={bldgW} h={90}
              col={C.bldg}
              name={n.name.length > 12 ? n.name.slice(0, 11) + '…' : n.name}
              line1={n.floors > 0 ? `${n.floors} floor${n.floors!==1?'s':''}` : ''}
              line2={n.type ?? ''}
              active={false}
            />
          ))}

          {/* No-buildings placeholder */}
          {bldgNodes.length === 0 && (
            <text x={W/2} y={BLD_Y} textAnchor="middle" fill="#9ca3af" fontSize={12}>
              No buildings added yet
            </text>
          )}

          {/* ── Legend — only items that actually appear in this diagram ── */}
          {(() => {
            const legY = diagramH - 22;
            const legX = 20;
            const step = Math.min(130, (W - 40) / legendItems.length);
            return legendItems.map((it, i) => (
              <g key={it.label} transform={`translate(${legX + i * step}, ${legY})`}>
                <rect x={0} y={-9} width={13} height={13} rx={3} fill={it.col} opacity={0.85} />
                <text x={18} y={2} fill="#6b7280" fontSize={11}>{it.label}</text>
              </g>
            ));
          })()}
        </svg>
      </div>

    </div>
  );
}
