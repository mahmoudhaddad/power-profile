/**
 * Opens a new browser window with a professional power-analysis report and
 * triggers the browser's Print dialog so the user can save as PDF.
 *
 * @param {object} data         — response from /api/projects/{id}/total-power
 * @param {string} title        — project name
 * @param {object} options      — { capApplied, engineerName }
 */
export function printPowerReport(data, title = 'Power Analysis Report', options = {}) {
  if (!data) return;

  const { capApplied = false, engineerName = '', financialData = null } = options;

  const date = new Date().toLocaleDateString('en-US', {
    year: 'numeric', month: 'long', day: 'numeric',
  });
  const ref = `PP-${Date.now().toString(36).toUpperCase().slice(-6)}`;

  const pf   = v => Number(v ?? 0).toFixed(3);
  const kvar = v => `${Number(v ?? 0).toFixed(2)} kVAR`;
  const va   = v => { const n = Number(v) || 0; if (n >= 1e6) return `${(n/1e6).toFixed(2)} MVA`; if (n >= 1e3) return `${(n/1e3).toFixed(2)} kVA`; return `${Math.round(n)} VA`; };
  const w    = v => { const n = Number(v) || 0; if (n >= 1e6) return `${(n/1e6).toFixed(2)} MW`;  if (n >= 1e3) return `${(n/1e3).toFixed(2)} kW`;  return `${Math.round(n)} W`;  };
  const pct  = v => `${Number(v ?? 0).toFixed(1)}%`;
  const amp  = v => `${Number(v ?? 0).toFixed(2)} A`;

  const pfCorrNeeded   = !!data.pf_correction_recommended;
  const displayPf      = capApplied && pfCorrNeeded ? data.capacitor_bank_target_pf   : data.system_power_factor;
  const displayKvar    = capApplied && pfCorrNeeded ? data.kvar_after_correction       : data.total_kvar;
  const displayCurrent = capApplied && pfCorrNeeded ? data.current_after_correction_a : data.current_before_correction_a;
  const pfPct          = Math.round((Number(displayPf) || 0) * 100);

  const pfBgColor = pfPct >= 95 ? '#d1fae5' : pfPct >= 85 ? '#fef3c7' : '#fee2e2';
  const pfFgColor = pfPct >= 95 ? '#065f46' : pfPct >= 85 ? '#92400e' : '#991b1b';
  const pfLabel   = pfPct >= 95 ? '✓ Compliant — above 0.95 target'
                  : pfPct >= 85 ? '⚠ Marginal — above PENRA 0.85 minimum but below 0.95 target'
                  :               '✗ PENRA Violation — below 0.85 minimum, capacitor bank required';

  const correctionSection = pfCorrNeeded ? `
    <div class="section">
      <h2>Capacitor Bank${capApplied ? ' — Applied' : ' Recommendation'}</h2>
      ${capApplied ? '<p class="note-green">✓ Capacitor bank effect applied in this report.</p>' : ''}
      <table>
        <tr><td>Required Capacitor Bank</td><td><strong>${kvar(data.capacitor_bank_kvar)}</strong></td></tr>
        ${data.capacitor_bank_uf > 0 ? `<tr><td>Capacitor Value</td><td><strong>C = ${Number(data.capacitor_bank_uf).toFixed(2)} μF (delta, 3-phase 400 V, IEC 60831)</strong></td></tr>` : ''}
        <tr><td>Target Power Factor</td><td>${pf(data.capacitor_bank_target_pf)}</td></tr>
        <tr><td>Line Current Before Correction</td><td>${amp(data.current_before_correction_a)}</td></tr>
        <tr><td>Line Current After Correction</td><td>${amp(data.current_after_correction_a)}</td></tr>
        <tr><td>Current Reduction</td><td>${pct(data.current_reduction_percent)}</td></tr>
      </table>
      ${!capApplied && data.correction_note ? `<blockquote>${data.correction_note}</blockquote>` : ''}
    </div>
  ` : `
    <div class="section">
      <h2>Capacitor Bank</h2>
      <p class="note-green">No capacitor bank required — system power factor is within acceptable limits.</p>
    </div>
  `;

  const priorityRows = ['critical', 'essential', 'normal'].map(p => `
    <tr>
      <td style="text-transform:capitalize;font-weight:600">${p}</td>
      <td>${va(data[`${p}_max_va`])}</td><td>${w(data[`${p}_max_w`])}</td>
      <td>${va(data[`${p}_va`])}</td><td>${w(data[`${p}_w`])}</td>
    </tr>`).join('');

  // Battery storage section
  const bs = data.battery_storage;
  const batterySection = bs ? `
    <div class="section">
      <h2>Battery Energy Storage (BESS)</h2>
      <table>
        <tr><td>Active Banks</td><td><strong>${bs.bank_count}</strong></td>
            <td>Nominal Capacity</td><td><strong>${Number(bs.total_nominal_kwh).toFixed(2)} kWh</strong></td></tr>
        <tr><td>Usable Capacity</td><td>${Number(bs.total_usable_kwh).toFixed(2)} kWh</td>
            <td>Available Now</td><td>${Number(bs.total_available_kwh).toFixed(2)} kWh</td></tr>
        ${bs.runtime_summary?.backup_hours_at_critical_load_full != null
          ? `<tr><td>Backup at Critical Load</td><td>${Number(bs.runtime_summary.backup_hours_at_critical_load_full).toFixed(1)} h</td>
                 <td>Backup at Optimized Load</td><td>${bs.runtime_summary.backup_hours_at_optimized_load_current != null ? Number(bs.runtime_summary.backup_hours_at_optimized_load_current).toFixed(1) + ' h' : 'N/A'}</td></tr>` : ''}
        ${bs.needs_attention ? '<tr><td colspan="4" style="color:#991b1b;font-weight:600">⚠ One or more batteries require replacement — check battery health.</td></tr>' : ''}
      </table>
    </div>` : '';

  // Energy consumption & cost section (from financial analysis data)
  const energySection = (() => {
    if (!financialData) return '';
    const ae   = financialData.annual_energy  ?? {};
    const ac   = financialData.annual_costs   ?? {};
    const sv   = financialData.savings        ?? {};
    const inv  = financialData.investment     ?? {};
    const pb   = financialData.payback        ?? {};
    const cur  = financialData.currency_symbol ?? '$';
    const fmtE = v => `${Number(v ?? 0).toLocaleString(undefined, { maximumFractionDigits: 0 })} kWh`;
    const fmtC = v => `${cur}${Number(v ?? 0).toLocaleString(undefined, { maximumFractionDigits: 2 })}`;
    const pctBar = (pct, color) =>
      `<div style="height:6px;background:#e5e7eb;border-radius:4px;margin:3px 0 1px;">
         <div style="height:100%;width:${Math.min(100,pct)}%;background:${color};border-radius:4px;"></div>
       </div>`;

    const totalAnn  = Number(ae.total_load_kwh      ?? 0);
    const solarAnn  = Number(ae.solar_kwh           ?? 0);
    const gridAnn   = Number(ae.grid_kwh            ?? 0);
    const genAnn    = Number(ae.generator_kwh       ?? 0); // load-serving only
    const battAnn   = Number(ae.battery_discharge_kwh ?? 0);
    const battLoss  = Number(ae.battery_loss_kwh    ?? 0);
    const hasBatt   = battAnn > 0;

    const periods = [
      { label: 'Daily (avg)',           factor: 1/365 },
      { label: 'Weekly (avg)',          factor: 7/365 },
      { label: 'Monthly (avg)',         factor: 1/12  },
      { label: 'Seasonal (avg, 3 mo)',  factor: 1/4   },
      { label: 'Annual',                factor: 1     },
    ];

    const energyRows = periods.map(({ label, factor }) => `
      <tr>
        <td style="font-weight:600">${label}</td>
        <td>${fmtE(totalAnn * factor)}</td>
        <td>${fmtE(solarAnn * factor)}</td>
        <td>${fmtE(gridAnn  * factor)}</td>
        <td>${fmtE(genAnn   * factor)}</td>
        ${hasBatt ? `<td>${fmtE(battAnn * factor)}</td>` : ''}
      </tr>`).join('');

    const gridCostAnn  = Number(ac.grid_cost         ?? 0);
    const genCostAnn   = Number(ac.generator_cost    ?? 0);
    const mntCostAnn   = Number(ac.maintenance_cost  ?? 0);
    const totalAnnCost = Number(ac.total_with_solar  ?? 0);
    const totalNoCost  = Number(ac.total_without_solar ?? 0);
    const annSav       = Number(sv.annual_savings    ?? 0);
    const savPct       = Number(sv.savings_percent   ?? 0);
    const tariff       = Number(ac.weighted_tariff   ?? 0);
    const genPerKwh    = Number(ac.generator_cost_per_kwh ?? 0);

    const costPeriods = [
      { label: 'Daily (avg)',  factor: 1/365 },
      { label: 'Weekly (avg)', factor: 7/365 },
      { label: 'Monthly (avg)',factor: 1/12  },
      { label: 'Annual',       factor: 1     },
    ];

    const costRows = costPeriods.map(({ label, factor }) => `
      <tr>
        <td style="font-weight:600">${label}</td>
        <td style="color:#991b1b">${fmtC(totalNoCost * factor)}</td>
        <td>${fmtC(gridCostAnn * factor)}</td>
        <td>${fmtC(genCostAnn  * factor)}</td>
        <td>${fmtC(mntCostAnn  * factor)}</td>
        <td><strong>${fmtC(totalAnnCost * factor)}</strong></td>
        <td style="color:#065f46;font-weight:600">${fmtC(annSav * factor)}</td>
      </tr>`).join('');

    const solarPct = Number(ae.solar_percent     ?? 0);
    const gridPct  = Number(ae.grid_percent      ?? 0);
    const genPct   = Number(ae.generator_percent ?? 0);
    const battPct  = Number(ae.battery_percent   ?? 0);

    return `
    <div class="section">
      <h2>Energy Consumption by Period</h2>
      <table>
        <thead>
          <tr>
            <th>Period</th><th>Total Load</th><th>Solar</th><th>Grid</th><th>Generator</th>
            ${hasBatt ? '<th>Battery (BESS)</th>' : ''}
          </tr>
        </thead>
        <tbody>${energyRows}</tbody>
      </table>
      ${battLoss > 0 ? `<p style="font-size:10px;color:#6b7280;margin-top:4px;">Battery round-trip conversion loss: ${fmtE(battLoss)} / year</p>` : ''}
    </div>

    <div class="section">
      <h2>Energy Source Mix — Load Coverage</h2>
      <table>
        <tr>
          <td style="width:80px;font-weight:600">Solar</td>
          <td style="width:48px"><span style="font-weight:700;color:#f59e0b">${solarPct}%</span></td>
          <td style="width:220px">${pctBar(solarPct, '#f59e0b')}</td>
          <td>${fmtE(solarAnn)}/yr</td>
        </tr>
        <tr>
          <td style="font-weight:600">Grid</td>
          <td><span style="font-weight:700;color:#3b82f6">${gridPct}%</span></td>
          <td>${pctBar(gridPct, '#3b82f6')}</td>
          <td>${fmtE(gridAnn)}/yr</td>
        </tr>
        <tr>
          <td style="font-weight:600">Generator</td>
          <td><span style="font-weight:700;color:#ef4444">${genPct}%</span></td>
          <td>${pctBar(genPct, '#ef4444')}</td>
          <td>${fmtE(genAnn)}/yr</td>
        </tr>
        ${hasBatt ? `<tr>
          <td style="font-weight:600">BESS</td>
          <td><span style="font-weight:700;color:#8b5cf6">${battPct}%</span></td>
          <td>${pctBar(battPct, '#8b5cf6')}</td>
          <td>${fmtE(battAnn)}/yr</td>
        </tr>` : ''}
      </table>
      <p style="font-size:10px;color:#6b7280;margin-top:5px;">
        Percentages represent each source's share of total load served.
        ${solarPct + gridPct + genPct + battPct < 99 ? `Unmet demand: ${(100 - solarPct - gridPct - genPct - battPct).toFixed(1)}%` : 'Sum ≈ 100% — load fully covered.'}
      </p>
    </div>

    <div class="section">
      <h2>Energy Cost Analysis</h2>
      <table>
        <thead>
          <tr>
            <th>Period</th><th style="background:#fef2f2;color:#991b1b">Baseline (No Solar)</th><th>Grid Cost</th><th>Generator Cost</th><th>Maintenance</th><th>Total (with Solar)</th><th style="background:#f0fdf4;color:#065f46">Savings</th>
          </tr>
        </thead>
        <tbody>${costRows}</tbody>
      </table>
      <table style="margin-top:8px;">
        <tr><td>Grid Tariff (weighted avg)</td><td>${cur}${tariff.toFixed(4)}/kWh</td>
            <td>Generator Cost</td><td>${cur}${genPerKwh.toFixed(4)}/kWh</td></tr>
        <tr><td>Annual Baseline Cost (no solar)</td><td>${fmtC(totalNoCost)}</td>
            <td>Annual Savings</td><td style="color:#065f46;font-weight:700">${fmtC(annSav)} (${savPct}%)</td></tr>
        ${inv.total_investment ? `<tr><td>Total Investment (solar + BESS)</td><td>${fmtC(inv.total_investment)}</td>
            <td>Simple Payback</td><td>${pb.simple_payback_years != null ? pb.simple_payback_years + ' years' : 'N/A'}</td></tr>` : ''}
      </table>
      ${(() => {
        const gi = financialData?.generator_info;
        if (!gi || !gi.is_oversized) return '';
        return `<blockquote style="border-left:3px solid #f97316;background:#fff7ed;color:#9a3412;margin-top:10px;">
          <strong>⚠ Generator Oversizing Notice (ISO 8528)</strong><br/>
          Generator rated ${gi.current_rated_kw} kW is running at an average of ${gi.efficiency_avg_pct}% load
          (peak demand: ${gi.peak_load_kw} kW). ISO 8528 recommends 70–85% average loading.
          Recommended size: <strong>${gi.recommended_kw} kW</strong>.
          At low load fractions the no-load fuel burn inflates effective $/kWh and the apparent baseline savings.
        </blockquote>`;
      })()}
    </div>`;
  })();

  // Motor inrush section
  const inrushSection = data.inrush_applied && data.inrush_component ? `
    <div class="section">
      <h2>Motor Inrush (NEC Art. 430 / IEC 60947-4)</h2>
      <table>
        <tr><td>Largest Motor</td><td><strong>${data.inrush_component.name}</strong></td>
            <td>Quantity</td><td>${data.inrush_component.quantity}</td></tr>
        <tr><td>Rated VA (per unit)</td><td>${va(data.inrush_component.per_unit_va)}</td>
            <td>Total Rated VA</td><td>${va(data.inrush_component.base_va)}</td></tr>
        <tr><td>Sized VA (125%)</td><td><strong>${va(data.inrush_component.sized_va)}</strong></td>
            <td>Inrush Addition</td><td>${va(data.inrush_component.inrush_addition_va)}</td></tr>
      </table>
    </div>` : '';

  const html = `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <title>${title} — Power Analysis Report</title>
  <style>
    *{margin:0;padding:0;box-sizing:border-box;}
    body{font-family:'Segoe UI',Arial,sans-serif;font-size:12px;color:#111;background:#fff;}
    .page{padding:28px 36px;max-width:820px;margin:auto;}

    /* ── Letterhead ─────────────────────────────────── */
    .letterhead{display:flex;align-items:flex-start;justify-content:space-between;
      padding-bottom:16px;border-bottom:3px solid #1e3a8a;margin-bottom:20px;}
    .lh-brand{display:flex;align-items:center;gap:10px;}
    .lh-logo{width:40px;height:40px;background:#1e3a8a;border-radius:8px;
      display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;}
    .lh-name{font-size:18px;font-weight:700;color:#1e3a8a;line-height:1.2;}
    .lh-tagline{font-size:10px;color:#6b7280;margin-top:2px;}
    .lh-meta{text-align:right;font-size:11px;color:#374151;line-height:1.7;}
    .lh-meta strong{color:#111827;}
    .lh-ref{display:inline-block;background:#eff6ff;color:#1d4ed8;font-size:10px;
      font-weight:600;padding:2px 8px;border-radius:4px;margin-top:4px;letter-spacing:.05em;}

    /* ── Section titles ─────────────────────────────── */
    .section{margin-bottom:20px;}
    h2{font-size:12px;font-weight:700;color:#1e3a8a;text-transform:uppercase;
      letter-spacing:.08em;padding:6px 10px;background:#eff6ff;
      border-left:3px solid #1e3a8a;margin-bottom:10px;}

    /* ── Summary cards ──────────────────────────────── */
    .cards{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px;}
    .card{border:1px solid #e5e7eb;border-radius:6px;padding:12px 14px;}
    .card-label{font-size:9px;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;}
    .card-value{font-size:18px;font-weight:700;color:#111827;margin-top:2px;line-height:1.1;}
    .card-sub{font-size:10px;color:#6b7280;margin-top:2px;}
    .card.accent{border-color:#bbf7d0;background:#f0fdf4;}
    .card.accent .card-value{color:#065f46;}

    /* ── Tables ─────────────────────────────────────── */
    table{width:100%;border-collapse:collapse;font-size:11px;margin-bottom:6px;}
    th{background:#eff6ff;color:#1e40af;text-align:left;padding:5px 8px;
      border:1px solid #dbeafe;font-size:10px;text-transform:uppercase;letter-spacing:.04em;}
    td{padding:5px 8px;border:1px solid #e5e7eb;vertical-align:middle;}
    tr:nth-child(even) td{background:#f9fafb;}

    /* ── PF bar ─────────────────────────────────────── */
    .pf-pill{display:inline-block;padding:3px 10px;border-radius:99px;font-size:10px;
      font-weight:600;background:${pfBgColor};color:${pfFgColor};margin-bottom:8px;}
    .bar-track{height:8px;background:#e5e7eb;border-radius:99px;overflow:hidden;margin:6px 0 2px;}
    .bar-fill{height:100%;border-radius:99px;
      background:linear-gradient(to right,#ef4444,#f59e0b 40%,#10b981);}
    .bar-labels{display:flex;justify-content:space-between;font-size:9px;color:#9ca3af;}

    /* ── Misc ───────────────────────────────────────── */
    blockquote{border-left:3px solid #f59e0b;padding:7px 12px;color:#78350f;
      background:#fffbeb;margin-top:8px;font-style:italic;font-size:11px;}
    .note-green{color:#065f46;font-size:11px;padding:4px 0;}
    .standards{margin-top:6px;display:flex;flex-wrap:wrap;gap:4px;}
    .std-badge{font-size:9px;background:#f3f4f6;color:#374151;
      padding:2px 7px;border-radius:99px;font-weight:500;}

    /* ── Signature block ───────────────────────────── */
    .sig-block{display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-top:12px;}
    .sig-item{border-top:1px solid #d1d5db;padding-top:6px;}
    .sig-label{font-size:9px;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em;}
    .sig-value{font-size:11px;font-weight:600;color:#111827;margin-top:2px;}

    /* ── Footer ─────────────────────────────────────── */
    .footer{margin-top:20px;padding-top:12px;border-top:1px solid #e5e7eb;
      display:flex;justify-content:space-between;font-size:9px;color:#9ca3af;}

    /* ── Print ──────────────────────────────────────── */
    @media print{
      body{background:#fff;}
      .page{padding:16px 18px;}
      @page{margin:12mm 10mm;size:A4;}
      h2{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
      .card,.lh-logo,.pf-pill,.std-badge{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
      .section{page-break-inside:avoid;}
    }
  </style>
</head>
<body>
<div class="page">

  <!-- ── Letterhead ── -->
  <div class="letterhead">
    <div class="lh-brand">
      <div class="lh-logo">⚡</div>
      <div>
        <div class="lh-name">Power Profile</div>
        <div class="lh-tagline">Electrical Load Analysis Platform</div>
      </div>
    </div>
    <div class="lh-meta">
      <div><strong>Project:</strong> ${title}</div>
      ${engineerName ? `<div><strong>Engineer:</strong> ${engineerName}</div>` : ''}
      <div><strong>Date:</strong> ${date}</div>
      <div><span class="lh-ref">Ref: ${ref}</span></div>
    </div>
  </div>

  <!-- ── Summary ── -->
  <div class="section">
    <h2>Load Summary</h2>
    <div class="cards">
      <div class="card">
        <div class="card-label">Max Load (Unoptimized)</div>
        <div class="card-value">${va(data.max_va)}</div>
        <div class="card-sub">${w(data.max_w)} active power</div>
      </div>
      <div class="card accent">
        <div class="card-label">Optimized Demand</div>
        <div class="card-value">${va(data.total_va)}</div>
        <div class="card-sub">${w(data.total)} active power</div>
      </div>
      <div class="card">
        <div class="card-label">Diversity Reduction</div>
        <div class="card-value">${data.max_va > 0 ? Math.round((1 - data.total_va / data.max_va) * 100) : 0}%</div>
        <div class="card-sub">from max to optimized</div>
      </div>
    </div>
  </div>

  <!-- ── Reactive Power ── -->
  <div class="section">
    <h2>Power Factor &amp; Reactive Power</h2>
    <div class="pf-pill">${pfLabel}</div>
    <table>
      <tr><td>System Power Factor</td><td><strong>${pf(displayPf)}</strong></td>
          <td>Reactive Power (Q)</td><td><strong>${kvar(displayKvar)}</strong></td></tr>
      <tr><td>Max Reactive Power</td><td>${kvar(data.max_kvar)}</td>
          <td>Line Current</td><td>${amp(displayCurrent)}</td></tr>
    </table>
    <div class="bar-track"><div class="bar-fill" style="width:${pfPct}%"></div></div>
    <div class="bar-labels"><span>0.0 (pure reactive)</span><span>0.85 PENRA min</span><span>1.0 (pure resistive)</span></div>
  </div>

  <!-- ── Capacitor Bank ── -->
  ${correctionSection}

  <!-- ── Priority Breakdown ── -->
  <div class="section">
    <h2>Load Breakdown by Priority</h2>
    <table>
      <thead>
        <tr>
          <th>Priority</th>
          <th>Max S (kVA)</th><th>Max P (kW)</th>
          <th>Optimized S (kVA)</th><th>Optimized P (kW)</th>
        </tr>
      </thead>
      <tbody>${priorityRows}</tbody>
    </table>
  </div>

  ${(data.socket_connected_va ?? 0) > 0 ? `
  <div class="section">
    <h2>Socket Outlets</h2>
    <table>
      <tr><td>Connected Capacity</td><td>${va(data.socket_connected_va)}</td>
          <td>Estimated Demand</td><td><strong>${va(data.socket_demand_va)}</strong></td></tr>
    </table>
  </div>` : ''}

  ${batterySection}
  ${energySection}
  ${inrushSection}

  <!-- ── Signature ── -->
  <div class="section">
    <h2>Certification</h2>
    <div class="sig-block">
      <div class="sig-item">
        <div class="sig-label">Prepared by</div>
        <div class="sig-value">${engineerName || '___________________'}</div>
      </div>
      <div class="sig-item">
        <div class="sig-label">Date</div>
        <div class="sig-value">${date}</div>
      </div>
      <div class="sig-item">
        <div class="sig-label">Reference No.</div>
        <div class="sig-value">${ref}</div>
      </div>
    </div>
  </div>

  <!-- ── Footer ── -->
  <div class="footer">
    <span>Standards: IEC 60364-8-1 · PENRA · NEC Article 430 · IEC 60831 · BS 7671 · CIBSE Guide C</span>
    <span>Power Profile — powerprofile.app</span>
  </div>

</div>
</body>
</html>`;

  const win = window.open('', '_blank', 'width=900,height=700');
  if (!win) return;
  win.document.write(html);
  win.document.close();
  win.onload = () => { win.focus(); win.print(); };
}
