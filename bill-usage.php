<?php
/**
 * PAYG Incremental Billing — Standalone Cron Script
 *
 * Fetches usage from MultiPortal API for all active PAYG services,
 * calculates charges from each VDC's Last Sync Date to now(), and
 * creates billable items in WHMCS. Designed to run at any interval
 * (e.g. every 5 minutes, hourly, daily) via system crontab:
 *
 *   * /5 * * * * php -q /var/www/html/modules/servers/multiportal/bill-usage.php 2>&1
 *
 * Groups VDCs by MultiPortal server to minimize API authentication calls.
 * Uses include_history=true for per-entry timestamped data, filtering
 * each VDC's historical entries by its own Last Sync Date.
 */

// ── Bootstrap WHMCS ──────────────────────────────────────────────────
// Try the standard WHMCS init.php relative to module directory.
// Module is at: {WHMCS_ROOT}/modules/servers/multiportal/
$whmcsRoot = realpath(__DIR__ . '/../../..');
$initFile = $whmcsRoot . '/init.php';

if (!file_exists($initFile)) {
    // Fallback: try the crons bootstrap (ionCube encrypted)
    $initFile = '/var/www/crons/bootstrap.php';
}

if (!file_exists($initFile)) {
    fwrite(STDERR, "ERROR: Cannot find WHMCS init.php or bootstrap.php\n");
    exit(1);
}

require_once $initFile;

use Illuminate\Database\Capsule\Manager as Capsule;

// Load module libraries
require_once __DIR__ . '/lib/ApiClient.php';
require_once __DIR__ . '/lib/VDCManager.php';
require_once __DIR__ . '/lib/CustomFieldFunctions.php';
require_once __DIR__ . '/multiportal.php';

// ── Lock file to prevent concurrent runs ─────────────────────────────
$lockFile = '/tmp/mp-bill-usage.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    output("Another instance is already running. Exiting.");
    exit(0);
}

// ── Helper: output to both console and WHMCS activity log ────────────
function output($msg) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . "\n";
}

// ── Phase 1: Discover all active PAYG services ──────────────────────
output("MultiPortal PAYG Billing: Starting");

$services = Capsule::table('tblhosting as h')
    ->join('tblservers as s', 'h.server', '=', 's.id')
    ->join('tblproducts as p', 'h.packageid', '=', 'p.id')
    ->where('s.type', 'multiportal')
    ->where('h.domainstatus', 'Active')
    ->select([
        'h.id as service_id', 'h.userid', 'h.packageid', 'h.regdate', 'h.domain',
        'h.server as server_id',
        's.username as server_username', 's.password as server_password',
        's.hostname as server_hostname', 's.secure as server_secure',
        'p.configoption1',  // API URL
        'p.configoption4',  // CPU rate
        'p.configoption5',  // Memory rate
        'p.configoption6',  // Storage rate
        'p.configoption7',  // Allocation type
        'p.configoption8',  // Backup storage rate
        'p.configoption9',  // ISO storage rate
    ])
    ->get();

// Filter to PAYG only and look up VDC UUID + Last Sync per service
$paygServices = [];
foreach ($services as $svc) {
    // Check allocation type from configoption7
    if (empty($svc->configoption7) || stripos($svc->configoption7, 'pay as you go') === false) {
        continue;
    }

    // Look up VDC UUID
    $vdcId = getProductCustomFieldValue($svc->service_id, 'VDC UUID');
    if (empty($vdcId)) {
        continue;
    }

    // Look up Last Usage Sync
    $lastSync = getProductCustomFieldValue($svc->service_id, 'Last Usage Sync');
    if (!empty($lastSync)) {
        $lastSyncDate = new DateTime($lastSync);
    } elseif ($svc->regdate && $svc->regdate !== '0000-00-00') {
        $lastSyncDate = new DateTime($svc->regdate);
    } else {
        $lastSyncDate = new DateTime('first day of this month midnight');
    }

    $svc->vdc_id = $vdcId;
    $svc->last_sync_date = $lastSyncDate;
    $paygServices[] = $svc;
}

if (empty($paygServices)) {
    output("No active PAYG services found. Done.");
    logActivity("MultiPortal PAYG Billing: No active PAYG services found.");
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    exit(0);
}

output("Found " . count($paygServices) . " active PAYG service(s)");

// ── Phase 2: Group by server ────────────────────────────────────────
$serverGroups = [];
foreach ($paygServices as $svc) {
    $serverGroups[$svc->server_id][] = $svc;
}

$billingEnd = new DateTime();
$totalItemsCreated = 0;
$totalChargeAll = 0.0;
$totalServicesProcessed = 0;
$errors = [];

