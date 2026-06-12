# Data Ingestion Guide

This guide explains how Thin Thread ingests source data, how to run ingestion safely, and how to verify the result.

## Ingestion Model

Thin Thread stores leak data in standalone DuckDB capsules. Each source file becomes one `.db` file in:

```text
storage/app/osint_capsules/
```

The capsule contains:

- `raw_data(raw_text VARCHAR)`: one normalized source row per record.
- `meta(...)`: display name, leak date, format tag, retention policy, expiry time, ingestion time, and row count.
- DuckDB FTS index tables created by `PRAGMA create_fts_index`.

The application does not store source rows in MariaDB.

## Ingestion Pipeline

The main ingestion implementation is:

```text
App\Jobs\CreateOsintCapsule
```

Pipeline steps:

1. Create a UUID for the new capsule.
2. Stream the source file line by line.
3. Normalize each line:
   - Remove invalid UTF-8 bytes.
   - Replace NUL/control bytes with spaces.
   - Convert text to lowercase.
4. Write a temporary one-column CSV file.
5. Ask DuckDB to import the CSV into `raw_data`.
6. Insert capsule metadata into `meta`.
7. Load DuckDB `fts`.
8. Build an FTS index over `raw_data.raw_text`.
9. Delete temporary ingest files.
10. Log success with the row count.

This two-step CSV normalization is intentional. Some SQL dumps contain NUL bytes and invalid byte sequences. Importing those directly through DuckDB's CSV reader can skip rows or fail. The normalized CSV gives DuckDB valid UTF-8 rows and disables silent skipping with `ignore_errors=False`.

## Supported Source Files

The current ingestion mode is line-oriented. It accepts any file where each newline-delimited row should be searchable.

Common examples:

- SQL dumps with one `INSERT ... VALUES (...)` statement per line.
- JSON lines.
- CSV or delimited rows.
- Mixed unstructured text.
- Log files.

The `classification` or `format` value is stored as metadata only. It does not currently change parsing behavior.

Available format tags in the admin UI:

- `UNSTRUCTURED`
- `CSV`
- `JSON`
- `SQL`

The CLI accepts any string for the format argument.

## Retention Policies

Retention rules are defined in:

```text
App\Support\CapsuleRetentionPolicy
```

Valid values:

| Value | Label | Expiry |
| --- | --- | --- |
| `breach` | Breach capsule | Never expires |
| `stealer` | Stealer logs | 3 months after ingestion |
| `ulp_log` | ULP logs | 3 months after ingestion |
| `telegram` | Telegram data | 3 months after ingestion |
| `scraped` | Scraped data | 3 months after ingestion |

Expired capsules can be deleted with:

```bash
php artisan capsules:prune-expired
```

## Admin Ingestion

Admin users can ingest from `/admin`.

Inputs:

- Server file path or uploaded file.
- Display name.
- Leak date.
- Structural tag.
- Retention class.

For server file paths, the file must already exist on the server. For uploads, the app streams the upload into `storage/app/osint_capsules/` before dispatching the ingestion job.

Admin ingestion dispatches a queued job. Make sure a queue worker is running:

```bash
php artisan queue:work --timeout=86400
```

## CLI Ingestion

Command:

```bash
php artisan osint:ingest {path} {name} {date} {format=UNSTRUCTURED} {retention=breach}
```

Example:

```bash
php artisan osint:ingest /home/jeff/bf_03_2026.sql "Breach forums v5" "2026-06-12" "UNSTRUCTURED" breach
```

Arguments:

- `path`: absolute or relative file path readable by PHP.
- `name`: display name shown in search/admin metadata.
- `date`: leak date in a parseable date format, preferably `YYYY-MM-DD`.
- `format`: data classification metadata.
- `retention`: one of `breach`, `stealer`, `ulp_log`, `telegram`, or `scraped`.

By default, the command dispatches a queue job. For a local one-off run without a separate worker, force the sync queue:

```bash
QUEUE_CONNECTION=sync php artisan osint:ingest /path/to/file.sql "Display name" "2026-06-12" "UNSTRUCTURED" breach
```

## Queue Worker Settings

Large ingestion jobs need a long timeout:

```bash
php artisan queue:work --timeout=86400 --tries=1
```

Why:

- Normalization is streaming but still reads the entire source file.
- DuckDB import can be fast, but FTS index creation can take time.
- Multi-GB files can take minutes or hours depending on disk and CPU.

## Disk and Memory Planning

