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
    | Archive password
    |--------------------------------------------------------------------------
    |
    | The archive holds the entire database — every participant's date of birth
    | and mobile number, refund payees' bank account numbers, every password
    | hash — plus the whole private disk: certificates, payment proofs, agency
    | documents. It is the single most sensitive object this system produces,
    | and the whole point of the note above is that it should leave the machine.
    | An unencrypted archive on a network share or a synced folder is that data
    | in the clear on every device the folder reaches.
    |
    | So set BACKUP_PASSWORD. With one, the archive is written with AES-256 and
    | `tims:backup` refuses to run without it in production — silently writing a
    | plaintext archive is the failure worth preventing, because nobody notices
    | until the archive is already somewhere it should not be.
    |
    | AES-256 zip rather than an application-level envelope, deliberately: any
    | ordinary tool (7-Zip, WinZip, macOS with a helper) opens it with the
    | password. A disaster recovery where the archive can only be read by the
    | application that is currently on fire is not a recovery.
    |
    | Store the password somewhere that is NOT this server. An archive encrypted
    | with a key that only exists on the machine the archive is protecting
    | against losing is a locked box with the key inside it.
    |
    */

    'password' => env('BACKUP_PASSWORD'),

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

    /*
     * The client binary, for tims:restore. Found the same way as mysqldump —
     * PATH first, then the XAMPP location — so it only needs setting on a
     * server where neither applies.
     */

    'mysql' => env('MYSQL_PATH'),

];
