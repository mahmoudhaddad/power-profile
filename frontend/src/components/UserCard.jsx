export default function UserCard({ user }) {
  return (
    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
      <div className="flex items-center gap-5">
        {user.avatar ? (
          <img src={user.avatar} alt={user.name}
            className="w-20 h-20 rounded-full object-cover border-4 border-blue-100 shadow" />
        ) : (
          <div className="w-20 h-20 rounded-full bg-blue-100 flex items-center justify-center
            text-blue-600 font-bold text-2xl border-4 border-blue-200 shadow">
            {user.name?.charAt(0).toUpperCase()}
          </div>
        )}
        <div>
          <h2 className="text-xl font-semibold text-gray-900">{user.name}</h2>
          <p className="text-gray-500 text-sm mt-0.5">{user.email}</p>
          <span className="inline-block mt-2 text-xs text-green-700 bg-green-100 px-2.5 py-0.5 rounded-full font-medium">
            Active
          </span>
        </div>
      </div>

      <div className="mt-5 pt-5 border-t border-gray-100 grid grid-cols-2 gap-4">
        <div>
          <p className="text-xs text-gray-400 uppercase tracking-wide font-medium">Member Since</p>
          <p className="text-sm text-gray-700 mt-1">
            {new Date(user.created_at).toLocaleDateString('en-US', {
              year: 'numeric', month: 'long', day: 'numeric',
            })}
          </p>
        </div>
        <div>
          <p className="text-xs text-gray-400 uppercase tracking-wide font-medium">Account Type</p>
          <p className="text-sm text-gray-700 mt-1">Google OAuth</p>
        </div>
      </div>
    </div>
  );
}
