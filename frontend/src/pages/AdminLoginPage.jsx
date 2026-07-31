import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api/axios';

export default function AdminLoginPage() {
  const [form, setForm] = useState({ name: '', password: '' });
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const navigate = useNavigate();

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      const { data } = await api.post('/api/admin/login', form);
      localStorage.setItem('admin_token', data.token);
      localStorage.setItem('admin_user', JSON.stringify(data.user));
      navigate('/admin/dashboard');
    } catch (err) {
      setError(err.response?.data?.message || 'Invalid credentials.');
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="min-h-screen bg-base flex items-center justify-center p-4">
      <div className="bg-surface-card rounded-2xl border border-line w-full max-w-md p-8">
        <div className="text-center mb-8">
          <div className="w-16 h-16 bg-accent-gradient rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-accent">
            <svg className="w-9 h-9 text-surface-deep" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
            </svg>
          </div>
          <h1 className="text-2xl font-bold text-ink-heading">Admin Panel</h1>
          <p className="text-ink-muted mt-1 text-sm">Power Profile Management</p>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          {error && (
            <div className="bg-danger-soft border border-danger-border text-danger text-sm px-4 py-3 rounded-lg">
              {error}
            </div>
          )}

          <div>
            <label className="block text-sm font-medium text-ink-body2 mb-1">Username</label>
            <input
              type="text"
              value={form.name}
              onChange={e => setForm({ ...form, name: e.target.value })}
              className="w-full border border-line rounded-lg px-4 py-2.5 text-sm bg-surface-inset text-ink-heading
                focus:outline-none focus:ring-2 focus:ring-accent/40 focus:border-accent"
              placeholder="Enter username"
              required
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-ink-body2 mb-1">Password</label>
            <input
              type="password"
              value={form.password}
              onChange={e => setForm({ ...form, password: e.target.value })}
              className="w-full border border-line rounded-lg px-4 py-2.5 text-sm bg-surface-inset text-ink-heading
                focus:outline-none focus:ring-2 focus:ring-accent/40 focus:border-accent"
              placeholder="Enter password"
              required
            />
          </div>

          <button
            type="submit"
            disabled={loading}
            className="w-full bg-accent-gradient text-base font-semibold py-3 rounded-lg
              hover:shadow-accent transition-shadow disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:shadow-none"
          >
            {loading ? 'Signing in...' : 'Sign In'}
          </button>
        </form>

        <p className="text-center mt-4">
          <a href="/login" className="text-sm text-ink-muted hover:text-ink-body2">
            ← Back to user login
          </a>
        </p>
      </div>
    </div>
  );
}
