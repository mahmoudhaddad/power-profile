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

  const { capApplied = false, engineerName = '' } = options;

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
