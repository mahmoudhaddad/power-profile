import * as XLSX from 'xlsx';
import api from '../api/axios';

// ── Formatting helpers ────────────────────────────────────────────────────────
const fmtVA  = v => { const n = Number(v)||0; if(n>=1e6) return `${(n/1e6).toFixed(2)} MVA`; if(n>=1e3) return `${(n/1e3).toFixed(2)} kVA`; return `${Math.round(n)} VA`; };
const fmtW   = v => { const n = Number(v)||0; if(n>=1e6) return `${(n/1e6).toFixed(2)} MW`;  if(n>=1e3) return `${(n/1e3).toFixed(2)} kW`;  return `${Math.round(n)} W`;  };
const fmtNum = (v, dp=2) => Number(v??0).toFixed(dp);

// ── Style helpers ─────────────────────────────────────────────────────────────
function headerStyle(bgColor = '1E3A8A') {
  return {
    font:      { bold: true, color: { rgb: 'FFFFFF' }, sz: 10 },
    fill:      { fgColor: { rgb: bgColor } },
    alignment: { horizontal: 'center', vertical: 'center', wrapText: true },
    border:    { bottom: { style: 'thin', color: { rgb: 'DBEAFE' } } },
  };
}
function cellStyle(even = false) {
  return {
    fill:      even ? { fgColor: { rgb: 'F9FAFB' } } : { fgColor: { rgb: 'FFFFFF' } },
    alignment: { vertical: 'center' },
    border:    { bottom: { style: 'hair', color: { rgb: 'E5E7EB' } } },
  };
}
function applyStyles(ws, headers, rowCount, startRow = 1) {
  const colCount = headers.length;
  // Header row
  for (let c = 0; c < colCount; c++) {
    const addr = XLSX.utils.encode_cell({ r: startRow - 1, c });
    if (!ws[addr]) continue;
    ws[addr].s = headerStyle();
  }
  // Data rows
  for (let r = startRow; r < startRow + rowCount; r++) {
    for (let c = 0; c < colCount; c++) {
      const addr = XLSX.utils.encode_cell({ r, c });
      if (!ws[addr]) ws[addr] = { v: '', t: 's' };
      ws[addr].s = cellStyle(r % 2 === 0);
    }
  }
}

