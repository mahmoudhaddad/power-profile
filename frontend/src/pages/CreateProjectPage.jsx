import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import api from '../api/axios';

const BUILDING_TYPES = [
  {
    key: 'educational',
    label: 'Educational',
    icon: (
      <svg className="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
          d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14zm-4 6v-7.5l4-2.222" />
      </svg>
    ),
  },
  {
    key: 'healthcare',
    label: 'Healthcare',
    icon: (
      <svg className="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
          d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0zM9 12h6M12 9v6" />
      </svg>
    ),
  },
  {
    key: 'housing',
    label: 'Housing',
    icon: (
      <svg className="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
          d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
      </svg>
    ),
  },
  {
    key: 'institutions',
    label: 'Institutions',
    icon: (
      <svg className="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
          d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z" />
      </svg>
    ),
  },
];

const TOTAL_STEPS = 2;

export default function CreateProjectPage() {
  const { id } = useParams();
  const navigate = useNavigate();

  const [project, setProject] = useState(null);
  const [loading, setLoading] = useState(true);
  const [step, setStep] = useState(1);
  const [selectedType, setSelectedType] = useState(null);
  const [editingName, setEditingName] = useState(false);
  const [nameInput, setNameInput] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api.get(`/api/projects/${id}`)
      .then(({ data }) => {
        setProject(data.data);
        setNameInput(data.data.name);
        setSelectedType(data.data.building_type || null);
        setStep(1);
      })
      .catch(() => navigate('/dashboard'))
      .finally(() => setLoading(false));
  }, [id]);

  async function saveName() {
    if (!nameInput.trim() || nameInput === project.name) {
      setEditingName(false);
      return;
    }
    const { data } = await api.put(`/api/projects/${id}`, { name: nameInput.trim() });
    setProject(data.data);
    setEditingName(false);
  }

  async function handleNext() {
    if (step === TOTAL_STEPS) {
      navigate('/dashboard');
    } else {
      setStep(s => s + 1);
    }
  }

  function handlePrev() {
    if (step === 1) {
      navigate('/dashboard');
    } else {
      setStep(s => s - 1);
    }
  }

  if (loading) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        <div className="w-10 h-10 border-4 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-50 flex flex-col">

      {/* Top Bar */}
      <header className="bg-white border-b border-gray-200 px-6 py-4 flex items-center justify-between">
        <div className="flex items-center gap-3">
          <button onClick={() => navigate('/dashboard')}
            className="text-gray-400 hover:text-gray-600 transition-colors p-1 rounded-lg hover:bg-gray-100">
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
            </svg>
          </button>

          {editingName ? (
            <div className="flex items-center gap-2">
              <input
                autoFocus
                value={nameInput}
                onChange={e => setNameInput(e.target.value)}
                onKeyDown={e => { if (e.key === 'Enter') saveName(); if (e.key === 'Escape') setEditingName(false); }}
                className="text-lg font-semibold text-gray-900 border-b-2 border-blue-500
                  outline-none bg-transparent w-56"
              />
              <button onClick={saveName}
                className="text-xs text-blue-600 hover:text-blue-800 font-medium px-2 py-1
                  rounded hover:bg-blue-50 transition-colors">
                Save
              </button>
              <button onClick={() => setEditingName(false)}
                className="text-xs text-gray-400 hover:text-gray-600 font-medium px-2 py-1
                  rounded hover:bg-gray-100 transition-colors">
                Cancel
              </button>
            </div>
          ) : (
            <div className="flex items-center gap-2">
              <h1 className="text-lg font-semibold text-gray-900">{project?.name}</h1>
              <button onClick={() => setEditingName(true)}
                className="text-gray-400 hover:text-blue-500 transition-colors p-1 rounded
                  hover:bg-blue-50">
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
              </button>
            </div>
          )}
        </div>

        {/* Step indicator */}
        <div className="flex items-center gap-2">
          {Array.from({ length: TOTAL_STEPS }).map((_, i) => (
            <div key={i} className="flex items-center gap-2">
              <div className={`w-8 h-8 rounded-full flex items-center justify-center text-xs font-semibold
                transition-all duration-200 ${
                  i + 1 < step
                    ? 'bg-blue-600 text-white'
                    : i + 1 === step
                    ? 'bg-blue-600 text-white ring-4 ring-blue-100'
                    : 'bg-gray-200 text-gray-400'
                }`}>
                {i + 1 < step ? (
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M5 13l4 4L19 7" />
                  </svg>
                ) : (
                  i + 1
                )}
              </div>
              {i < TOTAL_STEPS - 1 && (
                <div className={`w-10 h-0.5 transition-all duration-200 ${i + 1 < step ? 'bg-blue-600' : 'bg-gray-200'}`} />
              )}
            </div>
          ))}
        </div>
      </header>

      {/* Step Content */}
      <main className="flex-1 flex flex-col items-center justify-center px-4 py-12">
        {step === 1 && (
          <StepPlaceholder step={1} title="Project Details" />
        )}
        {step === 2 && (
          <StepPlaceholder step={2} title="Review & Confirm" />
        )}
      </main>

      {/* Bottom Nav */}
      <footer className="bg-white border-t border-gray-200 px-6 py-4 flex items-center justify-between">
        <button
          onClick={handlePrev}
          className="flex items-center gap-2 px-5 py-2.5 rounded-xl border border-gray-300
            text-gray-700 text-sm font-medium hover:bg-gray-50 transition-colors"
        >
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
          </svg>
          {step === 1 ? 'Cancel' : 'Previous'}
        </button>

        <span className="text-sm text-gray-400">Step {step} of {TOTAL_STEPS}</span>

        <button
          onClick={handleNext}
          disabled={saving}
          className="flex items-center gap-2 px-5 py-2.5 rounded-xl bg-blue-600 text-white
            text-sm font-medium hover:bg-blue-700 transition-colors
            disabled:opacity-40 disabled:cursor-not-allowed"
        >
          {saving ? 'Saving...' : step === TOTAL_STEPS ? 'Finish' : 'Next'}
          {!saving && step < TOTAL_STEPS && (
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
            </svg>
          )}
        </button>
      </footer>
    </div>
  );
}

