import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './contexts/AuthContext';
import LoginPage from './pages/LoginPage';
import AuthCallbackPage from './pages/AuthCallbackPage';
import DashboardPage from './pages/DashboardPage';
import AdminLoginPage from './pages/AdminLoginPage';
import AdminDashboardPage from './pages/AdminDashboardPage';
import ProjectPage from './pages/ProjectPage';
import BuildingPage from './pages/BuildingPage';
import FloorPage from './pages/FloorPage';
import NewRoomPage from './pages/NewRoomPage';
import LoadSchedulePage from './pages/LoadSchedulePage';
import LoadingSpinner from './components/LoadingSpinner';
import ProjectLayout from './layouts/ProjectLayout';

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
      </BrowserRouter>
    </AuthProvider>
  );
}
