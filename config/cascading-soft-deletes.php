<?php

return [
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
];