function Step1({ selected, onSelect }) {
  return (
    <div className="w-full max-w-2xl">
      <div className="text-center mb-10">
        <h2 className="text-2xl font-bold text-gray-900">Select Building Type</h2>
        <p className="text-gray-500 mt-2 text-sm">Choose the type that best describes the buildings in this project</p>
      </div>

      <div className="grid grid-cols-2 gap-4">
        {BUILDING_TYPES.map(type => (
          <button
            key={type.key}
            onClick={() => onSelect(type.key)}
            className={`group flex flex-col items-center gap-4 p-8 rounded-2xl border-2
              transition-all duration-200 text-center
              ${selected === type.key
                ? 'border-blue-500 bg-blue-50 shadow-md'
                : 'border-gray-200 bg-white hover:border-blue-300 hover:bg-blue-50 hover:shadow-sm'
              }`}
          >
            <div className={`w-16 h-16 rounded-2xl flex items-center justify-center transition-colors duration-200
              ${selected === type.key
                ? 'bg-blue-600 text-white'
                : 'bg-gray-100 text-gray-500 group-hover:bg-blue-100 group-hover:text-blue-600'
              }`}>
              {type.icon}
            </div>
            <div>
              <p className={`font-semibold text-base transition-colors duration-200
                ${selected === type.key ? 'text-blue-700' : 'text-gray-800 group-hover:text-blue-700'}`}>
                {type.label}
              </p>
            </div>
            {selected === type.key && (
              <div className="w-5 h-5 rounded-full bg-blue-600 flex items-center justify-center">
                <svg className="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M5 13l4 4L19 7" />
                </svg>
              </div>
            )}
          </button>
        ))}
      </div>
    </div>
  );
}

function StepPlaceholder({ step, title }) {
  return (
    <div className="w-full max-w-2xl text-center">
      <div className="w-20 h-20 bg-gray-100 rounded-2xl flex items-center justify-center mx-auto mb-6">
        <span className="text-3xl font-bold text-gray-300">{step}</span>
      </div>
      <h2 className="text-2xl font-bold text-gray-900 mb-2">{title}</h2>
      <p className="text-gray-400 text-sm">This step is coming soon.</p>
    </div>
  );
}
