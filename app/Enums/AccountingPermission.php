<?php

namespace App\Enums;

enum AccountingPermission: string
{
    case View = 'accounting.view';
    case ViewChart = 'accounting.view_chart';
    case ViewJournals = 'accounting.view_journals';
    case ViewPeriods = 'accounting.view_periods';
    case ViewGeneralLedger = 'accounting.view_general_ledger';
    case ManageChart = 'accounting.manage_chart';
    case ManagePeriods = 'accounting.manage_periods';
    case CreateJournal = 'accounting.create_journal';
    case PostJournal = 'accounting.post_journal';
    case ReverseJournal = 'accounting.reverse_journal';
    case ViewTrialBalance = 'accounting.view_trial_balance';
    case Export = 'accounting.export';
}
