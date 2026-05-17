/* eslint-disable react/prop-types */
import { useState } from 'react';

export default function BackupChoiceModal({ entityName, onDownload, onSaveToServer, onClose }) {
  const [saving, setSaving]   = useState(false);
  const [saved, setSaved]     = useState(false);
  const [error, setError]     = useState('');

  async function handleSaveToServer() {
    setSaving(true);
    setError('');
    try {
      await onSaveToServer();
      setSaved(true);
    } catch (err) {
      setError(err.response?.data?.message || err.message || 'Failed to save.');
    } finally {
      setSaving(false);
    }
  }

  function handleDownload() {
    onDownload();
    onClose();
  }

  return (
    <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4"
      onClick={e => { if (e.target === e.currentTarget) onClose(); }}>
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6">

        <div className="flex items-center justify-between mb-5">
          <h3 className="text-base font-semibold text-gray-900">Save Backup — {entityName}</h3>
          <button onClick={onClose}
            className="text-gray-400 hover:text-gray-600 p-1 rounded-lg hover:bg-gray-100 transition-colors">
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div className="space-y-3">
          {/* Download to Computer */}
          <button onClick={handleDownload}
            className="w-full flex items-center gap-3 px-4 py-3 border-2 border-gray-200 rounded-xl
              hover:border-emerald-400 hover:bg-emerald-50 transition-all duration-150 text-left">
            <div className="w-9 h-9 bg-emerald-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <svg className="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
              </svg>
            </div>
            <div>
              <p className="text-sm font-semibold text-gray-800">Download to Computer</p>
              <p className="text-xs text-gray-500">Save as a JSON file on your device</p>
            </div>
          </button>

          {/* Save to Server */}
          {saved ? (
            <div className="w-full flex items-center gap-3 px-4 py-3 border-2 border-blue-200 bg-blue-50 rounded-xl">
              <div className="w-9 h-9 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                <svg className="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                </svg>
              </div>
              <div>
                <p className="text-sm font-semibold text-blue-800">Saved to Server!</p>
                <p className="text-xs text-blue-600">You can restore it from the server backups list.</p>
              </div>
            </div>
          ) : (
            <button onClick={handleSaveToServer} disabled={saving}
              className="w-full flex items-center gap-3 px-4 py-3 border-2 border-gray-200 rounded-xl
                hover:border-blue-400 hover:bg-blue-50 transition-all duration-150 text-left
                disabled:opacity-60 disabled:cursor-not-allowed">
              <div className="w-9 h-9 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                {saving ? (
                  <div className="w-5 h-5 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
                ) : (
                  <svg className="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                      d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" />
                  </svg>
                )}
              </div>
              <div>
                <p className="text-sm font-semibold text-gray-800">
                  {saving ? 'Saving…' : 'Save to Server'}
                </p>
                <p className="text-xs text-gray-500">Store in the cloud and restore later</p>
              </div>
            </button>
          )}

          {error && <p className="text-xs text-red-600 px-1">{error}</p>}
        </div>

        {saved && (
          <button onClick={onClose}
            className="w-full mt-4 border border-gray-300 text-gray-700 py-2 rounded-lg text-sm
              font-medium hover:bg-gray-50 transition-colors">
            Close
          </button>
        )}
      </div>
    </div>
  );
}