// ── Sheet 1: Project Summary ──────────────────────────────────────────────────
function buildSummarySheet(projectName, powerData, engineerName) {
  const date = new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
  const pf   = Number(powerData?.system_power_factor ?? 0);
  const pfStatus = pf >= 0.95 ? 'Compliant (above 0.95 target)'
                 : pf >= 0.85 ? 'Marginal (above 0.85 minimum)'
                 :              'PENRA Violation (below 0.85)';

  const rows = [
    ['Power Profile — Electrical Load Analysis Export'],
    [''],
    ['Project', projectName],
    ['Engineer', engineerName || 'N/A'],
    ['Date',     date],
    ['Standards', 'IEC 60364-8-1 / PENRA / NEC Art.430 / IEC 60831'],
    [''],
    ['DEMAND SUMMARY', ''],
    ['Max Load (Unoptimized)',   fmtVA(powerData?.max_va),   fmtW(powerData?.max_w)  ],
    ['Optimized Load',          fmtVA(powerData?.total_va), fmtW(powerData?.total)   ],
    ['Diversity Reduction',     powerData?.max_va > 0 ? `${Math.round((1 - powerData.total_va / powerData.max_va)*100)}%` : 'N/A'],
    [''],
    ['REACTIVE POWER', ''],
    ['System Power Factor',     fmtNum(powerData?.system_power_factor, 3)],
    ['PF Status',               pfStatus],
    ['Reactive Power (Q)',      `${fmtNum(powerData?.total_kvar)} kVAR`],
    ['Max Reactive Power',      `${fmtNum(powerData?.max_kvar)} kVAR`],
    ['Line Current',            `${fmtNum(powerData?.current_before_correction_a)} A`],
    [''],
    ['PRIORITY BREAKDOWN', 'Max kVA', 'Max kW', 'Opt kVA', 'Opt kW'],
    ['Critical',  fmtVA(powerData?.critical_max_va),  fmtW(powerData?.critical_max_w),  fmtVA(powerData?.critical_va),  fmtW(powerData?.critical_w) ],
    ['Essential', fmtVA(powerData?.essential_max_va), fmtW(powerData?.essential_max_w), fmtVA(powerData?.essential_va), fmtW(powerData?.essential_w)],
    ['Normal',    fmtVA(powerData?.normal_max_va),    fmtW(powerData?.normal_max_w),    fmtVA(powerData?.normal_va),    fmtW(powerData?.normal_w)   ],
  ];

  if ((powerData?.socket_connected_va ?? 0) > 0) {
    rows.push([''], ['SOCKETS', '']);
    rows.push(['Connected Capacity', fmtVA(powerData.socket_connected_va)]);
    rows.push(['Estimated Demand',   fmtVA(powerData.socket_demand_va)]);
  }

  if (powerData?.pf_correction_recommended) {
    rows.push([''], ['CAPACITOR BANK', '']);
    rows.push(['Required Bank',          `${fmtNum(powerData.capacitor_bank_kvar)} kVAR`]);
    rows.push(['Capacitor Value',        `${fmtNum(powerData.capacitor_bank_uf)} μF (delta, 400V 3-phase)`]);
    rows.push(['Line Current After',     `${fmtNum(powerData.current_after_correction_a)} A`]);
    rows.push(['Current Reduction',      `${fmtNum(powerData.current_reduction_percent)}%`]);
  }

  const ws = XLSX.utils.aoa_to_sheet(rows);
  ws['!cols'] = [{ wch: 30 }, { wch: 18 }, { wch: 14 }, { wch: 14 }, { wch: 14 }];
  return ws;
}

// ── Sheet 2: Component Inventory ──────────────────────────────────────────────
function buildComponentsSheet(components) {
  const headers = [
    'Level', 'Location', 'Component Name', 'Power (W)', 'Quantity',
    'Power Factor', 'Total W', 'Phases', 'Priority',
    'Scheduling', 'Season', 'Day Type',
  ];
  const rows = [headers];

  for (const c of components) {
    rows.push([
      c.level,
      c.location,
      c.name,
      Number(c.power) || 0,
      Number(c.quantity) || 1,
      Number(c.power_factor) || 1,
      (Number(c.power) || 0) * (Number(c.quantity) || 1),
      c.phases || '1phase',
      c.priority || 'normal',
      c.load_flexibility || 'fixed',
      c.usage_season || 'all',
      c.usage_day_type || 'all',
    ]);
  }

  const ws = XLSX.utils.aoa_to_sheet(rows);
  ws['!cols'] = [
    {wch:10},{wch:22},{wch:28},{wch:10},{wch:9},
    {wch:12},{wch:10},{wch:8},{wch:10},{wch:12},{wch:10},{wch:10},
  ];
  applyStyles(ws, headers, rows.length - 1);
  return ws;
}

