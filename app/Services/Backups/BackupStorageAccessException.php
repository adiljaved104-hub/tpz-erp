<?php

namespace App\Services\Backups;

use RuntimeException;

class BackupStorageAccessException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A configured storage file or directory is unreadable by the backup account.');
    }
}
