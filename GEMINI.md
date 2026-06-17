# OSINT Search Engine (Capsule Architecture)

## Architecture Overview
This application is designed for high-performance OSINT search on restricted hardware. It avoids a centralized database for leak data, instead using **DuckDB Capsules**.

- **Database:** MariaDB stores users and Laravel metadata.
- **Authentication:** Mandatory login enforced for all search and admin routes.
- **DuckDB Capsules:** Each uploaded leak is a standalone `.db` file in `storage/app/osint_capsules/`.
- **Ingestion:** Performed via background jobs invoking the DuckDB CLI with aggressive memory limits (5GB) and thread pooling (4 threads).
- **Search:** The `SearchController` dynamically scans the capsule directory and executes CLI queries against each file, streaming results back to the analyst in real-time.

## Key Components

### 1. Ingestion Job (`App\Jobs\CreateOsintCapsule`)
- **Timeout:** 24 hours.
- **Normalisation:** All text is converted to lowercase during ingestion.
- **FTS:** Unicode-aware Full-Text Search index with no stemming or stopwords, preserving emails and special characters.

### 2. Search Controller (`App\Http\Controllers\SearchController`)
- **Dynamic Discovery:** No database pointers; it reads the filesystem directly.
- **Streaming:** Uses Server-Sent Events (SSE) to push results to the UI as they are found.
- **FTS Query:** Uses `match_bm25` for relevance scoring.

### 3. Authentication & Access Control
- **Login:** Handled via `Auth\LoginController`.
- **Middleware:** `auth` middleware protects all routes.
- **Session:** Configured to `file` driver for portability.

### 4. CLI Command
Manually trigger ingestion from the terminal:
```bash
php artisan osint:ingest /path/to/leak.txt "Target Breach" "2024-06-10" "JSON"
```

## Setup Instructions

1. **Environment Configuration:**
   In restricted production environments, you may need to specify the path to DuckDB and a valid HOME directory in your `.env`:
   ```env
   DUCKDB_BINARY=/usr/bin/duckdb
   DUCKDB_HOME=/home/user
   ```

2. **Permissions:**
   Ensure the capsule and temp directories are writable:
   ```bash
   mkdir -p storage/app/osint_capsules storage/app/duckdb_tmp
   chmod -R 775 storage/app/osint_capsules storage/app/duckdb_tmp
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
- **Memory:** Limited to 5GB during ingestion to fit within the 8GB RAM profile.
- **I/I/O:** Sequential processing of capsules during search to avoid SATA I/O thrashing.
- **Temporary Storage:** DuckDB uses `storage/app/duckdb_tmp` to avoid system `/tmp` restrictions.
- **FTS:** Custom regex `[^\p{L}0-9@.+\-_]` ensures international names and email formats are indexed correctly.