// ── Sheet 3: Energy Analysis ──────────────────────────────────────────────────
function buildEnergySheet(fin) {
  if (!fin) return null;

  const ae  = fin.annual_energy  ?? {};
  const ac  = fin.annual_costs   ?? {};
  const sv  = fin.savings        ?? {};
  const inv = fin.investment     ?? {};
  const pb  = fin.payback        ?? {};
  const cur = fin.currency_symbol ?? '$';
  const e   = (v, dp = 0) => `${Number(v ?? 0).toFixed(dp)} kWh`;
  const c   = (v) => `${cur}${Number(v ?? 0).toFixed(2)}`;

  const totalAnn  = Number(ae.total_load_kwh      ?? 0);
  const solarAnn  = Number(ae.solar_kwh           ?? 0);
  const gridAnn   = Number(ae.grid_kwh            ?? 0);
  const genAnn    = Number(ae.generator_kwh       ?? 0); // load-serving portion only
  const battAnn   = Number(ae.battery_discharge_kwh ?? 0);
  const battLoss  = Number(ae.battery_loss_kwh    ?? 0);
  const hasBatt   = battAnn > 0;

  const gridCostAnn  = Number(ac.grid_cost      ?? 0);
  const genCostAnn   = Number(ac.generator_cost ?? 0);
  const mntCostAnn   = Number(ac.maintenance_cost ?? 0);
  const totalWithSol = Number(ac.total_with_solar ?? 0);
  const totalNoSol   = Number(ac.total_without_solar ?? 0);
  const annSav       = Number(sv.annual_savings ?? 0);
  const savPct       = Number(sv.savings_percent ?? 0);

  const periods = [
    { label: 'Daily (avg)',          f: 1/365  },
    { label: 'Weekly (avg)',         f: 7/365  },
    { label: 'Monthly (avg)',        f: 1/12   },
    { label: 'Winter (3 mo avg)',    f: 1/4    },
    { label: 'Spring (3 mo avg)',    f: 1/4    },
    { label: 'Summer (3 mo avg)',    f: 1/4    },
    { label: 'Autumn (3 mo avg)',    f: 1/4    },
    { label: 'Annual',               f: 1      },
  ];

  const sourceMixRows = [
    ['Solar',    e(solarAnn), `${ae.solar_percent     ?? 0}%`],
    ['Grid',     e(gridAnn),  `${ae.grid_percent      ?? 0}%`],
    ['Generator',e(genAnn),   `${ae.generator_percent ?? 0}%`],
    ...(hasBatt ? [['BESS (battery discharge)', e(battAnn), `${ae.battery_percent ?? 0}%`]] : []),
    ['Total Load', e(totalAnn), '100%'],
    ...(battLoss > 0 ? [['Battery Round-trip Loss', e(battLoss), '—']] : []),
  ];

  const periodHeaders = hasBatt
    ? ['Period', 'Total Load', 'Solar', 'Grid', 'Generator', 'BESS Discharge']
    : ['Period', 'Total Load', 'Solar', 'Grid', 'Generator'];

  const rows = [
    ['ENERGY CONSUMPTION & SOURCE MIX — LOAD COVERAGE', '', '', '', ''],
    ['Note: Percentages show each source\'s share of total load served.'],
    [''],
    ['SOURCE MIX (ANNUAL)', ''],
    ['Source', 'Energy (kWh/yr)', '% of Load', '', ''],
    ...sourceMixRows,
    [''],
    ['ENERGY BY PERIOD', '', '', '', '', ''],
    periodHeaders,
    ...periods.map(({ label, f }) => [
      label,
      e(totalAnn * f, 1),
      e(solarAnn * f, 1),
      e(gridAnn  * f, 1),
      e(genAnn   * f, 1),
      ...(hasBatt ? [e(battAnn * f, 1)] : []),
    ]),
    [''],
    ['COST ANALYSIS BY PERIOD', '', '', '', '', ''],
    ['Period', `Baseline / No Solar (${cur})`, `Grid Cost (${cur})`, `Generator Cost (${cur})`, `Maintenance (${cur})`, `Total w/ Solar (${cur})`, `Savings (${cur})`],
    ...([
      { label: 'Daily (avg)',  f: 1/365 },
      { label: 'Weekly (avg)', f: 7/365 },
      { label: 'Monthly (avg)',f: 1/12  },
      { label: 'Annual',       f: 1     },
    ].map(({ label, f }) => [
      label,
      c(totalNoSol  * f),
      c(gridCostAnn * f),
      c(genCostAnn  * f),
      c(mntCostAnn  * f),
      c(totalWithSol* f),
      c(annSav      * f),
    ])),
    [''],
    ['SAVINGS SUMMARY', ''],
    ['Annual Baseline Cost (no solar)',  c(totalNoSol)],
    ['Annual Cost with Solar/BESS',      c(totalWithSol)],
    ['Annual Savings',                   c(annSav)],
    ['Savings %',                        `${savPct}%`],
    ['Grid Tariff (weighted avg)',        `${cur}${Number(ac.weighted_tariff ?? 0).toFixed(4)}/kWh`],
    ['Generator Cost per kWh',           `${cur}${Number(ac.generator_cost_per_kwh ?? 0).toFixed(4)}/kWh`],
    [''],
    ['INVESTMENT & PAYBACK', ''],
    ['Solar Installation Cost',          c(inv.solar_installation ?? 0)],
    ['Battery Purchase Cost',            c(inv.battery_purchase   ?? 0)],
    ['Total Investment',                 c(inv.total_investment   ?? 0)],
    ['Simple Payback Period',            pb.simple_payback_years != null ? `${pb.simple_payback_years} years` : 'N/A'],
    ['LCOE (Solar, 25-year)',            pb.lcoe_solar_per_kwh != null   ? `${cur}${Number(pb.lcoe_solar_per_kwh).toFixed(4)}/kWh` : 'N/A'],
  ];

  const ws = XLSX.utils.aoa_to_sheet(rows);
  ws['!cols'] = [{ wch: 24 }, { wch: 22 }, { wch: 16 }, { wch: 18 }, { wch: 16 }, { wch: 20 }, { wch: 16 }];
  return ws;
}

