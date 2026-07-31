import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import axios from 'axios';

const adminApi = axios.create({ baseURL: import.meta.env.VITE_API_URL });
adminApi.interceptors.request.use(config => {
  const token = localStorage.getItem('admin_token');
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

export default function AdminDashboardPage() {
  const [users, setUsers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [editingUser, setEditingUser] = useState(null);
  const [editForm, setEditForm] = useState({ name: '', email: '', is_admin: false });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const navigate = useNavigate();

  const adminUser = JSON.parse(localStorage.getItem('admin_user') || '{}');

  useEffect(() => {
    const token = localStorage.getItem('admin_token');
    if (!token) { navigate('/admin/login'); return; }
    fetchUsers();
  }, []);

  async function fetchUsers() {
    try {
      const { data } = await adminApi.get('/api/admin/users');
      setUsers(data.data);
    } catch {
      navigate('/admin/login');
    } finally {
      setLoading(false);
    }
  }

  function openEdit(user) {
    setEditingUser(user);
    setEditForm({ name: user.name, email: user.email, is_admin: user.is_admin });
    setError('');
  }

  async function saveEdit() {
    setSaving(true);
    setError('');
    try {
      const { data } = await adminApi.put(`/api/admin/users/${editingUser.id}`, editForm);
      setUsers(users.map(u => u.id === editingUser.id ? data.data : u));
      setEditingUser(null);
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to update user.');
    } finally {
      setSaving(false);
    }
  }

  async function deleteUser(user) {
    if (!confirm(`Delete user "${user.name}"? This cannot be undone.`)) return;
    try {
      await adminApi.delete(`/api/admin/users/${user.id}`);
      setUsers(users.filter(u => u.id !== user.id));
    } catch (err) {
      alert(err.response?.data?.message || 'Failed to delete user.');
    }
  }

  function logout() {
    localStorage.removeItem('admin_token');
    localStorage.removeItem('admin_user');
    navigate('/admin/login');
  }

  return (
    <div className="min-h-screen bg-base">
      {/* Navbar */}
      <nav className="bg-surface-darker text-ink-heading px-6 py-4 flex justify-between items-center">
        <div className="flex items-center gap-3">
          <div className="w-8 h-8 bg-accent-gradient rounded-lg flex items-center justify-center">
            <svg className="w-5 h-5 text-surface-deep" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
            </svg>
          </div>
          <span className="font-semibold">Admin Panel</span>
        </div>
        <div className="flex items-center gap-4">
          <span className="text-ink-body2 text-sm">Welcome, {adminUser.name}</span>
          <button onClick={logout}
            className="text-sm border border-line text-ink-body2 hover:border-line-strong hover:bg-surface-inset px-3 py-1.5 rounded-lg transition-colors">
            Sign out
          </button>
        </div>
      </nav>

      <main className="max-w-6xl mx-auto px-4 py-8">
        <div className="flex items-center justify-between mb-6">
          <div>
            <h1 className="text-2xl font-bold text-ink-heading">Users Management</h1>
            <p className="text-ink-muted text-sm mt-1">{users.length} total users</p>
          </div>
        </div>

        {loading ? (
          <div className="flex justify-center py-20">
            <div className="w-10 h-10 border-4 border-accent border-t-transparent rounded-full animate-spin"></div>
          </div>
        ) : (
          <div className="bg-surface-card rounded-xl border border-line overflow-hidden">
            <table className="w-full text-sm">
              <thead className="bg-surface-alt border-b border-line">
                <tr>
                  <th className="text-left px-6 py-3 font-medium text-ink-muted">#</th>
                  <th className="text-left px-6 py-3 font-medium text-ink-muted">User</th>
                  <th className="text-left px-6 py-3 font-medium text-ink-muted">Email</th>
                  <th className="text-left px-6 py-3 font-medium text-ink-muted">Role</th>
                  <th className="text-left px-6 py-3 font-medium text-ink-muted">Joined</th>
                  <th className="text-right px-6 py-3 font-medium text-ink-muted">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line-subtle">
                {users.map((user, i) => (
                  <tr key={user.id} className="hover:bg-surface-inset transition-colors">
                    <td className="px-6 py-4 text-ink-muted">{i + 1}</td>
                    <td className="px-6 py-4">
                      <div className="flex items-center gap-3">
                        {user.avatar ? (
                          <img src={user.avatar} alt={user.name}
                            className="w-9 h-9 rounded-full object-cover border border-line" />
                        ) : (
                          <div className="w-9 h-9 rounded-full bg-surface-inset flex items-center justify-center
                            text-ink-body2 font-semibold text-sm">
                            {user.name?.charAt(0).toUpperCase()}
                          </div>
                        )}
                        <span className="font-medium text-ink-heading">{user.name}</span>
                      </div>
                    </td>
                    <td className="px-6 py-4 text-ink-muted">{user.email}</td>
                    <td className="px-6 py-4">
                      {user.is_admin ? (
                        <span className="bg-accent-soft text-accent border border-accent-border text-xs font-medium px-2.5 py-1 rounded-full">
                          Admin
                        </span>
                      ) : (
                        <span className="bg-surface-inset text-ink-body2 border border-line text-xs font-medium px-2.5 py-1 rounded-full">
                          User
                        </span>
                      )}
                    </td>
                    <td className="px-6 py-4 text-ink-muted">
                      {new Date(user.created_at).toLocaleDateString('en-US', {
                        year: 'numeric', month: 'short', day: 'numeric',
                      })}
                    </td>
                    <td className="px-6 py-4 text-right">
                      <button onClick={() => openEdit(user)}
                        className="text-accent hover:text-accent-light font-medium mr-4 transition-colors">
                        Edit
                      </button>
                      {!user.is_admin && (
                        <button onClick={() => deleteUser(user)}
                          className="text-danger hover:text-danger/80 font-medium transition-colors">
                          Delete
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </main>

      {/* Edit Modal */}
      {editingUser && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-surface-card rounded-2xl border border-line w-full max-w-md p-6">
            <h2 className="text-lg font-semibold text-ink-heading mb-5">Edit User</h2>

            {error && (
              <div className="bg-danger-soft border border-danger-border text-danger text-sm px-4 py-3 rounded-lg mb-4">
                {error}
              </div>
            )}

            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-ink-body2 mb-1">Name</label>
                <input
                  type="text"
                  value={editForm.name}
                  onChange={e => setEditForm({ ...editForm, name: e.target.value })}
                  className="w-full border border-line rounded-lg px-4 py-2.5 text-sm bg-surface-inset text-ink-heading
                    focus:outline-none focus:ring-2 focus:ring-accent/40 focus:border-accent"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-ink-body2 mb-1">Email</label>
                <input
                  type="email"
                  value={editForm.email}
                  onChange={e => setEditForm({ ...editForm, email: e.target.value })}
                  className="w-full border border-line rounded-lg px-4 py-2.5 text-sm bg-surface-inset text-ink-heading
                    focus:outline-none focus:ring-2 focus:ring-accent/40 focus:border-accent"
                />
              </div>
              <div className="flex items-center gap-3">
                <input
                  type="checkbox"
                  id="is_admin"
                  checked={editForm.is_admin}
                  onChange={e => setEditForm({ ...editForm, is_admin: e.target.checked })}
                  className="w-4 h-4 accent-accent"
                />
                <label htmlFor="is_admin" className="text-sm font-medium text-ink-body2">
                  Admin privileges
                </label>
              </div>
            </div>

            <div className="flex gap-3 mt-6">
              <button onClick={() => setEditingUser(null)}
                className="flex-1 border border-line text-ink-body2 py-2.5 rounded-lg
                  text-sm font-medium hover:border-line-strong hover:bg-surface-inset transition-colors">
                Cancel
              </button>
              <button onClick={saveEdit} disabled={saving}
                className="flex-1 bg-accent-gradient text-base py-2.5 rounded-lg text-sm font-semibold
                  hover:shadow-accent transition-shadow disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:shadow-none">
                {saving ? 'Saving...' : 'Save Changes'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
