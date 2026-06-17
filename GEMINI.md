# OSINT Search Engine (qgrep Architecture)

## Architecture Overview
This application is designed for high-performance OSINT search on restricted hardware. It uses **qgrep** for fast full-text searching across normalized text files.

- **Database:** MariaDB stores users, Laravel metadata, and **Leak metadata**.
- **Authentication:** Mandatory login enforced for all search and admin routes.
- **Leak Files:** Each uploaded leak is stored as a normalized `.txt` file in `storage/app/osint_leaks/`.
- **Ingestion:** Performed via background jobs that normalize text, store metadata in MariaDB, and update the qgrep index (`storage/app/qgrep_index/leaks.qf`).
- **Search:** The `SearchController` executes `qgrep search` against the unified index and joins results with MariaDB metadata for real-time streaming to the analyst.

## Key Components

### 1. Ingestion Job (`App\Jobs\IngestLeakFile`)
- **Timeout:** 24 hours.
- **Normalisation:** All text is converted to lowercase, UTF-8 encoded, and stripped of control characters.
- **Indexing:** Triggers `qgrep index` to rebuild the searchable index.

### 2. Search Controller (`App\Http\Controllers\SearchController`)
- **qgrep Search:** Uses `qgrep` for high-speed regex-based search across all ingested files.
- **Metadata Join:** Fetches display names and leak info from MariaDB based on file paths returned by qgrep.
- **Streaming:** Uses Server-Sent Events (SSE) to push results to the UI as they are found.

### 3. Authentication & Access Control
- **Login:** Handled via `Auth\LoginController`.
- **Middleware:** `auth` middleware protects all routes.
- **Session:** Configured to `database` driver (MariaDB).

### 4. CLI Command
Manually trigger ingestion from the terminal:
```bash
php artisan osint:ingest /path/to/leak.txt "Target Breach" "2024-06-10" "JSON"
```

## Setup Instructions

1. **Environment Configuration:**
   Specify the path to qgrep in your `.env`:
   ```env
   QGREP_BINARY=/usr/bin/qgrep
   ```

2. **Permissions:**
   Ensure the leak and index directories are writable:
   ```bash
   mkdir -p storage/app/osint_leaks storage/app/qgrep_index
   chmod -R 775 storage/app/osint_leaks storage/app/qgrep_index
   ```

3. **Database:**
   Ensure MariaDB is running and the database `thin_thread` exists. Run migrations:
   ```bash
   php artisan migrate --seed
   ```

4. **Queue Worker:**
   Start the queue worker to process ingestion jobs:
   ```bash
   php artisan queue:work --timeout=86400
   ```

5. **Web Server:**
   Start the Laravel server:
   ```bash
   php artisan serve
   ```

## Optimization Parameters
- **qgrep Index:** Fast bitset-based searching avoids sequential file scans.
- **Metadata Cache:** `SearchController` caches metadata lookups per-request to minimize database load during high-volume streaming.
- **Normalisation:** Aggressive pre-processing ensures consistent search hits across international data.

