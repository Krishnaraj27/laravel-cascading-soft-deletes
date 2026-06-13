<?php

/**
 * Migration: Create the cascade_deletions tracking table.
 *
 * This migration creates a table that records which child models were
 * soft-deleted as part of a cascading soft-delete triggered by a parent model.
 *
 * The table acts as an audit trail so that the package can accurately determine
 * which children should be restored when a parent is restored — avoiding the
 * unintentional restoration of children that were independently soft-deleted
 * before (or after) the parent's cascade operation.
 *
 * The table name is configurable via the `cascading-soft-deletes.table_name`
 * config key and defaults to `cascade_deletions`.
 *
 * @see \Krishnaraj\LaravelCascadingSoftDeletes\Models\CascadeDeletion
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Get the configured table name for cascade deletion records.
     *
     * Reads from the package config so that consuming applications can
     * customise the table name without modifying the migration itself.
     *
     * @return string
     */
    protected function tableName(): string
    {
        return config('cascading-soft-deletes.table_name', 'cascade_deletions');
    }

    /**
     * Run the migrations.
     *
     * Creates the cascade_deletions table with the following columns:
     *
     *  - `id`          – Auto-incrementing primary key.
     *  - `parent_type` – The fully-qualified class name (morph type) of the
     *                     parent model that triggered the cascade delete
     *                     (e.g. `App\Models\User`).
     *  - `parent_id`   – The primary key value of the parent model.
     *  - `child_type`  – The fully-qualified class name (morph type) of the
     *                     child model that was soft-deleted by the cascade.
     *  - `child_id`    – The primary key value of the child model.
     *  - `created_at`  – Timestamp indicating when the cascade deletion
     *                     record was created.
     *
     * Two composite indexes are added:
     *  - `cascade_del_parent_idx` on (parent_type, parent_id) – speeds up
     *    lookups when restoring a parent and finding all its cascaded children.
     *  - `cascade_del_child_idx` on (child_type, child_id) – speeds up
     *    lookups when checking whether a specific child was cascade-deleted
     *    (e.g. to prevent double-restoring).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create($this->tableName(), function (Blueprint $table) {
            // Primary key
            $table->bigIncrements('id');

            // Parent morph columns — identify the model whose deletion
            // triggered the cascade.
            $table->string('parent_type')->comment('Morph type of the parent model');
            $table->string('parent_id')->comment('Primary key of the parent model');

            // Child morph columns — identify the model that was soft-deleted
            // as a result of the cascade.
            $table->string('child_type')->comment('Morph type of the child model');
            $table->string('child_id')->comment('Primary key of the child model');

            // Only `created_at` is needed; there is no concept of "updating"
            // a cascade deletion record.
            $table->timestamp('created_at')->nullable();

            // Composite index for fast parent-based lookups (restore flow).
            $table->index(['parent_type', 'parent_id'], 'cascade_del_parent_idx');

            // Composite index for fast child-based lookups (existence checks).
            $table->index(['child_type', 'child_id'], 'cascade_del_child_idx');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the cascade_deletions table entirely.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists($this->tableName());
    }
};
