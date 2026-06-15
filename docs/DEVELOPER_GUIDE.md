# Developer Guide

This guide is for PHP developers adding features to Thin Thread Intelligence.

## Project Shape

Thin Thread is a Laravel 13 application with a small, explicit architecture:

```text
app/
  Console/Commands/
    IngestLeak.php
    PruneExpiredCapsules.php
  Http/Controllers/
    AdminController.php
    SearchController.php
    Auth/LoginController.php
  Http/Middleware/
    EnsureUserIsAdmin.php
  Jobs/
    CreateOsintCapsule.php
  Models/
    User.php
  Support/
    CapsuleRetentionPolicy.php

resources/views/
  admin.blade.php
  search.blade.php
  auth/login.blade.php
  partials/thread-ui.blade.php

routes/
  web.php

tests/
  Feature/
  Unit/
```

## Data Architecture

There are two storage layers:

### MariaDB or MySQL

Laravel uses the relational database for:

- Users.
- Sessions, if `SESSION_DRIVER=database`.
- Queue jobs, if `QUEUE_CONNECTION=database`.
- Cache, if `CACHE_STORE=database`.

Leak rows are not stored in MariaDB.

### DuckDB Capsules

Leak data is stored in standalone DuckDB files:

```text
storage/app/osint_capsules/capsule_{uuid}.db
```

Each capsule owns its own:

- Raw searchable text.
- Embedded metadata.
- FTS index.

This keeps ingestion and search decoupled from the Laravel database.

## Important Classes

### `App\Jobs\CreateOsintCapsule`

Responsibilities:

- Normalize source files.
- Create temporary one-column CSV import files.
- Create DuckDB capsule files.
- Insert `raw_data` and `meta`.
- Build DuckDB FTS index.
- Clean up temporary files.
- Log ingestion success/failure.

Add ingestion behavior here when the change affects how source files become DuckDB data.

### `App\Http\Controllers\SearchController`

Responsibilities:

- Accept search query input.
- Build safe DuckDB SQL.
- Scan every `.db` capsule in `storage/app/osint_capsules`.
- Stream Server-Sent Events to the frontend.

Search has two query modes:

- Literal substring mode for punctuation queries, such as domains and emails.
- FTS mode for plain word queries, with token presence filtering.

Add search behavior here when the change affects query semantics, ranking, limits, or SSE output.

### `App\Http\Controllers\AdminController`

Responsibilities:

- Render admin dashboard.
- Dispatch ingestion jobs.
- Read capsule inventory metadata.
- Delete capsules.
- Manage users.

Add admin-only operational features here unless they deserve their own controller.

### `App\Console\Commands\IngestLeak`

CLI wrapper around `CreateOsintCapsule::dispatch`.

Add or change arguments here when operators need a new CLI ingestion option.

### `App\Console\Commands\PruneExpiredCapsules`

Deletes capsules whose embedded `meta.retention_expires_at` is in the past.

Add retention-related batch operations here.

### `App\Support\CapsuleRetentionPolicy`

Single source of truth for retention policy values, labels, descriptions, and expiry calculation.

Add new retention policies here first, then update tests and UI expectations.

## Routes and Middleware

Routes live in:

```text
routes/web.php
```

Route groups:

- Public login/logout routes.
- Authenticated search routes.
- Authenticated admin routes protected by `admin` middleware.

Admin middleware:

```text
App\Http\Middleware\EnsureUserIsAdmin
```

Only users with `is_admin=true` can access admin routes.

## Frontend Structure

The app uses Blade views and Tailwind utility classes.

Primary views:

- `resources/views/search.blade.php`: search UI and SSE JavaScript.
- `resources/views/admin.blade.php`: ingestion, capsule inventory, user management.
- `resources/views/auth/login.blade.php`: login form.
- `resources/views/partials/thread-ui.blade.php`: shared CSS/design layer.

Frontend build commands:

```bash
npm run dev
npm run build
```

When adding UI:

- Keep admin screens dense and operational.
- Prefer existing `thread-*` classes before adding new styling.
- Keep destructive actions behind clear forms with CSRF and confirmation.
- Do not put sensitive values in frontend JavaScript.

## DuckDB Process Calls

The app invokes DuckDB through the `App\Support\DuckDB` helper class, which wraps Laravel's `Process` facade with the correct environment settings.

Preferred pattern:

```php
use App\Support\DuckDB;

$result = DuckDB::process(60)
    ->run([DuckDB::binary(), '-json', '-readonly', $path, '-c', DuckDB::preamble() . ' ' . $sql]);
```

Use argument arrays instead of shell strings. This avoids quoting bugs and shell injection risk.

The `DuckDB` class handles:

- Setting `HOME` to ensure extensions (like `fts`) load correctly.
- Setting `PRAGMA temp_directory` via `DuckDB::preamble()` to avoid `/tmp` restrictions.
- Allowing `DUCKDB_HOME` and `DUCKDB_BINARY` overrides in `.env`.

For write operations, use `-bail` where appropriate so DuckDB exits on the first SQL error.

## SQL Safety

Do not concatenate untrusted strings directly into SQL.

Current pattern:

```php
private function sqlString(string $value): string
{
    return "'".str_replace("'", "''", $value)."'";
}
```

DuckDB CLI calls do not use PDO prepared statements. Escape string literals deliberately and keep filenames validated separately.

For capsule deletion, validate filenames by:

- Requiring a basename only.
- Requiring `.db`.
- Resolving with `realpath`.
- Ensuring the resolved path is inside the capsule directory.

## Adding a New Feature

Use this workflow:

1. Identify the feature boundary:
   - Search behavior: `SearchController`.
   - Ingestion behavior: `CreateOsintCapsule` or `IngestLeak`.
   - Admin operation: `AdminController` and `admin.blade.php`.
   - Retention rule: `CapsuleRetentionPolicy` and pruning command.
   - User/account rule: `AdminController`, `User`, migrations.

2. Add or update routes in `routes/web.php`.

3. Keep validation near the entry point:
   - Request validation in controllers.
   - CLI validation in commands.
   - Path validation before filesystem changes.

4. Add focused tests.

5. Run formatter:

   ```bash
   ./vendor/bin/pint path/to/changed/files.php
   ```

6. Run targeted tests:

   ```bash
   php artisan test tests/Feature/RelevantTest.php
   ```

7. Run the full suite when the environment has all required extensions:

   ```bash
   php artisan test
   ```

## Testing

Primary test command:

```bash
php artisan test
```

The full suite requires `pdo_sqlite` because `phpunit.xml` configures:

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

Focused tests that avoid database auth can still pass without SQLite.

Useful focused tests:

```bash
php artisan test tests/Feature/IngestionTest.php
php artisan test tests/Feature/SearchTest.php
php artisan test tests/Feature/AdminCapsuleDeletionTest.php
php artisan test tests/Unit/CapsuleRetentionPolicyTest.php
```

## Writing Tests for Capsule Features

Avoid deleting real capsules in tests.

Pattern:

1. Snapshot the existing capsule file list.
2. Create test files or run a small ingestion.
3. Assert only new files.
4. Delete only files created by the test.

Do not blindly delete every `.db` in `storage/app/osint_capsules`.

For controller methods that would normally require auth/database middleware, a focused unit-style feature test can call the controller method directly when the goal is filesystem behavior.

## Adding a Retention Policy

1. Add a constant in `CapsuleRetentionPolicy`.
2. Add it to `options()`.
3. Add expiry logic to `expiresAt()`.
4. Update admin UI copy if needed.
5. Update tests:

   ```text
   tests/Unit/CapsuleRetentionPolicyTest.php
   tests/Feature/CapsuleRetentionTest.php
   ```

6. Consider migration/backfill only if existing capsules need a changed policy value. Current policy values are embedded in each capsule's `meta` table.

## Adding Search Features

Search streams events to the browser.

Existing event types:

- `ping`: capsule scan started.
- `meta`: capsule metadata.
- `hit`: matching row.
- `done`: all capsules scanned.
- `close`: no query.

When adding event types:

1. Emit valid SSE:

   ```text
   event: name
   data: {"key":"value"}
   ```

2. Flush output after each event.
3. Update JavaScript in `resources/views/search.blade.php`.
4. Add a test for SQL/query semantics separately from the browser UI when possible.

Search accuracy guidelines:

- Domains and emails should use literal matching.
- Plain word queries may use FTS.
- Avoid returning rows that match only one weak token from a multi-token query unless that behavior is explicitly designed.
- Keep query limits explicit.

## Adding Ingestion Features

Potential feature examples:

- Structured CSV extraction.
- SQL `INSERT` parser that extracts selected columns.
- Per-format normalization.
- Capsule-level encryption.
- Ingestion progress reporting.

Implementation guidance:

- Keep large-file handling streaming.
- Avoid reading full source files into memory.
- Keep temporary files under `storage/app/osint_capsules`.
- Clean temporary files on success and failure.
- Surface import errors. Do not hide them with silent `ignore_errors=True`.
- Preserve `meta.total_lines` or add new metadata fields if the row meaning changes.

If adding format-specific parsing, keep the existing line-oriented behavior as a fallback.

## Migrations and Database Changes

Laravel migrations live in:

```text
database/migrations/
```

Use migrations for:

- Users and roles.
- App settings.
- Queue/session/cache tables.
- Any relational app metadata.

Do not migrate leak rows into MariaDB unless the architecture intentionally changes.

## Security Considerations

Important rules:

- Admin routes must stay behind `auth` and `admin`.
- Destructive admin actions must use CSRF-protected forms.
- Validate all filesystem paths.
- Prefer route parameters that are filenames, not arbitrary paths.
- Use `Process::run([...])` argument arrays, not shell command strings.
- Do not display raw secrets in logs.
- Treat leak data as sensitive. Avoid dumping raw rows into application logs.

## Performance Considerations

Ingestion:

- Streaming normalization keeps PHP memory stable.
- DuckDB FTS indexing can be CPU and disk heavy.
- Queue workers need long timeouts.

Search:

- Capsules are scanned sequentially.
- This avoids heavy I/O contention on constrained hardware.
- Each capsule query has an explicit limit.

If search becomes slow:

1. Measure per-capsule query time.
2. Consider filtering capsules before scan.
3. Consider adding metadata indexes or an external registry.
4. Be careful with parallel searches on slow disks.

## Code Style

Use Laravel Pint:

```bash
./vendor/bin/pint
```

Project style:

- Prefer explicit controller validation.
- Prefer small support classes for shared policy logic.
- Keep comments useful and sparse.
- Use structured APIs instead of shell parsing where possible.
- Preserve existing Blade and CSS conventions.

## Release Checklist

Before deploying a feature:

1. Run Pint.
2. Run targeted tests.
3. Run full tests with `pdo_sqlite` installed.
4. Verify migrations.
5. Verify queue worker compatibility.
6. Verify DuckDB CLI and FTS extension.
7. Smoke test login, admin, ingestion, search, and capsule deletion.
8. Back up MariaDB and capsules before production changes.
