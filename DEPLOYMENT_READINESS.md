# Hostinger Deployment Readiness

## Laravel API: api.nexrobnb.com

1. Upload the Laravel backend to the API hosting account.
2. Set the `api.nexrobnb.com` document root to `backend/public`.
3. Keep the standard Laravel `backend/public/.htaccess`; no legacy backend launcher configuration is required for the API.
4. Ensure `backend/storage` and `backend/bootstrap/cache` are writable by the web server.
5. Configure production `.env` without printing secrets:
   - `APP_NAME`, `APP_ENV=production`, `APP_KEY`, `APP_DEBUG=false`, `APP_URL=https://api.nexrobnb.com`
   - `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE` or `DB_NAME`, `DB_USERNAME` or `DB_USER`, `DB_PASSWORD`
   - `AUTH_TOKEN_SECRET`, `AUTH_TOKEN_TTL_SECONDS`, optional legacy fallback `JWT_SECRET`
   - `FRONTEND_URL=https://nexrobnb.com`, `FRONTEND_URLS=https://nexrobnb.com,https://www.nexrobnb.com`
   - `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`
   - `SESSION_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`, `HASH_DRIVER=scrypt`
6. After upload, run only production-safe commands:
   ```bash
   composer install --no-dev --optimize-autoloader
   php artisan config:clear
   php artisan cache:clear
   php artisan route:clear
   php artisan view:clear
   php artisan config:cache
   php artisan route:cache
   ```
7. Do not run `php artisan migrate`; the Laravel API maps to the existing MySQL schema.

## Next.js Frontend: nexrobnb.com

1. Configure production environment:
   - `NEXT_PUBLIC_API_BASE_URL=https://api.nexrobnb.com`
   - `NEXT_PUBLIC_BOOKING_API_URL=https://api.nexrobnb.com`
   - `NEXT_PUBLIC_SITE_URL=https://nexrobnb.com`
2. Build locally or in Hostinger:
   ```bash
   npm ci
   npm run build
   ```
3. Upload/use the generated `frontend/hostinger-dist` runtime artifact for the frontend Node app.

## Package Exclusions

Exclude `.env*`, `node_modules`, `.next*` caches, `.next*.stuck-*`, `backend/vendor`, runtime logs, test output, and debug captures from deployment packages. Upload dependency manifests and install dependencies on the target instead.

## Smoke Tests

1. API: `GET https://api.nexrobnb.com/health`
2. API public: `GET https://api.nexrobnb.com/api/public/events`
3. API protected: `GET https://api.nexrobnb.com/api/me/dashboard` should return JSON `401` without a token.
4. Frontend: open `https://nexrobnb.com`, `/upcoming-events`, `/previous-events`, `/about`, `/contact`, `/login`, `/signup`, `/admin`, and `/dashboard`.
5. Confirm checkout and registration confirmation flows against safe test data only.