// ── Sheet 4: Financial Analysis (25-year projection) ─────────────────────────
function buildFinancialSheet(fin) {
  if (!fin) return null;

  const summaryRows = [
    ['FINANCIAL ANALYSIS SUMMARY', ''],
    ['Installation Cost',    fin.installation_cost     != null ? `$${Number(fin.installation_cost).toFixed(0)}`  : 'N/A'],
    ['Annual Savings',       fin.annual_savings         != null ? `$${Number(fin.annual_savings).toFixed(0)}`     : 'N/A'],
    ['Simple Payback',       fin.simple_payback_years   != null ? `${Number(fin.simple_payback_years).toFixed(1)} years` : 'N/A'],
    ['LCOE',                 fin.lcoe_per_kwh           != null ? `$${Number(fin.lcoe_per_kwh).toFixed(4)}/kWh`  : 'N/A'],
    ['25-Year Net Benefit',  fin.net_25yr               != null ? `$${Number(fin.net_25yr).toFixed(0)}`          : 'N/A'],
    ['Solar Capacity',       fin.solar_capacity_kw      != null ? `${fin.solar_capacity_kw} kW`                  : 'N/A'],
    ['Battery Capacity',     fin.battery_capacity_kwh   != null ? `${fin.battery_capacity_kwh} kWh`              : 'N/A'],
    [''],
  ];

  const yearlyHeaders = ['Year', 'Solar kWh', 'Savings ($)', 'Battery Replacement ($)', 'Net ($)', 'Cumulative ($)'];
  summaryRows.push(yearlyHeaders);

  if (Array.isArray(fin.yearly)) {
    for (const yr of fin.yearly) {
      summaryRows.push([
        yr.year,
        Number(yr.solar_kwh ?? 0).toFixed(0),
        Number(yr.savings  ?? 0).toFixed(0),
        Number(yr.battery_replacement_cost ?? 0).toFixed(0),
        Number(yr.net      ?? 0).toFixed(0),
        Number(yr.cumulative ?? 0).toFixed(0),
      ]);
    }
  }

  const ws = XLSX.utils.aoa_to_sheet(summaryRows);
  ws['!cols'] = [{wch:28},{wch:14},{wch:14},{wch:24},{wch:12},{wch:14}];
  return ws;
}

