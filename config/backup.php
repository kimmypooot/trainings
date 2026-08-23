<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where archives are written
    |--------------------------------------------------------------------------
    |
    | Deliberately outside storage/app/private, which is the directory being
    | archived — writing backups into the backup source grows each archive by
    | the last one. Point this at a mapped network drive, a synced folder, or
    | anything that leaves the machine: an archive sitting on the same disk as
    | the data protects against a bad migration or a deleted file, but not
    | against the disk. Getting it off the host is an ops step this command
    | cannot do for you.
    |
    */

    'path' => env('BACKUP_PATH', storage_path('backups')),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Archives older than this many days are pruned at the end of a successful
    | run. Pruning only ever happens after a new archive lands, so a broken
    | backup run cannot quietly delete the last good one.
    |
    */

    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | mysqldump
    |--------------------------------------------------------------------------
    |
    | Left null so the command can look for it: PATH first, then the XAMPP
    | location this project is developed against. Set it explicitly on a server
    | where neither applies.
    |
    */

    'mysqldump' => env('MYSQLDUMP_PATH'),

];
