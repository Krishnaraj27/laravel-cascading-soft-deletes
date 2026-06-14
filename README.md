<h1 align="center">🌊 Laravel Cascading Soft Deletes</h1>

<p align="center">
  <strong>Automatically cascade soft-deletes <em>and</em> restores across your Eloquent relationships — safely, precisely, and with full multi-parent support.</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/krishnaraj/laravel-cascading-soft-deletes"><img alt="Packagist Version" src="https://img.shields.io/packagist/v/krishnaraj/laravel-cascading-soft-deletes?style=flat-square"></a>
  <a href="https://packagist.org/packages/krishnaraj/laravel-cascading-soft-deletes"><img alt="PHP Version" src="https://img.shields.io/badge/PHP-%5E8.2-blue?style=flat-square"></a>
  <a href="https://packagist.org/packages/krishnaraj/laravel-cascading-soft-deletes"><img alt="Laravel Version" src="https://img.shields.io/badge/Laravel-11%20|%2012-red?style=flat-square"></a>
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/license-MIT-green?style=flat-square"></a>
</p>

---

## ✨ Why this package?

Laravel's built-in `SoftDeletes` trait only soft-deletes the model you call `delete()` on. Child models in `HasOne`, `HasMany`, or `BelongsToMany` relationships are left untouched — leading to orphaned records, broken UI states, and restore operations that silently bring back incomplete data.

**Laravel Cascading Soft Deletes** solves all of that:

| Problem | Solution |
|---|---|
| Children not deleted when parent is soft-deleted | ✅ Cascade soft-deletes through any relationship depth |
| Children not restored when parent is restored | ✅ Cascade restores — tracked precisely at the DB level |
| Restoring a child that was already independently deleted | ✅ Smart tracking skips independently-deleted children |
| Multiple parents holding the same child deleted | ✅ Multi-parent guard prevents premature restore |
| Force-deleting a parent leaving orphaned children | ✅ Cascade force-deletes with tracking cleanup |
| Many-to-many pivot records left after parent deletion | ✅ Auto-detach `BelongsToMany` on delete |

---

## 📋 Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.2` |
| Laravel | `11.x` or `12.x` |

---

## 🚀 Installation

### 1. Install via Composer

```bash
composer require krishnaraj/laravel-cascading-soft-deletes
```

### 2. Run Migrations

The package includes a migration that creates the `cascade_deletions` tracking table. Run it with:

```bash
php artisan migrate
```

> The migration is loaded automatically — no need to publish it unless you want to customise the schema.

### 3. (Optional) Publish the Config File

```bash
php artisan vendor:publish --tag=cascading-soft-deletes-config
```

This copies `config/cascading-soft-deletes.php` into your application for customisation.

### 4. (Optional) Publish the Migration

If you prefer to manage the migration yourself (e.g., to rename the table):

```bash
php artisan vendor:publish --tag=cascading-soft-deletes-migrations
```

---

## ⚙️ Basic Setup

### Step 1 — Add `SoftDeletes` and `CascadesSoftDeletes` to your model

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Krishnaraj\LaravelCascadingSoftDeletes\Traits\CascadesSoftDeletes;

class User extends Model
{
    use SoftDeletes, CascadesSoftDeletes;

    // List the relationships you want to cascade
    protected $cascadeRelationships = ['posts', 'profile'];

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }
}
```

### Step 2 — That's it!

```php
$user->delete();    // Soft-deletes user, posts, and profile
$user->restore();   // Restores user, posts, and profile
$user->forceDelete(); // Permanently deletes user, posts, and profile
```

---

## 🔗 Relationship Support

### `HasOne` and `HasMany`

These are the primary cascadeable relationships. When the parent is deleted, all related children are soft-deleted. When the parent is restored, only children that were cascade-deleted (not independently deleted) are restored.

```php
protected $cascadeRelationships = ['posts', 'profile', 'addresses'];
```

### Nested (Deep) Relationships

Use dot-notation to cascade through multiple levels:

```php
// User → Posts → Comments
protected $cascadeRelationships = ['posts.comments'];
```

When `User` is deleted:
- `User` is soft-deleted
- `Post` records are soft-deleted
- `Comment` records under each post are soft-deleted

When `User` is restored, the full chain is restored in reverse.

```php
// Even deeper — 3 levels (the default max)
protected $cascadeRelationships = ['posts.comments.replies'];
```

### `BelongsToMany` (Many-to-Many)

Pivot records are **detached** (removed from the pivot table) when a parent is soft-deleted. Since pivot data is permanently lost, re-attaching on restore is not supported.

```php
protected $cascadeRelationships = ['roles', 'tags'];

public function roles(): BelongsToMany
{
    return $this->belongsToMany(Role::class);
}
```

> To keep pivot records on delete, set `detach_belongs_to_many => false` in the config or set `protected $cascadeDetachBelongsToMany = false` on the model.

---

## 🔄 Smart Restore Behaviour

The restore system uses a **database tracking table** (`cascade_deletions`) — not timestamp comparisons — to decide which children to restore. This makes it:

- **Precise:** A child deleted independently before the parent's cascade will never be restored by the parent's restore.
- **Multi-parent safe:** If two parents both cascade-deleted a shared child, that child will only be restored once **both** parents are restored.
- **Stale-safe:** If a child was force-deleted or independently restored after the cascade, stale tracking records are cleaned up automatically.

### Example: Independent Deletion Not Restored

```php
$post->delete();   // Post deleted independently, no tracking record

