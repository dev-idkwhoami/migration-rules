# Migration Rules

A `composer require --dev` package that scans, sorts, inlines, and regenerates Laravel migrations to enforce consistent naming and ordering rules.

> **Warning:** Safe to run only on undeployed apps. This tool modifies your migration files.

## Requirements

- Laravel 12+ (anonymous class migration convention)
- PHP 8.2+

## Installation

```bash
composer require idkwhoami/migration-rules --dev
```

## Usage

```bash
# Preview what would change (no files written)
php artisan migrate:enforce --dry-run

# Apply changes
php artisan migrate:enforce

# Show current manifest / state
php artisan migrate:enforce --status
```

### Options

| Option | Description | Default |
|--------|-------------|---------|
| `--dry-run` | Preview changes without writing files | false |
| `--pattern` | Date prefix for filenames | `0001_01_01` |
| `--base-step` | Slot step for base migrations | `50` |
| `--pivot-step` | Slot step for pivot migrations | `25` |
| `--force` | Auto-resolve collisions (keep base version) | false |
| `--status` | Show manifest state | false |

## Rules

- **Filename pattern:** `{prefix}_{slot}_{action}.php`
- **Base slot step:** 50 (each distinct model)
- **Pivot slot step:** 25
- **Ordering:** Topological sort by FK dependencies
- **Altering migrations:** Inlined into base migration (no separate altering migrations)
- **down():** Omitted entirely (undeployed = no rollback needed)

## FK Detection

Detects foreign keys via:
- `->foreignId('column')->constrained()`
- `->foreignIdFor(Model::class)`
- `->foreignUlid('column')` / `->foreignUuid('column')`
- `->morphs('column')` / `->nullableMorphs()` / `->ulidMorphs()` / `->uuidMorphs()`
- `->constrained()->onDelete(...)`

External table references (tables outside `database/migrations/`) are skipped.

## Manifest

After running, a `.migration.rules` file is written to your project root and added to `.gitignore`. This allows subsequent runs to detect drift.

## License

MIT