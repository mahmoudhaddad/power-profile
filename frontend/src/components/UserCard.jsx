export default function UserCard({ user }) {
  return (
    <div className="bg-surface-card rounded-xl border border-line p-6">
      <div className="flex items-center gap-5">
        {user.avatar ? (
          <img src={user.avatar} alt={user.name}
            className="w-20 h-20 rounded-full object-cover border-4 border-accent-border" />
        ) : (
          <div className="w-20 h-20 rounded-full bg-accent-soft flex items-center justify-center
            text-accent font-bold text-2xl border-4 border-accent-border">
            {user.name?.charAt(0).toUpperCase()}
          </div>
        )}
        <div>
          <h2 className="text-xl font-semibold text-ink-heading">{user.name}</h2>
          <p className="text-ink-muted text-sm mt-0.5">{user.email}</p>
          <span className="inline-block mt-2 text-xs text-success bg-success-soft px-2.5 py-0.5 rounded-full font-medium">
            Active
          </span>
        </div>
      </div>

      <div className="mt-5 pt-5 border-t border-line-subtle grid grid-cols-2 gap-4">
        <div>
          <p className="text-xs text-ink-muted uppercase tracking-wide font-medium">Member Since</p>
          <p className="text-sm text-ink-body mt-1">
            {new Date(user.created_at).toLocaleDateString('en-US', {
              year: 'numeric', month: 'long', day: 'numeric',
            })}
          </p>
        </div>
        <div>
          <p className="text-xs text-ink-muted uppercase tracking-wide font-medium">Account Type</p>
          <p className="text-sm text-ink-body mt-1">Google OAuth</p>
        </div>
      </div>
    </div>
  );
}
