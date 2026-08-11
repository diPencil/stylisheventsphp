# Project Overview

This repository contains the accepted Stylish Events application baseline.

## Backend

The active backend is a Laravel API in `backend/`.

- `app/`: controllers, models, authentication, hashing, and mail logic.
- `routes/`: API, web, and console route definitions.
- `database/migrations/`: Laravel schema migrations.
- `database/seeders/`: seeders for controlled local/bootstrap data.
- `tests/`: PHPUnit feature and unit tests for auth, RBAC, public checkout, customer portal, QR/check-in, certificates, email workflows, and compatibility vectors.
- `storage/`: runtime storage directories. Contents are ignored except Laravel `.gitignore` placeholders.

## Frontend

The active frontend is a Next.js application in `frontend/`.

- `app/`: public site, admin dashboard, customer dashboard, auth pages, and route entry points.
- `components/`: shared UI, admin modules, public components, and customer portal components.
- `contexts/`: app-wide language and UI state.
- `lib/`: API client, translations, permissions, and shared helpers.
- `public/`: static frontend assets.
- `types/`: shared TypeScript declarations.

## Main Product Areas

- Public website and event registration.
- Admin dashboard.
- Customer dashboard.
- Booking and registration lifecycle.
- QR ticket generation and check-in.
- Certificate and event-card templates.
- Bulk certificate email.
- Dynamic roles and permissions, including Chairman and Speaker.

## Local Validation

```bash
cd backend
php artisan test
composer validate
php artisan route:list

cd ../frontend
npm run build
```

## Repository Hygiene

Do not commit local secrets, generated builds, dependency folders, logs, runtime uploads, local tunnel files, or QA artifacts.