$user->delete();   // User cascade-deletes only other posts (post above is already trashed)
$user->restore();  // User restored — independently-deleted post stays deleted ✅
```

### Example: Multi-Parent Guard

```php
$team->delete();  // Team cascade-deletes Post, tracking record created
$user->delete();  // User tries to cascade-delete Post — already trashed, no tracking entry

$user->restore(); // Post stays deleted (Team still holds it)
$team->restore(); // Post now restored ✅
```

---

## ⚙️ Configuration Reference

After publishing, edit `config/cascading-soft-deletes.php`:

```php
return [

    // The database table used to track cascade deletions
    'table_name' => 'cascade_deletions',

    // Maximum allowed nesting depth for relationship paths (default: 3)
    // e.g. 'posts.comments.replies' = depth 3
    'max_nesting_level' => 3,

    // Wrap cascade operations in a DB transaction (default: true)
    'use_transaction' => true,

    // Re-throw exceptions encountered during cascade (default: true)
    // Set to false to silently log errors instead
    'throw_on_error' => true,

    // Roll back the transaction if an error occurs (default: true)
    'rollback_on_error' => true,

    // Cascade restores to children when a parent is restored (default: true)
    'cascade_on_restore' => true,

    // Detach BelongsToMany pivot records on delete (default: true)
    'detach_belongs_to_many' => true,

];
```

---

## 🎛️ Per-Model Configuration

Every config option can be overridden on a per-model basis using protected properties. Model-level settings always take precedence over the global config.

```php
class User extends Model
{
    use SoftDeletes, CascadesSoftDeletes;

    protected $cascadeRelationships = ['posts', 'profile'];

    // Override defaults per model:
    protected $cascadeNestingLimit       = 5;     // Allow deeper nesting for this model
    protected $cascadeUseTransaction     = true;  // Use transactions
    protected $cascadeThrowOnError       = false; // Suppress errors silently
    protected $cascadeRollbackOnError    = true;  // Roll back on error
    protected $cascadeOnRestore          = true;  // Cascade restores
    protected $cascadeDetachBelongsToMany = false; // Keep pivot records on delete
}
```

### Using a Method Instead of a Property

For dynamic relationship lists, you may implement `cascadeRelationships()` as a method:

```php
public function cascadeRelationships(): array
{
    return ['posts', 'profile'];
}
```

---

## 🔢 Nesting Limit

The default maximum nesting depth is **3 levels** (e.g. `user → post → comment → reply`). You can configure this globally or per-model.

If a relationship path exceeds the limit, a `NestingLimitExceededException` is thrown at delete time:

```php
// Global
'max_nesting_level' => 5,

// Per model
protected $cascadeNestingLimit = 5;
```

---

## 💥 Force Delete Cascade

When a model is **force-deleted**, the cascade hard-deletes all children (using `forceDelete()` if available, otherwise `delete()`). All tracking records are purged:

```php
$user->forceDelete(); // Permanently removes user + all cascade children
```

---

## 🗃️ The `CascadeDeletion` Model

The `cascade_deletions` table is exposed as a first-class Eloquent model for inspection or debugging:

```php
use Krishnaraj\LaravelCascadingSoftDeletes\Models\CascadeDeletion;

// Find all cascade records where a User is the parent
CascadeDeletion::forParent($user)->get();

// Find all cascade records where a Post is the child
CascadeDeletion::forChild($post)->get();
```

Each record contains:

| Column | Description |
|---|---|
| `parent_type` | Fully-qualified class name of the parent model |
| `parent_id` | Primary key of the parent |
| `child_type` | Fully-qualified class name of the child model |
| `child_id` | Primary key of the child |
| `created_at` | When the cascade-delete occurred |

---

## 🛡️ Error Handling

### Throwing exceptions (default)

By default (`throw_on_error = true`), any exception during cascading is logged **and** re-thrown, allowing your application to handle it:

```php
try {
    $user->delete();
} catch (\Throwable $e) {
    // handle
}
```

### Suppressing exceptions

Set `throw_on_error = false` (globally or per-model) to silently log errors via Laravel's `Log::error()` without interrupting execution:

```php
protected $cascadeThrowOnError = false;
```

### Transaction rollback

When `use_transaction = true` and `rollback_on_error = true` (both default), any error during cascading will roll back the entire delete operation — keeping your database consistent:

```php
// If 'nonExistentRelation' throws, the whole cascade is rolled back
protected $cascadeRelationships = ['posts', 'nonExistentRelation'];
protected $cascadeRollbackOnError = true;
```

---

## 🧪 Testing

```bash
composer test
```

The test suite covers:
- Basic cascade soft-delete and restore (HasOne, HasMany, nested)
- Tracking record creation and cleanup
- Independent deletion not restored
- Multi-parent cascade guard
- BelongsToMany detach and config flags
- Force delete cascade and tracking cleanup
- String/UUID primary keys
- Custom nesting limits
- Exception handling and transaction rollback

---

## 📦 Package Structure

```
src/
├── CascadingSoftDeletesServiceProvider.php   # Auto-discovery service provider
├── Traits/
│   └── CascadesSoftDeletes.php              # Attach to your Eloquent models
├── Services/
│   └── CascadeService.php                   # Core cascade logic
├── Models/
│   └── CascadeDeletion.php                  # Eloquent model for tracking table
└── Exceptions/
    ├── InvalidRelationshipException.php
    └── NestingLimitExceededException.php
config/
└── cascading-soft-deletes.php               # Package configuration
database/migrations/
└── create_cascade_deletions_table.php       # Tracking table migration
```

---

## 📄 License

MIT © [Krishnaraj](mailto:gkrishnaraj2002@gmail.com)
