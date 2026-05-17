/* eslint-disable react/prop-types */
import { useState, useEffect } from 'react';
import api from '../api/axios';

export default function ServerBackupsList({ projectId, entityType, entityId, onRestore }) {
  const [backups, setBackups]   = useState([]);
  const [loading, setLoading]   = useState(true);
  const [restoring, setRestoring] = useState(null); // id of backup being restored
  const [deleting, setDeleting]   = useState(null); // id of backup being deleted

  useEffect(() => {
    if (!projectId) return;
    setLoading(true);
    let url = `/api/projects/${projectId}/server-backups?entity_type=${entityType}`;
    if (entityId) url += `&entity_id=${entityId}`;
    api.get(url)
      .then(({ data }) => setBackups(data.data))
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [projectId, entityType, entityId]);

  async function handleRestore(backup) {
    setRestoring(backup.id);
    try {
      const { data } = await api.get(`/api/server-backups/${backup.id}/data`);
      onRestore(data.data);
    } catch (err) {
      alert('Restore failed: ' + (err.response?.data?.message || err.message));
    } finally {
      setRestoring(null);
    }
  }

  async function handleDelete(backupId) {
    setDeleting(backupId);
    try {
      await api.delete(`/api/server-backups/${backupId}`);
      setBackups(prev => prev.filter(b => b.id !== backupId));
    } catch (err) {
      alert('Delete failed: ' + (err.response?.data?.message || err.message));
    } finally {
      setDeleting(null);
    }
  }

  if (loading) {
    return (
      <div className="flex justify-center py-6">
        <div className="w-5 h-5 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
      </div>
    );
  }

  if (backups.length === 0) {
    return (
      <div className="text-center py-8 text-gray-400">
        <svg className="w-8 h-8 mx-auto mb-2 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
            d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" />
        </svg>
        <p className="text-sm">No server backups yet.</p>
      </div>
    );
  }

  return (
    <div className="space-y-2">
      {backups.map(backup => (
        <div key={backup.id}
          className="flex items-center justify-between px-4 py-3 bg-white border border-gray-200 rounded-xl">
          <div className="min-w-0 flex-1">
            <p className="text-sm font-medium text-gray-800 truncate">{backup.entity_name}</p>
            <p className="text-xs text-gray-400">
              {new Date(backup.created_at).toLocaleString('en-US', {
                month: 'short', day: 'numeric', year: 'numeric',
                hour: '2-digit', minute: '2-digit',
              })}
            </p>
          </div>
          <div className="flex items-center gap-2 flex-shrink-0 ml-3">
            <button
              onClick={() => handleRestore(backup)}
              disabled={restoring === backup.id}
              className="flex items-center gap-1.5 text-xs font-medium text-blue-600 px-3 py-1.5
                rounded-lg border border-blue-200 bg-blue-50 hover:bg-blue-100
                transition-colors disabled:opacity-50">
              {restoring === backup.id ? (
                <div className="w-3.5 h-3.5 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
              ) : (
                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
              )}
              Restore
            </button>
            <button
              onClick={() => handleDelete(backup.id)}
              disabled={deleting === backup.id}
              className="flex items-center gap-1 text-xs font-medium text-gray-400 px-2 py-1.5
                rounded-lg border border-gray-200 hover:border-red-300 hover:text-red-500 hover:bg-red-50
                transition-colors disabled:opacity-50">
              {deleting === backup.id ? (
                <div className="w-3.5 h-3.5 border-2 border-red-400 border-t-transparent rounded-full animate-spin" />
              ) : (
                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              )}
            </button>
          </div>
        </div>
      ))}
    </div>
  );
}
