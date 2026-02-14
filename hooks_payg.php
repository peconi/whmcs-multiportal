<?php
/**
 * PAYG Billing Hooks for Multiportal Module
 *
 * Previously contained an InvoiceCreation hook that inserted PAYG charges
 * directly onto invoices. This has been replaced by:
 *
 *   1. bill-usage.php — Standalone cron script that incrementally creates
 *      billable items in tblbillableitems. WHMCS natively collects these
 *      when generating invoices.
 *
 *   2. multiportal_RebillPAYGUsage() — Admin button that deletes uninvoiced
 *      items and regenerates at current rates.
 *
 * No hooks are needed — WHMCS handles billable item → invoice collection
 * automatically.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}