Ingestion uses:

- Source file.
- Temporary normalized CSV.
- Final DuckDB capsule.
- DuckDB temporary/index working files.

Keep several times the source file size available as free disk space.

The DuckDB SQL sets:

```sql
PRAGMA memory_limit='5GB';
PRAGMA threads=4;
```

This is tuned for a constrained machine. If the host has more RAM and CPU, this can be increased in `CreateOsintCapsule`, but test with real data before changing production defaults.

## DuckDB FTS Setup

Install the extension once for the runtime OS user:

```bash
duckdb -c "INSTALL fts; LOAD fts;"
```

The application sets `HOME` when invoking DuckDB because web and queue processes often run with a limited environment. Still, the extension must exist for the effective runtime user.

## Verifying an Ingest

Find the newest capsule:

```bash
ls -lht storage/app/osint_capsules
```

Read metadata:

```bash
duckdb -readonly storage/app/osint_capsules/capsule_x.db -c "SELECT * FROM meta;"
```

Check row count:

```bash
duckdb -readonly storage/app/osint_capsules/capsule_x.db -c "SELECT count(*) FROM raw_data;"
```

The `meta.total_lines` value should match `SELECT count(*) FROM raw_data`.

For line-oriented files, it should also match:

```bash
wc -l /path/to/source
```

Search for a literal domain:

```bash
duckdb -readonly storage/app/osint_capsules/capsule_x.db -c "SELECT count(*) FROM raw_data WHERE contains(raw_text, 'example.com');"
```

## Search Semantics After Ingestion

Search behavior is implemented in:

```text
App\Http\Controllers\SearchController
```

Rules:

- Queries containing punctuation, such as domains or emails, use literal substring search.
- Plain word queries use DuckDB FTS, but every token must still be present in the row.
- Search streams results from every `.db` capsule in the capsule directory.
- Search currently limits output to 25 hits per capsule in the controller query.

This prevents false positives such as a domain search matching rows that only contain a shared TLD token.

## Managing Capsules

### List Capsules

Use the admin page or:

```bash
ls -lh storage/app/osint_capsules/*.db
```

### Delete Capsules

Use the admin page's "Delete capsule" action.

Deletion removes:

- The selected `.db`.
- Same-path DuckDB sidecars such as `.wal` and `.tmp`.
- Matching local ingest sidecars such as `ingest_{uuid}.sql` and `ingest_{uuid}.csv`, if present.

Because metadata is embedded in the `.db`, deleting the `.db` deletes associated metadata.

### Prune Expired Capsules

Dry run first:

```bash
php artisan capsules:prune-expired --dry-run
```

Delete expired capsules:

```bash
php artisan capsules:prune-expired
```

## Common Failure Modes

### Partial Ingestion

Expected safeguards:

- Source rows are normalized before DuckDB import.
- DuckDB import uses `ignore_errors=False`.
- Failures should surface as job failures rather than silent partial imports.

If row counts still do not match:

1. Compare `wc -l` to `meta.total_lines`.
2. Check `storage/logs/laravel.log`.
3. Inspect the source for non-newline record boundaries.
4. Confirm the queue worker did not stop mid-job.

### Job Times Out

Increase worker timeout:

```bash
php artisan queue:work --timeout=86400 --tries=1
```

If managed by Supervisor, also increase `stopwaitsecs`.

### Disk Full

Symptoms:

- DuckDB import failure.
- Missing final capsule.
- Temporary `ingest_*.csv` or `ingest_*.sql` files left behind.

Fix:

1. Free disk space.
2. Delete stale `ingest_*.csv` and `ingest_*.sql` files only when no ingestion jobs are running.
3. Re-run ingestion.

### FTS Extension Errors

Run:

```bash
duckdb -c "INSTALL fts; LOAD fts;"
```

Then retry ingestion or search.

## Operational Checklist for Large Files

Before ingesting:

1. Confirm disk space:

   ```bash
   df -h .
   ```

2. Confirm source line count:

   ```bash
   wc -l /path/to/source
   ```

3. Confirm queue worker timeout is high enough.
4. Confirm DuckDB FTS loads.
5. Start ingestion.

After ingestion:

1. Confirm Laravel log says ingestion succeeded.
2. Confirm `meta.total_lines`.
3. Confirm `SELECT count(*) FROM raw_data`.
4. Run one known-positive search.
5. Run one known-negative search.
6. Delete any old partial duplicate capsules from the admin page.
