<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cascade Deletions Table Name
    |--------------------------------------------------------------------------
    |
    | The name of the database table used to track cascade deletion records.
    | This table stores the parent-child relationship log so that the package
    | can accurately determine which children to restore when a parent is
    | restored, avoiding unintended restorations of independently deleted
    | records or records still held by other soft-deleted parents.
    |
    */
    'table_name' => 'cascade_deletions',

    /*
    |--------------------------------------------------------------------------
    | Maximum Nesting Level
    |--------------------------------------------------------------------------
    |
    | This value defines the default maximum nesting level allowed when cascading
    | soft deletes. Any relationship path defined in a model that exceeds this
    | limit will throw a NestingLimitExceededException to prevent unintended
    | deep cascades or infinite loops.
    |
    */
    'max_nesting_level' => 3,

    /*
    |--------------------------------------------------------------------------
    | Use Database Transaction
    |--------------------------------------------------------------------------
    |
    | When set to true, all cascade delete and restore operations will be
    | wrapped in a database transaction to ensure database integrity.
    | Note: It will only start a transaction if one is not already running.
    |
    */
    'use_transaction' => true,

    /*
    |--------------------------------------------------------------------------
    | Throw Exceptions/Errors
    |--------------------------------------------------------------------------
    |
    | When set to true, any exception or error encountered during cascading
    | will be re-thrown. When set to false, they will be caught and ignored.
    |
    */
    'throw_on_error' => true,

    /*
    |--------------------------------------------------------------------------
    | Rollback Transaction On Error
    |--------------------------------------------------------------------------
    |
    | When set to true and a transaction is used, the transaction will be
    | rolled back if any error occurs. When false, the transaction will not
    | be rolled back (and successful deletions will be committed).
    |
    */
    'rollback_on_error' => true,

    /*
    |--------------------------------------------------------------------------
    | Cascade Restores
    |--------------------------------------------------------------------------
    |
    | When set to true, restoring a parent model will automatically restore
    | any child models that were cascade-deleted with it. When set to false,
    | child models will remain soft-deleted.
    |
    */
    'cascade_on_restore' => true,

    /*
    |--------------------------------------------------------------------------
    | Detaching BelongsToMany Relationships
    |--------------------------------------------------------------------------
    |
    | When set to true, deleting a parent model will automatically detach
    | its BelongsToMany (many-to-many) pivot relationships.
    | Note: This only detaches the pivot records during deletion; the pivot
    | records are NOT re-attached when the parent is restored.
    |
    */
    'detach_belongs_to_many' => true,
];

