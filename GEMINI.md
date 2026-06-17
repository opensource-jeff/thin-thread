# OSINT Search Engine (Manual qgrep Architecture)

## Architecture Overview
This application uses **qgrep** for high-speed searching across normalized text files, with metadata tracked in MariaDB.

- **Database:** MariaDB stores users, Laravel metadata, and **Leak metadata**.
- **Search Command:** Configurable via `.env`. By default, it runs against a manually managed qgrep project named `osint_leaks`.
- **Leak Files:** Each uploaded leak is stored as a normalized `.txt` file in `storage/app/osint_leaks/`.
- **Ingestion:** Background jobs normalize text, split them into chunks, and store metadata in MariaDB.
- **Indexing:** **Manual management.** The administrator must run `qgrep index` manually on the server after ingestion to update the searchable data.

## Key Components

### 1. Ingestion Job (`App\Jobs\IngestLeakFile`)
- **Normalization:** Converts text to lowercase and strips control characters.
- **Chunking:** Splits large leaks into 1,000,000-line chunks for optimal qgrep performance.
- **Metadata:** Creates records in MariaDB for every chunk to enable UI grouping.

### 2. Search Controller (`App\Http\Controllers\SearchController`)
- **Configurable Command:** Executes the search command defined in `QGREP_SEARCH_COMMAND`.
- **Metadata Join:** Maps file paths returned by qgrep to MariaDB records to display human-friendly leak names.

## Setup Instructions

1. **Environment Configuration:**
   Add the search command to your `.env`:
   ```env
   QGREP_SEARCH_COMMAND="qgrep search osint_leaks {query}"
   ```

2. **Permissions:**
   Ensure the leak and index directories are writable:
   ```bash
   mkdir -p storage/app/osint_leaks storage/app/qgrep_index
   chmod -R 775 storage/app/osint_leaks storage/app/qgrep_index
   ```

3. **Manual Indexing:**
   After ingesting new leaks, run the following command on the server:
   ```bash
   qgrep index osint_leaks storage/app/osint_leaks/
   ```

4. **Database:**
   Ensure MariaDB is running and the database `thin_thread` exists. Run migrations:
   ```bash
   php artisan migrate --seed
   ```

5. **Queue Worker:**
   Start the queue worker to process ingestion jobs:
   ```bash
   php artisan queue:work --timeout=86400
   ```

6. **Web Server:**
   Start the Laravel server:
   ```bash
   php artisan serve
   ```