// ── Collect all components across hierarchy ───────────────────────────────────
async function collectComponents(projectId) {
  const components = [];

  // Project-level
  try {
    const { data } = await api.get(`/api/projects/${projectId}/components`);
    for (const c of (data.data ?? [])) {
      components.push({ ...c, level: 'Project', location: 'Project', name: c.component_name ?? c.componentType?.name ?? '' });
    }
  } catch { /* ignore */ }

  // Buildings
  let buildings = [];
  try {
    const { data } = await api.get(`/api/projects/${projectId}/buildings`);
    buildings = data.data ?? [];
  } catch { /* ignore */ }

  for (const b of buildings) {
    // Building components
    try {
      const { data } = await api.get(`/api/buildings/${b.id}/components`);
      for (const c of (data.data ?? [])) {
        components.push({ ...c, level: 'Building', location: b.name, name: c.component_name ?? c.componentType?.name ?? '' });
      }
    } catch { /* ignore */ }

    // Floors
    let floors = [];
    try {
      const { data } = await api.get(`/api/buildings/${b.id}/floors`);
      floors = data.data ?? [];
    } catch { /* ignore */ }

    for (const f of floors) {
      try {
        const { data } = await api.get(`/api/floors/${f.id}/components`);
        for (const c of (data.data ?? [])) {
          components.push({ ...c, level: 'Floor', location: `${b.name} › ${f.name}`, name: c.component_name ?? c.componentType?.name ?? '' });
        }
      } catch { /* ignore */ }

      // Rooms
      let rooms = [];
      try {
        const { data } = await api.get(`/api/floors/${f.id}/rooms`);
        rooms = data.data ?? [];
      } catch { /* ignore */ }

      for (const r of rooms) {
        try {
          const { data } = await api.get(`/api/rooms/${r.id}/components`);
          for (const c of (data.data ?? [])) {
            components.push({ ...c, level: 'Room', location: `${b.name} › ${f.name} › ${r.name}`, name: c.component_name ?? c.componentType?.name ?? '' });
          }
        } catch { /* ignore */ }
      }
    }
  }

  return components;
}

// ── Collect components scoped to a building ───────────────────────────────────
async function collectBuildingComponents(buildingId) {
  const components = [];
  let buildingName = `Building #${buildingId}`;

  try {
    const { data } = await api.get(`/api/buildings/${buildingId}/components`);
    buildingName = data.building?.name ?? buildingName;
    for (const c of (data.data ?? [])) {
      components.push({ ...c, level: 'Building', location: buildingName, name: c.component_name ?? c.componentType?.name ?? '' });
    }
  } catch { /* ignore */ }

  let floors = [];
  try {
    const { data } = await api.get(`/api/buildings/${buildingId}/floors`);
    floors = data.data ?? [];
  } catch { /* ignore */ }

  for (const f of floors) {
    try {
      const { data } = await api.get(`/api/floors/${f.id}/components`);
      for (const c of (data.data ?? [])) {
        components.push({ ...c, level: 'Floor', location: f.name, name: c.component_name ?? c.componentType?.name ?? '' });
      }
    } catch { /* ignore */ }

    let rooms = [];
    try {
      const { data } = await api.get(`/api/floors/${f.id}/rooms`);
      rooms = data.data ?? [];
    } catch { /* ignore */ }

    for (const r of rooms) {
      try {
        const { data } = await api.get(`/api/rooms/${r.id}/components`);
        for (const c of (data.data ?? [])) {
          components.push({ ...c, level: 'Room', location: `${f.name} › ${r.name}`, name: c.component_name ?? c.componentType?.name ?? '' });
        }
      } catch { /* ignore */ }
    }
  }

  return components;
}

// ── Collect components scoped to a floor ─────────────────────────────────────
async function collectFloorComponents(floorId) {
  const components = [];

  try {
    const { data } = await api.get(`/api/floors/${floorId}/components`);
    for (const c of (data.data ?? [])) {
      components.push({ ...c, level: 'Floor', location: 'Floor', name: c.component_name ?? c.componentType?.name ?? '' });
    }
  } catch { /* ignore */ }

  let rooms = [];
  try {
    const { data } = await api.get(`/api/floors/${floorId}/rooms`);
    rooms = data.data ?? [];
  } catch { /* ignore */ }

  for (const r of rooms) {
    try {
      const { data } = await api.get(`/api/rooms/${r.id}/components`);
      for (const c of (data.data ?? [])) {
        components.push({ ...c, level: 'Room', location: r.name, name: c.component_name ?? c.componentType?.name ?? '' });
      }
    } catch { /* ignore */ }
  }

  return components;
}

