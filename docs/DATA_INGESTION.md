# Data Ingestion Guide

This guide explains how Thin Thread ingests source data, how to run ingestion safely, and how to verify the result.

## Ingestion Model

Thin Thread stores leak data in normalized text files, while metadata is stored in MariaDB. Each source file becomes one `.txt` file in:

```text
storage/app/osint_leaks/
```

Search is powered by **qgrep**, which maintains an index in:

```text
storage/app/qgrep_index/
```

The metadata for each leak is stored in the `leaks` table in MariaDB:

- `display_name`
- `file_path`: absolute path to the normalized text file.
- `leak_date`
- `data_format`
- `retention_policy`, `retention_label`, `retention_expires_at`
- `ingested_at`
- `total_lines`

## Ingestion Pipeline

The main ingestion implementation is:

```text
App\Jobs\IngestLeakFile
```

Pipeline steps:

1. Create a UUID for the new leak file.
2. Stream the source file line by line.
3. Normalize each line:
   - Remove invalid UTF-8 bytes.
   - Replace NUL/control bytes with spaces.
   - Convert text to lowercase.
4. Write a normalized `.txt` file.
5. Create a record in the MariaDB `leaks` table.
6. Trigger `qgrep index` to rebuild the searchable index.
7. Delete the temporary source file (if uploaded).
8. Log success with the row count.

## Supported Source Files

The current ingestion mode is line-oriented. It accepts any file where each newline-delimited row should be searchable.

Common examples:

- SQL dumps with one `INSERT ... VALUES (...)` statement per line.
- JSON lines.
- CSV or delimited rows.
- Mixed unstructured text.
- Log files.

## Retention Policies

Retention rules are defined in:

```text
App\Support\CapsuleRetentionPolicy
```

Valid values:

| Value | Label | Expiry |
| --- | --- | --- |
| `breach` | Breach leak | Never expires |
| `stealer` | Stealer logs | 3 months after ingestion |
| `ulp_log` | ULP logs | 3 months after ingestion |
| `telegram` | Telegram data | 3 months after ingestion |
| `scraped` | Scraped data | 3 months after ingestion |

Expired leaks can be deleted with:

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

## qgrep Index Setup

Specify the path to the `qgrep` binary in your `.env`:

```env
QGREP_BINARY=/usr/local/bin/qgrep
```

The application will automatically manage the index under `storage/app/qgrep_index/`.

## Verifying an Ingest

Find the newest leak file:

```bash
ls -lht storage/app/osint_leaks
```

Check row count in MariaDB:

```bash
php artisan tinker --execute="print_r(App\Models\Leak::latest()->first()->toArray())"
```

The `total_lines` value should match:

```bash
wc -l /path/to/normalized/leak.txt
```

## Search Semantics After Ingestion

Search behavior is implemented in:

```text
App\Http\Controllers\SearchController
```

Rules:

- Queries are escaped for literal regex matching in qgrep.
- Search joins hits with MariaDB metadata to provide the source leak name.
- Results are streamed in real-time.

## Managing Leaks

### List Leaks

Use the admin page or query the `leaks` table in MariaDB.

### Delete Leaks

Use the admin page's "Delete leak" action.

Deletion removes:

- The record from MariaDB.
- The normalized `.txt` file.
- Triggers a qgrep re-indexing.

### Prune Expired Leaks

Dry run first:

```bash
php artisan capsules:prune-expired --dry-run
```

Delete expired leaks:

```bash
php artisan capsules:prune-expired
```
