<?php

return [
    /*
     * Fail-closed. Must remain FALSE until the Owner, UAE VAT adviser and
     * accounting team approve the actual posting, return, and e-invoice links.
     */
    'issuance_enabled' => (bool) env('TPZ_TAX_INVOICE_ADJUSTMENT_ISSUANCE_ENABLED', false),

    /*
     * Actual electronic invoice / credit note transmission (where mandated)
     * must be integrated and approved before enabling production issuance.
     */
    'electronic_delivery_ready' => (bool) env('TPZ_TAX_ADJUSTMENT_EINVOICE_READY', false),
];