// ── Collect components scoped to a room ──────────────────────────────────────
async function collectRoomComponents(roomId) {
  const components = [];
  try {
    const { data } = await api.get(`/api/rooms/${roomId}/components`);
    for (const c of (data.data ?? [])) {
      components.push({ ...c, level: 'Room', location: 'Room', name: c.component_name ?? c.componentType?.name ?? '' });
    }
  } catch { /* ignore */ }
  return components;
}

// ── Main export function ──────────────────────────────────────────────────────
export async function exportProjectExcel(projectId, projectName, powerData, engineerName = '') {
  const wb = XLSX.utils.book_new();

  // Sheet 1: Summary (always available)
  const summaryWs = buildSummarySheet(projectName, powerData, engineerName);
  XLSX.utils.book_append_sheet(wb, summaryWs, 'Summary');

  // Sheet 2: Components (fetch live)
  try {
    const components = await collectComponents(projectId);
    if (components.length > 0) {
      const compWs = buildComponentsSheet(components);
      XLSX.utils.book_append_sheet(wb, compWs, 'Components');
    }
  } catch { /* skip sheet if fetch fails */ }

  // Sheets 3 & 4: Energy Analysis + Financial Projection
  try {
    const { data: fin } = await api.get(`/api/projects/${projectId}/financial-analysis`);
    const energyWs = buildEnergySheet(fin);
    if (energyWs) XLSX.utils.book_append_sheet(wb, energyWs, 'Energy Analysis');
    const finWs = buildFinancialSheet(fin);
    if (finWs) XLSX.utils.book_append_sheet(wb, finWs, 'Financial Projection');
  } catch { /* skip sheets if no financial data */ }

  const filename = `${projectName.replace(/[^a-zA-Z0-9]/g, '_')}_PowerProfile.xlsx`;
  XLSX.writeFile(wb, filename);
}

// ── Scoped export (project / building / floor / room) ────────────────────────
export async function exportScopedExcel({ projectId, projectName, title, powerData, financialData = null, engineerName = '', scope = 'project', entityId }) {
  const wb = XLSX.utils.book_new();

  // Sheet 1: Summary
  const summaryWs = buildSummarySheet(title, powerData, engineerName);
  XLSX.utils.book_append_sheet(wb, summaryWs, 'Summary');

  // Sheet 2: Components — scoped collection
  try {
    let components = [];
    if (scope === 'project')  components = await collectComponents(projectId);
    if (scope === 'building') components = await collectBuildingComponents(entityId);
    if (scope === 'floor')    components = await collectFloorComponents(entityId);
    if (scope === 'room')     components = await collectRoomComponents(entityId);

    if (components.length > 0) {
      const compWs = buildComponentsSheet(components);
      XLSX.utils.book_append_sheet(wb, compWs, 'Components');
    }
  } catch { /* skip sheet if fetch fails */ }

  // Sheets 3 & 4: Energy + Financial — only for project scope
  if (scope === 'project') {
    // Use pre-fetched financial data if provided, otherwise fetch
    let fin = financialData;
    if (!fin) {
      try { fin = (await api.get(`/api/projects/${projectId}/financial-analysis`)).data; } catch { /* ignore */ }
    }
    if (fin) {
      const energyWs = buildEnergySheet(fin);
      if (energyWs) XLSX.utils.book_append_sheet(wb, energyWs, 'Energy Analysis');

      const finWs = buildFinancialSheet(fin);
      if (finWs) XLSX.utils.book_append_sheet(wb, finWs, 'Financial Projection');
    }
  }

  const scopeLabel = scope !== 'project' ? `_${scope}` : '';
  const filename   = `${title.replace(/[^a-zA-Z0-9]/g, '_')}${scopeLabel}_PowerProfile.xlsx`;
  XLSX.writeFile(wb, filename);
}