// ── Phase 3: Process each server group ──────────────────────────────
foreach ($serverGroups as $serverId => $groupServices) {
    $firstSvc = $groupServices[0];

    // Build API URL
    $apiUrl = !empty($firstSvc->configoption1) ? trim($firstSvc->configoption1) : '';
    if (empty($apiUrl) && !empty($firstSvc->server_hostname)) {
        $protocol = (!empty($firstSvc->server_secure) && $firstSvc->server_secure === 'on')
            ? 'https' : 'http';
        $apiUrl = $protocol . '://' . $firstSvc->server_hostname . '/api/v1';
    }
    if (empty($apiUrl)) {
        $msg = "Server ID {$serverId}: No API URL available. Skipping "
            . count($groupServices) . " service(s).";
        output("WARNING: " . $msg);
        $errors[] = $msg;
        continue;
    }

    // Decrypt server password (WHMCS stores it encrypted)
    $serverPassword = $firstSvc->server_password;
    if (function_exists('decrypt')) {
        $serverPassword = decrypt($serverPassword);
    }

    // Authenticate once for this server
    try {
        $api = new ApiClient(
            $firstSvc->server_username,
            $serverPassword,
            $apiUrl,
            false // SSL verify — matches initiateAPI() default
        );
        $vdcMgr = new VDCManager($api);
    } catch (Exception $e) {
        $msg = "Server ID {$serverId}: Auth failed — " . $e->getMessage()
            . ". Skipping " . count($groupServices) . " service(s).";
        output("ERROR: " . $msg);
        logActivity("MultiPortal PAYG Billing: " . $msg);
        $errors[] = $msg;
        continue;
    }

    output("  Server {$serverId} ({$apiUrl}): " . count($groupServices) . " VDC(s)");

    // Process each VDC in this server group
    foreach ($groupServices as $svc) {
        try {
            $lastSyncDate = $svc->last_sync_date;
            $todayStr = date('Y-m-d');

            // ── Determine $todayStart by checking for existing live item ──
            $liveItem = Capsule::table('tblbillableitems')
                ->where('userid', $svc->userid)
                ->where('invoicecount', 0)
                ->where('duedate', $todayStr)
                ->where('description', 'LIKE', '%(' . $svc->vdc_id . ')%')
                ->first();

            if ($liveItem && preg_match('/Period: (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $liveItem->description, $m)) {
                // Live item exists — use its period start (midnight normally, invoice-time post-invoice)
                $todayStart = new DateTime($m[1]);
            } else {
                // No live item (first run of day, or post-invoice first run) — use Last Usage Sync
                $todayStart = clone $lastSyncDate;
            }

            // API fetch range: from the earlier of lastSyncDate and todayStart
            $fetchStart = ($lastSyncDate < $todayStart) ? clone $lastSyncDate : clone $todayStart;

            // Fetch usage with historical data
            $usageParams = [
                'date_range'      => $fetchStart->format('Y/m/d H:i:s')
                    . ' - ' . $billingEnd->format('Y/m/d H:i:s'),
                'include_history' => 'true',
            ];
            $usage = $vdcMgr->getVDCUsage($svc->vdc_id, $usageParams);

            if (!$usage || !is_array($usage)) {
                output("    VDC {$svc->vdc_id} (svc={$svc->service_id}): No usage data returned");
                continue;
            }

            // Load rates for this product
            $rates = multiportal_loadPAYGRates($svc);

            // VDC display name
            $vdcName = !empty($svc->domain) ? $svc->domain : ('VDC-' . $svc->service_id);

            // Process usage and create/update billable items
            $result = multiportal_processVDCUsage(
                $usage, $svc->vdc_id, $vdcName,
                $lastSyncDate, $billingEnd,
                $rates, $svc->service_id, $svc->userid,
                $todayStart
            );

            // Update Last Usage Sync for this VDC
            $productId = $svc->packageid;
            $syncField = Capsule::table('tblcustomfields')
                ->where('type', 'product')
                ->where('fieldname', 'Last Usage Sync')
                ->where('relid', $productId)
                ->first();
            if (!$syncField) {
                $syncField = Capsule::table('tblcustomfields')
                    ->where('type', 'product')
                    ->where('fieldname', 'Last Usage Sync')
                    ->where('relid', 0)
                    ->first();
            }
            if ($syncField) {
                Capsule::table('tblcustomfieldsvalues')->updateOrInsert(
                    ['fieldid' => $syncField->id, 'relid' => $svc->service_id],
                    ['value'   => $billingEnd->format('Y-m-d H:i:s')]
                );
            }

            $totalItemsCreated += $result['items_created'];
            $totalChargeAll += $result['total_charge'];
            $totalServicesProcessed++;

            $chargeStr = $result['total_charge'] > 0
                ? '$' . number_format($result['total_charge'], 2) . " ({$result['items_created']} items)"
                : 'no charges';
            output("    VDC {$svc->vdc_id} (svc={$svc->service_id}): {$chargeStr}");

        } catch (Exception $e) {
            $msg = "VDC {$svc->vdc_id} (svc={$svc->service_id}): " . $e->getMessage();
            output("    ERROR: " . $msg);
            logActivity("MultiPortal PAYG Billing Error: " . $msg);
            $errors[] = $msg;
        }
    }
}

// ── Summary ─────────────────────────────────────────────────────────
$summary = sprintf(
    "MultiPortal PAYG Billing: %d service(s) processed, %d billable item(s) created, total $%.2f",
    $totalServicesProcessed, $totalItemsCreated, $totalChargeAll
);
if (!empty($errors)) {
    $summary .= ". Errors: " . count($errors);
}
output($summary);
logActivity($summary);

// ── Cleanup ─────────────────────────────────────────────────────────
flock($lockFp, LOCK_UN);
fclose($lockFp);
