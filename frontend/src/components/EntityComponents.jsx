import { useState, useEffect, useRef } from 'react';
import api from '../api/axios';

const PRIORITY_LABELS = {
  critical:  { label: 'Critical',  bg: 'bg-red-100',    text: 'text-red-700'    },
  essential: { label: 'Essential', bg: 'bg-amber-100',  text: 'text-amber-700'  },
  normal:    { label: 'Normal',    bg: 'bg-gray-100',   text: 'text-gray-500'   },
};

// ── Hour option helpers ───────────────────────────────────────────────────────
const HOURS_START = Array.from({ length: 24 }, (_, i) => ({
  value: i,
  label: `${String(i).padStart(2, '0')}:00`,
}));
const HOURS_END = Array.from({ length: 24 }, (_, i) => ({
  value: i + 1,
  label: i === 23 ? '24:00' : `${String(i + 1).padStart(2, '0')}:00`,
}));

// ── Flexibility badge (Part B) ────────────────────────────────────────────────
function FlexBadge({ flex }) {
  if (!flex || flex === 'fixed') return null;
  if (flex === 'shiftable')
    return (
      <span className="text-xs font-semibold px-1.5 py-0.5 rounded-full flex-shrink-0 bg-yellow-100 text-yellow-700 flex items-center gap-0.5">
        <svg className="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
        </svg>
        Shiftable
      </span>
    );
  return (
    <span className="text-xs font-semibold px-1.5 py-0.5 rounded-full flex-shrink-0 bg-orange-100 text-orange-700">
      ↓ Curtailable
    </span>
  );
}

const emptyFlexFields = () => ({
  load_flexibility:    'fixed',
  required_run_hours:  '',
  earliest_start_hour: 6,
  latest_end_hour:     22,
  min_continuous_run:  '1',
  allow_split:         false,
  max_interruptions:   '1',
  curtail_min_pct:     '50',
});

