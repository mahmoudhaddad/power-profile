/* eslint-disable react/prop-types */
import { useState, useEffect } from 'react';
import api from '../api/axios';

const ROLE_META = {
  admin:  { label: 'Admin',       bg: 'bg-red-100',    text: 'text-red-700'   },
  main:   { label: 'Main User',   bg: 'bg-blue-100',   text: 'text-blue-700'  },
  normal: { label: 'View Only',   bg: 'bg-gray-100',   text: 'text-gray-600'  },
};

function RoleBadge({ role }) {
  const { label, bg, text } = ROLE_META[role] ?? ROLE_META.normal;
  return (
    <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${bg} ${text}`}>{label}</span>
  );
}

export default function ProjectMembersModal({ projectId, onClose }) {
  const [admin, setAdmin]     = useState(null);
  const [members, setMembers] = useState([]);
  const [loading, setLoading] = useState(true);

  const [email, setEmail]     = useState('');
  const [role, setRole]       = useState('normal');
  const [adding, setAdding]   = useState(false);
  const [addError, setAddError] = useState('');

  useEffect(() => {
    api.get(`/api/projects/${projectId}/members`)
      .then(({ data }) => {
        setAdmin(data.admin);
        setMembers(data.members);
      })
      .finally(() => setLoading(false));
  }, [projectId]);

  async function handleAdd(e) {
    e.preventDefault();
    if (!email.trim()) return;
    setAdding(true);
    setAddError('');
    try {
      const { data } = await api.post(`/api/projects/${projectId}/members`, {
        email: email.trim(),
        role,
      });
      setMembers(prev => [...prev, data.data]);
      setEmail('');
      setRole('normal');
    } catch (err) {
      setAddError(err.response?.data?.message || err.response?.data?.errors?.email?.[0] || 'Failed to add user.');
    } finally {
      setAdding(false);
    }
  }

  async function handleRoleChange(memberId, newRole) {
    try {
      const { data } = await api.put(`/api/projects/${projectId}/members/${memberId}`, { role: newRole });
      setMembers(prev => prev.map(m => m.id === memberId ? { ...m, role: data.data.role } : m));
    } catch {
      // silently ignore
    }
  }

  async function handleRemove(memberId) {
    await api.delete(`/api/projects/${projectId}/members/${memberId}`);
    setMembers(prev => prev.filter(m => m.id !== memberId));
  }

  return (
    <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md">

        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100">
          <div className="flex items-center gap-2">
            <div className="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center">
              <svg className="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
            </div>
            <h3 className="text-base font-semibold text-gray-900">Project Members</h3>
          </div>
          <button onClick={onClose}
            className="text-gray-400 hover:text-gray-600 p-1 rounded-lg hover:bg-gray-100 transition-colors">
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div className="px-6 py-4 space-y-4 max-h-[70vh] overflow-y-auto">

          {/* Add member form */}
          <form onSubmit={handleAdd} className="bg-gray-50 rounded-xl p-4 space-y-3">
            <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide">Add Member</p>
            <div className="flex gap-2">
              <input
                type="email"
                value={email}
                onChange={e => { setEmail(e.target.value); setAddError(''); }}
                placeholder="User's email address"
                className="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm
                  focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
              />
              <select value={role} onChange={e => setRole(e.target.value)}
                className="border border-gray-300 rounded-lg px-3 py-2 text-sm bg-white
                  focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <option value="admin">Admin</option>
                <option value="main">Main User</option>
                <option value="normal">View Only</option>
              </select>
            </div>
            {addError && <p className="text-xs text-red-600">{addError}</p>}
            <button type="submit" disabled={adding || !email.trim()}
              className="w-full bg-blue-600 text-white py-2 rounded-lg text-sm font-medium
                hover:bg-blue-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
              {adding ? 'Adding…' : 'Add Member'}
            </button>
          </form>

          {/* Members list */}
          {loading ? (
            <div className="flex justify-center py-6">
              <div className="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
            </div>
          ) : (
            <div className="space-y-2">
              <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide">Current Members</p>

              {/* Admin row */}
              {admin && (
                <div className="flex items-center gap-3 px-3 py-2.5 bg-white border border-gray-200 rounded-xl">
                  <Avatar user={admin} />
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium text-gray-900 truncate">{admin.name}</p>
                    <p className="text-xs text-gray-400 truncate">{admin.email}</p>
                  </div>
                  <RoleBadge role="admin" />
                </div>
              )}

              {/* Added members */}
              {members.length === 0 && (
                <p className="text-sm text-gray-400 text-center py-4">No members added yet.</p>
              )}
              {members.map(member => (
                <div key={member.id} className="flex items-center gap-3 px-3 py-2.5 bg-white border border-gray-200 rounded-xl">
                  <Avatar user={member.user} />
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium text-gray-900 truncate">{member.user?.name}</p>
                    <p className="text-xs text-gray-400 truncate">{member.user?.email}</p>
                  </div>
                  <select
                    value={member.role}
                    onChange={e => handleRoleChange(member.id, e.target.value)}
                    className="text-xs border border-gray-200 rounded-lg px-2 py-1 bg-white
                      focus:outline-none focus:ring-1 focus:ring-blue-400 text-gray-700">
                    <option value="admin">Admin</option>
                    <option value="main">Main User</option>
                    <option value="normal">View Only</option>
                  </select>
                  <button onClick={() => handleRemove(member.id)}
                    className="text-gray-400 hover:text-red-500 p-1 rounded hover:bg-red-50 transition-colors flex-shrink-0">
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                  </button>
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="px-6 py-3 border-t border-gray-100">
          <button onClick={onClose}
            className="w-full border border-gray-300 text-gray-700 py-2 rounded-lg text-sm
              font-medium hover:bg-gray-50 transition-colors">
            Close
          </button>
        </div>
      </div>
    </div>
  );
}

function Avatar({ user }) {
  if (user?.avatar) {
    return <img src={user.avatar} alt={user.name} className="w-8 h-8 rounded-full object-cover flex-shrink-0" />;
  }
  const initials = user?.name?.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase() ?? '?';
  return (
    <div className="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center flex-shrink-0">
      <span className="text-xs font-semibold text-blue-600">{initials}</span>
    </div>
  );
}
