import { lazy, Suspense } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './contexts/AuthContext';
import LoadingSpinner from './components/LoadingSpinner';
import ErrorBoundary from './components/ErrorBoundary';

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
const PhaseBalancePage   = lazy(() => import('./pages/PhaseBalancePage'));
const FinancialPage           = lazy(() => import('./pages/FinancialPage'));
const ValidationPage          = lazy(() => import('./pages/ValidationPage'));
const SingleLineDiagramPage   = lazy(() => import('./pages/SingleLineDiagramPage'));
const ElectricalDesignPage    = lazy(() => import('./pages/ElectricalDesignPage'));
const DefensePrepPage    = lazy(() => import('./pages/DefensePrepPage'));

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
            <Route path="/dashboard" element={<ProtectedRoute><ErrorBoundary label="dashboard"><DashboardPage /></ErrorBoundary></ProtectedRoute>} />
            <Route path="/validation" element={<ProtectedRoute><ErrorBoundary label="validation"><ValidationPage /></ErrorBoundary></ProtectedRoute>} />
            <Route path="/defense-prep" element={<ProtectedRoute><ErrorBoundary label="defense prep"><DefensePrepPage /></ErrorBoundary></ProtectedRoute>} />
            <Route path="/admin/login" element={<AdminLoginPage />} />
            <Route path="/admin/dashboard" element={<AdminDashboardPage />} />

            <Route element={<ProtectedRoute><ProjectLayout /></ProtectedRoute>}>
              <Route path="/projects/:projectId" element={<ErrorBoundary label="project page"><ProjectPage /></ErrorBoundary>} />
              <Route path="/projects/:projectId/buildings/:buildingId" element={<ErrorBoundary label="building page"><BuildingPage /></ErrorBoundary>} />
              <Route path="/projects/:projectId/buildings/:buildingId/floors/:floorId" element={<ErrorBoundary label="floor page"><FloorPage /></ErrorBoundary>} />
              <Route path="/projects/:projectId/buildings/:buildingId/floors/:floorId/rooms/:roomId" element={<ErrorBoundary label="room page"><NewRoomPage /></ErrorBoundary>} />
              <Route path="/projects/:projectId/schedule" element={<ErrorBoundary label="load schedule"><LoadSchedulePage /></ErrorBoundary>} />
              <Route path="/projects/:projectId/phase-balance" element={<ErrorBoundary label="phase balance"><PhaseBalancePage /></ErrorBoundary>} />
              <Route path="/projects/:projectId/financial" element={<ErrorBoundary label="financial analysis"><FinancialPage /></ErrorBoundary>} />
              <Route path="/projects/:projectId/single-line"        element={<ErrorBoundary label="single-line diagram"><SingleLineDiagramPage /></ErrorBoundary>} />
              <Route path="/projects/:projectId/electrical-design" element={<ErrorBoundary label="electrical design"><ElectricalDesignPage /></ErrorBoundary>} />
            </Route>
          </Routes>
        </Suspense>
      </BrowserRouter>
    </AuthProvider>
  );
}
