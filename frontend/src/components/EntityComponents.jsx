import { useState, useEffect, useRef } from 'react';
import api from '../api/axios';

const PRIORITY_LABELS = {
  critical:  { label: 'Critical',  bg: 'bg-red-100',    text: 'text-red-700'    },
  essential: { label: 'Essential', bg: 'bg-amber-100',  text: 'text-amber-700'  },
  normal:    { label: 'Normal',    bg: 'bg-gray-100',   text: 'text-gray-500'   },
};

export default function EntityComponents({ endpoint, componentTypes, onTypesUpdated, onChanged, canEdit = true }) {
  const [components, setComponents] = useState([]);
  const [showModal, setShowModal]   = useState(false);
  const [editingComp, setEditingComp] = useState(null);
  const emptyForm = { name: '', power: '', quantity: '1', priority: 'normal', phases: '1phase', power_factor: '1', group_name: '', needs_socket: false, usage_season: 'all', usage_day_type: 'all', usage_time_intervals: [{ start: '08:00', end: '18:00' }] };
  const [form, setForm] = useState(emptyForm);

  useEffect(() => {
    if (!endpoint) return;
    api.get(endpoint).then(({ data }) => setComponents(data.data)).catch(() => {});
  }, [endpoint]);

  async function handleSubmit() {
    const payload = {
      component_name: form.name.trim(),
      power:         form.power,
      phases:        form.phases,
      power_factor:  form.power_factor,
      quantity:      form.quantity,
      priority:      form.priority,
      group_name:           form.group_name || null,
      needs_socket:         form.needs_socket,
      usage_season:         form.usage_season,
      usage_day_type:       form.usage_day_type,
      usage_time_intervals: form.usage_time_intervals,
    };

    setSubmitError('');
    try {
      if (editingComp) {
        const { data } = await api.put(`${endpoint}/${editingComp.id}`, payload);
        setComponents(components.map(c => c.id === editingComp.id ? data.data : c));
        setEditingComp(null);
      } else {
        const { data } = await api.post(endpoint, payload);
        setComponents([data.data, ...components]);
        if (onTypesUpdated && !componentTypes.find(t => t.name === form.name.trim())) {
          onTypesUpdated(data.data.component_type);
        }
      }
      setForm(emptyForm);
      setShowModal(false);
      onChanged?.();
    } catch {
      setSubmitError('Failed to save. Please try again.');
    }
  }

  async function handleDelete(id) {
    await api.delete(`${endpoint}/${id}`);
    setComponents(components.filter(c => c.id !== id));
    onChanged?.();
  }

  async function handleDuplicate(comp) {
    const { data } = await api.post(endpoint, {
      component_name: comp.component_type.name,
      power:         comp.power,
      phases:        comp.phases,
      power_factor:  comp.power_factor,
      quantity:      comp.quantity,
      priority:      comp.priority,
      group_name:           comp.group_name           ?? null,
      needs_socket:         comp.needs_socket,
      usage_season:         comp.usage_season         ?? 'all',
      usage_day_type:       comp.usage_day_type       ?? 'all',
      usage_time_intervals: comp.usage_time_intervals ?? [{ start: '08:00', end: '18:00' }],
    });
    setComponents(prev => [data.data, ...prev]);
    onChanged?.();
  }

  function openEdit(comp) {
    setEditingComp(comp);
    setForm({
      name:         comp.component_type.name,
      power:        comp.power,
      phases:       comp.phases ?? '1phase',
      power_factor: comp.power_factor ?? '1',
      quantity:     comp.quantity,
      priority:             comp.priority,
      group_name:           comp.group_name           ?? '',
      needs_socket:         comp.needs_socket         ?? false,
      usage_season:         comp.usage_season         ?? 'all',
      usage_day_type:       comp.usage_day_type       ?? 'all',
      usage_time_intervals: comp.usage_time_intervals ?? [{ start: '08:00', end: '18:00' }],
    });
    setShowModal(true);
  }

  function openAdd() {
    setEditingComp(null);
    setForm(emptyForm);
    setShowModal(true);
  }

  const intervalsOk = Array.isArray(form.usage_time_intervals) && form.usage_time_intervals.length >= 1 && form.usage_time_intervals.every(iv => iv.start && iv.end);
  const isValid = form.name.trim() && Number(form.power) > 0 && Number(form.quantity) >= 1 && intervalsOk;

  const [submitError, setSubmitError] = useState('');
  const [open, setOpen] = useState(true);

  return (
    <section className="mt-8">
      <div className={`flex items-center justify-between ${open ? 'mb-4' : 'mb-0'}`}>
        <button onClick={() => setOpen(o => !o)}
          className="flex items-center gap-2 group">
          <svg className={`w-4 h-4 text-gray-400 transition-transform duration-200 ${open ? 'rotate-90' : ''}`}
            fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M9 5l7 7-7 7" />
          </svg>
          <h2 className="text-base font-semibold text-gray-900 group-hover:text-gray-700">
            Electrical Components
            <span className="ml-2 text-xs font-normal text-gray-400">({components.length})</span>
          </h2>
        </button>
        {canEdit && open && (
          <button onClick={openAdd}
            className="flex items-center gap-1.5 text-sm font-medium text-blue-600 bg-blue-50 hover:bg-blue-100
              border border-blue-200 px-3 py-1.5 rounded-lg transition-colors duration-150">
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
            </svg>
            Add Component
          </button>
        )}
      </div>

      {open && (components.length === 0 ? (
        <div className="bg-white rounded-xl border border-gray-200 shadow-sm py-10 text-center text-gray-400">
          <svg className="w-8 h-8 mx-auto mb-2 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M13 10V3L4 14h7v7l9-11h-7z" />
          </svg>
          <p className="text-sm">No components yet.</p>
        </div>
      ) : (
        <div className="grid grid-cols-2 gap-3">
          {components.map(comp => (
            <ComponentCard key={comp.id} comp={comp}
              canEdit={canEdit}
              onEdit={() => openEdit(comp)}
              onDelete={() => handleDelete(comp.id)}
              onDuplicate={() => handleDuplicate(comp)} />
          ))}
        </div>
      ))}

      {showModal && (
        <ComponentModal
          title={editingComp ? 'Edit Component' : 'New Component'}
          form={form}
          onChange={setForm}
          onSubmit={handleSubmit}
          onClose={() => { setShowModal(false); setEditingComp(null); setForm(emptyForm); setSubmitError(''); }}
          submitLabel={editingComp ? 'Save Changes' : 'Add Component'}
          componentTypes={componentTypes}
          existingGroups={[...new Set(components.filter(c => c.group_name).map(c => c.group_name))]}
          isValid={isValid}
          submitError={submitError}
        />
      )}
    </section>
  );
}

