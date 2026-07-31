import { Link } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';

export default function Navbar() {
  const { user, logout } = useAuth();

  return (
    <nav className="bg-surface-card border-b border-line">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex justify-between items-center h-16">
          <div className="flex items-center gap-2">
            <div className="w-8 h-8 bg-accent-gradient rounded-lg flex items-center justify-center">
              <svg className="w-5 h-5 text-surface-deep" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M13 10V3L4 14h7v7l9-11h-7z" />
              </svg>
            </div>
            <span className="text-lg font-heading font-semibold text-ink-heading">Power Profile</span>
          </div>

          {user && (
            <div className="flex items-center gap-4">
              {user.is_admin && (
                <Link
                  to="/defense-prep"
                  className="hidden sm:flex items-center gap-1.5 text-xs font-semibold text-accent
                    bg-accent-soft hover:bg-accent-softer border border-accent-border px-2.5 py-1.5 rounded-lg
                    transition-colors"
                  title="Defense Preparation — Admin Only"
                >
                  <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                      d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                  </svg>
                  Defense Prep
                </Link>
              )}
              <div className="flex items-center gap-3">
                {user.avatar ? (
                  <img src={user.avatar} alt={user.name}
                    className="w-9 h-9 rounded-full object-cover border-2 border-line" />
                ) : (
                  <div className="w-9 h-9 rounded-full bg-surface-inset flex items-center justify-center
                    text-accent font-semibold text-sm border-2 border-line">
                    {user.name?.charAt(0).toUpperCase()}
                  </div>
                )}
                <span className="text-sm font-medium text-ink-body2 hidden sm:block">{user.name}</span>
              </div>
              <button
                onClick={logout}
                className="text-sm text-ink-muted hover:text-danger transition-colors px-3 py-1.5
                  rounded-md hover:bg-danger-soft border border-transparent hover:border-danger-border"
              >
                Sign out
              </button>
            </div>
          )}
        </div>
      </div>
    </nav>
  );
}
