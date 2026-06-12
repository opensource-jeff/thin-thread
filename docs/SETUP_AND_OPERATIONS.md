# Setup and Operations

This guide covers installing, configuring, running, and operating Thin Thread Intelligence.

## System Requirements

Required runtime components:

- PHP 8.3 or newer.
- Composer.
- Node.js and npm.
- MariaDB or MySQL for Laravel users, sessions, jobs, and other application metadata.
- DuckDB CLI available as `duckdb` on the system path.
- DuckDB `fts` extension installed for the OS user that runs PHP and queue workers.

Required PHP extensions:

- `mbstring`
- `pdo`
- `pdo_mysql`
- `openssl`
- `json`
- `fileinfo`
- `curl`
- `dom`
- `xml`
- `zip`
- `posix`

Test-only PHP extension:

- `pdo_sqlite`

The application can run with MariaDB only, but the full PHPUnit suite uses in-memory SQLite through `phpunit.xml`.

## Fresh Installation

1. Install PHP dependencies:

   ```bash
   composer install
   ```

2. Install frontend dependencies:

   ```bash
   npm install
   ```

3. Create the environment file:

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. Configure `.env`.

   For MariaDB:

   ```env
   APP_NAME="Thin Thread Intelligence"
   APP_ENV=local
   APP_DEBUG=true
   APP_URL=http://127.0.0.1:8000

   DB_CONNECTION=mariadb
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=thin_thread
   DB_USERNAME=thin_thread
   DB_PASSWORD=change-this

   SESSION_DRIVER=database
   QUEUE_CONNECTION=database
   CACHE_STORE=database
   ```

5. Create the database and user in MariaDB.

   Example SQL:

   ```sql
   CREATE DATABASE thin_thread CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'thin_thread'@'localhost' IDENTIFIED BY 'change-this';
   GRANT ALL PRIVILEGES ON thin_thread.* TO 'thin_thread'@'localhost';
   FLUSH PRIVILEGES;
   ```

6. Run migrations and seed the initial admin account:

   ```bash
   php artisan migrate --seed
   ```

7. Build frontend assets:

   ```bash
   npm run build
   ```

8. Create and permission the capsule directory:

   ```bash
   mkdir -p storage/app/osint_capsules
   chmod -R 775 storage bootstrap/cache
   ```

9. Install and load the DuckDB FTS extension once for the runtime user:

   ```bash
   duckdb -c "INSTALL fts; LOAD fts;"
   ```

   Run this as the same OS user that will run `php artisan serve`, `php-fpm`, and `php artisan queue:work`.

## Running Locally

Start the application:

```bash
php artisan serve
```

Start the queue worker in a second terminal:

```bash
php artisan queue:work --timeout=86400
```

For frontend development:

```bash
npm run dev
```

The Composer `dev` script starts Laravel, the queue listener, logs, and Vite together:

```bash
composer run dev
```

## Initial Login

The database seeder creates one admin user:

- Email: `test@example.com`
- Password: `password`

Immediately replace this password or create a new admin user and delete the seeded account before using real data.

## Production Process Model

Thin Thread needs at least these long-running processes:

- Web server: nginx or Apache pointing to `public/index.php`, usually through PHP-FPM.
- Queue worker: `php artisan queue:work --timeout=86400`.
- Optional scheduler: Laravel scheduler if you want automatic retention pruning.

Queue workers need a long timeout because large DuckDB ingestion jobs can take minutes or hours.

Example Supervisor program:

```ini
[program:thin-thread-worker]
command=php /var/www/thin-thread/artisan queue:work --timeout=86400 --tries=1
directory=/var/www/thin-thread
user=www-data
autostart=true
autorestart=true
stopwaitsecs=86400
redirect_stderr=true
stdout_logfile=/var/log/thin-thread-worker.log
```

## Web Routes

Public:

- `GET /login`
- `POST /login`
- `POST /logout`

Authenticated:

- `GET /search`
- `GET /search/stream?q=...`

Admin:

- `GET /admin`
- `POST /admin/ingest`
- `DELETE /admin/capsules/{capsule}`
- `POST /admin/users`
- `PUT /admin/users/{user}`
- `DELETE /admin/users/{user}`

## Admin Operations

### User Management

Admins can:

- Create accounts.
- Grant or remove admin access.
- Change account names, emails, and passwords.
- Delete other accounts.

Protections:

- Admins cannot delete their own current account.
- Admins cannot remove admin access from their own current account.
- The application prevents deleting or demoting the last admin account.

### Capsule Inventory

The admin page lists every `.db` file in:

```text
storage/app/osint_capsules/
```

For each capsule, the application attempts to read the embedded `meta` table and displays:

- Display name.
- Filename.
- File size.
- Modified time.
- Row count.
- Leak date.
- Data format tag.
- Retention label.
- Expiry time.
- Ingestion time.

If metadata cannot be read, the file still appears with an unreadable metadata warning.

### Capsule Deletion

Admins can delete a capsule from the admin page.

Deletion removes:

- The selected `capsule_*.db` file.
- Matching DuckDB sidecars such as `.wal` and `.tmp`, if present.
- Matching local ingest sidecars such as `ingest_{uuid}.sql` and `ingest_{uuid}.csv`, if present.

The DuckDB file contains the capsule metadata, so deleting the file removes the associated metadata as well.

The delete action validates that the filename resolves inside `storage/app/osint_capsules/` and ends in `.db`.

### Retention Pruning

Run:

```bash
php artisan capsules:prune-expired
```

Dry run:

```bash
php artisan capsules:prune-expired --dry-run
```

Override the capsule directory:

```bash
php artisan capsules:prune-expired --path=/some/capsule/dir
```

The command reads each capsule's `meta.retention_expires_at`.

- `breach` capsules are retained indefinitely.
- `stealer`, `ulp_log`, `telegram`, and `scraped` capsules expire after 3 months.

## Logs

Laravel logs are written to:

```text
storage/logs/laravel.log
```

Useful log entries:

- Ingestion success and row count.
- Ingestion failure and DuckDB error output.
- Search failures per capsule.
- Admin capsule deletion events.

For live logs:

```bash
php artisan pail
```

## Backups

Back up both:

- MariaDB database.
- `storage/app/osint_capsules/`.

Capsules are standalone DuckDB files. Restoring a capsule file to `storage/app/osint_capsules/` makes it searchable again.

Recommended backup checklist:

1. Pause ingestion jobs or confirm none are running.
2. Dump MariaDB.
3. Copy `storage/app/osint_capsules/`.
4. Verify file sizes and checksums for large capsules.

## Upgrades

1. Put the app into maintenance mode:

   ```bash
   php artisan down
   ```

2. Stop queue workers after active jobs finish.
3. Back up MariaDB and capsules.
4. Update code and dependencies:

   ```bash
   composer install --no-dev --optimize-autoloader
   npm ci
   npm run build
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

5. Restart PHP-FPM/web server and queue workers.
6. Bring the app back:

   ```bash
   php artisan up
   ```

## Troubleshooting

### DuckDB FTS Extension Not Found

Symptom:

```text
Extension ".../fts.duckdb_extension" not found.
Install it first using "INSTALL fts".
```

Fix:

```bash
duckdb -c "INSTALL fts; LOAD fts;"
```

Run it as the same OS user that runs PHP and the queue worker.

### Ingestion Job Dispatches But Nothing Happens

Check:

```bash
php artisan queue:work --timeout=86400
php artisan queue:failed
tail -n 100 storage/logs/laravel.log
```

If `QUEUE_CONNECTION=database`, migrations must have created the `jobs` table.

### Full Test Suite Fails With "could not find driver"

The test environment uses SQLite:

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

Install/enable `pdo_sqlite` for the PHP CLI.

### Search Returns No Results

Check:

1. The capsule directory contains `.db` files.
2. Each capsule has a readable `meta` table.
3. DuckDB can open the file:

   ```bash
   duckdb -readonly storage/app/osint_capsules/capsule_x.db -c "SELECT * FROM meta LIMIT 1;"
   ```

4. The query is expected to match literal punctuation if searching a domain or email.

### Disk Usage Grows During Ingestion

Ingestion writes a temporary normalized CSV and then a DuckDB capsule. Large source files require working disk space for:

- Original file.
- Temporary normalized CSV.
- Final capsule.
- DuckDB temporary files during index creation.

Keep several times the source size available.