function fmtVA(va) {
  const v = Number(va);
  if (!v) return '0 VA';
  if (v >= 1000000) return `${(v / 1000000).toLocaleString(undefined, { maximumFractionDigits: 2 })} MVA`;
  if (v >= 1000)    return `${(v / 1000).toLocaleString(undefined,    { maximumFractionDigits: 2 })} kVA`;
  return `${v.toLocaleString(undefined, { maximumFractionDigits: 2 })} VA`;
}

function fmtW(w) {
  const v = Number(w);
  if (!v) return '0 W';
  if (v >= 1000000) return `${(v / 1000000).toLocaleString(undefined, { maximumFractionDigits: 2 })} MW`;
  if (v >= 1000)    return `${(v / 1000).toLocaleString(undefined,    { maximumFractionDigits: 2 })} kW`;
  return `${v.toLocaleString(undefined, { maximumFractionDigits: 2 })} W`;
}

function ComponentCard({ comp, canEdit, onEdit, onDelete, onDuplicate }) {
  const va      = Number(comp.power);
  const pf      = Number(comp.power_factor ?? 1);
  const qty     = Number(comp.quantity ?? 1);
  const realW   = va * pf;           // P(W) = S(VA) × PF  — single and 3-phase formula
  const totalW  = realW * qty;
  const phases  = comp.phases === '3phase' ? '3Φ' : '1Φ';
  const { bg, text, label } = PRIORITY_LABELS[comp.priority] ?? PRIORITY_LABELS.normal;

  return (
    <div className="flex items-center gap-3 border border-yellow-300 rounded-xl px-4 bg-white
      group transition-all duration-200 hover:bg-yellow-50 hover:-translate-y-1 hover:shadow-md"
      style={{ minHeight: '70px', paddingTop: '10px', paddingBottom: '10px' }}>
      <div className="w-8 h-8 bg-yellow-50 group-hover:bg-yellow-100 rounded-lg flex items-center
        justify-center flex-shrink-0 transition-colors duration-200">
        <svg className="w-4 h-4 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
        </svg>
      </div>
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-1.5 mb-0.5 flex-wrap">
          <p className="font-semibold text-gray-900 text-sm truncate">{comp.component_type.name}</p>
          <span className={`text-xs px-1.5 py-0.5 rounded flex-shrink-0 ${bg} ${text}`}>{label}</span>
          <span className={`text-xs font-semibold px-1.5 py-0.5 rounded-full flex-shrink-0 ${
            comp.phases === '3phase' ? 'bg-violet-100 text-violet-700' : 'bg-blue-100 text-blue-700'
          }`}>{phases}</span>
          {comp.needs_socket && (
            <span className="text-xs font-semibold px-1.5 py-0.5 rounded-full flex-shrink-0 bg-orange-100 text-orange-600 flex items-center gap-0.5">
              <svg className="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" />
              </svg>
              Socket
            </span>
          )}
          {comp.group_name && (
            <span className="text-xs font-semibold px-1.5 py-0.5 rounded-full flex-shrink-0 bg-teal-100 text-teal-700 flex items-center gap-0.5">
              <svg className="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0" />
              </svg>
              {comp.group_name}
            </span>
          )}
        </div>
        <p className="text-xs text-gray-400">
          {fmtVA(va)} × {qty} &nbsp;·&nbsp; PF {pf} &nbsp;·&nbsp; {fmtW(totalW)}
        </p>
        {(comp.usage_season !== 'all' || comp.usage_day_type !== 'all' || (comp.usage_time_intervals?.length > 0)) && (
          <div className="flex gap-1 mt-0.5 flex-wrap">
            {comp.usage_season !== 'all' && (
              <span className={`text-xs px-1.5 py-0.5 rounded-full font-medium ${comp.usage_season === 'summer' ? 'bg-amber-100 text-amber-700' : 'bg-sky-100 text-sky-700'}`}>
                {comp.usage_season === 'summer' ? 'Summer' : 'Winter'}
              </span>
            )}
            {comp.usage_day_type !== 'all' && (
              <span className={`text-xs px-1.5 py-0.5 rounded-full font-medium ${comp.usage_day_type === 'weekday' ? 'bg-slate-100 text-slate-600' : 'bg-green-100 text-green-700'}`}>
                {comp.usage_day_type === 'weekday' ? 'Weekday' : 'Weekend'}
              </span>
            )}
            {comp.usage_time_intervals?.map((iv, i) => (
              <span key={i} className="text-xs px-1.5 py-0.5 rounded-full font-medium bg-indigo-100 text-indigo-700">
                {iv.start}–{iv.end}
              </span>
            ))}
          </div>
        )}
      </div>
      {canEdit && (
        <div className="flex items-center gap-1.5 flex-shrink-0">
          <button onClick={e => { e.stopPropagation(); onDuplicate(); }}
            className="text-xs font-medium text-gray-500 px-2.5 py-1 rounded-lg border border-gray-200 bg-white
              hover:border-violet-400 hover:text-violet-600 hover:bg-violet-50 transition-all duration-150">Dup</button>
          <button onClick={e => { e.stopPropagation(); onEdit(); }}
            className="text-xs font-medium text-gray-500 px-2.5 py-1 rounded-lg border border-gray-200 bg-white
              hover:border-blue-400 hover:text-blue-600 hover:bg-blue-50 transition-all duration-150">Edit</button>
          <button onClick={e => { e.stopPropagation(); onDelete(); }}
            className="text-xs font-medium text-gray-500 px-2.5 py-1 rounded-lg border border-gray-200 bg-white
              hover:border-red-300 hover:text-red-600 hover:bg-red-50 transition-all duration-150">Del</button>
        </div>
      )}
    </div>
  );
}

