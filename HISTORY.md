# Power Profile — Project History

## Project Overview
A full-stack web application for analysing power profiles of buildings.
- **Backend:** Laravel 13 (PHP 8.4) · SQLite · Sanctum · Socialite
- **Frontend:** React 18 · Vite 4 · Tailwind CSS 3 · React Router v6 · Axios

---

## [v0.3] — 2026-03-26 — Admin Panel

### Added
- `is_admin` boolean column on the `users` table
- Admin user seeded: **Mahmoud** (username: `Mahmoud`, password: `Mahmoud`)
- `AdminMiddleware` — protects all admin API routes
- `AdminController` with endpoints:
  - `POST /api/admin/login` — username + password login (returns Sanctum token)
  - `GET /api/admin/users` — list all users
  - `PUT /api/admin/users/{id}` — edit user (name, email, admin flag)
  - `DELETE /api/admin/users/{id}` — delete non-admin users
- Frontend admin login page at `/admin/login`
- Frontend admin dashboard at `/admin/dashboard`
  - Users table with avatar, email, role badge, join date
  - Edit user modal (name, email, admin toggle)
  - Delete user with confirmation

---

## [v0.2] — 2026-03-26 — Google OAuth & User Dashboard

### Added
- Laravel Sanctum (v4.3) for API token authentication
- Laravel Socialite (v5.26) for Google OAuth
- `GoogleController` — handles `/auth/google` redirect and `/auth/google/callback`
- `UserController` — `GET /api/user` and `POST /api/logout`
- `UserResource` — shapes user API response
- Users table extended with: `google_id`, `avatar`, `google_token`, `google_refresh_token`
- `config/cors.php` — CORS configured for frontend origin
- `config/services.php` — Google OAuth credentials block
- Frontend login page with "Continue with Google" button
- `AuthContext` — global auth state, persistent login via localStorage
- `AuthCallbackPage` — reads `?token=` from redirect URL, stores token, fetches user
- `DashboardPage` — main page after login with user card and stats placeholders
- `Navbar` with user avatar and sign-out button
- `UserCard` showing name, email, avatar, member since, account type
- Protected routes — unauthenticated users redirected to `/login`

### Fixed
- `personal_access_tokens` table missing → published and ran Sanctum migration
- `FRONTEND_URL` corrected from port `3000` to `5173` (Vite default)

---

## [v0.1] — 2026-03-26 — Project Scaffold

### Added
- Laravel 13 project created in `/backend`
- React + Vite 4 project created in `/frontend`
- Tailwind CSS 3 installed and configured
- React Router v6 and Axios installed
- Base project structure established
- SQLite database configured (default Laravel 13 setup)
- Initial migrations run: `users`, `cache`, `jobs` tables

### Notes
- Used Vite 4 (not latest) due to Node.js v18.16.0 — `create-vite@latest` requires Node ≥ 20
- Used React Router v6 (not v7) for the same reason

---

## Running the Project

```bash
# Backend — http://127.0.0.1:8000
cd backend
php artisan serve

# Frontend — http://localhost:5173
cd frontend
npm run dev
```

## Key Credentials

| Account | URL | Credentials |
|---------|-----|-------------|
| User login | http://localhost:5173/login | Google OAuth |
| Admin login | http://localhost:5173/admin/login | Mahmoud / Mahmoud |
