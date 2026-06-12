# Thin Thread Intelligence

Thin Thread is a Laravel-based OSINT search application built around standalone DuckDB capsules. The Laravel app handles authentication, administration, queueing, and the search UI. Leak data is not stored in MariaDB; each ingested source file becomes a self-contained DuckDB `.db` file under `storage/app/osint_capsules/`.

## Documentation

- [Setup and Operations](docs/SETUP_AND_OPERATIONS.md): install prerequisites, configure the app, run services, manage users, manage capsules, and troubleshoot the environment.
- [Data Ingestion Guide](docs/DATA_INGESTION.md): ingestion workflow, supported inputs, CLI and admin ingestion, retention policy, validation, and operational checks.
- [Developer Guide](docs/DEVELOPER_GUIDE.md): codebase architecture, extension points, feature workflow, testing, and conventions for PHP developers.

## Quick Start

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve
php artisan queue:work --timeout=86400
```

The seeded admin account is:

- Email: `test@example.com`
- Password: `password`

Change this account before using the application with real data.

## Core Commands

Run the web app:

```bash
php artisan serve
```

Run the ingestion queue:

```bash
php artisan queue:work --timeout=86400
```

Ingest a source file from the CLI:

```bash
php artisan osint:ingest /path/to/source.sql "Display name" "2026-06-12" "UNSTRUCTURED" breach
```

Prune expired capsules:

```bash
php artisan capsules:prune-expired
```

Run tests:

```bash
php artisan test
```

The full test suite requires the PHP SQLite PDO extension because `phpunit.xml` uses an in-memory SQLite database.

## Architecture Summary

- `App\Jobs\CreateOsintCapsule` normalizes source files, imports rows into DuckDB, writes metadata, and creates a DuckDB FTS index.
- `App\Http\Controllers\SearchController` scans all `.db` capsules and streams Server-Sent Events to the search UI.
- `App\Http\Controllers\AdminController` handles ingestion requests, capsule inventory/deletion, and user administration.
- `App\Console\Commands\PruneExpiredCapsules` removes capsules whose embedded retention metadata has expired.
- `App\Support\CapsuleRetentionPolicy` defines valid retention classes and expiry rules.

## Data Location

Capsules live in:

```text
storage/app/osint_capsules/
```

Each capsule contains:

- `raw_data`: normalized searchable text rows.
- `meta`: display name, leak date, format tag, retention policy, ingest time, expiry time, and row count.
- DuckDB FTS tables created by the DuckDB `fts` extension.

Deleting a capsule deletes the DuckDB file, including all embedded metadata.
