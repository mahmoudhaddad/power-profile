import { createContext, useContext, useState, useEffect } from 'react';
import api from '../api/axios';

const AUTH_USER_KEY = 'auth_user';
const AUTH_TOKEN_KEY = 'auth_token';

const AuthContext = createContext(null);

function getCachedUser() {
  try {
    const raw = localStorage.getItem(AUTH_USER_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}

export function AuthProvider({ children }) {
  const [user, setUser]       = useState(() => getCachedUser());
  const [isLoading, setIsLoading] = useState(false);

  useEffect(() => {
    const token = localStorage.getItem(AUTH_TOKEN_KEY);
    if (token) {
      verifyUser();
    }
  }, []);

  async function verifyUser() {
    try {
      const { data } = await api.get('/api/user');
      setUser(data.data);
      localStorage.setItem(AUTH_USER_KEY, JSON.stringify(data.data));
    } catch {
      localStorage.removeItem(AUTH_TOKEN_KEY);
      localStorage.removeItem(AUTH_USER_KEY);
      setUser(null);
    } finally {
      setIsLoading(false);
    }
  }

  async function login(token) {
    localStorage.setItem(AUTH_TOKEN_KEY, token);
    setIsLoading(true);
    await verifyUser();
  }

  async function logout() {
    try {
      await api.post('/api/logout');
    } catch {
      // ignore
    }
    localStorage.removeItem(AUTH_TOKEN_KEY);
    localStorage.removeItem(AUTH_USER_KEY);
    setUser(null);
  }

  return (
    <AuthContext.Provider value={{ user, isLoading, login, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  return useContext(AuthContext);
}
