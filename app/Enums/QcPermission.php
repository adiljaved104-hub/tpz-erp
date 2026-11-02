<?php

namespace App\Enums;

enum QcPermission: string
{
    case View = 'qc.view';
    case Start = 'qc.start';
    case Update = 'qc.update';
    case Complete = 'qc.complete';
    case PrintCertificate = 'qc.print_certificate';
    case PrintLabel = 'qc.print_label';
    case ViewCustomerEvidence = 'qc.view_customer_evidence';
    case ViewAll = 'qc.view_all';
    case Reopen = 'qc.reopen';
    case ManageTemplates = 'qc.manage_templates';
    case ViewInternalEvidence = 'qc.view_internal_evidence';
    case ViewOrderAssignments = 'qc.view_order_assignments';
    case AssignOrderDevice = 'qc.assign_order_device';
    case ReleaseOrderAssignment = 'qc.release_order_assignment';
    case ViewDispatchQueue = 'qc.view_dispatch_queue';
    case ScanDispatch = 'qc.scan_dispatch';
    case ShipDispatch = 'qc.ship_dispatch';
    case FocusedWorkspace = 'qc.focused_workspace';
    case AssignMarketplaceTransferDevice = 'qc.assign_marketplace_transfer_device';
    case ReleaseMarketplaceTransferDevice = 'qc.release_marketplace_transfer_device';
    case ViewMarketplaceTransferAssignments = 'qc.view_marketplace_transfer_assignments';
    case ViewReturnEvidence = 'qc.view_return_evidence';
    case RecordReturnEvidence = 'qc.record_return_evidence';
}
