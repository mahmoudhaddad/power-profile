/**
 * Opens a new browser window with a formatted power-analysis report and
 * triggers the browser's Print dialog so the user can save as PDF.
 *
 * @param {object} data   — response from /api/projects/{id}/total-power
 * @param {string} title  — project name or label for the report heading
 */
export function printPowerReport(data, title = 'Power Analysis Report', options = {}) {
  if (!data) return;

  const { capApplied = false } = options;

  const date = new Date().toLocaleDateString(undefined, {
    year: 'numeric', month: 'long', day: 'numeric',
  });

  const pf = (v) => Number(v ?? 0).toFixed(3);
  const kvar = (v) => `${Number(v ?? 0).toFixed(2)} kVAR`;
  const va = (v) => {
    const n = Number(v) || 0;
    if (n >= 1e6) return `${(n / 1e6).toFixed(2)} MVA`;
    if (n >= 1e3) return `${(n / 1e3).toFixed(2)} kVA`;
    return `${Math.round(n)} VA`;
  };
  const w = (v) => {
    const n = Number(v) || 0;
    if (n >= 1e6) return `${(n / 1e6).toFixed(2)} MW`;
    if (n >= 1e3) return `${(n / 1e3).toFixed(2)} kW`;
    return `${Math.round(n)} W`;
  };
  const pct = (v) => `${Number(v ?? 0).toFixed(1)}%`;
  const amp = (v) => `${Number(v ?? 0).toFixed(2)} A`;

  const pfCorrNeeded = !!data.pf_correction_recommended;

  // Values shown in report depend on whether cap bank is applied
  const displayPf   = capApplied && pfCorrNeeded ? data.capacitor_bank_target_pf : data.system_power_factor;
  const displayKvar = capApplied && pfCorrNeeded ? data.kvar_after_correction     : data.total_kvar;
  const displayCurrent = capApplied && pfCorrNeeded
    ? data.current_after_correction_a
    : data.current_before_correction_a;

  const pfStatus = capApplied && pfCorrNeeded
    ? `<span style="color:#059669">✓ Corrected — capacitor bank installed (PF = ${pf(displayPf)})</span>`
    : pfCorrNeeded
      ? `<span style="color:#d97706">⚠ Correction Recommended (PF = ${pf(displayPf)})</span>`
      : `<span style="color:#059669">✓ Acceptable (PF = ${pf(displayPf)})</span>`;

  const correctionSection = pfCorrNeeded ? `
    <h2>Capacitor Bank${capApplied ? ' — Applied' : ' Recommendation'}</h2>
    ${capApplied ? `<p style="color:#059669;margin-bottom:10px">✓ Capacitor bank effect applied in this report.</p>` : ''}
    <table>
      <tr><td>Required Capacitor Bank</td><td><strong>${kvar(data.capacitor_bank_kvar)}</strong></td></tr>
      ${data.capacitor_bank_uf > 0 ? `<tr><td>Capacitor Value</td><td><strong>C = ${Number(data.capacitor_bank_uf).toFixed(2)} μF</strong></td></tr>` : ''}
      <tr><td>Target Power Factor</td><td>${pf(data.capacitor_bank_target_pf)}</td></tr>
      <tr><td>Line Current Before Correction</td><td>${amp(data.current_before_correction_a)}</td></tr>
      <tr><td>Line Current After Correction</td><td>${amp(data.current_after_correction_a)}</td></tr>
      <tr><td>Current Reduction</td><td>${pct(data.current_reduction_percent)}</td></tr>
    </table>
    ${!capApplied && data.correction_note ? `<blockquote>${data.correction_note}</blockquote>` : ''}
  ` : `
    <h2>Capacitor Bank</h2>
    <p style="color:#059669">No capacitor bank required — system power factor is within acceptable limits.</p>
  `;

  const priorityRows = ['critical', 'essential', 'normal'].map(p => `
    <tr>
      <td style="text-transform:capitalize">${p}</td>
      <td>${va(data[`${p}_max_va`])}</td>
      <td>${w(data[`${p}_max_w`])}</td>
      <td>${va(data[`${p}_va`])}</td>
      <td>${w(data[`${p}_w`])}</td>
    </tr>
  `).join('');

  const capBadge = capApplied && pfCorrNeeded
    ? `&nbsp;<span style="background:#d1fae5;color:#065f46;font-size:10px;padding:2px 7px;border-radius:99px;font-weight:600">Cap Bank Applied</span>`
    : '';

  const html = `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>${title}</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 13px; color: #111; padding: 32px 40px; }
    h1 { font-size: 22px; font-weight: 700; color: #1e3a8a; margin-bottom: 4px; }
    .subtitle { color: #6b7280; font-size: 12px; margin-bottom: 28px; }
    h2 { font-size: 14px; font-weight: 600; color: #1e3a8a; margin: 24px 0 10px;
         padding-bottom: 4px; border-bottom: 1px solid #dbeafe; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    th { background: #eff6ff; color: #1e40af; font-size: 11px; text-align: left;
         padding: 6px 10px; border: 1px solid #dbeafe; }
    td { padding: 6px 10px; border: 1px solid #e5e7eb; vertical-align: top; }
    tr:nth-child(even) td { background: #f9fafb; }
    .summary-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 8px; }
    .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 18px; }
    .card-label { font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: #9ca3af; }
    .card-value { font-size: 20px; font-weight: 700; color: #111827; margin-top: 2px; }
    .card-sub { font-size: 11px; color: #6b7280; margin-top: 2px; }
    .pf-bar-wrap { margin-top: 16px; }
    .pf-bar-track { height: 10px; background: #e5e7eb; border-radius: 99px; overflow: hidden; }
    .pf-bar-fill { height: 100%; border-radius: 99px;
      background: linear-gradient(to right, #ef4444, #f59e0b 40%, #10b981); }
    blockquote { border-left: 3px solid #f59e0b; padding: 8px 14px; color: #78350f;
                 background: #fffbeb; margin-top: 10px; font-style: italic; font-size: 12px; }
    @media print {
      body { padding: 16px 20px; }
      @page { margin: 16mm 14mm; }
    }
  </style>
</head>
<body>
  <h1>${title}${capBadge}</h1>
  <p class="subtitle">Generated: ${date} &nbsp;·&nbsp; IEC 60364-8-1 / PENRA</p>

  <h2>Load Summary</h2>
  <div class="summary-grid">
    <div class="card">
      <div class="card-label">Max Load (Unoptimized)</div>
      <div class="card-value">${va(data.max_va)}</div>
      <div class="card-sub">${w(data.max_w)} real power</div>
    </div>
    <div class="card">
      <div class="card-label">Optimized Load</div>
      <div class="card-value">${va(data.total_va)}</div>
      <div class="card-sub">${w(data.total)} real power</div>
    </div>
  </div>

  <h2>Reactive Power</h2>
  <table>
    <tr><td>System Power Factor</td><td><strong>${pf(displayPf)}</strong></td></tr>
    <tr><td>PF Status</td><td>${pfStatus}</td></tr>
    <tr><td>Reactive Power (kVAR)</td><td><strong>${kvar(displayKvar)}</strong></td></tr>
    <tr><td>Max Reactive Power</td><td>${kvar(data.max_kvar)}</td></tr>
    <tr><td>Line Current</td><td>${amp(displayCurrent)}</td></tr>
  </table>
  <div class="pf-bar-wrap">
    <div class="pf-bar-track">
      <div class="pf-bar-fill" style="width:${Math.round((displayPf ?? 1) * 100)}%"></div>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:10px;color:#9ca3af;margin-top:3px">
      <span>0.0 (pure reactive)</span><span>1.0 (pure resistive)</span>
    </div>
  </div>

  ${correctionSection}

  <h2>Priority Breakdown</h2>
  <table>
    <thead>
      <tr>
        <th>Priority</th>
        <th>Max VA</th><th>Max W</th>
        <th>Optimized VA</th><th>Optimized W</th>
      </tr>
    </thead>
    <tbody>
      ${priorityRows}
    </tbody>
  </table>

  ${(data.socket_connected_va ?? 0) > 0 ? `
  <h2>Sockets</h2>
  <table>
    <tr><td>Connected Capacity</td><td>${va(data.socket_connected_va)}</td></tr>
    <tr><td>Estimated Demand</td><td>${va(data.socket_demand_va)}</td></tr>
  </table>` : ''}

</body>
</html>`;

  const win = window.open('', '_blank', 'width=900,height=700');
  if (!win) return;
  win.document.write(html);
  win.document.close();
  win.onload = () => { win.focus(); win.print(); };
}
