/* eslint-disable react/prop-types */
import { useState, useEffect, useRef } from 'react';
import { useParams } from 'react-router-dom';
import api from '../api/axios';

// ── Layout constants ──────────────────────────────────────────────────────────
const W        = 900;
const SRC_Y    = 60;   // y centre of source nodes
const BUS_Y    = 200;  // y of main busbar
const BLD_Y    = 340;  // y centre of building panels
const NODE_R   = 32;
const BUS_H    = 6;

// ── Colour palette ────────────────────────────────────────────────────────────
const C = {
  solar:   { fill: '#fef9c3', stroke: '#ca8a04', text: '#713f12', label: 'Solar'     },
  battery: { fill: '#ede9fe', stroke: '#7c3aed', text: '#4c1d95', label: 'BESS'      },
  utility: { fill: '#dbeafe', stroke: '#2563eb', text: '#1e3a8a', label: 'Grid'      },
  gen:     { fill: '#ffedd5', stroke: '#ea580c', text: '#7c2d12', label: 'Generator' },
  bus:     { fill: '#1e3a8a', stroke: '#1e3a8a' },
  bldg:    { fill: '#f0fdf4', stroke: '#16a34a', text: '#14532d', label: 'Building'  },
};

// ── Helpers ───────────────────────────────────────────────────────────────────
const fmtVA = v => { const n = Number(v)||0; if(n>=1e6) return `${(n/1e6).toFixed(1)} MVA`; if(n>=1e3) return `${(n/1e3).toFixed(1)} kVA`; return `${Math.round(n)} VA`; };
const fmtKW = v => { const n = Number(v)||0; if(n>=1e3) return `${(n/1e3).toFixed(1)} MW`; if(n>=1) return `${n.toFixed(1)} kW`; return `${Math.round(n*1000)} W`; };

// ── SVG node components ───────────────────────────────────────────────────────
function SourceNode({ cx, cy, r, col, title, line1, line2, icon }) {
  return (
    <g>
      <circle cx={cx} cy={cy} r={r + 4} fill={col.fill} stroke={col.stroke} strokeWidth={1.5} opacity={0.4} />
      <circle cx={cx} cy={cy} r={r}     fill={col.fill} stroke={col.stroke} strokeWidth={2} />
      <text x={cx} y={cy - 6}  textAnchor="middle" fill={col.text} fontSize={14} fontWeight="bold">{icon}</text>
      <text x={cx} y={cy + 7}  textAnchor="middle" fill={col.text} fontSize={8}  fontWeight="600">{title}</text>
      {line1 && <text x={cx} y={cy + r + 14} textAnchor="middle" fill="#374151" fontSize={9}  fontWeight="600">{line1}</text>}
      {line2 && <text x={cx} y={cy + r + 25} textAnchor="middle" fill="#6b7280" fontSize={8}>{line2}</text>}
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
      <text x={cx} y={cy - 10} textAnchor="middle" fill={col.text} fontSize={10} fontWeight="700"
        style={{textTransform:'uppercase',letterSpacing:'0.04em'}}>{name}</text>
      {line1 && <text x={cx} y={cy + 5}  textAnchor="middle" fill={col.text} fontSize={12} fontWeight="800">{line1}</text>}
      {line2 && <text x={cx} y={cy + 19} textAnchor="middle" fill="#6b7280" fontSize={8}>{line2}</text>}
    </g>
  );
}

function Wire({ x1, y1, x2, y2, dashed }) {
  return (
    <line x1={x1} y1={y1} x2={x2} y2={y2}
      stroke="#6b7280" strokeWidth={1.5}
      strokeDasharray={dashed ? '5 3' : undefined}
    />
  );
}

function Breaker({ cx, cy }) {
  const s = 7;
  return (
    <g>
      <rect x={cx - s} y={cy - s} width={s * 2} height={s * 2}
        rx={2} fill="white" stroke="#374151" strokeWidth={1.5} />
      <line x1={cx - s + 3} y1={cy + s - 3} x2={cx + s - 3} y2={cy - s + 3}
        stroke="#374151" strokeWidth={1.5} />
    </g>
  );
}

