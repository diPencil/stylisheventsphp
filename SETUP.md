# Stylish Holidays Setup

## Project Structure

```text
Stylish-Holidays - v2/
├── frontend/   # Next.js frontend
├── backend/    # Laravel API backend
└── uploads/    # Local runtime uploads, not committed
```

## Frontend

```bash
cd frontend
npm install
npm run dev
```

The local frontend usually runs on `http://localhost:3000`.

Use `frontend/.env.local` for local-only frontend settings. Do not commit `.env.local`.

## Backend

```bash
cd backend
composer install
php artisan serve --host=127.0.0.1 --port=5000
```

The local Laravel API usually runs on `http://127.0.0.1:5000`.

Use `backend/.env` for local-only Laravel settings. Do not commit `.env`.

## Validation

```bash
cd backend
php artisan test
composer validate
php artisan route:list

cd ../frontend
npm run build
```

## Notes

- Production credentials belong only in the production environment manager.
- Keep generated files out of Git: `.next/`, `hostinger-dist/`, `vendor/`, `node_modules/`, logs, caches, and local uploads.
- The legacy Node/Express backend is not part of this repository baseline.
