const KEY = 'nav_ctx';

export function setNav(updates) {
  const ctx = getNav();
  sessionStorage.setItem(KEY, JSON.stringify({ ...ctx, ...updates }));
}

export function getNav() {
  try {
    return JSON.parse(sessionStorage.getItem(KEY) || '{}');
  } catch {
    return {};
  }
}
