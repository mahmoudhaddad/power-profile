import { lazy, Suspense } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './contexts/AuthContext';
import LoadingSpinner from './components/LoadingSpinner';

const LoginPage          = lazy(() => import('./pages/LoginPage'));
const AuthCallbackPage   = lazy(() => import('./pages/AuthCallbackPage'));
const DashboardPage      = lazy(() => import('./pages/DashboardPage'));
const AdminLoginPage     = lazy(() => import('./pages/AdminLoginPage'));
const AdminDashboardPage = lazy(() => import('./pages/AdminDashboardPage'));
const ProjectLayout      = lazy(() => import('./layouts/ProjectLayout'));
const ProjectPage        = lazy(() => import('./pages/ProjectPage'));
const BuildingPage       = lazy(() => import('./pages/BuildingPage'));
const FloorPage          = lazy(() => import('./pages/FloorPage'));
const NewRoomPage        = lazy(() => import('./pages/NewRoomPage'));
const LoadSchedulePage   = lazy(() => import('./pages/LoadSchedulePage'));

function ProtectedRoute({ children }) {
  const { user, isLoading } = useAuth();

  if (isLoading) return <LoadingSpinner />;
  if (!user) return <Navigate to="/login" replace />;

  return children;
}

function PublicRoute({ children }) {
  const { user, isLoading } = useAuth();

  if (isLoading) return <LoadingSpinner />;
  if (user) return <Navigate to="/dashboard" replace />;

  return children;
}

export default function App() {
  return (
    <AuthProvider>
      <BrowserRouter future={{ v7_startTransition: true, v7_relativeSplatPath: true }}>
        <Suspense fallback={<LoadingSpinner />}>
          <Routes>
            <Route path="/" element={<Navigate to="/dashboard" replace />} />
            <Route path="/login" element={<PublicRoute><LoginPage /></PublicRoute>} />
            <Route path="/auth/callback" element={<AuthCallbackPage />} />
            <Route path="/dashboard" element={<ProtectedRoute><DashboardPage /></ProtectedRoute>} />
            <Route path="/admin/login" element={<AdminLoginPage />} />
            <Route path="/admin/dashboard" element={<AdminDashboardPage />} />

            <Route element={<ProtectedRoute><ProjectLayout /></ProtectedRoute>}>
              <Route path="/project" element={<ProjectPage />} />
              <Route path="/project/building" element={<BuildingPage />} />
              <Route path="/project/building/floor" element={<FloorPage />} />
              <Route path="/project/building/floor/room" element={<NewRoomPage />} />
              <Route path="/project/schedule" element={<LoadSchedulePage />} />
            </Route>
          </Routes>
        </Suspense>
      </BrowserRouter>
    </AuthProvider>
  );
}
