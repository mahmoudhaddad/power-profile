/* eslint-disable react/prop-types */
import { useState, useEffect } from 'react';
import api from '../api/axios';

const PHASE_STYLE = {
  '1phase': { label: '1Φ', bg: 'bg-surface-inset', text: 'text-ink-body2' },
  '3phase': { label: '3Φ', bg: 'bg-surface-inset', text: 'text-ink-body2' },
};

function fmtVA(v) {
  const n = Number(v);
  if (!n) return '0 VA';
  if (n >= 1_000_000) return `${(n / 1_000_000).toLocaleString(undefined, { maximumFractionDigits: 2 })} MVA`;
  if (n >= 1_000)     return `${(n / 1_000).toLocaleString(undefined,     { maximumFractionDigits: 2 })} kVA`;
  return `${n.toLocaleString(undefined, { maximumFractionDigits: 2 })} VA`;
}

export default function EntitySockets({ endpoint, onChanged, canEdit = true }) {
  const [sockets, setSockets]     = useState([]);
  const [showModal, setShowModal] = useState(false);
  const [editing, setEditing]     = useState(null);

  const emptyForm = { phase_type: '1phase', power: '200', quantity: '1' };
  const [form, setForm] = useState(emptyForm);

  useEffect(() => {
    if (!endpoint) return;
    api.get(endpoint).then(({ data }) => setSockets(data.data)).catch(() => {});
  }, [endpoint]);

  function openAdd() {
    setEditing(null);
    setForm(emptyForm);
    setShowModal(true);
  }

  function openEdit(s) {
    setEditing(s);
    setForm({ phase_type: s.phase_type, power: String(s.power), quantity: String(s.quantity) });
    setShowModal(true);
  }

  async function handleSubmit() {
    const payload = { phase_type: form.phase_type, power: form.power, quantity: form.quantity };
    if (editing) {
      const { data } = await api.put(`/api/sockets/${editing.id}`, payload);
      setSockets(prev => prev.map(s => s.id === editing.id ? data.data : s));
    } else {
      const { data } = await api.post(endpoint, payload);
      setSockets(prev => [...prev, data.data]);
    }
    setShowModal(false);
    setEditing(null);
    setForm(emptyForm);
    onChanged?.();
  }

  async function handleDelete(id) {
    await api.delete(`/api/sockets/${id}`);
    setSockets(prev => prev.filter(s => s.id !== id));
    onChanged?.();
  }

  const isValid = Number(form.power) > 0 && Number(form.quantity) >= 1;

  const [open, setOpen] = useState(true);

  return (
    <section className="mt-8">
      <div className={`flex items-center justify-between ${open ? 'mb-4' : 'mb-0'}`}>
        <button onClick={() => setOpen(o => !o)}
          className="flex items-center gap-2 group">
          <svg className={`w-4 h-4 text-ink-muted transition-transform duration-200 ${open ? 'rotate-90' : ''}`}
            fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M9 5l7 7-7 7" />
          </svg>
          <h2 className="text-base font-semibold text-ink-heading group-hover:text-ink-body2">
            Sockets
            <span className="ml-2 text-xs font-normal text-ink-muted">({sockets.length})</span>
          </h2>
        </button>
        {canEdit && open && (
          <button onClick={openAdd}
            className="flex items-center gap-1.5 text-sm font-medium text-accent bg-accent-soft hover:bg-accent-softer
              border border-accent-border px-3 py-1.5 rounded-lg transition-colors duration-150">
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
            </svg>
            Add Socket
          </button>
        )}
      </div>

      {open && (sockets.length === 0 ? (
        <div className="bg-surface-card rounded-xl border border-line py-10 text-center text-ink-muted">
          <svg className="w-8 h-8 mx-auto mb-2 text-ink-muted2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
              d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" />
          </svg>
          <p className="text-sm">No sockets yet.</p>
        </div>
      ) : (
        <div className="grid grid-cols-2 gap-3">
          {sockets.map(s => {
            const style   = PHASE_STYLE[s.phase_type] ?? PHASE_STYLE['1phase'];
            const totalVA = Number(s.power) * Number(s.quantity);
            return (
              <div key={s.id}
                className="flex items-center gap-3 border border-line rounded-xl px-4 bg-surface-card
                  group transition-all duration-200 hover:border-line-strong hover:-translate-y-1"
                style={{ minHeight: '70px', paddingTop: '10px', paddingBottom: '10px' }}>
                <div className="w-8 h-8 bg-surface-inset group-hover:bg-accent-soft rounded-lg flex items-center
                  justify-center flex-shrink-0 transition-colors duration-200">
                  <svg className="w-4 h-4 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                      d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" />
                  </svg>
                </div>
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-1.5 mb-0.5">
                    <span className={`text-xs font-semibold px-1.5 py-0.5 rounded-full ${style.bg} ${style.text}`}>
                      {style.label}
                    </span>
                    <span className="text-sm font-semibold text-ink-heading">Socket</span>
                  </div>
                  <p className="text-xs text-ink-muted">
                    {fmtVA(s.power)} × {s.quantity} &nbsp;·&nbsp; Total {fmtVA(totalVA)}
                  </p>
                </div>
                {canEdit && (
                  <div className="flex items-center gap-1.5 flex-shrink-0">
                    <button onClick={() => openEdit(s)}
                      className="text-xs font-medium text-ink-muted px-2.5 py-1 rounded-lg border border-line bg-surface-card
                        hover:border-accent-border hover:text-accent hover:bg-accent-soft transition-all duration-150">Edit</button>
                    <button onClick={() => handleDelete(s.id)}
                      className="text-xs font-medium text-ink-muted px-2.5 py-1 rounded-lg border border-line bg-surface-card
                        hover:border-danger-border hover:text-danger hover:bg-danger-soft transition-all duration-150">Del</button>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      ))}

      {showModal && (
        <div className="fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4">
          <div className="bg-surface-card border border-line rounded-2xl w-full max-w-sm p-6">
            <h3 className="text-lg font-semibold text-ink-heading mb-5">
              {editing ? 'Edit Socket' : 'Add Socket'}
            </h3>
            <div className="space-y-4">

              {/* Phase type */}
              <div>
                <label className="block text-sm font-medium text-ink-body2 mb-2">Phase Type</label>
                <div className="flex rounded-lg border border-line overflow-hidden">
                  <button type="button"
                    onClick={() => setForm(f => ({ ...f, phase_type: '1phase' }))}
                    className={`flex-1 py-3 text-sm font-semibold transition-colors ${
                      form.phase_type === '1phase' ? 'bg-accent-gradient text-base' : 'text-ink-body2 hover:bg-surface-inset'
                    }`}>
                    1Φ — Single Phase
                  </button>
                  <button type="button"
                    onClick={() => setForm(f => ({ ...f, phase_type: '3phase' }))}
                    className={`flex-1 py-3 text-sm font-semibold transition-colors border-l border-line ${
                      form.phase_type === '3phase' ? 'bg-accent-gradient text-base' : 'text-ink-body2 hover:bg-surface-inset'
                    }`}>
                    3Φ — Three Phase
                  </button>
                </div>
              </div>

              {/* Power per socket */}
              <div>
                <label className="block text-sm font-medium text-ink-body2 mb-1">Power per Socket (VA)</label>
                <input type="number" min="1" step="1" autoFocus value={form.power}
                  onChange={e => setForm(f => ({ ...f, power: e.target.value }))}
                  placeholder="e.g. 2500"
                  className="w-full bg-surface-inset border border-line rounded-lg px-4 py-2.5 text-sm text-ink-data
                    focus:outline-none focus:ring-2 focus:ring-accent/40 focus:border-accent" />
              </div>

              {/* Quantity */}
              <div>
                <label className="block text-sm font-medium text-ink-body2 mb-1">Number of Sockets</label>
                <input type="number" min="1" step="1" value={form.quantity}
                  onChange={e => setForm(f => ({ ...f, quantity: e.target.value }))}
                  placeholder="e.g. 4"
                  className="w-full bg-surface-inset border border-line rounded-lg px-4 py-2.5 text-sm text-ink-data
                    focus:outline-none focus:ring-2 focus:ring-accent/40 focus:border-accent" />
              </div>

              {isValid && (
                <p className="text-xs text-ink-muted bg-surface-inset rounded-lg px-3 py-2">
                  Total load: <span className="font-semibold text-ink-body2">{fmtVA(Number(form.power) * Number(form.quantity))}</span>
                </p>
              )}
            </div>

            <div className="flex gap-3 mt-6">
              <button onClick={() => { setShowModal(false); setEditing(null); setForm(emptyForm); }}
                className="flex-1 border border-line text-ink-body2 py-2.5 rounded-lg text-sm font-medium hover:bg-surface-inset transition-colors">
                Cancel
              </button>
              <button onClick={handleSubmit} disabled={!isValid}
                className="flex-1 bg-accent-gradient text-base py-2.5 rounded-lg text-sm font-medium hover:shadow-accent transition-shadow disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:shadow-none">
                {editing ? 'Save Changes' : 'Add Socket'}
              </button>
            </div>
          </div>
        </div>
      )}
    </section>
  );
}