export default function EntityComponents({ endpoint, componentTypes, onTypesUpdated, onChanged, canEdit = true }) {
  const [components, setComponents] = useState([]);
  const [showModal, setShowModal]   = useState(false);
  const [editingComp, setEditingComp] = useState(null);

  const emptyForm = {
    name: '', power: '', quantity: '1', priority: 'normal',
    phases: '1phase', phase: null, power_factor: '1',
    group_name: '', needs_socket: false, is_motor: false,
    usage_season: 'all', usage_day_type: 'all',
    usage_time_intervals: [{ start: '08:00', end: '18:00' }],
    ...emptyFlexFields(),
  };
  const [form, setForm] = useState(emptyForm);

  const formRef         = useRef(form);
  formRef.current       = form;
  const editingCompRef  = useRef(editingComp);
  editingCompRef.current= editingComp;
  const componentsRef   = useRef(components);
  componentsRef.current = components;

  useEffect(() => {
    if (!endpoint) return;
    api.get(endpoint).then(({ data }) => setComponents(data.data)).catch(() => {});
  }, [endpoint]);

  // ── Build shiftable-specific payload fields (Part C) ──────────────────────
  function flexPayload(f) {
    if (f.load_flexibility === 'shiftable') {
      return {
        load_flexibility:    'shiftable',
        required_run_hours:  f.required_run_hours !== '' ? Number(f.required_run_hours) : null,
        earliest_start_hour: Number(f.earliest_start_hour),
        latest_end_hour:     Number(f.latest_end_hour),
        min_continuous_run:  f.min_continuous_run !== '' ? Number(f.min_continuous_run) : null,
        max_interruptions:   f.allow_split ? (Number(f.max_interruptions) || 1) : 0,
        curtail_min_pct:     null,
      };
    }
    if (f.load_flexibility === 'curtailable') {
      return {
        load_flexibility:    'curtailable',
        required_run_hours:  null,
        earliest_start_hour: null,
        latest_end_hour:     null,
        min_continuous_run:  null,
        max_interruptions:   null,
        curtail_min_pct:     f.curtail_min_pct !== '' ? Number(f.curtail_min_pct) : 50,
      };
    }
    // 'fixed'
    return {
      load_flexibility:    'fixed',
      required_run_hours:  null,
      earliest_start_hour: null,
      latest_end_hour:     null,
      min_continuous_run:  null,
      max_interruptions:   null,
      curtail_min_pct:     null,
    };
  }

  async function handleSubmit() {
    const f    = formRef.current;
    const comp = editingCompRef.current;
    const payload = {
      component_name:       f.name.trim(),
      power:                f.power,
      phases:               f.phases,
      phase:                f.phases === '1phase' ? (f.phase || null) : null,
      power_factor:         f.power_factor,
      quantity:             f.quantity,
      priority:             f.priority,
      group_name:           f.group_name || null,
      needs_socket:         f.needs_socket,
      is_motor:             f.is_motor,
      usage_season:         f.usage_season,
      usage_day_type:       f.usage_day_type,
      usage_time_intervals: f.usage_time_intervals,
      ...flexPayload(f),
    };

    setSubmitError('');
    try {
      if (comp) {
        const { data } = await api.put(`${endpoint}/${comp.id}`, payload);
        setComponents(componentsRef.current.map(c => c.id === comp.id ? data.data : c));
        setEditingComp(null);
      } else {
        const { data } = await api.post(endpoint, payload);
        setComponents([data.data, ...componentsRef.current]);
        if (onTypesUpdated && !componentTypes.find(t => t.name === f.name.trim())) {
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
    const ivs = comp.usage_time_intervals;
    const parsedIvs = ivs ? (typeof ivs === 'string' ? JSON.parse(ivs) : ivs) : [{ start: '08:00', end: '18:00' }];
    const flex = comp.load_flexibility ?? 'fixed';
    const { data } = await api.post(endpoint, {
      component_name:       comp.component_type.name,
      power:                comp.power,
      phases:               comp.phases,
      phase:                comp.phases === '1phase' ? (comp.phase ?? null) : null,
      power_factor:         comp.power_factor,
      quantity:             comp.quantity,
      priority:             comp.priority,
      group_name:           comp.group_name ?? null,
      needs_socket:         comp.needs_socket,
      is_motor:             comp.component_type?.is_motor ?? false,
      usage_season:         comp.usage_season         ?? 'all',
      usage_day_type:       comp.usage_day_type       ?? 'all',
      usage_time_intervals: parsedIvs,
      load_flexibility:     flex,
      required_run_hours:   comp.required_run_hours   ?? null,
      earliest_start_hour:  comp.earliest_start_hour  ?? null,
      latest_end_hour:      comp.latest_end_hour      ?? null,
      min_continuous_run:   comp.min_continuous_run   ?? null,
      max_interruptions:    comp.max_interruptions     ?? null,
      curtail_min_pct:      comp.curtail_min_pct       ?? null,
    });
    setComponents(prev => [data.data, ...prev]);
    onChanged?.();
  }

  function openEdit(comp) {
    setEditingComp(comp);
    const ivs = comp.usage_time_intervals;
    const parsedIvs = ivs ? (typeof ivs === 'string' ? JSON.parse(ivs) : ivs) : [{ start: '08:00', end: '18:00' }];
    const flex = comp.load_flexibility ?? 'fixed';
    const maxInt = comp.max_interruptions ?? 0;
    setForm({
      name:                 comp.component_type.name,
      power:                comp.power,
      phases:               comp.phases ?? '1phase',
      phase:                comp.phase  ?? null,
      power_factor:         comp.power_factor ?? '1',
      quantity:             comp.quantity,
      priority:             comp.priority,
      group_name:           comp.group_name           ?? '',
      needs_socket:         comp.needs_socket         ?? false,
      is_motor:             comp.component_type?.is_motor ?? false,
      usage_season:         comp.usage_season         ?? 'all',
      usage_day_type:       comp.usage_day_type       ?? 'all',
      usage_time_intervals: parsedIvs,
      load_flexibility:     flex,
      required_run_hours:   comp.required_run_hours   ?? '',
      earliest_start_hour:  comp.earliest_start_hour  ?? 6,
      latest_end_hour:      comp.latest_end_hour      ?? 22,
      min_continuous_run:   comp.min_continuous_run   ?? '1',
      allow_split:          maxInt > 0,
      max_interruptions:    maxInt > 0 ? String(maxInt) : '1',
      curtail_min_pct:      comp.curtail_min_pct      ?? '50',
    });
    setShowModal(true);
  }

  function openAdd() {
    setEditingComp(null);
    setForm(emptyForm);
    setShowModal(true);
  }

  const intervalsOk = Array.isArray(form.usage_time_intervals)
    && form.usage_time_intervals.length >= 1
    && form.usage_time_intervals.every(iv => iv.start && iv.end);

  // Shiftable is valid without time intervals (optimizer assigns them)
  const flexOk = form.load_flexibility !== 'shiftable' ? intervalsOk : (
    form.required_run_hours !== '' && Number(form.required_run_hours) >= 1
  );

  const isValid = form.name.trim() && Number(form.power) > 0 && Number(form.quantity) >= 1 && flexOk;

  const [submitError, setSubmitError] = useState('');
  const [open, setOpen] = useState(true);

  return (
    <section className="mt-8">
      <div className={`flex items-center justify-between ${open ? 'mb-4' : 'mb-0'}`}>
        <button onClick={() => setOpen(o => !o)} className="flex items-center gap-2 group">
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

// ── Part B — Component card with flexibility badge ────────────────────────────
function ComponentCard({ comp, canEdit, onEdit, onDelete, onDuplicate }) {
  const va    = Number(comp.power);
  const pf    = Number(comp.power_factor ?? 1);
  const qty   = Number(comp.quantity ?? 1);
  const totalW= va * pf * qty;
  const phases= comp.phases === '3phase' ? '3Φ' : '1Φ';
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
          {/* ── Flexibility badge (Part B) ── */}
          <FlexBadge flex={comp.load_flexibility} />
          <span className={`text-xs font-semibold px-1.5 py-0.5 rounded-full flex-shrink-0 ${
            comp.phases === '3phase' ? 'bg-violet-100 text-violet-700' : 'bg-blue-100 text-blue-700'
          }`}>{phases}</span>
          {comp.phases !== '3phase' && comp.phase && (
            <span className={`text-xs font-bold px-1.5 py-0.5 rounded-full flex-shrink-0 ${
              comp.phase === 'A' ? 'bg-indigo-100 text-indigo-700' :
              comp.phase === 'B' ? 'bg-emerald-100 text-emerald-700' :
                                   'bg-amber-100 text-amber-700'
            }`}>Ph {comp.phase}</span>
          )}
          {comp.needs_socket && (
            <span className="text-xs font-semibold px-1.5 py-0.5 rounded-full flex-shrink-0 bg-orange-100 text-orange-600 flex items-center gap-0.5">
              <svg className="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" />
              </svg>
              Socket
            </span>
          )}
          {comp.component_type?.is_motor && (
            <span className="text-xs font-semibold px-1.5 py-0.5 rounded-full flex-shrink-0 bg-rose-100 text-rose-700 flex items-center gap-0.5">
              <svg className="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
              Motor
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
        {comp.load_flexibility === 'shiftable' && comp.required_run_hours && (
          <p className="text-xs text-yellow-600 mt-0.5">
            ⚡ Run {comp.required_run_hours}h/day
            {comp.earliest_start_hour != null && comp.latest_end_hour != null
              ? ` · window ${String(comp.earliest_start_hour).padStart(2,'0')}:00–${comp.latest_end_hour === 24 ? '24:00' : `${String(comp.latest_end_hour).padStart(2,'0')}:00`}`
              : ''}
          </p>
        )}
        {(comp.usage_season !== 'all' || comp.usage_day_type !== 'all' || (comp.usage_time_intervals?.length > 0)) && comp.load_flexibility !== 'shiftable' && (
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

// ── Part A — Component modal (with full load flexibility section) ─────────────
function ComponentModal({ title, form, onChange, onSubmit, onClose, submitLabel, componentTypes, existingGroups = [], isValid, submitError }) {
  const inputRef   = useRef(null);
  const wrapperRef = useRef(null);
  const groupRef   = useRef(null);
  const [showSuggestions, setShowSuggestions] = useState(false);
  const [showGroupList, setShowGroupList]     = useState(false);

  const intervals = form.usage_time_intervals ?? [{ start: '08:00', end: '18:00' }];
  function addInterval()   { onChange({ ...form, usage_time_intervals: [...intervals, { start: '', end: '' }] }); }
  function removeInterval(idx) { if (intervals.length <= 1) return; onChange({ ...form, usage_time_intervals: intervals.filter((_, i) => i !== idx) }); }
  function updateInterval(idx, field, value) { onChange({ ...form, usage_time_intervals: intervals.map((iv, i) => i === idx ? { ...iv, [field]: value } : iv) }); }

  useEffect(() => {
    function h(e) { if (wrapperRef.current && !wrapperRef.current.contains(e.target)) setShowSuggestions(false); }
    document.addEventListener('mousedown', h);
    return () => document.removeEventListener('mousedown', h);
  }, []);

  const filtered = form.name.trim()
    ? componentTypes.filter(t => t.name.toLowerCase().includes(form.name.toLowerCase()))
    : componentTypes;

  const flex = form.load_flexibility ?? 'fixed';

  return (
    <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm flex flex-col max-h-[90vh]">
        <h3 className="text-lg font-semibold text-gray-900 px-6 pt-6 pb-4 flex-shrink-0">{title}</h3>
        <div className="space-y-4 overflow-y-auto px-6 flex-1" style={{ minHeight: 0 }}>

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
                      if (t.default_needs_socket  != null) next.needs_socket = t.default_needs_socket;
                      if (t.is_motor              != null) next.is_motor     = t.is_motor;
                      if (t.default_usage_season)          next.usage_season = t.default_usage_season;
                      if (t.default_usage_day_type)        next.usage_day_type = t.default_usage_day_type;
                      if (t.default_usage_time_intervals) {
                        const raw = t.default_usage_time_intervals;
                        next.usage_time_intervals = typeof raw === 'string' ? JSON.parse(raw) : raw;
                      }
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
            <div className="flex-1">
              <label className="block text-sm font-medium text-gray-700 mb-1">Phases</label>
              <div className="flex rounded-lg border border-gray-300 overflow-hidden">
                <button type="button" onClick={() => onChange({ ...form, phases: '1phase' })}
                  className={`flex-1 py-2.5 text-sm font-semibold transition-colors ${form.phases === '1phase' ? 'bg-blue-500 text-white' : 'text-gray-600 hover:bg-gray-50'}`}>1Φ</button>
                <button type="button" onClick={() => onChange({ ...form, phases: '3phase' })}
                  className={`flex-1 py-2.5 text-sm font-semibold transition-colors border-l border-gray-300 ${form.phases === '3phase' ? 'bg-violet-500 text-white' : 'text-gray-600 hover:bg-gray-50'}`}>3Φ</button>
              </div>
            </div>
            <div className="flex-1">
              <label className="block text-sm font-medium text-gray-700 mb-1">Power Factor</label>
              <input type="number" min="0.01" max="1" step="0.01" value={form.power_factor}
                onChange={e => onChange({ ...form, power_factor: e.target.value })}
                placeholder="0.00 – 1.00"
                className="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm
                  focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" />
            </div>
          </div>

          {/* Phase (1-phase only) */}
          {form.phases === '1phase' && (
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Phase <span className="text-gray-400 font-normal text-xs">(optional)</span>
              </label>
              <div className="flex gap-1.5">
                {[['A', 'bg-indigo-500 border-indigo-500'], ['B', 'bg-emerald-500 border-emerald-500'], ['C', 'bg-amber-500 border-amber-500']].map(([ph, active]) => (
                  <button key={ph} type="button"
                    onClick={() => onChange({ ...form, phase: form.phase === ph ? null : ph })}
                    className={`flex-1 py-2 text-sm font-bold rounded-lg border-2 transition-colors ${form.phase === ph ? `${active} text-white` : 'border-gray-200 text-gray-400 hover:border-gray-400 bg-white'}`}>
                    {ph}
                  </button>
                ))}
                {form.phase && (
                  <button type="button" onClick={() => onChange({ ...form, phase: null })}
                    className="px-3 py-2 text-sm font-medium rounded-lg border-2 border-gray-200 text-gray-400 hover:border-red-300 hover:text-red-500 bg-white transition-colors">
                    Clear
                  </button>
                )}
              </div>
            </div>
          )}

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
            <select value={form.priority}
              onChange={e => {
                const p = e.target.value;
                onChange({
                  ...form,
                  priority: p,
                  ...(p === 'critical' ? {
                    usage_season: 'all', usage_day_type: 'all',
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

          {/* ── Part A: Load Scheduling Type ──────────────────────────────── */}
          <div className="space-y-2">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Load Scheduling Type</label>
              <select value={flex}
                onChange={e => onChange({ ...form, load_flexibility: e.target.value })}
                className="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm
                  focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white">
                <option value="fixed">Fixed</option>
                <option value="shiftable">Shiftable</option>
                <option value="curtailable">Curtailable</option>
              </select>
              <p className="text-xs text-gray-400 mt-1 leading-relaxed">
                <strong>Fixed:</strong> runs at the times you set below.&nbsp;
                <strong>Shiftable:</strong> system finds the cheapest time window.&nbsp;
                <strong>Curtailable:</strong> can be reduced during peak cost hours.
              </p>
            </div>

            {/* Shiftable sub-section */}
            {flex === 'shiftable' && (
              <div className="border border-yellow-200 bg-yellow-50 rounded-xl p-3 space-y-3">
                <p className="text-xs font-semibold text-yellow-800 uppercase tracking-wide">Shiftable Load Settings</p>

                {/* Required run hours */}
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Required run hours per day</label>
                  <input type="number" min="1" max="24" step="1" value={form.required_run_hours}
                    onChange={e => onChange({ ...form, required_run_hours: e.target.value })}
                    placeholder="e.g. 3"
                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm
                      focus:outline-none focus:ring-2 focus:ring-yellow-400" />
                  <p className="text-xs text-gray-400 mt-0.5">How many hours this load must run every day</p>
                </div>

                {/* Earliest start + Latest end */}
                <div className="grid grid-cols-2 gap-2">
                  <div>
                    <label className="block text-xs font-medium text-gray-700 mb-1">Earliest allowed start</label>
                    <select value={form.earliest_start_hour}
                      onChange={e => onChange({ ...form, earliest_start_hour: Number(e.target.value) })}
                      className="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-400 bg-white">
                      {HOURS_START.map(h => <option key={h.value} value={h.value}>{h.label}</option>)}
                    </select>
                    <p className="text-xs text-gray-400 mt-0.5">Cannot start before</p>
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-gray-700 mb-1">Latest allowed end</label>
                    <select value={form.latest_end_hour}
                      onChange={e => onChange({ ...form, latest_end_hour: Number(e.target.value) })}
                      className="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-400 bg-white">
                      {HOURS_END.map(h => <option key={h.value} value={h.value}>{h.label}</option>)}
                    </select>
                    <p className="text-xs text-gray-400 mt-0.5">Must finish by</p>
                  </div>
                </div>

                {/* Min continuous run */}
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Minimum continuous run (hours)</label>
                  <input type="number" min="1" max="24" step="1" value={form.min_continuous_run}
                    onChange={e => onChange({ ...form, min_continuous_run: e.target.value })}
                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm
                      focus:outline-none focus:ring-2 focus:ring-yellow-400" />
                  <p className="text-xs text-gray-400 mt-0.5">Minimum hours it must run without stopping once started</p>
                </div>

                {/* Allow split toggle */}
                <button type="button"
                  onClick={() => onChange({ ...form, allow_split: !form.allow_split })}
                  className={`w-full flex items-center justify-between px-3 py-2.5 rounded-xl border-2 transition-all duration-150 ${
                    form.allow_split ? 'border-yellow-400 bg-yellow-100' : 'border-gray-200 bg-white hover:border-yellow-300'
                  }`}>
                  <span className={`text-sm font-medium ${form.allow_split ? 'text-yellow-800' : 'text-gray-600'}`}>
                    Allow split schedule <span className="text-xs font-normal">(e.g. 2h morning + 1h evening)</span>
                  </span>
                  <div className={`w-10 h-5 rounded-full transition-colors duration-200 flex items-center px-0.5 flex-shrink-0 ${
                    form.allow_split ? 'bg-yellow-400 justify-end' : 'bg-gray-200 justify-start'
                  }`}>
                    <div className="w-4 h-4 bg-white rounded-full shadow-sm" />
                  </div>
                </button>

                {/* Max interruptions (visible when allow_split is ON) */}
                {form.allow_split && (
                  <div>
                    <label className="block text-xs font-medium text-gray-700 mb-1">Maximum number of splits</label>
                    <input type="number" min="1" max="5" step="1" value={form.max_interruptions}
                      onChange={e => onChange({ ...form, max_interruptions: e.target.value })}
                      className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm
                        focus:outline-none focus:ring-2 focus:ring-yellow-400" />
                    <p className="text-xs text-gray-400 mt-0.5">How many separate blocks the schedule can be split into</p>
                  </div>
                )}
              </div>
            )}

            {/* Curtailable sub-section */}
            {flex === 'curtailable' && (
              <div className="border border-orange-200 bg-orange-50 rounded-xl p-3 space-y-2">
                <p className="text-xs font-semibold text-orange-800 uppercase tracking-wide">Curtailable Settings</p>
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Minimum load when curtailed (%)</label>
                  <input type="number" min="0" max="100" step="1" value={form.curtail_min_pct}
                    onChange={e => onChange({ ...form, curtail_min_pct: e.target.value })}
                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm
                      focus:outline-none focus:ring-2 focus:ring-orange-400" />
                  <p className="text-xs text-gray-400 mt-0.5">During high-cost hours, load runs at this % of full power</p>
                </div>
              </div>
            )}
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
                  className="px-3 py-2 rounded-lg border border-gray-200 text-gray-400 hover:text-gray-600 hover:bg-gray-50 text-sm">✕</button>
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
                  <li onMouseDown={() => setShowGroupList(false)} className="px-4 py-2 text-sm text-teal-600 font-medium bg-teal-50 cursor-default">
                    Create "{form.group_name}"
                  </li>
                )}
                {existingGroups.length === 0 && !form.group_name && (
                  <li className="px-4 py-2 text-xs text-gray-400 cursor-default">Type a name to create a new group</li>
                )}
              </ul>
            )}
          </div>

          {/* Needs Socket */}
          <button type="button"
            onClick={() => onChange({ ...form, needs_socket: !form.needs_socket })}
            className={`w-full flex items-center justify-between px-4 py-3 rounded-xl border-2 transition-all duration-150 ${
              form.needs_socket ? 'border-orange-400 bg-orange-50' : 'border-gray-200 bg-white hover:border-gray-300'
            }`}>
            <div className="flex items-center gap-2.5">
              <svg className={`w-4 h-4 ${form.needs_socket ? 'text-orange-500' : 'text-gray-400'}`}
                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" />
              </svg>
              <span className={`text-sm font-medium ${form.needs_socket ? 'text-orange-700' : 'text-gray-600'}`}>Needs socket outlet</span>
            </div>
            <div className={`w-10 h-5 rounded-full transition-colors duration-200 flex items-center px-0.5 ${form.needs_socket ? 'bg-orange-400 justify-end' : 'bg-gray-200 justify-start'}`}>
              <div className="w-4 h-4 bg-white rounded-full shadow-sm" />
            </div>
          </button>

          {/* Is Motor */}
          <button type="button"
            onClick={() => onChange({ ...form, is_motor: !form.is_motor })}
            className={`w-full flex items-center justify-between px-4 py-3 rounded-xl border-2 transition-all duration-150 ${
              form.is_motor ? 'border-rose-400 bg-rose-50' : 'border-gray-200 bg-white hover:border-gray-300'
            }`}>
            <div className="flex items-center gap-2.5">
              <svg className={`w-4 h-4 ${form.is_motor ? 'text-rose-500' : 'text-gray-400'}`}
                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
              <span className={`text-sm font-medium ${form.is_motor ? 'text-rose-700' : 'text-gray-600'}`}>Motor load (inrush sizing)</span>
            </div>
            <div className={`w-10 h-5 rounded-full transition-colors duration-200 flex items-center px-0.5 ${form.is_motor ? 'bg-rose-400 justify-end' : 'bg-gray-200 justify-start'}`}>
              <div className="w-4 h-4 bg-white rounded-full shadow-sm" />
            </div>
          </button>

          {/* Usage Schedule — grayed out for shiftable loads */}
          <div className={`space-y-2 border rounded-xl p-3 ${flex === 'shiftable' ? 'border-yellow-200 bg-yellow-50/40 opacity-60' : 'border-gray-200'}`}>
            <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide">Usage Schedule</p>

            {/* Yellow note for shiftable */}
            {flex === 'shiftable' && (
              <div className="flex items-start gap-2 bg-yellow-100 border border-yellow-300 rounded-lg px-3 py-2">
                <svg className="w-3.5 h-3.5 text-yellow-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
                <p className="text-xs text-yellow-800 leading-relaxed">
                  <strong>⚡ Time intervals are managed by the optimizer for shiftable loads.</strong>{' '}
                  Run the optimizer on the Load Schedule page to auto-assign the best window.
                </p>
              </div>
            )}

            {form.priority === 'critical' ? (
              <div className="flex items-center gap-2.5 px-3 py-2.5 bg-red-50 border border-red-100 rounded-lg">
                <svg className="w-4 h-4 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
                <p className="text-xs text-red-600 font-medium">Always on — critical loads run 24 / 7</p>
              </div>
            ) : (
              // Disable pointer events for time intervals when shiftable
              <div className={flex === 'shiftable' ? 'pointer-events-none select-none' : ''}>
                <ScheduleRow label="Season" value={form.usage_season}
                  options={[
                    { value: 'summer',  label: 'Summer',   on: 'bg-amber-400 text-white' },
                    { value: 'winter',  label: 'Winter',   on: 'bg-sky-500 text-white' },
                    { value: 'all',     label: 'All Year', on: 'bg-gray-400 text-white' },
                  ]}
                  onChange={v => onChange({ ...form, usage_season: v })} />
                <div className="mt-2">
                <ScheduleRow label="Days" value={form.usage_day_type}
                  options={[
                    { value: 'weekday', label: 'Weekday',  on: 'bg-slate-500 text-white' },
                    { value: 'weekend', label: 'Weekend',  on: 'bg-green-500 text-white' },
                    { value: 'all',     label: 'All Days', on: 'bg-gray-400 text-white' },
                  ]}
                  onChange={v => onChange({ ...form, usage_day_type: v })} />
                </div>
                <div className="mt-2">
                  <div className="flex items-center justify-between mb-1.5">
                    <p className="text-xs text-gray-400">Time Intervals</p>
                    <button type="button" onClick={addInterval}
                      className="text-xs text-blue-500 font-medium hover:text-blue-700">+ Add</button>
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
              </div>
            )}
          </div>
        </div>

        <div className="px-6 pt-3 pb-0 flex-shrink-0">
          {submitError && <p className="text-xs text-red-500 text-center">{submitError}</p>}
        </div>
        <div className="flex gap-3 px-6 py-5 flex-shrink-0 border-t border-gray-100">
          <button onClick={onClose}
            className="flex-1 border border-gray-300 text-gray-700 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-50 transition-colors">Cancel</button>
          <button type="button" onClick={onSubmit} disabled={!isValid}
            className="flex-1 bg-blue-600 text-white py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
            {submitLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
