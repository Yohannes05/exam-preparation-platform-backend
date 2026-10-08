# Telegram Quiz Bot

Laravel application for the admin dashboard and the Telegram student bot. The admin manages grades, subjects, chapters, notes, questions, exams, announcements, students, results, payments, and activation. Students use Telegram for registration, practice, quizzes, referrals, and account activation.

## Requirements

- PHP 8.3 or later with the extensions required by Laravel, including PDO SQLite/MySQL/PostgreSQL, fileinfo, and DOM.
- Composer 2.
- Node.js 22.12 or later for Vite 8 (`.nvmrc` pins the minimum compatible release).
- MySQL/PostgreSQL for a typical production deployment, or a persistent SQLite volume.

## Local setup

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan admin:create admin@example.com "Admin Name"
php artisan storage:link
npm ci
npm run build
php artisan serve
```

The local seeder adds sample curriculum, but does not create an admin with a default password. `admin:create` prompts for the admin password. Add the Telegram and Telegraph settings listed below to `.env` to connect external services.

## Production deployment

1. Create a Supabase project. In its Dashboard, choose **Connect** and copy the PostgreSQL connection details. Use the direct connection if the application host supports IPv6; for an IPv4-only host choose the **Session pooler** details. Set the values in the server's environment (not in Git):

   ```env
   DB_CONNECTION=pgsql
   DB_HOST=host-from-supabase-connect
   DB_PORT=5432
   DB_DATABASE=postgres
   DB_USERNAME=username-from-supabase-connect
   DB_PASSWORD=your-database-password
   DB_SSLMODE=require
   ```

   Supabase connection host, port, and username vary by project and connection mode; copy them from the dashboard. If the password contains reserved URL characters and you use `DB_URL`, URL-encode the password. [Supabase connection guide](https://supabase.com/docs/guides/database/connecting-to-postgres)

2. Use a public HTTPS domain and set production environment values. Keep `.env` outside version control and never expose bot, Telegraph, database, or app secrets.

   ```env
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://your-domain.example
   TELEGRAM_BOT_TOKEN=...
   TELEGRAM_WEBHOOK_SECRET=... # generate with: php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
   TELEGRAM_ADMIN_CHAT_ID=...
   TELEGRAPH_ACCESS_TOKEN=...
   ```

   Configure the channel, payment details, and database connection as needed. For Supabase Storage, create a **public** bucket named `lesson-files`, then set `SUPABASE_URL` and `SUPABASE_STORAGE_SERVICE_KEY` on Render. Find the server-side `service_role` key in Supabase project API settings; keep it only in Render secrets. Existing local files are not copied when database rows are transferred, so upload those files again after deploy.

   The webhook secret is not provided by Telegram. Generate a random one in a terminal with `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`, then save the resulting 64-character value as `TELEGRAM_WEBHOOK_SECRET` in Render's **web service** Environment settings. Keep it private. The Render Blueprint also asks for it during initial setup.

3. Install dependencies and build assets with Node.js 22.12 or later:

   ```sh
   composer install --no-dev --prefer-dist --optimize-autoloader
   npm ci
   npm run build
   ```

4. Run database setup, transfer existing SQLite rows, then create a unique admin account. The transfer command requires the PHP `pdo_pgsql` extension, an empty Supabase application schema, and the local SQLite file. It preserves IDs and foreign-key order, skips transient cache/session/queue tables, and leaves the SQLite database unchanged. First run its read-only dry run, inspect the counts, then run it again to copy:

   ```sh
   php artisan migrate --force
   php artisan db:transfer-sqlite-to-supabase --dry-run
   php artisan db:transfer-sqlite-to-supabase
   php artisan storage:link
   php artisan admin:create admin@your-domain.example "Admin Name"
   ```

   Run the transfer before creating the admin account. The command stops if Supabase application tables already contain rows. Database rows do not include uploaded file bytes; copy any files referenced by note/chapter records to the production file store separately. Do not run `db:seed` in production; it is intentionally blocked because it contains sample content. The admin command prompts securely for a password. Point the web server document root at Laravel's `public` directory, then cache configuration for production:

   ```sh
   php artisan optimize
   ```

5. Register Telegram's webhook once, after DNS and HTTPS are working:

   ```sh
   php artisan telegram:set-webhook https://your-domain.example/api/telegram/webhook
   php artisan telegram:status
   ```

   Production webhook setup requires `TELEGRAM_WEBHOOK_SECRET`. Do not run `telegram:poll` while the webhook is active; only one update receiver may use the bot token at a time.

6. The Render Blueprint defines a separate Cron service that runs `php artisan schedule:run` every minute. It shares the database and Telegram bot token with the web service; set the same `APP_KEY` and `DB_PASSWORD` for both services. Render Cron jobs are billed by run time with a $1/month minimum. The reminder task itself runs daily at 09:00 Addis Ababa time.

   If setting up the Cron service manually, use the same repository and Dockerfile, command `php artisan schedule:run`, and schedule `* * * * *` (UTC).

## Checks before release

```sh
php artisan test
npm run build
php artisan route:list
php artisan schedule:list
```

The app also provides `php artisan telegram:status` to check Telegram API connectivity and webhook status without printing the bot token.
