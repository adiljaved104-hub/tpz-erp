<?php

namespace App\Enums;

enum AccountingJournalStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
}