function ScheduleRow({ label, value, options, onChange }) {
  return (
    <div>
      <p className="text-xs text-gray-400 mb-1">{label}</p>
      <div className="flex rounded-lg border border-gray-200 overflow-hidden">
        {options.map((opt, i) => (
          <button key={opt.value} type="button"
            onClick={() => onChange(opt.value)}
            className={`flex-1 py-1.5 text-xs font-medium transition-colors ${i > 0 ? 'border-l border-gray-200' : ''} ${
              value === opt.value ? opt.on : 'text-gray-500 hover:bg-gray-50'
            }`}>
            {opt.label}
          </button>
        ))}
      </div>
    </div>
  );
}

function ComponentModal({ title, form, onChange, onSubmit, onClose, submitLabel, componentTypes, existingGroups = [], isValid, submitError }) {
  const inputRef   = useRef(null);
  const wrapperRef = useRef(null);
  const groupRef   = useRef(null);
  const [showSuggestions, setShowSuggestions] = useState(false);
  const [showGroupList, setShowGroupList]     = useState(false);

  const intervals = form.usage_time_intervals ?? [{ start: '08:00', end: '18:00' }];
  function addInterval() {
    onChange({ ...form, usage_time_intervals: [...intervals, { start: '', end: '' }] });
  }
  function removeInterval(idx) {
    if (intervals.length <= 1) return;
    onChange({ ...form, usage_time_intervals: intervals.filter((_, i) => i !== idx) });
  }
  function updateInterval(idx, field, value) {
    onChange({ ...form, usage_time_intervals: intervals.map((iv, i) => i === idx ? { ...iv, [field]: value } : iv) });
  }

  useEffect(() => {
    function handleClickOutside(e) {
      if (wrapperRef.current && !wrapperRef.current.contains(e.target)) {
        setShowSuggestions(false);
      }
    }
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const filtered = form.name.trim()
    ? componentTypes.filter(t => t.name.toLowerCase().includes(form.name.toLowerCase()))
    : componentTypes;

  return (
    <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm flex flex-col max-h-[90vh]">
        <h3 className="text-lg font-semibold text-gray-900 px-6 pt-6 pb-4 flex-shrink-0">{title}</h3>
        <div className="space-y-4 overflow-y-auto px-6 flex-1"  style={{ minHeight: 0 }}>

          {/* Name */}
          <div className="relative" ref={wrapperRef}>
            <label className="block text-sm font-medium text-gray-700 mb-1">Component Name</label>
            <input ref={inputRef} type="text" autoFocus value={form.name}
              onChange={e => { onChange({ ...form, name: e.target.value }); setShowSuggestions(true); }}
              onFocus={() => setShowSuggestions(true)}
              onKeyDown={e => { if (e.key === 'Escape') setShowSuggestions(false); }}
              placeholder="Select or type a component name"
              className="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm
                focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" />
            {showSuggestions && filtered.length > 0 && (
              <ul className="absolute z-10 w-full bg-white border border-gray-200 rounded-lg shadow-lg mt-1 max-h-40 overflow-y-auto">
                {filtered.map(t => (
                  <li key={t.id}
                    onMouseDown={() => {
                      const next = { ...form, name: t.name };
                      if (t.default_power)        next.power        = String(t.default_power);
                      if (t.default_phases)       next.phases       = t.default_phases;
                      if (t.default_power_factor) next.power_factor = String(t.default_power_factor);
                      if (t.default_needs_socket    != null) next.needs_socket      = t.default_needs_socket;
                      if (t.default_usage_season)          next.usage_season      = t.default_usage_season;
                      if (t.default_usage_day_type)        next.usage_day_type    = t.default_usage_day_type;
                      if (t.default_usage_time_intervals)  next.usage_time_intervals = t.default_usage_time_intervals;
                      onChange(next);
                      setShowSuggestions(false);
                    }}
                    className="px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 hover:text-blue-700 cursor-pointer">
                    <div className="flex items-center justify-between">
                      <span>{t.name}</span>
                      {t.is_preset && <span className="text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded">preset</span>}
                    </div>
                    {!t.is_preset && t.default_power && (
                      <p className="text-xs text-gray-400 mt-0.5">
                        {t.default_power} VA · {t.default_phases === '3phase' ? '3Φ' : '1Φ'} · PF {t.default_power_factor}
                      </p>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </div>

          {/* Power (VA) */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Apparent Power (VA)</label>
            <input type="number" min="1" step="1" value={form.power}
              onChange={e => onChange({ ...form, power: e.target.value })}
              placeholder="e.g. 60"
              className="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm
                focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" />
          </div>

          {/* Phases + Power Factor */}
          <div className="flex gap-3">
            {/* Phases */}
            <div className="flex-1">
              <label className="block text-sm font-medium text-gray-700 mb-1">Phases</label>
              <div className="flex rounded-lg border border-gray-300 overflow-hidden">
                <button type="button"
                  onClick={() => onChange({ ...form, phases: '1phase' })}
                  className={`flex-1 py-2.5 text-sm font-semibold transition-colors ${
                    form.phases === '1phase'
                      ? 'bg-blue-500 text-white'
                      : 'text-gray-600 hover:bg-gray-50'
                  }`}>1Φ</button>
                <button type="button"
                  onClick={() => onChange({ ...form, phases: '3phase' })}
                  className={`flex-1 py-2.5 text-sm font-semibold transition-colors border-l border-gray-300 ${
                    form.phases === '3phase'
                      ? 'bg-violet-500 text-white'
                      : 'text-gray-600 hover:bg-gray-50'
                  }`}>3Φ</button>
              </div>
            </div>
            {/* Power Factor */}
            <div className="flex-1">
              <label className="block text-sm font-medium text-gray-700 mb-1">Power Factor</label>
              <input type="number" min="0.01" max="1" step="0.01" value={form.power_factor}
                onChange={e => onChange({ ...form, power_factor: e.target.value })}
                placeholder="0.00 – 1.00"
                className="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm
                  focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" />
            </div>
          </div>

          {/* Quantity */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Number of Pieces</label>
            <input type="number" min="1" step="1" value={form.quantity}
              onChange={e => onChange({ ...form, quantity: e.target.value })}
              placeholder="e.g. 4"
              className="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm
                focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" />
          </div>

          {/* Priority */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Priority</label>
            <select value={form.priority} onChange={e => {
                const p = e.target.value;
                onChange({
                  ...form,
                  priority: p,
                  ...(p === 'critical' ? {
                    usage_season: 'all',
                    usage_day_type: 'all',
                    usage_time_intervals: [{ start: '00:00', end: '23:59' }],
                  } : {}),
                });
              }}
              className="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm
                focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white">
              <option value="normal">Normal</option>
              <option value="essential">Essential</option>
              <option value="critical">Critical</option>
            </select>
          </div>

          {/* Group */}
          <div className="relative" ref={groupRef}>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Load Group <span className="text-gray-400 font-normal text-xs">(optional)</span>
            </label>
            <div className="flex gap-2">
              <input type="text" value={form.group_name ?? ''}
                onChange={e => { onChange({ ...form, group_name: e.target.value }); setShowGroupList(true); }}
                onFocus={() => setShowGroupList(true)}
                onBlur={() => setTimeout(() => setShowGroupList(false), 150)}
                placeholder="Select or create a group…"
                className="flex-1 border border-gray-300 rounded-lg px-4 py-2.5 text-sm
                  focus:outline-none focus:ring-2 focus:ring-teal-400 focus:border-transparent" />
              {form.group_name && (
                <button type="button" onClick={() => onChange({ ...form, group_name: '' })}
                  className="px-3 py-2 rounded-lg border border-gray-200 text-gray-400 hover:text-gray-600 hover:bg-gray-50 text-sm">
                  ✕
                </button>
              )}
            </div>
            {showGroupList && (
              <ul className="absolute z-10 w-full bg-white border border-gray-200 rounded-lg shadow-lg mt-1 overflow-hidden">
                {existingGroups.filter(g => !form.group_name || g.toLowerCase().includes(form.group_name.toLowerCase())).map(g => (
                  <li key={g} onMouseDown={() => { onChange({ ...form, group_name: g }); setShowGroupList(false); }}
                    className="px-4 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 cursor-pointer flex items-center gap-2">
                    <svg className="w-3.5 h-3.5 text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                        d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0" />
                    </svg>
                    {g}
                  </li>
                ))}
                {form.group_name && !existingGroups.includes(form.group_name) && (
                  <li onMouseDown={() => setShowGroupList(false)}
                    className="px-4 py-2 text-sm text-teal-600 font-medium bg-teal-50 cursor-default">
                    Create "{form.group_name}"
                  </li>
                )}
                {existingGroups.length === 0 && !form.group_name && (
                  <li className="px-4 py-2 text-xs text-gray-400 cursor-default">
                    Type a name to create a new group
                  </li>
                )}
              </ul>
            )}
          </div>

          {/* Needs Socket */}
          <button type="button"
            onClick={() => onChange({ ...form, needs_socket: !form.needs_socket })}
            className={`w-full flex items-center justify-between px-4 py-3 rounded-xl border-2 transition-all duration-150 ${
              form.needs_socket
                ? 'border-orange-400 bg-orange-50'
                : 'border-gray-200 bg-white hover:border-gray-300'
            }`}>
            <div className="flex items-center gap-2.5">
              <svg className={`w-4 h-4 ${form.needs_socket ? 'text-orange-500' : 'text-gray-400'}`}
                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" />
              </svg>
              <span className={`text-sm font-medium ${form.needs_socket ? 'text-orange-700' : 'text-gray-600'}`}>
                Needs socket outlet
              </span>
            </div>
            <div className={`w-10 h-5 rounded-full transition-colors duration-200 flex items-center px-0.5 ${
              form.needs_socket ? 'bg-orange-400 justify-end' : 'bg-gray-200 justify-start'
            }`}>
              <div className="w-4 h-4 bg-white rounded-full shadow-sm" />
            </div>
          </button>

          {/* Usage Schedule */}
          <div className="space-y-2 border border-gray-200 rounded-xl p-3">
            <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide">Usage Schedule</p>
            {form.priority === 'critical' ? (
              <div className="flex items-center gap-2.5 px-3 py-2.5 bg-red-50 border border-red-100 rounded-lg">
                <svg className="w-4 h-4 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
                <p className="text-xs text-red-600 font-medium">Always on — critical loads run 24 / 7</p>
              </div>
            ) : (
            <>
            <ScheduleRow label="Season"
              value={form.usage_season}
              options={[
                { value: 'summer',  label: 'Summer',   on: 'bg-amber-400 text-white' },
                { value: 'winter',  label: 'Winter',   on: 'bg-sky-500 text-white' },
                { value: 'all',     label: 'All Year', on: 'bg-gray-400 text-white' },
              ]}
              onChange={v => onChange({ ...form, usage_season: v })} />
            <ScheduleRow label="Days"
              value={form.usage_day_type}
              options={[
                { value: 'weekday', label: 'Weekday',  on: 'bg-slate-500 text-white' },
                { value: 'weekend', label: 'Weekend',  on: 'bg-green-500 text-white' },
                { value: 'all',     label: 'All Days', on: 'bg-gray-400 text-white' },
              ]}
              onChange={v => onChange({ ...form, usage_day_type: v })} />
            <div>
              <div className="flex items-center justify-between mb-1.5">
                <p className="text-xs text-gray-400">Time Intervals</p>
                <button type="button" onClick={addInterval}
                  className="text-xs text-blue-500 font-medium hover:text-blue-700 flex items-center gap-0.5">
                  + Add
                </button>
              </div>
              <div className="space-y-1.5">
                {intervals.map((iv, i) => (
                  <div key={i} className="flex items-center gap-1.5">
                    <input type="time" value={iv.start}
                      onChange={e => updateInterval(i, 'start', e.target.value)}
                      className="flex-1 border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-blue-400" />
                    <span className="text-xs text-gray-400">–</span>
                    <input type="time" value={iv.end}
                      onChange={e => updateInterval(i, 'end', e.target.value)}
                      className="flex-1 border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-blue-400" />
                    <button type="button" onClick={() => removeInterval(i)}
                      disabled={intervals.length <= 1}
                      className="w-5 h-5 flex items-center justify-center rounded text-gray-400 hover:text-red-500 hover:bg-red-50 disabled:opacity-30 disabled:cursor-not-allowed transition-colors text-base leading-none">
                      ×
                    </button>
                  </div>
                ))}
              </div>
            </div>
            </>
            )}
          </div>
        </div>

        <div className="px-6 pt-3 pb-0 flex-shrink-0">
          {submitError && <p className="text-xs text-red-500 text-center">{submitError}</p>}
        </div>
        <div className="flex gap-3 px-6 py-5 flex-shrink-0 border-t border-gray-100">
          <button onClick={onClose}
            className="flex-1 border border-gray-300 text-gray-700 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-50 transition-colors">Cancel</button>
          <button onClick={onSubmit} disabled={!isValid}
            className="flex-1 bg-blue-600 text-white py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
            {submitLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
