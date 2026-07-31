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
      <div className="bg-surface-card border border-line rounded-2xl shadow-2xl w-full max-w-sm p-6">

        <div className="flex items-center justify-between mb-5">
          <h3 className="text-base font-semibold text-ink-heading">Save Backup — {entityName}</h3>
          <button onClick={onClose}
            className="text-ink-muted hover:text-ink-heading p-1 rounded-lg hover:bg-surface-inset transition-colors">
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div className="space-y-3">
          {/* Download to Computer */}
          <button onClick={handleDownload}
            className="w-full flex items-center gap-3 px-4 py-3 border-2 border-line rounded-xl
              hover:border-accent-border hover:bg-accent-soft transition-all duration-150 text-left">
            <div className="w-9 h-9 bg-accent-soft rounded-lg flex items-center justify-center flex-shrink-0">
              <svg className="w-5 h-5 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
              </svg>
            </div>
            <div>
              <p className="text-sm font-semibold text-ink-heading">Download to Computer</p>
              <p className="text-xs text-ink-muted">Save as a JSON file on your device</p>
            </div>
          </button>

          {/* Save to Server */}
          {saved ? (
            <div className="w-full flex items-center gap-3 px-4 py-3 border-2 border-success-border bg-success-soft rounded-xl">
              <div className="w-9 h-9 bg-success-soft rounded-lg flex items-center justify-center flex-shrink-0">
                <svg className="w-5 h-5 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                </svg>
              </div>
              <div>
                <p className="text-sm font-semibold text-success">Saved to Server!</p>
                <p className="text-xs text-ink-body2">You can restore it from the server backups list.</p>
              </div>
            </div>
          ) : (
            <button onClick={handleSaveToServer} disabled={saving}
              className="w-full flex items-center gap-3 px-4 py-3 border-2 border-line rounded-xl
                hover:border-accent-border hover:bg-accent-soft transition-all duration-150 text-left
                disabled:opacity-40 disabled:cursor-not-allowed">
              <div className="w-9 h-9 bg-accent-soft rounded-lg flex items-center justify-center flex-shrink-0">
                {saving ? (
                  <div className="w-5 h-5 border-2 border-accent border-t-transparent rounded-full animate-spin" />
                ) : (
                  <svg className="w-5 h-5 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                      d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" />
                  </svg>
                )}
              </div>
              <div>
                <p className="text-sm font-semibold text-ink-heading">
                  {saving ? 'Saving…' : 'Save to Server'}
                </p>
                <p className="text-xs text-ink-muted">Store in the cloud and restore later</p>
              </div>
            </button>
          )}

          {error && <p className="text-xs text-danger px-1">{error}</p>}
        </div>

        {saved && (
          <button onClick={onClose}
            className="w-full mt-4 border border-line text-ink-body2 py-2 rounded-lg text-sm
              font-medium hover:border-line-strong hover:bg-surface-inset transition-colors">
            Close
          </button>
        )}
      </div>
    </div>
  );
}
