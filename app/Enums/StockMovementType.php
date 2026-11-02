<?php

namespace App\Enums;

enum StockMovementType: string
{
    case OpeningStock = 'opening_stock';
    case Reservation = 'reservation';
    case ReservationRelease = 'reservation_release';
    case MarkDamaged = 'mark_damaged';
    case RestoreDamaged = 'restore_damaged';
    case PurchaseReceipt = 'purchase_receipt';
    case RenewedQcIntake = 'renewed_qc_intake';
    case RenewedQcRelease = 'renewed_qc_release';
    case RenewedQcIntakeAdjustment = 'renewed_qc_intake_adjustment';
    case PurchaseReceiptCorrection = 'purchase_receipt_correction';
    case InventoryAdjustment = 'inventory_adjustment';
    case QuotationSourcingReceipt = 'quotation_sourcing_receipt';
    case OrderFulfillment = 'order_fulfillment';
    case TransferDispatch = 'transfer_dispatch';
    case TransferReceipt = 'transfer_receipt';
    case TransferReturnedToSource = 'transfer_returned_to_source';
    case CustomerReturnCompanyReceipt = 'customer_return_company_receipt';
    case CustomerReturnReceiptCorrection = 'customer_return_receipt_correction';
    case CustomerReturnQcSellable = 'customer_return_qc_sellable';
    case CustomerReturnQcDamaged = 'customer_return_qc_damaged';
    case MarketplaceReturnSellable = 'marketplace_return_sellable';
    case MarketplaceReturnNonSellable = 'marketplace_return_non_sellable';
    case MarketplaceReturnRemovalDispatch = 'marketplace_return_removal_dispatch';
    case MarketplaceReturnCompanyReceipt = 'marketplace_return_company_receipt';
    case WarrantyRetainedDamaged = 'warranty_retained_damaged';
    case WarrantyRepairRestored = 'warranty_repair_restored';
    case RepairClassificationOut = 'repair_classification_out';
    case RepairClassificationIn = 'repair_classification_in';
    case UpgradeComponentReservation = 'upgrade_component_reservation';
    case UpgradeComponentReservationRelease = 'upgrade_component_reservation_release';
    case UpgradeComponentInstall = 'upgrade_component_install';
    case UpgradeComponentRecovery = 'upgrade_component_recovery';
    case UpgradeComponentRecoveryDamaged = 'upgrade_component_recovery_damaged';

    public function label(): string
    {
        return match ($this) {
            self::OpeningStock => 'Opening Stock',
            self::Reservation => 'Reservation',
            self::ReservationRelease => 'Reservation Release',
            self::MarkDamaged => 'Marked Damaged',
            self::RestoreDamaged => 'Restored from Damaged',
            self::PurchaseReceipt => 'Goods Receipt (GRN)',
            self::RenewedQcIntake => 'Renewed Stock QC Intake',
            self::RenewedQcRelease => 'Renewed Stock QC Release',
            self::RenewedQcIntakeAdjustment => 'Renewed QC Intake Adjustment',
            self::PurchaseReceiptCorrection => 'Purchase Receipt Correction',
            self::InventoryAdjustment => 'Inventory Adjustment',
            self::QuotationSourcingReceipt => 'Quotation Sourcing Receipt',
            self::OrderFulfillment => 'Order Fulfilment',
            self::TransferDispatch => 'Transfer Dispatch',
            self::TransferReceipt => 'Transfer Receipt',
            self::TransferReturnedToSource => 'Transfer Returned to Source',
            self::CustomerReturnCompanyReceipt => 'Customer Return Company Receipt',
            self::CustomerReturnReceiptCorrection => 'Customer Return Receipt Correction',
            self::CustomerReturnQcSellable => 'Customer Return QC Sellable',
            self::CustomerReturnQcDamaged => 'Customer Return QC Damaged',
            self::MarketplaceReturnSellable => 'Marketplace Return Sellable',
            self::MarketplaceReturnNonSellable => 'Marketplace Return Non-Sellable',
            self::MarketplaceReturnRemovalDispatch => 'Marketplace Return Removal Dispatch',
            self::MarketplaceReturnCompanyReceipt => 'Marketplace Return Company Receipt',
            self::WarrantyRetainedDamaged => 'Warranty Retained as Damaged',
            self::WarrantyRepairRestored => 'Warranty Repair Restored',
            self::RepairClassificationOut => 'Repair Classification — Damaged Source',
            self::RepairClassificationIn => 'Repair Classification — Sellable Destination',
            self::UpgradeComponentReservation => 'Upgrade Component Reservation',
            self::UpgradeComponentReservationRelease => 'Upgrade Component Reservation Release',
            self::UpgradeComponentInstall => 'Upgrade Component Installed',
            self::UpgradeComponentRecovery => 'Upgrade Component Recovered',
            self::UpgradeComponentRecoveryDamaged => 'Upgrade Component Recovered as Damaged',
        };
    }
}
