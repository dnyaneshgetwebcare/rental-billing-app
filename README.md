# Rental Billing

Warehouse rent billing and accounting for multiple companies: agreements with yearly increase, automatic monthly GST bills, WhatsApp sharing, receipts, purchases, expenses, double-entry ledger, trial balance and GST summaries.

- `backend/`: Yii3 JSON API (PHP 8.2 / 8.3, MySQL). Deployed to `~/billing-api/`, outside the web root.
- `public_html/`: what the web server serves: the React build, `api/index.php` (entry to the API), `.htaccess`, `uploads/`.

The React source is in [rental-billing-frontend](https://github.com/dnyaneshgetwebcare/rental-billing-frontend). Build it there and copy `dist/` into `public_html/` (leave `api/`, `uploads/`, and `.htaccess` in place).

No Node.js or Composer is needed on the server.

## Local development (Docker)

```powershell
docker compose --profile db up -d                 # PHP 8.2 on :8082, PHP 8.3 on :8083, MySQL on :3317
docker compose exec php82 php yii migrate:up --no-interaction
docker compose exec php82 php yii user:create admin@example.com "Admin" admin12345
```

Open http://localhost:8082, log in, and create the first company. The chart of accounts and GST rates are seeded automatically.

Tests:

```powershell
docker compose exec php82 vendor/bin/codecept run       # unit, API, console (repeat on php83)
powershell -File scripts/api-smoke.ps1                  # end-to-end API check (add -Base http://localhost:8083/api/v1 for 8.3)
```

## Release

```powershell
powershell -ExecutionPolicy Bypass -File scripts/release.ps1
```

This runs the backend tests, runs `composer install --no-dev` inside the container and writes `dist/<timestamp>/billing-api.zip` and `public_html.zip`. The last three releases are kept so you can roll back. The React build already in `public_html/` is packed as-is.

1. Extract `billing-api.zip` into `~/billing-api/`. The existing `.env` is not overwritten.
2. Extract `public_html.zip` into `~/public_html/`. `uploads/` is not in the archive.
3. In cPanel Terminal, run `php ~/billing-api/yii migrate:up --no-interaction`.
4. If you use Cloudflare, purge `/index.html` and `/sw.js`.

## GoDaddy one-time setup

1. In cPanel **Select PHP Version**, choose 8.2 (move to 8.3 before go-live, because 8.2 is end of life on 31 Dec 2026). Enable `bcmath gd exif fileinfo intl mbstring pdo_mysql zip`. Set `memory_limit=256M`, `upload_max_filesize=10M` and `post_max_size=12M`.
2. Create the MySQL database and user.
3. Create `~/billing-api/.env` from `backend/.env.example`:
   - `APP_URL`: the site URL.
   - `DB_*`: the database credentials.
   - `JWT_SECRET`: `php -r "echo bin2hex(random_bytes(32));"`.
   - `UPLOAD_DIR`: `/home/<cpanel-user>/public_html/uploads`.
4. Upload a release (see above), run migrations, then create the first login with `php ~/billing-api/yii user:create you@example.com "Your Name" 'strong-password'`.
5. Cron jobs (cPanel → Cron Jobs; check the PHP path shown by cPanel):

   ```
   # Rent bills every morning at 06:00 IST
   0 6 * * * /usr/local/bin/php ~/billing-api/yii billing:generate >> ~/billing-api/runtime/billing.log 2>&1
   # Database backup at 02:00, keep 14 days
   0 2 * * * mysqldump -u DBUSER -p'DBPASS' DBNAME | gzip > ~/backups/db-$(date +\%F).sql.gz && find ~/backups -name 'db-*.sql.gz' -mtime +14 -delete
   # Weekly uploads archive (Sunday 03:00)
   0 3 * * 0 tar czf ~/backups/uploads-$(date +\%F).tgz -C ~/public_html uploads && find ~/backups -name 'uploads-*.tgz' -mtime +60 -delete
   ```

   Download the backups regularly so a copy exists off the server.

`scripts/php-check.php` lists the PHP version and extensions. Upload it temporarily to check a new server, then delete it.

## Notes

- Place of supply for warehouse rent is the warehouse's state (IGST Act section 12(3)). That decides CGST + SGST versus IGST. You can override it on a bill.
- Documents are never edited or deleted in the books. Cancelling one posts a reversal, and the number stays used.
- Share links for invoice PDFs are signed and expire after 7 days.