// ── Main page ─────────────────────────────────────────────────────────────────
export default function SingleLineDiagramPage() {
  const { projectId } = useParams();
  const svgRef        = useRef(null);

  const [project,    setProject]    = useState(null);
  const [buildings,  setBuildings]  = useState([]);
  const [sources,    setSources]    = useState({ solar: [], gen: [], util: [], batt: [] });
  const [powerData,  setPowerData]  = useState(null);
  const [loading,    setLoading]    = useState(true);
  const [tooltip,    setTooltip]    = useState(null);  // { x, y, content[] }

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
    const svg  = svgRef.current;
    if (!svg) return;
    const blob = new Blob([svg.outerHTML], { type: 'image/svg+xml' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = `${project?.name ?? 'diagram'}_SLD.svg`;
    a.click();
    URL.revokeObjectURL(url);
  }

  // ── Build layout ─────────────────────────────────────────────────────────────
  const srcNodes   = [];
  const bldgNodes  = [];

  // Sources
  const totalSolarKw  = sources.solar.filter(s => s.is_active).reduce((a, s) => a + Number(s.capacity_kw||0), 0);
  const totalBattKwh  = sources.batt.filter(b => b.is_active).reduce((a, b) => a + Number(b.usable_capacity_kwh||0), 0);
  const totalGenKva   = sources.gen.reduce((a, g) => a + Number(g.power||0), 0) / 1000;
  const totalUtilKva  = sources.util.reduce((a, u) => a + Number(u.power||0), 0) / 1000;

  if (sources.solar.length > 0 || totalSolarKw > 0) srcNodes.push({ type: 'solar',   label: 'Solar PV',   val: `${totalSolarKw.toFixed(1)} kW`,   sub: `${sources.solar.length} system${sources.solar.length!==1?'s':''}` });
  if (sources.batt.length  > 0 || totalBattKwh > 0) srcNodes.push({ type: 'battery', label: 'BESS',        val: `${totalBattKwh.toFixed(1)} kWh`,  sub: `${sources.batt.length} bank${sources.batt.length!==1?'s':''}` });
  if (sources.util.length  > 0 || totalUtilKva > 0) srcNodes.push({ type: 'utility', label: 'Utility Grid', val: fmtVA(totalUtilKva * 1000),      sub: `${sources.util.length} line${sources.util.length!==1?'s':''}` });
  if (sources.gen.length   > 0 || totalGenKva  > 0) srcNodes.push({ type: 'gen',     label: 'Generator',   val: fmtKW(totalGenKva),               sub: `${sources.gen.length} unit${sources.gen.length!==1?'s':''}` });

  // If no sources defined, show placeholder
  if (srcNodes.length === 0) srcNodes.push({ type: 'utility', label: 'No sources', val: '—', sub: 'Add sources' });

  // Source X positions (evenly spaced)
  const srcSpacing = W / (srcNodes.length + 1);
  srcNodes.forEach((n, i) => { n.cx = srcSpacing * (i + 1); n.cy = SRC_Y; });

  // Buildings
  buildings.forEach((b, i) => {
    bldgNodes.push({
      id:    b.id,
      name:  b.name,
      type:  b.type,
      floors: b.floors_count ?? 0,
    });
  });
  const bldgW = Math.min(120, Math.max(80, (W - 80) / Math.max(bldgNodes.length, 1) - 16));
  const bldgSpacing = W / (bldgNodes.length + 1);
  bldgNodes.forEach((n, i) => { n.cx = bldgSpacing * (i + 1); n.cy = BLD_Y; });

  // Bus bar x extents
  const allXs = [...srcNodes.map(n => n.cx), ...bldgNodes.map(n => n.cx)];
  const busX1 = Math.min(...allXs, 60) - 20;
  const busX2 = Math.max(...allXs, W - 60) + 20;
  const busCx = (busX1 + busX2) / 2;

  // Total diagram height
  const diagramH = bldgNodes.length > 0 ? BLD_Y + 100 : BUS_Y + 80;
  const viewBox  = `0 0 ${W} ${diagramH}`;

  // Source icons
  const icons = { solar: '☀', battery: '⚡', utility: '🔌', gen: '⚙' };
  const cols  = { solar: C.solar, battery: C.battery, utility: C.utility, gen: C.gen };

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
        <div>
          <h1 className="text-lg font-bold text-gray-900">Single-Line Diagram</h1>
          <p className="text-xs text-gray-400 mt-0.5">{project?.name} — simplified electrical topology</p>
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
            ['Max Load',    fmtVA(powerData.max_va),    'Unoptimized demand'],
            ['Opt. Load',   fmtVA(powerData.total_va),  'Diversity-factored'],
            ['Power Factor', Number(powerData.system_power_factor||0).toFixed(3), 'System PF'],
            ['Buildings',   buildings.length,            'In this project'],
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
          style={{ minWidth: Math.min(W, 500), maxWidth: W, display: 'block', margin: 'auto' }}
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
          <text x={W/2} y={16} textAnchor="middle" fill="#94a3b8" fontSize={9}
            fontWeight="500" style={{textTransform:'uppercase',letterSpacing:'0.1em'}}>
            {project?.name ?? 'Project'} — Simplified Single-Line Diagram
          </text>

          {/* ── Source → Bus wires ── */}
          {srcNodes.map(n => {
            const wireY1 = n.cy + NODE_R + 4;
            const wireY2 = BUS_Y - BUS_H / 2;
            const midY   = (wireY1 + wireY2) / 2;
            return (
              <g key={n.type + n.cx}>
                <line x1={n.cx} y1={wireY1} x2={n.cx} y2={midY - 10}
                  stroke="#9ca3af" strokeWidth={1.5} />
                <Breaker cx={n.cx} cy={midY} />
                <line x1={n.cx} y1={midY + 10} x2={n.cx} y2={wireY2}
                  stroke="#9ca3af" strokeWidth={1.5} />
              </g>
            );
          })}

          {/* ── Main busbar ── */}
          <rect x={busX1} y={BUS_Y - BUS_H/2} width={busX2 - busX1} height={BUS_H}
            rx={3} fill={C.bus.fill} />
          <text x={busCx} y={BUS_Y + BUS_H/2 + 13} textAnchor="middle"
            fill="#1e3a8a" fontSize={9} fontWeight="700"
            style={{textTransform:'uppercase',letterSpacing:'0.06em'}}>
            MAIN DISTRIBUTION BUS
          </text>
          {powerData && (
            <text x={busCx} y={BUS_Y + BUS_H/2 + 24} textAnchor="middle"
              fill="#6b7280" fontSize={8}>
              {fmtVA(powerData.max_va)} max · {fmtVA(powerData.total_va)} optimized
            </text>
          )}

          {/* ── Bus → Building wires ── */}
          {bldgNodes.map(n => {
            const wireY1 = BUS_Y + BUS_H / 2;
            const wireY2 = n.cy - 42;
            const midY   = (wireY1 + wireY2) / 2 + 10;
            return (
              <g key={n.id}>
                <line x1={n.cx} y1={wireY1} x2={n.cx} y2={midY - 10}
                  stroke="#9ca3af" strokeWidth={1.5} />
                <Breaker cx={n.cx} cy={midY} />
                <line x1={n.cx} y1={midY + 10} x2={n.cx} y2={wireY2}
                  stroke="#9ca3af" strokeWidth={1.5} />
              </g>
            );
          })}

          {/* ── Source nodes ── */}
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
              w={bldgW} h={70}
              col={C.bldg}
              name={n.name.length > 12 ? n.name.slice(0, 11) + '…' : n.name}
              line1={n.floors > 0 ? `${n.floors} floor${n.floors!==1?'s':''}` : ''}
              line2={n.type ?? ''}
              active={false}
            />
          ))}

          {/* No buildings placeholder */}
          {bldgNodes.length === 0 && (
            <text x={W/2} y={BLD_Y} textAnchor="middle" fill="#9ca3af" fontSize={12}>
              No buildings added yet
            </text>
          )}

          {/* Legend */}
          {(() => {
            const items = [
              { col: C.solar.stroke,   label: 'Solar PV'  },
              { col: C.battery.stroke, label: 'BESS'      },
              { col: C.utility.stroke, label: 'Utility Grid' },
              { col: C.gen.stroke,     label: 'Generator' },
              { col: C.bldg.stroke,    label: 'Building Panel' },
            ];
            const legY = diagramH - 22;
            const legX = 20;
            return items.map((it, i) => (
              <g key={it.label} transform={`translate(${legX + i * 130}, ${legY})`}>
                <rect x={0} y={-7} width={10} height={10} rx={2}
                  fill={it.col} opacity={0.8} />
                <text x={14} y={2} fill="#6b7280" fontSize={8}>{it.label}</text>
              </g>
            ));
          })()}
        </svg>
      </div>

      {/* Disclaimer */}
      <p className="text-xs text-gray-400 text-center">
        Simplified topology diagram — does not represent physical cable routing, transformer sizing, or protection coordination.
        For engineering design use the full calculation outputs.
      </p>
    </div>
  );
}
