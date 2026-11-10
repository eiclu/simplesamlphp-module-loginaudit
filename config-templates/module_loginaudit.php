<?php

/**
 * Configuration for the loginaudit module.
 * Copy this file to your config/ folder. Every option is optional.
 */

$config = [
    // Folder containing the log files. Default: 'loggingdir' from config.php.
    //'logdir' => '/var/www/simplesaml/log',

    // Name of the active log file. Default: 'logging.logfile' from config.php.
    // Rotated files are expected as <name>-*.log, e.g. simplesamlphp-2026-11-01.log.
    //'logfile' => 'simplesamlphp.log',

    // SQLite file for cached IP locations. Default: <tempdir>/loginaudit_ipcache.sqlite
    //'cache_db' => '/var/www/simplesaml/tmp/loginaudit_ipcache.sqlite',

    // Look up unknown IPs at ip-api.com. Set to false to only use the cache.
    'geo_lookup' => true,

    // Log rotation (call from a webcron):
    //   https://<host>/simplesaml/module.php/loginaudit/rotate?token=<rotate_token>
    // Copies the active log to <name>-YYYY-MM-DD.log, truncates it, and deletes archives
    // older than retention_days. The endpoint is disabled while rotate_token is empty.
    // Generate a token with: php -r 'echo bin2hex(random_bytes(24));'
    'rotate_token' => '',

    // Days to keep rotated logs.
    'retention_days' => 120,

    // Logins shown per page.
    'page_size' => 50,
];
