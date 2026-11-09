<?php

namespace App\Enums;

enum AccountingPermission: string
{
    case View = 'accounting.view';
    case ManageChart = 'accounting.manage_chart';
    case ManagePeriods = 'accounting.manage_periods';
    case CreateJournal = 'accounting.create_journal';
    case PostJournal = 'accounting.post_journal';
    case ReverseJournal = 'accounting.reverse_journal';
    case ViewTrialBalance = 'accounting.view_trial_balance';
    case Export = 'accounting.export';
}
