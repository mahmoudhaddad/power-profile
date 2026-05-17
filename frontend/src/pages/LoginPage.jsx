import logo1 from '../assets/logo1.jpeg';
import logo2 from '../assets/logo2.png';

export default function LoginPage() {
  const apiUrl = import.meta.env.VITE_API_URL;

  return (
    <div className="min-h-screen bg-gradient-to-br from-blue-50 to-indigo-100 flex items-center justify-center p-4">
      <div className="flex flex-col items-center w-full max-w-md">

        {/* Login card */}
        <div className="bg-white rounded-2xl shadow-xl w-full p-8">

          {/* University & College logos */}
          <div className="flex items-center justify-between mb-10">
            <img src={logo1} alt="University Logo" className="h-36 w-36 object-contain" />
            <img src={logo2} alt="College Logo"     className="h-36 w-36 object-contain" />
          </div>

          <div className="text-center mb-8">
            <div className="w-16 h-16 bg-blue-600 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg">
              <svg className="w-9 h-9 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M13 10V3L4 14h7v7l9-11h-7z" />
              </svg>
            </div>
            <h1 className="text-2xl font-bold text-gray-900">Power Profile</h1>
            <p className="text-gray-500 mt-1 text-sm">Building Energy Analysis Platform</p>
          </div>

          <div className="space-y-4">
            <p className="text-center text-gray-600 text-sm">Sign in to access your dashboard</p>

            <a
              href={`${apiUrl}/auth/google`}
              className="flex items-center justify-center gap-3 w-full py-3 px-4 bg-white border-2
                border-gray-200 rounded-xl text-gray-700 font-medium hover:border-blue-400
                hover:bg-blue-50 transition-all duration-200 shadow-sm hover:shadow group"
            >
              <svg className="w-5 h-5" viewBox="0 0 24 24">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" />
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" />
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" />
              </svg>
              <span>Continue with Google</span>
            </a>
          </div>

        </div>

        {/* Credits — below the card */}
        <div className="mt-8 text-center space-y-4">
          <p className="text-base text-gray-600 whitespace-nowrap">
            <span className="font-semibold text-gray-800">Made by:</span> Mahmoud Emad ALHaddad &amp; Ahmed Zoher Abu Awad
          </p>
          <p className="text-base text-gray-600">
            <span className="font-semibold text-gray-800">Supervisor:</span> Dr. Muayad ALMubayed
          </p>
        </div>

      </div>
    </div>
  );
}
