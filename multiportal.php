<?php

require_once __DIR__ . '/lib/ApiClient.php';
require_once __DIR__ . '/lib/ResellerManager.php';
require_once __DIR__ . '/lib/TenantManager.php';
require_once __DIR__ . '/lib/VDCManager.php';
require_once __DIR__ . '/lib/CustomFieldFunctions.php';
require_once __DIR__ . '/lib/ModuleConfiguration.php';

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Module metadata - required for WHMCS server modules
 */
function multiportal_MetaData()
{
    return array(
        'DisplayName' => 'MultiPortal',
        'APIVersion' => '1.0',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '80',
        'DefaultSSLPort' => '443',
        'ServiceSingleSignOnLabel' => 'Access MultiPortal',
        'AdminSingleSignOnLabel' => 'Access MultiPortal Admin',
    );
}

/**
 * Generate a secure password
 */
function generateSecurePassword($length = 16)
{
    // Ensure minimum length of 8
    $length = max(8, $length);
    
    // Character sets
    $lowercase = 'abcdefghijklmnopqrstuvwxyz';
    $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $numbers = '0123456789';
    $symbols = '!@#$%^&*';
    
    // Ensure at least one of each required character type
    $password = '';
    $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
    $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
    $password .= $numbers[random_int(0, strlen($numbers) - 1)];
    $password .= $symbols[random_int(0, strlen($symbols) - 1)];
    
    // Fill the rest of the password
    $allChars = $lowercase . $uppercase . $numbers . $symbols;
    $remaining = $length - 4;
    
    for ($i = 0; $i < $remaining; $i++) {
        $password .= $allChars[random_int(0, strlen($allChars) - 1)];
    }
    
    // Shuffle the password to avoid predictable patterns
    $password = str_shuffle($password);
    
    return $password;
}

/**
 * Ensure per-product custom fields (Tenant UUID, URL) exist for a specific product.
 *
 * WHMCS only shows product custom fields on the service admin page when
 * relid matches the product ID. This creates per-product field definitions
 * so they appear alongside VDC UUID in the admin UI.
 */
function ensureProductCustomFields($productId)
{
    if (empty($productId)) {
        return;
    }

    $fields = [
        [
            'fieldname' => 'Tenant UUID',
            'description' => 'MultiPortal tenant identifier for this service',
            'sortorder' => 1,
        ],
        [
            'fieldname' => 'URL',
            'description' => 'MultiPortal portal URL for this service',
            'sortorder' => 2,
        ],
        [
            'fieldname' => 'Last Usage Sync',
            'description' => 'Last PAYG usage sync date',
            'sortorder' => 3,
        ],
    ];

    foreach ($fields as $field) {
        $existing = Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->where('fieldname', $field['fieldname'])
            ->where('relid', $productId)
            ->first();

        if (!$existing) {
            Capsule::table('tblcustomfields')->insert([
                'type' => 'product',
                'relid' => $productId,
                'fieldname' => $field['fieldname'],
                'fieldtype' => 'text',
                'description' => $field['description'],
                'fieldoptions' => '',
                'regexpr' => '',
                'adminonly' => 'on',
                'required' => '',
                'showorder' => '',
                'showinvoice' => '',
                'sortorder' => $field['sortorder'],
            ]);
        }
    }
}

/**
 * Ensure custom fields exist - create if missing
 */
function ensureCustomFieldsExist()
{
    $created = [];

    try {
        // Only Tenant UUID remains at client level for backwards-compat fallback.
        // Username/Password/URL are now stored per-service in product custom fields.
        $clientFields = [
            [
                'fieldname' => 'MultiPortal Tenant UUID',
                'fieldtype' => 'text',
                'description' => 'Stores the MultiPortal tenant identifier',
                'showorder' => 'on',
            ],
        ];

        foreach ($clientFields as $fieldConfig) {
            $field = Capsule::table('tblcustomfields')
                ->where('type', 'client')
                ->where('fieldname', $fieldConfig['fieldname'])
                ->first();

            if (!$field) {
                Capsule::table('tblcustomfields')->insert([
                    'type' => 'client',
                    'fieldname' => $fieldConfig['fieldname'],
                    'fieldtype' => $fieldConfig['fieldtype'],
                    'description' => $fieldConfig['description'],
                    'fieldoptions' => '',
                    'regexpr' => '',
                    'adminonly' => 'on',
                    'required' => '',
                    'showorder' => $fieldConfig['showorder'] ?? '',
                    'showinvoice' => '',
                    'sortorder' => $fieldConfig['sortorder'] ?? 0
                ]);
                $created[] = 'Client field: ' . $fieldConfig['fieldname'];
            }
        }
    } catch (Exception $e) {
        throw new Exception('Error creating client fields: ' . $e->getMessage());
    }

    try {
        // Check and create VDC UUID field
        $vdcField = Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->where('fieldname', 'VDC UUID')
            ->first();

        if (!$vdcField) {
            // This needs to be created PER PRODUCT in the Setup Wizard, not here
            // We'll create it for the specific product in multiportal_SetupWizard
        }
    } catch (Exception $e) {
        throw new Exception('Error creating Virtual Data Center UUID field: ' . $e->getMessage());
    }

    try {
        // Product-level Tenant UUID and URL (per-service credential storage)
        // relid=0 entries serve as fallbacks; per-product entries (created by
        // ensureProductCustomFields) are what WHMCS displays in the admin UI.
        $serviceFields = [
            ['fieldname' => 'Tenant UUID', 'description' => 'MultiPortal tenant identifier for this service', 'sortorder' => 1],
            ['fieldname' => 'URL', 'description' => 'MultiPortal portal URL for this service', 'sortorder' => 2],
        ];
        foreach ($serviceFields as $sf) {
            $existing = Capsule::table('tblcustomfields')
                ->where('type', 'product')
                ->where('fieldname', $sf['fieldname'])
                ->first();
            if (!$existing) {
                Capsule::table('tblcustomfields')->insert([
                    'type' => 'product',
                    'relid' => 0,
                    'fieldname' => $sf['fieldname'],
                    'fieldtype' => 'text',
                    'description' => $sf['description'],
                    'fieldoptions' => '',
                    'regexpr' => '',
                    'adminonly' => 'on',
                    'required' => '',
                    'showorder' => '',
                    'showinvoice' => '',
                    'sortorder' => $sf['sortorder'],
                ]);
                $created[] = 'Product field: ' . $sf['fieldname'];
            }
        }
    } catch (Exception $e) {
        throw new Exception('Error creating product credential fields: ' . $e->getMessage());
    }

    // Username/Password are stored in tblhosting.username/tblhosting.password (no custom fields needed).

    try {
        // Check and create delete confirmation field
        $deleteField = Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->where('fieldname', 'delete_vdc_confirm')
            ->first();

        if (!$deleteField) {
            Capsule::table('tblcustomfields')->insert([
                'type' => 'product',
                'relid' => 0, // 0 means applies to all products
                'fieldname' => 'delete_vdc_confirm',
                'fieldtype' => 'text',
                'description' => '',
                'fieldoptions' => '',
                'regexpr' => '',
                'adminonly' => 'on',
                'required' => '',
                'showorder' => '',
                'showinvoice' => '',
                'sortorder' => 999
            ]);
            $created[] = 'Product field: delete_vdc_confirm';
        }
    } catch (Exception $e) {
        throw new Exception('Error creating delete field: ' . $e->getMessage());
    }

    return $created;
}

/**
 * Log debug information to WHMCS module log
 */
function multiportal_log($action, $request, $response, $processedData = [], $replaceVars = [])
{
    // Replace sensitive data in logs
    $debugData = [
        'request' => $request,
        'response' => $response,
        'processed' => $processedData
    ];

    foreach ($replaceVars as $search => $replace) {
        $debugData = json_decode(
            str_replace($search, $replace, json_encode($debugData)),
            true
        );
    }

    logModuleCall('multiportal', $action, $debugData['request'], $debugData['response'], $debugData['processed']);
}

/**
 * Append a timestamped entry to the service's Admin Notes field.
 *
 * Prepends the new entry so the most recent action is always at the top.
 * Keeps at most $maxEntries log lines (oldest are trimmed).
 */
function multiportal_appendAdminNote($serviceId, $message, $maxEntries = 50)
{
    $timestamp = date('Y-m-d H:i:s');
    $newEntry = "[$timestamp] $message";

    $currentNotes = Capsule::table('tblhosting')
        ->where('id', $serviceId)
        ->value('notes');

    if (!empty($currentNotes)) {
        $lines = explode("\n", $currentNotes);
        // Keep only the most recent entries
        if (count($lines) >= $maxEntries) {
            $lines = array_slice($lines, 0, $maxEntries - 1);
        }
        $notes = $newEntry . "\n" . implode("\n", $lines);
    } else {
        $notes = $newEntry;
    }

    Capsule::table('tblhosting')
        ->where('id', $serviceId)
        ->update(['notes' => $notes]);
}

/**
 * Validate required configuration options
 */
function multiportal_validateConfig($params)
{
    // Use ModuleConfiguration class for validation
    return ModuleConfiguration::validate($params);
}


/**
 * Module Activation - Create required custom fields
 */
function multiportal_activate()
{
    try {
        logModuleCall('multiportal', 'activate', 'Starting activation', '', '');
        // Create client custom field for Tenant UUID
        $clientField = Capsule::table('tblcustomfields')
            ->where('type', 'client')
            ->where('fieldname', 'MultiPortal Tenant UUID')
            ->first();

        if (!$clientField) {
            Capsule::table('tblcustomfields')->insert([
                'type' => 'client',
                'fieldname' => 'MultiPortal Tenant UUID',
                'fieldtype' => 'text',
                'description' => 'Stores the MultiPortal tenant identifier',
                'fieldoptions' => '',
                'regexpr' => '',
                'adminonly' => 'on',
                'required' => '',
                'showorder' => 'on',
                'showinvoice' => '',
                'sortorder' => 0
            ]);
        }
        
        // Client-level Username/Password/URL fields are no longer created for new
        // installs. Credentials are now stored per-service in product custom fields.
        // Existing client-level fields remain accessible via the fallback chain.

        // Create product custom field for VDC UUID
        $productField = Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->where('fieldname', 'VDC UUID')
            ->first();

        if (!$productField) {
            Capsule::table('tblcustomfields')->insert([
                'type' => 'product',
                'fieldname' => 'VDC UUID',
                'fieldtype' => 'text',
                'description' => 'Stores the Virtual Data Center identifier',
                'fieldoptions' => '',
                'regexpr' => '',
                'adminonly' => 'on',
                'required' => '',
                'showorder' => '',
                'showinvoice' => '',
                'sortorder' => 0,
                'relid' => 0 // 0 means applies to all products
            ]);
        }
        
        // Create product custom fields for Tenant UUID and URL (per-service)
        $serviceFields = [
            ['fieldname' => 'Tenant UUID', 'description' => 'MultiPortal tenant identifier for this service', 'sortorder' => 1],
            ['fieldname' => 'URL', 'description' => 'MultiPortal portal URL for this service', 'sortorder' => 2],
        ];
        foreach ($serviceFields as $sf) {
            $existing = Capsule::table('tblcustomfields')
                ->where('type', 'product')
                ->where('fieldname', $sf['fieldname'])
                ->first();
            if (!$existing) {
                Capsule::table('tblcustomfields')->insert([
                    'type' => 'product',
                    'fieldname' => $sf['fieldname'],
                    'fieldtype' => 'text',
                    'description' => $sf['description'],
                    'fieldoptions' => '',
                    'regexpr' => '',
                    'adminonly' => 'on',
                    'required' => '',
                    'showorder' => '',
                    'showinvoice' => '',
                    'sortorder' => $sf['sortorder'],
                    'relid' => 0
                ]);
            }
        }

        // Username/Password are stored in tblhosting (no custom fields needed).

        return ['status' => 'success', 'description' => 'MultiPortal module activated successfully'];
    } catch (Exception $e) {
        return ['status' => 'error', 'description' => 'MultiPortal activation failed: ' . $e->getMessage()];
    }
}

/**
 * Module Deactivation - Optionally remove custom fields
 */
function multiportal_deactivate()
{
    // We typically don't delete custom fields to preserve existing data
    // Uncomment below if you want to remove them on deactivation

    // Capsule::table('tblcustomfields')
    //     ->whereIn('fieldname', ['Tenant UUID', 'URL', 'VDC UUID'])
    //     ->delete();

    return ['status' => 'success', 'description' => 'MultiPortal module deactivated'];
}

/**
 * Get client custom field value by name
 */
function getClientCustomFieldValue($params, $fieldName)
{
    $customField = Capsule::table('tblcustomfields')
        ->where('type', 'client')
        ->where('fieldname', $fieldName)
        ->first();

    if (!$customField) {
        return null;
    }

    // Check if value exists in params (format: customfieldsX where X is the field ID)
    $fieldKey = 'customfields' . $customField->id;
    if (isset($params['clientsdetails'][$fieldKey])) {
        return $params['clientsdetails'][$fieldKey];
    }

    // If not in params, query the database directly
    $value = Capsule::table('tblcustomfieldsvalues')
        ->where('fieldid', $customField->id)
        ->where('relid', $params['userid'])
        ->first();

    return $value ? $value->value : null;
}

/**
 * Get product custom field value for a service
 */
function getProductCustomFieldValue($serviceId, $fieldName)
{
    $productId = Capsule::table('tblhosting')->where('id', $serviceId)->value('packageid');

    // Prefer field matching the service's product, fall back to relid=0
    $customField = null;
    if ($productId) {
        $customField = Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->where('fieldname', $fieldName)
            ->where('relid', $productId)
            ->first();
    }
    if (!$customField) {
        $customField = Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->where('fieldname', $fieldName)
            ->where('relid', 0)
            ->first();
    }

    if (!$customField) {
        return null;
    }

    $value = Capsule::table('tblcustomfieldsvalues')
        ->where('fieldid', $customField->id)
        ->where('relid', $serviceId)
        ->first();

    return $value ? $value->value : null;
}


/**
 * Update or create product custom field value for a service
 */
function updateProductCustomFieldValue($serviceId, $fieldName, $fieldValue)
{
    // Get the service details to find the product ID
    $service = Capsule::table('tblhosting')
        ->where('id', $serviceId)
        ->first();
    
    if (!$service) {
        return false;
    }
    
    // Find the custom field
    $customField = Capsule::table('tblcustomfields')
        ->where('type', 'product')
        ->where('fieldname', $fieldName)
        ->where('relid', $service->packageid)
        ->first();
    
    if (!$customField) {
        return false;
    }
    
    // Check if value already exists
    $existingValue = Capsule::table('tblcustomfieldsvalues')
        ->where('fieldid', $customField->id)
        ->where('relid', $serviceId)
        ->first();
    
    if ($existingValue) {
        // Update existing value
        Capsule::table('tblcustomfieldsvalues')
            ->where('id', $existingValue->id)
            ->update(['value' => $fieldValue]);
    } else {
        // Insert new value
        Capsule::table('tblcustomfieldsvalues')->insert([
            'fieldid' => $customField->id,
            'relid' => $serviceId,
            'value' => $fieldValue
        ]);
    }
    
    return true;
}

/**
 * Define module configuration options shown in WHMCS product settings.
 */
function multiportal_ConfigOptions($params)
{
    // $clientId = $params['serverusername']; // set this in module settings
    // $clientSecret = $params['serverpassword'];

    // if (empty($clientId) || empty($clientSecret)) {
    //     throw new Exception('Client ID and Client Secret are required.');
    // }

    // $api = new ApiClient($clientId, $clientSecret);
    return [
        'API Base URL' => ['Type' => 'text', 'Size' => '50', 'Default' => 'https://myfqdn.domain.local/api/v1', 'Description' => 'MultiPortal API endpoint URL'],
        'Data Center UUID' => ['Type' => 'text', 'Size' => '40', 'Description' => 'UUID of the data center'],
        'Reseller UUID' => ['Type' => 'text', 'Size' => '40', 'Description' => 'UUID of the reseller'],
        'PAYG CPU Rate ($/hour)' => ['Type' => 'text', 'Size' => '10', 'Default' => '0.10', 'Description' => 'Cost per CPU core per hour for PAYG'],
        'PAYG Memory Rate ($/GB/hour)' => ['Type' => 'text', 'Size' => '10', 'Default' => '0.05', 'Description' => 'Cost per GB of RAM per hour for PAYG'],
        'PAYG Storage Rate ($/GB/hour)' => ['Type' => 'text', 'Size' => '10', 'Default' => '0.01', 'Description' => 'Cost per GB of storage per hour for PAYG'],
        'Allocation Type' => ['Type' => 'dropdown', 'Options' => 'Allocation,Pay As You Go', 'Default' => 'Allocation', 'Description' => 'Allocation = fixed resources, Pay As You Go = metered usage'],
        'Backup Storage Rate ($/GB/hour)' => ['Type' => 'text', 'Size' => '10', 'Default' => '0.01', 'Description' => 'Cost per GB of backup storage per hour (blank = use Storage Rate)'],
        'ISO Storage Rate ($/GB/hour)' => ['Type' => 'text', 'Size' => '10', 'Default' => '0.01', 'Description' => 'Cost per GB of ISO storage per hour (blank = use Storage Rate)'],
    ];
}

/**
 * Get allocation type as integer (1=Allocation, 2=PAYG).
 *
 * Primary: reads from module-level configoption7.
 * Fallback: reads from configurable option dropdown
 * for backwards compatibility with existing setups.
 *
 * @param array $params WHMCS module parameters
 * @return int 1 for Allocation, 2 for Pay As You Go
 */
function multiportal_getAllocationType($params)
{
    // Primary: read from module-level configoption7
    $setting = ModuleConfiguration::get($params, ModuleConfiguration::FIELD_ALLOCATION_TYPE, false);
    if (!empty($setting)) {
        return (stripos($setting, 'pay as you go') !== false) ? 2 : 1;
    }
    // Fallback: read from configurable option dropdown (backwards compat for existing setups)
    if (isset($params['configoptions']['Allocation Type'])) {
        $selected = $params['configoptions']['Allocation Type'];
        if (stripos($selected, 'pay as you go') !== false || stripos($selected, 'payg') !== false) {
            return 2;
        }
    }
    return 1; // Default: Allocation
}

/**
 * Get a MultiPortal custom field value with fallback from service-level to client-level.
 *
 * Used for Tenant UUID and URL lookups. Username/Password use tblhosting instead
 * ($params['username']/$params['password']), not custom fields.
 *
 * @param string|null $clientFieldName Override for client-level field name (backwards compat).
 *                                     E.g. 'Tenant UUID' at service level maps to
 *                                     'MultiPortal Tenant UUID' at client level.
 */
function multiportal_getCredential($params, $fieldName, $clientFieldName = null)
{
    // 1. Service-level product custom field (primary)
    if (isset($params['customfields'][$fieldName]) && !empty($params['customfields'][$fieldName])) {
        return $params['customfields'][$fieldName];
    }
    $serviceValue = getProductCustomFieldValue($params['serviceid'], $fieldName);
    if (!empty($serviceValue)) {
        return $serviceValue;
    }

    // 2. Client-level custom field (backwards compat fallback)
    $clientField = $clientFieldName ?: $fieldName;
    $clientValue = getClientCustomFieldValue($params, $clientField);
    if (!empty($clientValue)) {
        return $clientValue;
    }

    return null;
}

/**
 * Find existing MultiPortal credentials for a client on the same WHMCS server.
 *
 * When a client orders a second VDC on the same MultiPortal server, we reuse
 * their existing tenant and user rather than creating duplicates.
 *
 * @return array|null Credential array or null if no existing service found
 */
function multiportal_findExistingCredentials($clientId, $serverId, $excludeServiceId = null)
{
    $query = Capsule::table('tblhosting')
        ->where('userid', $clientId)
        ->where('server', $serverId)
        ->whereIn('domainstatus', ['Active', 'Suspended']);

    if ($excludeServiceId) {
        $query->where('id', '!=', $excludeServiceId);
    }

    $services = $query->get();

    foreach ($services as $service) {
        $tenantUUID = getProductCustomFieldValue($service->id, 'Tenant UUID');
        if (!empty($tenantUUID) && !empty($service->username)) {
            return [
                'tenant_uuid' => $tenantUUID,
                'username' => $service->username,
                'password_encrypted' => $service->password,
                'url' => getProductCustomFieldValue($service->id, 'URL'),
            ];
        }
    }

    return null;
}

/**
 * Define admin-area module command buttons and trigger auto-migration on page view.
 */
function multiportal_AdminCustomButtonArray($params)
{
    $buttonarray = array();

    // Debug logging to understand params structure
    multiportal_log('AdminCustomButtonArray', [
        'serviceid' => $params['serviceid'],
        'customfields_exists' => isset($params['customfields']),
        'customfields' => $params['customfields'] ?? 'not set',
        'params_keys' => array_keys($params)
    ], 'Debug params structure');

    // Get VDC UUID - try params first, then database
    $vdcId = isset($params['customfields']['VDC UUID']) ? $params['customfields']['VDC UUID'] : null;

    if (!$vdcId) {
        // If not in params, get directly from database
        $vdcField = Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->where('fieldname', 'VDC UUID')
            ->first();

        if ($vdcField) {
            $value = Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $vdcField->id)
                ->where('relid', $params['serviceid'])
                ->first();
            $vdcId = $value ? $value->value : null;
        }
    }

    // Add setup wizard button if no configurable options exist
    if (!$vdcId && empty($params['configoptions'])) {
        $buttonarray['Setup Product Options'] = 'SetupWizard';
    }

    // Show re-setup button when configurable options already exist,
    // allowing admins to sync new storage policies from the data center
    if (!empty($params['configoptions'])) {
        $buttonarray['Re-Sync Storage Policies'] = 'ReSetupProductOptions';
    }

    if ($vdcId) {
        try {
            $api = initiateAPI($params);
            $vdcMgr = new VDCManager($api);
            $vdc = $vdcMgr->getVDCById($vdcId);

            // User management (right after WHMCS standard Create button)
            $buttonarray['Create/Reset User'] = 'CreateMultiPortalUser';
            $buttonarray['Email Credentials'] = 'EmailCredentials';

            // Re-sync storage policies (inside VDC block so it appears in order)
            if (!empty($params['configoptions'])) {
                // Remove the one added outside the block to control position
                unset($buttonarray['Re-Sync Storage Policies']);
                $buttonarray['Re-Sync Storage Policies'] = 'ReSetupProductOptions';
            }

            // Push/Pull data
            $buttonarray['Push Config to MP'] = 'UpdateVDC';
            $buttonarray['Pull Config from MP'] = 'SyncVDC';

            // Usage & billing
            $buttonarray["Show Current Month's Usage"] = 'ViewUsage';
            $buttonarray["Show Last Month's Usage"] = 'ViewLastMonthUsage';
            if (multiportal_getAllocationType($params) === 2) {
                $buttonarray['Re-bill PAYG Usage'] = 'RebillPAYGUsage';
            }

            // Suspend/Unsuspend
            if ($vdc && isset($vdc['is_enabled'])) {
                if ($vdc['is_enabled'] == 1) {
                    $buttonarray['Suspend VDC'] = 'DisableVdc';
                } else {
                    $buttonarray['Unsuspend VDC'] = 'EnableVdc';
                }
            }

            // Destructive
            $buttonarray['⚠️ Delete Virtual Data Center ⚠️'] = 'DestroyVdc';
        } catch (Exception $e) {
            // If we can't get VDC status, show both buttons
            multiportal_log('AdminCustomButtonArray', ['error' => $e->getMessage()], 'Failed to get Virtual Data Center status');
            $buttonarray['Suspend VDC'] = 'DisableVdc';
            $buttonarray['Unsuspend VDC'] = 'EnableVdc';
        }
    }

    // Auto-migrate legacy data on page view (transparent upgrade)
    try {
        $productId = Capsule::table('tblhosting')->where('id', $params['serviceid'])->value('packageid');
        if ($productId) {
            ensureProductCustomFields($productId);
            multiportal_autoMigrateService($params['serviceid']);
        }
    } catch (Exception $e) {
        // Silent fail - don't break button rendering
    }

    // Show "Migrate Credentials" when ANY legacy data exists:
    // - Client-level Tenant UUID with no service-level Tenant UUID
    // - Last Usage Sync in old relid=0 field with no per-product field value
    $needsMigration = false;

    $serviceTenantUUID = getProductCustomFieldValue($params['serviceid'], 'Tenant UUID');
    if (empty($serviceTenantUUID)) {
        $clientTenantUUID = getClientCustomFieldValue($params, 'MultiPortal Tenant UUID');
        if (!empty($clientTenantUUID)) {
            $needsMigration = true;
        }
    }

    if (!$needsMigration) {
        // Check for legacy Last Usage Sync (relid=0) with data for this service
        $oldSyncField = Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->where('fieldname', 'Last Usage Sync')
            ->where('relid', 0)
            ->first();
        if ($oldSyncField) {
            $oldSyncValue = Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $oldSyncField->id)
                ->where('relid', $params['serviceid'])
                ->value('value');
            if (!empty($oldSyncValue)) {
                $needsMigration = true;
            }
        }
    }

    if ($needsMigration) {
        $buttonarray['⬆️ MODULE UPGRADE (RUN MIGRATION!) ⬆️'] = 'MigrateCredentials';
    }

    return $buttonarray;
}

/**
 * Client Area Custom Button Array
 */
function multiportal_ClientAreaCustomButtonArray($params)
{
    $buttonarray = array();
    
    // Get VDC UUID to check if VDC exists
    $vdcId = isset($params['customfields']['VDC UUID']) ? $params['customfields']['VDC UUID'] : getProductCustomFieldValue($params['serviceid'], 'VDC UUID');
    
    if ($vdcId) {
        // Additional user creation has been removed
    }
    
    return $buttonarray;
}

function initiateAPI($params)
{
    $clientId = ModuleConfiguration::getClientId($params);
    $clientSecret = ModuleConfiguration::getClientSecret($params);
    $baseUrl = ModuleConfiguration::get($params, ModuleConfiguration::FIELD_API_URL);

    // Debug logging (optional - can be removed in production)
    multiportal_log('initiateAPI', [
        'clientId' => substr($clientId, 0, 10) . '...',
        'baseUrl' => $baseUrl
    ], 'API initialization');

    // SSL verification - defaults to true for security
    // SSL verification setting
    // TODO: Add SSL verification as a configuration option if needed
    $sslVerify = false; // TODO: Set to true in production
    if (!$sslVerify && function_exists('logModuleCall')) {
        logModuleCall('multiportal', 'SSL Warning', 'SSL verification is disabled. This should only be used in development environments.', '', '', []);
    }
    
    return new ApiClient($clientId, $clientSecret, $baseUrl, $sslVerify);
}

/**
 * Debug configuration - logs all configuration values
 */
function multiportal_debugConfig($params, $action = 'Debug Config')
{
    ModuleConfiguration::debug($params);
}

/**
 * Push local WHMCS configuration (CPU, RAM, storage policies) to the MultiPortal VDC.
 */
function multiportal_UpdateVDC(array $params)
{
    try {
        // Validate configuration
        $validationErrors = multiportal_validateConfig($params);
        if (!empty($validationErrors)) {
            throw new Exception('Configuration errors: ' . implode(', ', $validationErrors));
        }

        $api = initiateAPI($params);
        $vdcMgr = new VDCManager($api);

        $dataCenterId = ModuleConfiguration::get($params, ModuleConfiguration::FIELD_DATA_CENTER_ID);
        $vdcId = isset($params['customfields']['VDC UUID']) ? $params['customfields']['VDC UUID'] : getProductCustomFieldValue($params['serviceid'], 'VDC UUID');

        if (empty($vdcId)) {
            throw new Exception('Virtual Data Center UUID not found. Please create the Virtual Data Center first.');
        }

        multiportal_log('UpdateVDC', ['vdcId' => $vdcId, 'dataCenterId' => $dataCenterId], 'Starting Virtual Data Center update');

        $res = $vdcMgr->getStoragePoliciesByDataCenter($dataCenterId);
        $storagePolicyConfig = verifyStoragePolicyOptions($params['configoptions'], $res);

        // Determine allocation type (configoption7 with fallback to configurable option dropdown)
        $allocationType = multiportal_getAllocationType($params);

        // PAYG storage options are hidden (hidden=1) and WHMCS does not pass
        // hidden configurable options through $params['configoptions'] during
        // provisioning. Fall back to reading the config group from the DB.
        if (empty($storagePolicyConfig) && $allocationType === 2) {
            $storagePolicyConfig = getPaygStoragePoliciesFromDb($params['pid'], $res);
        }

        // For PAYG, CPU/Memory are not selected by the client.
        // API requires minimum 1 for both, so default to 1.
        $cpu = isset($params['configoptions']['CPU'])
            ? max(1, (int) $params['configoptions']['CPU']) : 1;
        $memory = isset($params['configoptions']['Memory Allocation'])
            ? max(1, (int) $params['configoptions']['Memory Allocation']) : 1;

        $vdc = $vdcMgr->updateVDC(
            $vdcId,
            [
                'vdc_name' => 'VDC - ' . $params['serviceid'],
                'allocation_type' => $allocationType,
                'memory_in_gb' => $memory,
                'core_count' => $cpu,
                'is_enabled' => 1,
            ]
        );

        multiportal_log('UpdateVDC', ['vdcId' => $vdcId], $vdc, [], ['serverpassword' => '***']);

        if (isset($vdc['error'])) {
            throw new Exception('Failed to update VDC: ' . $vdc['error']);
        }

        setCustomFieldValue($params['serviceid'], 'VDC UUID', $vdc['uuid']);

        // Get existing storage policies
        $storagePolicies = $vdcMgr->getStoragePolicy($vdcId);

        $existingStoragePolicies = [];
        foreach ($storagePolicies['data'] as $storagePolicy) {
            $existingStoragePolicies[$storagePolicy['storage_policy_id']] = $storagePolicy;
        }

        // Build a set of storage_policy_ids from the WHMCS config (capacity > 0)
        $configPolicyIds = [];
        foreach ($storagePolicyConfig as $config) {
            $configPolicyIds[$config['storage_policy_id']] = true;
        }

        // Process storage policy updates: add new or update existing
        foreach ($storagePolicyConfig as $config) {
            $storagePolicyId = $config['storage_policy_id'];
            $capacity = (int) $config['capacity'];

            if (isset($existingStoragePolicies[$storagePolicyId])) {
                // Update existing policy
                $vdcStoragePolicyId = $existingStoragePolicies[$storagePolicyId]['uuid'];
                $vdcMgr->updateStoragePolicy($vdcId, $vdcStoragePolicyId, [
                    'storage_policy_id' => $storagePolicyId,
                    'capacity' => $capacity,
                ]);
            } else {
                // Add new policy
                $vdcMgr->addStoragePolicy($vdcId, [
                    'storage_policy_id' => $storagePolicyId,
                    'capacity' => $capacity,
                ]);
            }
        }

        // Remove VDC policies that are no longer in the WHMCS config (set to 0 or removed)
        foreach ($existingStoragePolicies as $policyId => $policy) {
            if (!isset($configPolicyIds[$policyId])) {
                $vdcMgr->deleteStoragePolicy($vdcId, $policy['uuid']);
            }
        }

        $policyNames = array_map(function ($p) { return $p['name'] . ' (' . $p['capacity'] . 'GB)'; }, $storagePolicyConfig);
        $removedNames = [];
        foreach ($existingStoragePolicies as $policyId => $policy) {
            if (!isset($configPolicyIds[$policyId])) {
                $removedNames[] = $policy['name'] ?? $policyId;
            }
        }
        $pushNote = "Push Config to MP: ";
        if ($allocationType !== 2) {
            $pushNote .= "CPU: {$cpu}, RAM: {$memory}GB. ";
        }
        $pushNote .= "Storage: " . (empty($policyNames) ? 'none' : implode(', ', $policyNames));
        if (!empty($removedNames)) {
            $pushNote .= ". Removed: " . implode(', ', $removedNames);
        }
        multiportal_appendAdminNote($params['serviceid'], $pushNote);

        return 'success';
    } catch (Exception $e) {
        multiportal_appendAdminNote($params['serviceid'],
            "Push Config to MP ERROR: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Sync VDC data from API and update local records
 */
function multiportal_SyncVDC(array $params)
{
    try {
        $api = initiateAPI($params);
        $vdcMgr = new VDCManager($api);

        $vdcId = isset($params['customfields']['VDC UUID']) ? $params['customfields']['VDC UUID'] : getProductCustomFieldValue($params['serviceid'], 'VDC UUID');
        if (empty($vdcId)) {
            throw new Exception('Virtual Data Center UUID not found. Cannot sync non-existent Virtual Data Center.');
        }

        multiportal_log('SyncVDC', ['vdcId' => $vdcId], 'Starting Virtual Data Center sync');

        // Get VDC details from API
        $vdc = $vdcMgr->getVDCById($vdcId);
        if (!$vdc || isset($vdc['error'])) {
            throw new Exception('Failed to fetch Virtual Data Center data: ' . ($vdc['error'] ?? 'VDC not found'));
        }

        // Get storage policies
        $storagePolicies = $vdcMgr->getStoragePolicy($vdcId);

        multiportal_log('SyncVDC', ['vdcId' => $vdcId], $vdc, ['storagePolicies' => $storagePolicies]);

        $vdcStatus = $vdc['is_enabled'] ? 'Enabled' : 'Disabled';

        // Update service status based on VDC status
        $updateData = [];
        if ($vdc['is_enabled'] == 1 && $params['status'] == 'Suspended') {
            $updateData['domainstatus'] = 'Active';
        } elseif ($vdc['is_enabled'] == 0 && $params['status'] == 'Active') {
            $updateData['domainstatus'] = 'Suspended';
        }

        // Update service in WHMCS
        if (!empty($updateData)) {
            Capsule::table('tblhosting')
                ->where('id', $params['serviceid'])
                ->update($updateData);
        }

        $pullPolicyNames = array_map(function ($p) {
            return ($p['name'] ?? $p['storage_policy_name'] ?? 'Unknown') . ' (' . ($p['capacity'] ?? 0) . 'GB)';
        }, $storagePolicies['data'] ?? []);
        $pullNote = "Pull Config from MP: VDC {$vdcStatus}. ";
        if (isset($vdc['allocation_type']) && $vdc['allocation_type'] != 2) {
            $pullNote .= "CPU: {$vdc['core_count']}, RAM: {$vdc['memory_in_gb']}GB. ";
        }
        $pullNote .= "Storage: " . (empty($pullPolicyNames) ? 'none' : implode(', ', $pullPolicyNames));
        multiportal_appendAdminNote($params['serviceid'], $pullNote);

        // Update configurable options to reflect actual VDC values
        // First, get the product's configurable options
        $productId = $params['pid'];
        $serviceId = $params['serviceid'];

        // Find CPU and Memory configurable options
        // First get the config groups linked to this product
        $configGroups = Capsule::table('tblproductconfiglinks')
            ->where('pid', $productId)
            ->pluck('gid');

        $configOptions = Capsule::table('tblproductconfigoptions')
            ->whereIn('gid', $configGroups)
            ->whereIn('optionname', ['CPU', 'Memory Allocation'])
            ->get();

        foreach ($configOptions as $option) {
            // Determine which value to sync
            $syncValue = null;
            if ($option->optionname == 'CPU') {
                $syncValue = $vdc['core_count'];
            } elseif ($option->optionname == 'Memory Allocation') {
                $syncValue = $vdc['memory_in_gb'];
            }

            if ($syncValue !== null) {
                // For quantity-based options (text fields), get the first sub-option
                $subOption = Capsule::table('tblproductconfigoptionssub')
                    ->where('configid', $option->id)
                    ->first();

                if ($subOption) {
                    // Update or insert the configurable option value
                    $existingValue = Capsule::table('tblhostingconfigoptions')
                        ->where('relid', $serviceId)
                        ->where('configid', $option->id)
                        ->first();

                    if ($existingValue) {
                        Capsule::table('tblhostingconfigoptions')
                            ->where('id', $existingValue->id)
                            ->update([
                                'optionid' => $subOption->id,
                                'qty' => $syncValue
                            ]);
                    } else {
                        Capsule::table('tblhostingconfigoptions')
                            ->insert([
                                'relid' => $serviceId,
                                'configid' => $option->id,
                                'optionid' => $subOption->id,
                                'qty' => $syncValue
                            ]);
                    }
                }
            }
        }

        // Sync storage policies
        if (isset($storagePolicies['data']) && is_array($storagePolicies['data'])) {
            $dataCenterId = ModuleConfiguration::get($params, ModuleConfiguration::FIELD_DATA_CENTER_ID);
            $dcStoragePolicies = $vdcMgr->getStoragePoliciesByDataCenter($dataCenterId);

            foreach ($dcStoragePolicies['data'] as $dcPolicy) {
                $configOptionName = "Storage - {$dcPolicy['name']}";

                // Find this storage policy in VDC
                $vdcPolicyCapacity = 0;
                foreach ($storagePolicies['data'] as $vdcPolicy) {
                    if ($vdcPolicy['storage_policy_id'] == $dcPolicy['uuid']) {
                        $vdcPolicyCapacity = $vdcPolicy['capacity'];
                        break;
                    }
                }

                // Update the configurable option
                $storageOption = Capsule::table('tblproductconfigoptions')
                    ->whereIn('gid', $configGroups)
                    ->where('optionname', $configOptionName)
                    ->first();

                if ($storageOption) {
                    // For quantity-based options, update the quantity
                    $existingValue = Capsule::table('tblhostingconfigoptions')
                        ->where('relid', $serviceId)
                        ->where('configid', $storageOption->id)
                        ->first();

                    if ($existingValue) {
                        Capsule::table('tblhostingconfigoptions')
                            ->where('id', $existingValue->id)
                            ->update(['qty' => $vdcPolicyCapacity]);
                    } elseif ($vdcPolicyCapacity > 0) {
                        // Get the first sub-option (for quantity-based options)
                        $subOption = Capsule::table('tblproductconfigoptionssub')
                            ->where('configid', $storageOption->id)
                            ->first();

                        if ($subOption) {
                            Capsule::table('tblhostingconfigoptions')
                                ->insert([
                                    'relid' => $serviceId,
                                    'configid' => $storageOption->id,
                                    'optionid' => $subOption->id,
                                    'qty' => $vdcPolicyCapacity
                                ]);
                        }
                    }
                }
            }
        }

        return 'success';
    } catch (Exception $e) {
        multiportal_log('SyncVDC', $params, ['error' => $e->getMessage()]);
        multiportal_appendAdminNote($params['serviceid'],
            "Pull Config from MP ERROR: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Shared helper: fetch and log VDC usage for a given date range
 *
 * @param array  $params     WHMCS module params
 * @param array  $dateRange  ['date_range' => 'YYYY/MM/DD HH:MM:SS - YYYY/MM/DD HH:MM:SS'] or empty for current month
 * @param string $noteLabel  Label prefix for the admin note (e.g. "Current Month Usage")
 * @return string 'success' or error message
 */
function multiportal_fetchUsage(array $params, array $dateRange, $noteLabel)
{
    try {
        $api = initiateAPI($params);
        $vdcMgr = new VDCManager($api);

        $vdcId = isset($params['customfields']['VDC UUID']) ? $params['customfields']['VDC UUID'] : getProductCustomFieldValue($params['serviceid'], 'VDC UUID');
        if (empty($vdcId)) {
            throw new Exception('VDC UUID not found. Cannot view usage for non-existent VDC.');
        }

        multiportal_log('ViewUsage', ['vdcId' => $vdcId, 'label' => $noteLabel], 'Fetching VDC usage');

        $usage = $vdcMgr->getVDCUsage($vdcId, $dateRange);

        multiportal_log('ViewUsage', ['vdcId' => $vdcId, 'usage_keys' => array_keys($usage ?? [])], $usage);

        // API returns: virtual_data_center, summary, usage_breakdown, formatted_usage, metadata
        $formatted = $usage['formatted_usage'] ?? [];
        $summary   = $usage['summary'] ?? [];
        $status    = $formatted['current_status'] ?? [];
        $resSummary = $formatted['resource_summary'] ?? [];
        $vmStats   = $formatted['vm_statistics'] ?? [];
        $storageSummary = $formatted['storage_summary'] ?? [];
        $isPayg = isset($usage['virtual_data_center']['allocation_type']) && $usage['virtual_data_center']['allocation_type'] == 2;

        $period = ($summary['date_range']['start'] ?? '?') . ' to ' . ($summary['date_range']['end'] ?? '?');

        // Build detailed admin note
        $note = "{$noteLabel} ({$period}): ";
        $note .= "VMs: " . ($status['running_vms'] ?? 'N/A') . " running";
        if (isset($vmStats['total_runtime_hours'])) {
            $note .= ", runtime: " . $vmStats['total_runtime_hours'] . "h";
        }
        $note .= ". ";

        if (isset($resSummary['cpu'])) {
            $cpu = $resSummary['cpu'];
            $note .= "CPU: avg " . ($cpu['average_usage'] ?? 'N/A')
                . ", total " . ($cpu['total_usage'] ?? 'N/A')
                . ", daily " . ($cpu['daily_average'] ?? 'N/A') . ". ";
        }

        if (isset($resSummary['memory'])) {
            $mem = $resSummary['memory'];
            $note .= "RAM: avg " . ($mem['average_usage'] ?? 'N/A')
                . ", total " . ($mem['total_usage'] ?? 'N/A')
                . ", daily " . ($mem['daily_average'] ?? 'N/A') . ". ";
        }

        if (!empty($storageSummary)) {
            $storageParts = [];
            foreach ($storageSummary as $policyName => $info) {
                $part = "{$policyName}: avg " . ($info['average_usage'] ?? 'N/A');
                if (!$isPayg) {
                    $part .= " / " . ($info['capacity'] ?? 'N/A')
                        . " (" . ($info['utilization'] ?? 'N/A') . ")";
                }
                $storageParts[] = $part;
            }
            $note .= "Storage: " . implode(', ', $storageParts);
        }

        multiportal_appendAdminNote($params['serviceid'], $note);

        return 'success';
    } catch (Exception $e) {
        multiportal_log('ViewUsage', $params, ['error' => $e->getMessage()]);
        multiportal_appendAdminNote($params['serviceid'],
            "{$noteLabel} ERROR: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Show Current Month's Usage
 */
function multiportal_ViewUsage(array $params)
{
    return multiportal_fetchUsage($params, [], "Current Month Usage");
}

/**
 * Show Last Month's Usage
 */
function multiportal_ViewLastMonthUsage(array $params)
{
    $dateFrom = date('Y/m/d 00:00:00', strtotime('first day of last month'));
    $dateTo   = date('Y/m/d 23:59:59', strtotime('last day of last month'));

    return multiportal_fetchUsage(
        $params,
        ['date_range' => $dateFrom . ' - ' . $dateTo],
        "Last Month Usage"
    );
}

/**
 * Create or Reset MultiPortal User
 */
function multiportal_CreateMultiPortalUser(array $params)
{
    try {
        $api = initiateAPI($params);
        $tenantMgr = new TenantManager($api);
        
        // Get tenant UUID (service-level 'Tenant UUID' → client-level 'MultiPortal Tenant UUID' fallback)
        $tenantUUID = multiportal_getCredential($params, 'Tenant UUID', 'MultiPortal Tenant UUID');
        if (empty($tenantUUID)) {
            throw new Exception('Tenant UUID not found. Cannot create user without tenant.');
        }

        // Get tenant details
        $tenant = $tenantMgr->findTenantById($tenantUUID);
        if (!$tenant) {
            throw new Exception('Tenant not found in MultiPortal.');
        }

        multiportal_log('CreateMultiPortalUser', ['tenantUUID' => $tenantUUID], 'Starting user creation/reset');

        // Generate secure password
        $multiportalPassword = generateSecurePassword(16);

        // Check if this service already has a username (from a prior provisioning or reset).
        // If so, find that user in MultiPortal and reset their password.
        $currentUsername = !empty($params['username']) ? $params['username'] : '';
        $clientEmail = $params['clientsdetails']['email'];

        // Try to find an existing user by current username or email
        $existingUser = $tenantMgr->findUserInTenant($tenant['uuid'], $currentUsername, $clientEmail);

        if ($existingUser && !empty($existingUser['id'])) {
            // User exists — reset their password
            $multiportalUsername = $existingUser['username'];

            $tenantMgr->updateUser($tenant['uuid'], $existingUser['id'], [
                'password' => $multiportalPassword,
                'confirmPassword' => $multiportalPassword,
            ]);

            $action = 'reset';
            multiportal_log('CreateMultiPortalUser', [
                'user_id' => $existingUser['id'],
                'username' => $multiportalUsername,
            ], 'Existing user found — password reset');
        } else {
            // No existing user found — create a new one
            $emailParts = explode('@', $clientEmail);
            $baseUsername = $emailParts[0];
            $multiportalUsername = $baseUsername . '_' . $params['serviceid'];

            $tenantMgr->createUser(
                $tenant['uuid'],
                $multiportalUsername,
                $multiportalPassword,
                $clientEmail,
                $params['clientsdetails']['firstname'],
                $params['clientsdetails']['lastname'],
                'Tenant Administrator'
            );

            $action = 'created';
        }

        // Store credentials in tblhosting (for ClientArea $params['username']/$params['password'])
        Capsule::table('tblhosting')
            ->where('id', $params['serviceid'])
            ->update([
                'username' => $multiportalUsername,
                'password' => encrypt($multiportalPassword)
            ]);

        multiportal_log('CreateMultiPortalUser', [
            'action' => $action,
            'username' => $multiportalUsername,
            'service_id' => $params['serviceid'],
            'tenant' => $tenant['name']
        ], "User $action successfully. Credentials stored in service record.");

        multiportal_appendAdminNote($params['serviceid'],
            "Create/Reset User: User {$action} — username '{$multiportalUsername}', tenant '{$tenant['name']}'");

        return 'success';

    } catch (Exception $e) {
        multiportal_log('CreateMultiPortalUser', $params, ['error' => $e->getMessage()]);
        multiportal_appendAdminNote($params['serviceid'],
            "Create/Reset User ERROR: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Email the client their MultiPortal portal credentials.
 *
 * Sends two separate emails for security: one with the username and
 * portal URL, another with the temporary password and a link to
 * change it immediately.
 */
function multiportal_EmailCredentials(array $params)
{
    try {
        // Get current credentials from the service record
        $service = Capsule::table('tblhosting')->where('id', $params['serviceid'])->first();
        if (!$service) {
            throw new Exception('Service not found.');
        }

        $username = $service->username;
        if (empty($username)) {
            throw new Exception('No username set for this service. Run Create/Reset User first.');
        }

        // Decrypt the stored password
        $password = decrypt($service->password);
        if (empty($password)) {
            throw new Exception('No password set for this service. Run Create/Reset User first.');
        }

        // Get portal URL from service custom field, fall back to deriving from API URL
        $portalUrl = getProductCustomFieldValue($params['serviceid'], 'URL');
        if (empty($portalUrl)) {
            $apiUrl = ModuleConfiguration::get($params, ModuleConfiguration::FIELD_API_URL);
            $portalUrl = rtrim($apiUrl, '/');
            $portalUrl = preg_replace('#/api(/v\d+)?$#', '', $portalUrl);
        }

        // Get client info for the emails
        $clientName = trim($params['clientsdetails']['firstname'] . ' ' . $params['clientsdetails']['lastname']);
        $passwordChangeUrl = rtrim($portalUrl, '/') . '/user/profile#password';

        // --- Email 1/2: Username ---
        $usernameBody = '<p>Hello ' . htmlspecialchars($clientName) . ',</p>'
            . '<p><b>Portal URL:</b> <a href="' . htmlspecialchars($portalUrl) . '">' . htmlspecialchars($portalUrl) . '</a><br>'
            . '<b>Username:</b> ' . htmlspecialchars($username) . '</p>'
            . '<p>Your <b>temporary</b> password will arrive in a separate email.</p>'
            . '<p>If you have any questions, please don\'t hesitate to contact our support team.</p>';

        $result1 = localAPI('SendEmail', [
            'id' => $params['serviceid'],
            'customtype' => 'product',
            'customsubject' => 'Cloud Portal Credentials Reset (1/2) — Username',
            'custommessage' => $usernameBody,
        ]);

        if ($result1['result'] !== 'success') {
            throw new Exception('Failed to send username email: ' . ($result1['message'] ?? json_encode($result1)));
        }

        // --- Email 2/2: Temporary Password ---
        $passwordBody = '<p>Hello ' . htmlspecialchars($clientName) . ',</p>'
            . '<p><b>Temporary Password:</b> <code>' . htmlspecialchars($password) . '</code></p>'
            . '<p>Please login at <a href="' . htmlspecialchars($passwordChangeUrl) . '">'
            . htmlspecialchars($passwordChangeUrl) . '</a> and change your password right away.</p>'
            . '<p><strong>After changing your password, please DELETE this email.</strong></p>';

        $result2 = localAPI('SendEmail', [
            'id' => $params['serviceid'],
            'customtype' => 'product',
            'customsubject' => 'Cloud Portal Credentials Reset (2/2) — TEMPORARY Password',
            'custommessage' => $passwordBody,
        ]);

        if ($result2['result'] !== 'success') {
            throw new Exception('Username email sent, but password email failed: ' . ($result2['message'] ?? json_encode($result2)));
        }

        multiportal_log('EmailCredentials', [
            'service_id' => $params['serviceid'],
            'client_id' => $params['userid'],
            'username' => $username,
            'email_to' => $params['clientsdetails']['email'],
        ], 'Credentials emailed to client (2 emails)');

        multiportal_appendAdminNote($params['serviceid'],
            "Email Credentials: Sent 2 emails to {$params['clientsdetails']['email']} (username: {$username})");

        return 'success';

    } catch (Exception $e) {
        multiportal_log('EmailCredentials', $params, ['error' => $e->getMessage()]);
        multiportal_appendAdminNote($params['serviceid'],
            "Email Credentials ERROR: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Create API User for MultiPortal tenant
 */
function multiportal_CreateApiUser(array $params)
{
    try {
        $api = initiateAPI($params);
        $tenantMgr = new TenantManager($api);
        
        // Get tenant UUID (service-level 'Tenant UUID' → client-level 'MultiPortal Tenant UUID' fallback)
        $tenantUUID = multiportal_getCredential($params, 'Tenant UUID', 'MultiPortal Tenant UUID');
        if (empty($tenantUUID)) {
            throw new Exception('Tenant UUID not found. Cannot create API user without tenant.');
        }

        // Check if API credentials already exist
        $existingApiUsername = multiportal_getCredential($params, 'MultiPortal API Username');
        $existingApiPassword = multiportal_getCredential($params, 'MultiPortal API Password');
        
        if ($existingApiUsername && $existingApiPassword) {
            // API user already exists, return existing credentials
            $tenant = $tenantMgr->findTenantById($tenantUUID);
            
            // Get tenant URL
            if (isset($tenant['domain']) && !empty($tenant['domain'])) {
                $multiportalUrl = 'https://' . $tenant['domain'];
            } else {
                $tenantName = preg_replace('/[^a-z0-9\-]/', '', strtolower($tenant['name']));
                $multiportalUrl = 'https://' . $tenantName . '.multiportal.io';
            }
            
            $message = "Additional MultiPortal User Already Exists\n\n";
            $message .= "Username: " . $existingApiUsername . "\n";
            $message .= "Password: " . $existingApiPassword . "\n";
            $message .= "Portal URL: " . $multiportalUrl . "\n\n";
            $message .= "These credentials are shared across all services for this client.";
            
            // Store message in session for display
            if (session_status() == PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['multiportal_message'] = $message;
            
            return 'success';
        }
        
        // Get tenant details
        $tenant = $tenantMgr->findTenantById($tenantUUID);
        if (!$tenant) {
            throw new Exception('Tenant not found in MultiPortal.');
        }
        
        multiportal_log('CreateApiUser', ['tenantUUID' => $tenantUUID], 'Starting API user creation');
        
        // Check if a user with this email already exists in the tenant
        $existingUsers = $tenantMgr->getUsersByTenant($tenantUUID);
        $emailExists = false;
        $usernameExists = false;
        
        if (!empty($existingUsers)) {
            foreach ($existingUsers as $user) {
                if (isset($user['email']) && strtolower($user['email']) === strtolower($params['clientsdetails']['email'])) {
                    $emailExists = true;
                }
                // Check for existing usernames for this client
                if (isset($user['username']) && strpos($user['username'], 'user' . $params['userid']) === 0) {
                    $usernameExists = true;
                }
            }
        }
        
        // Generate unique username for the user with API access
        if ($usernameExists) {
            // If username exists, add more randomness
            $apiUsername = 'user' . $params['userid'] . '_' . rand(1000, 9999);
        } else {
            $apiUsername = 'user' . $params['userid'] . '_' . time();
        }
        
        // Generate secure API password (16 chars instead of 32)
        $apiPassword = generateSecurePassword(16);
        
        // Use a different email if the client's email already exists
        if ($emailExists) {
            // Extract domain from client email
            $emailParts = explode('@', $params['clientsdetails']['email']);
            $emailDomain = isset($emailParts[1]) ? $emailParts[1] : 'example.com';
            $apiEmail = $apiUsername . '@' . $emailDomain;
        } else {
            $apiEmail = $params['clientsdetails']['email'];
        }
        
        multiportal_log('CreateApiUser', [
            'username' => $apiUsername,
            'email' => $apiEmail,
            'emailExists' => $emailExists,
            'tenantId' => $tenant['uuid'],
            'tenantName' => $tenant['name']
        ], 'Creating API user');
        
        try {
            // Create API user in MultiPortal with Tenant Administrator role
            $apiUser = $tenantMgr->createUser(
                $tenant['uuid'],
                $apiUsername,
                $apiPassword,
                $apiEmail,
                'API',
                'User',
                'Tenant Administrator'
            );
        } catch (Exception $e) {
            multiportal_log('CreateApiUser', [
                'error' => $e->getMessage(),
                'username' => $apiUsername,
                'email' => $apiEmail
            ], 'Failed to create API user');
            throw new Exception('Failed to create API user: ' . $e->getMessage());
        }
        
        // Create client custom fields if they don't exist
        $apiUsernameField = Capsule::table('tblcustomfields')
            ->where('type', 'client')
            ->where('fieldname', 'MultiPortal API Username')
            ->first();
        
        $apiPasswordField = Capsule::table('tblcustomfields')
            ->where('type', 'client')
            ->where('fieldname', 'MultiPortal API Password')
            ->first();
        
        if (!$apiUsernameField) {
            Capsule::table('tblcustomfields')->insert([
                'type' => 'client',
                'fieldname' => 'MultiPortal API Username',
                'fieldtype' => 'text',
                'description' => 'API username for MultiPortal access',
                'fieldoptions' => '',
                'regexpr' => '',
                'adminonly' => 'on',
                'required' => '',
                'showorder' => 'on',
                'showinvoice' => '',
                'sortorder' => 0
            ]);
        }
        
        if (!$apiPasswordField) {
            Capsule::table('tblcustomfields')->insert([
                'type' => 'client',
                'fieldname' => 'MultiPortal API Password',
                'fieldtype' => 'password',
                'description' => 'API password for MultiPortal access',
                'fieldoptions' => '',
                'regexpr' => '',
                'adminonly' => 'on',
                'required' => '',
                'showorder' => 'on',
                'showinvoice' => '',
                'sortorder' => 0
            ]);
        }
        
        // Store the API credentials at service level
        // Create product-level fields if they don't exist
        foreach (['MultiPortal API Username', 'MultiPortal API Password'] as $fname) {
            $ftype = (strpos($fname, 'Password') !== false) ? 'password' : 'text';
            $exists = Capsule::table('tblcustomfields')
                ->where('type', 'product')
                ->where('fieldname', $fname)
                ->first();
            if (!$exists) {
                Capsule::table('tblcustomfields')->insert([
                    'type' => 'product',
                    'relid' => 0,
                    'fieldname' => $fname,
                    'fieldtype' => $ftype,
                    'description' => 'API credentials for MultiPortal access',
                    'fieldoptions' => '',
                    'regexpr' => '',
                    'adminonly' => 'on',
                    'required' => '',
                    'showorder' => '',
                    'showinvoice' => '',
                    'sortorder' => 10
                ]);
            }
        }
        setCustomFieldValue($params['serviceid'], 'MultiPortal API Username', $apiUsername);
        setCustomFieldValue($params['serviceid'], 'MultiPortal API Password', $apiPassword);
        
        // Get tenant URL
        if (isset($tenant['domain']) && !empty($tenant['domain'])) {
            $multiportalUrl = 'https://' . $tenant['domain'];
        } else {
            $tenantName = preg_replace('/[^a-z0-9\-]/', '', strtolower($tenant['name']));
            $multiportalUrl = 'https://' . $tenantName . '.multiportal.io';
        }
        
        multiportal_log('CreateApiUser', [
            'username' => $apiUsername,
            'url' => $multiportalUrl,
            'tenant' => $tenant['name']
        ], 'API user created successfully');
        
        $message = "Additional MultiPortal User Created Successfully\n\n";
        $message .= "Username: " . $apiUsername . "\n";
        $message .= "Password: " . $apiPassword . "\n";
        $message .= "Portal URL: " . $multiportalUrl . "\n\n";
        $message .= "These credentials have been stored at the client level and will be shared across all services.\n";
        $message .= "This user has Tenant Administrator role with full access to the portal.";
        
        // Return success with message appended
        return $message;
        
    } catch (Exception $e) {
        multiportal_log('CreateApiUser', $params, ['error' => $e->getMessage()]);
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Sync usage data from Multiportal and create billable items for PAYG services
 */
/**
 * Re-bill PAYG Usage (admin button).
 *
 * Deletes all uninvoiced billable items for this VDC, resets Last Usage Sync
 * to the end of the last invoiced period, fetches usage with include_history=true,
 * and recreates billable items at current rates.
 */
function multiportal_RebillPAYGUsage(array $params)
{
    try {
        $api = initiateAPI($params);
        $vdcMgr = new VDCManager($api);

        $vdcId = isset($params['customfields']['VDC UUID'])
            ? $params['customfields']['VDC UUID']
            : getProductCustomFieldValue($params['serviceid'], 'VDC UUID');
        if (empty($vdcId)) {
            throw new Exception('VDC UUID not found. Cannot bill usage for non-existent VDC.');
        }

        // Verify PAYG
        $vdc = $vdcMgr->getVDCById($vdcId);
        if (!$vdc || !isset($vdc['allocation_type']) || $vdc['allocation_type'] != 2) {
            return 'This VDC is not configured for PAYG billing (allocation type: '
                . ($vdc['allocation_type'] ?? 'unknown') . ').';
        }

        $vdcName = $params['domain'] ?? ('VDC-' . $params['serviceid']);

        // ── Read previous Last Usage Sync for tracing ────────────
        $prevSyncValue = getProductCustomFieldValue($params['serviceid'], 'Last Usage Sync');

        // ── Delete uninvoiced items and find sync reset date ─────
        $cleanup = multiportal_deleteUninvoicedPAYGItems($params['userid'], $vdcId);

        // Determine billing start: day after last invoiced day → first of month
        // duedate on billable items = the bucket's actual day (YYYY-MM-DD),
        // so we start from the NEXT day to avoid re-billing an invoiced day.
        if (!empty($cleanup['last_invoiced_date'])) {
            $billingStart = new DateTime($cleanup['last_invoiced_date']);
            $billingStart->modify('+1 day');
            $billingStart->setTime(0, 0, 0);
        } else {
            $billingStart = new DateTime('first day of this month midnight');
        }

        // Don't bill before the VDC existed — floor to Last Usage Sync creation date
        if (!empty($prevSyncValue)) {
            $syncFloor = new DateTime($prevSyncValue);
            $syncFloor->setTime(0, 0, 0);
            if ($syncFloor > $billingStart) {
                $billingStart = $syncFloor;
            }
        }

        $billingEnd = new DateTime();

        // If last invoiced date is today, billingStart lands on tomorrow.
        // Fall back to Last Usage Sync to bill the post-invoice remainder of today.
        if ($billingStart > $billingEnd) {
            if (!empty($prevSyncValue)) {
                $billingStart = new DateTime($prevSyncValue);
            } else {
                multiportal_appendAdminNote($params['serviceid'],
                    "Re-bill PAYG: All usage is invoiced and no Last Usage Sync found.");
                return 'success';
            }
        }

        // ── Load rates ──────────────────────────────────────────
        $productRow = Capsule::table('tblproducts as p')
            ->join('tblhosting as h', 'h.packageid', '=', 'p.id')
            ->where('h.id', $params['serviceid'])
            ->select('p.configoption4', 'p.configoption5', 'p.configoption6', 'p.configoption8', 'p.configoption9')
            ->first();
        $rates = multiportal_loadPAYGRates($productRow);

        // ── Fetch usage with historical data ────────────────────
        $usageParams = [
            'date_range'      => $billingStart->format('Y/m/d H:i:s')
                . ' - ' . $billingEnd->format('Y/m/d H:i:s'),
            'include_history' => 'true',
        ];
        $usage = $vdcMgr->getVDCUsage($vdcId, $usageParams);

        if (!$usage || !is_array($usage)) {
            throw new Exception('No usage data returned from API.');
        }

        // ── Process usage and create billable items ─────────────
        $result = multiportal_processVDCUsage(
            $usage, $vdcId, $vdcName,
            $billingStart, $billingEnd,
            $rates, $params['serviceid'], $params['userid'],
            $billingStart  // Re-bill controls its own range
        );

        // ── Update Last Usage Sync ──────────────────────────────
        $productId = Capsule::table('tblhosting')
            ->where('id', $params['serviceid'])
            ->value('packageid');
        ensureProductCustomFields($productId);

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
                ['fieldid' => $syncField->id, 'relid' => $params['serviceid']],
                ['value'   => $billingEnd->format('Y-m-d H:i:s')]
            );
        }

        // ── Write details to admin notes ─────────────────────────
        $period = $billingStart->format('Y-m-d H:i') . ' to ' . $billingEnd->format('Y-m-d H:i');

        $noteLines = ["Re-bill PAYG | Period: {$period}"];
        $noteLines[] = "Prev Last Usage Sync: " . ($prevSyncValue ?: '(not set)');
        $noteLines[] = "New Last Usage Sync: " . $billingEnd->format('Y-m-d H:i:s');
        if ($cleanup['deleted'] > 0) {
            $noteLines[] = "Deleted {$cleanup['deleted']} uninvoiced item(s) before re-billing";
        }
        foreach ($result['details'] as $detail) {
            $noteLines[] = $detail;
        }
        if ($result['items_created'] > 0) {
            $noteLines[] = "Total: \$" . number_format($result['total_charge'], 2)
                . " ({$result['items_created']} items) — will appear on next invoice";
        } else {
            $noteLines[] = "No billable usage for this period";
        }
        multiportal_appendAdminNote($params['serviceid'], implode(' | ', $noteLines));

        return 'success';
    } catch (Exception $e) {
        multiportal_log('RebillPAYGUsage', $params, ['error' => $e->getMessage()]);
        multiportal_appendAdminNote($params['serviceid'],
            "Re-bill PAYG ERROR: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

// ── PAYG Incremental Billing Functions ────────────────────────────────
// Shared between bill-usage.php (cron) and the admin "Re-bill PAYG Usage" button.

/**
 * Parse a MultiPortal API history timestamp into a DateTime.
 *
 * The API returns timestamps like "Feb 10, 2026 10:00:00 AM".
 *
 * @param string $timestamp
 * @return DateTime|null
 */
function multiportal_parseHistoryTimestamp($timestamp)
{
    $dt = DateTime::createFromFormat('M j, Y g:i:s A', trim($timestamp));
    if (!$dt) {
        $dt = DateTime::createFromFormat('M d, Y g:i:s A', trim($timestamp));
    }
    return $dt ?: null;
}

/**
 * Load PAYG rates with fallback chain: product config → payg_config.php → defaults.
 *
 * @param object $productRow  DB row with configoption4/5/6
 * @return array  ['cpu_per_hour' => float, 'memory_per_gb_hour' => float, 'storage_per_gb_hour' => float, 'backup_storage_per_gb_hour' => float, 'iso_storage_per_gb_hour' => float]
 */
function multiportal_loadPAYGRates($productRow)
{
    // Hardcoded defaults (lowest priority)
    $rates = [
        'cpu_per_hour'                  => 0.10,
        'memory_per_gb_hour'            => 0.05,
        'storage_per_gb_hour'           => 0.01,
        'backup_storage_per_gb_hour'    => 0.01,
        'iso_storage_per_gb_hour'       => 0.01,
    ];

    // payg_config.php overrides
    $configFile = @include __DIR__ . '/payg_config.php';
    if (is_array($configFile) && isset($configFile['rates'])) {
        $rates = array_merge($rates, $configFile['rates']);
    }

    // Product-level overrides (highest priority)
    if (!empty($productRow->configoption4) && is_numeric($productRow->configoption4)) {
        $rates['cpu_per_hour'] = (float) $productRow->configoption4;
    }
    if (!empty($productRow->configoption5) && is_numeric($productRow->configoption5)) {
        $rates['memory_per_gb_hour'] = (float) $productRow->configoption5;
    }
    if (!empty($productRow->configoption6) && is_numeric($productRow->configoption6)) {
        $rates['storage_per_gb_hour'] = (float) $productRow->configoption6;
    }

    // Backup/ISO: if product config is blank, fall back to whatever storage rate resolved to
    if (!empty($productRow->configoption8) && is_numeric($productRow->configoption8)) {
        $rates['backup_storage_per_gb_hour'] = (float) $productRow->configoption8;
    } else {
        $rates['backup_storage_per_gb_hour'] = $rates['storage_per_gb_hour'];
    }
    if (!empty($productRow->configoption9) && is_numeric($productRow->configoption9)) {
        $rates['iso_storage_per_gb_hour'] = (float) $productRow->configoption9;
    } else {
        $rates['iso_storage_per_gb_hour'] = $rates['storage_per_gb_hour'];
    }

    return $rates;
}

/**
 * Process a single VDC's usage data and create billable items.
 *
 * Accepts pre-fetched usage data (with include_history=true) and filters
 * historical entries by the VDC's Last Sync Date. Creates one billable item
 * per resource type (CPU, RAM, storage policy, backup, ISO) via AddBillableItem.
 *
 * Does NOT update Last Usage Sync — the caller is responsible for that.
 *
 * @param array    $usageData     API response data from getVDCUsage with include_history=true
 * @param string   $vdcId         VDC UUID
 * @param string   $vdcName       VDC display name (e.g. domain from tblhosting)
 * @param DateTime $lastSyncDate  Only bill entries with timestamp >= this
 * @param DateTime $billingEnd    End of billing period (typically now())
 * @param array    $rates         Rate array from multiportal_loadPAYGRates
 * @param int      $serviceId     WHMCS service ID
 * @param int      $userId        WHMCS client ID
 * @return array   ['items_created' => int, 'total_charge' => float, 'details' => string[]]
 */
function multiportal_processVDCUsage($usageData, $vdcId, $vdcName, DateTime $lastSyncDate, DateTime $billingEnd, array $rates, $serviceId, $userId, DateTime $todayStart = null)
{
    $result = ['items_created' => 0, 'total_charge' => 0.0, 'details' => []];
    $breakdown = $usageData['usage_breakdown'] ?? [];
    $GiB = 1024 * 1024 * 1024;

    // Helper: format charge (6 decimals, strip trailing zeros — but always keep 6 for $0)
    $fmtCharge = function ($v) {
        if ((float) $v == 0) return '$0.000000';
        return '$' . rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
    };

    // ── Helper: parse timestamp and return [day_bucket_key, hour] ──
    // Mirrors mp-gen-usage.sh SLICE=10 (YYYY-MM-DD) — daily buckets
    // Uses $todayFilter for today's entries, $lastSyncDate for completed days
    $todayStr = date('Y-m-d');
    $todayFilter = $todayStart ?? $lastSyncDate;

    $getBucket = function ($timestamp) use ($lastSyncDate, $todayStr, $todayFilter) {
        $ts = multiportal_parseHistoryTimestamp($timestamp);
        if (!$ts) return [null, null];
        $dayKey = $ts->format('Y-m-d');
        $filterDate = ($dayKey === $todayStr) ? $todayFilter : $lastSyncDate;
        if ($ts < $filterDate) return [null, null];
        return [$dayKey, (int) $ts->format('G')];
    };

    // ── Step 1: Collect all entries into daily buckets ──
    // Each bucket tracks billing totals + per-VM/per-policy detail for summaries
    $newBucket = function () {
        return [
            'cpu_hours' => 0, 'mem_gb_hours' => 0,
            'storage' => [], 'backup' => [], 'iso' => [],
            // Per-VM compute detail: vm_name => [vcpus, ram_gb, duration_s, cpu_duration, mem_gb_duration]
            'vm_compute' => [],
            // Per-policy storage detail: policy => [duration_s, usage_bytes_sum, entry_count]
            'storage_detail' => [],
            // Per-target backup/iso detail: target => [duration_s, usage_bytes_sum, entry_count]
            'backup_detail' => [], 'iso_detail' => [],
            // Track which hours (0-23) have data entries
            'hours_seen' => [],
        ];
    };
    $dailyBuckets = [];

    // Resource entries (CPU + RAM) — also track per-VM
    if (isset($breakdown['resource']['historical_data']) && is_array($breakdown['resource']['historical_data'])) {
        foreach ($breakdown['resource']['historical_data'] as $entry) {
            [$bucket, $entryHour] = $getBucket($entry['timestamp'] ?? '');
            if ($bucket === null) continue;
            if (!isset($dailyBuckets[$bucket])) $dailyBuckets[$bucket] = $newBucket();
            $dailyBuckets[$bucket]['hours_seen'][$entryHour] = true;
            $cpu      = (float) ($entry['cpu'] ?? 0);
            $memBytes = (float) ($entry['memory'] ?? 0);
            $duration = (float) ($entry['duration'] ?? 0);
            $vmName   = $entry['name'] ?? 'unknown';
            $dailyBuckets[$bucket]['cpu_hours']   += ($cpu * $duration) / 3600;
            $dailyBuckets[$bucket]['mem_gb_hours'] += ($memBytes / $GiB) * $duration / 3600;
            // Per-VM detail
            if (!isset($dailyBuckets[$bucket]['vm_compute'][$vmName])) {
                $dailyBuckets[$bucket]['vm_compute'][$vmName] = [
                    'vcpus' => $cpu, 'ram_gb' => round($memBytes / $GiB, 2),
                    'duration_s' => 0, 'cpu_duration' => 0, 'mem_gb_duration' => 0,
                ];
            }
            $vm = &$dailyBuckets[$bucket]['vm_compute'][$vmName];
            $vm['duration_s']      += $duration;
            $vm['cpu_duration']    += $cpu * $duration;
            $vm['mem_gb_duration'] += ($memBytes / $GiB) * $duration;
            unset($vm);
        }
    }

    // Storage entries (per policy) — also track detail
    if (isset($breakdown['storage']) && is_array($breakdown['storage'])) {
        foreach ($breakdown['storage'] as $policyName => $policyData) {
            if (!isset($policyData['historical_data']) || !is_array($policyData['historical_data'])) continue;
            foreach ($policyData['historical_data'] as $entry) {
                [$bucket, $entryHour] = $getBucket($entry['timestamp'] ?? '');
                if ($bucket === null) continue;
                if (!isset($dailyBuckets[$bucket])) $dailyBuckets[$bucket] = $newBucket();
                $dailyBuckets[$bucket]['hours_seen'][$entryHour] = true;
                $usage    = (float) ($entry['usage'] ?? 0);
                $duration = (float) ($entry['duration'] ?? 0);
                if (!isset($dailyBuckets[$bucket]['storage'][$policyName])) $dailyBuckets[$bucket]['storage'][$policyName] = 0;
                $dailyBuckets[$bucket]['storage'][$policyName] += ($usage / $GiB) * $duration / 3600;
                if (!isset($dailyBuckets[$bucket]['storage_detail'][$policyName])) {
                    $dailyBuckets[$bucket]['storage_detail'][$policyName] = ['duration_s' => 0, 'usage_bytes_sum' => 0, 'entry_count' => 0];
                }
                $dailyBuckets[$bucket]['storage_detail'][$policyName]['duration_s']      += $duration;
                $dailyBuckets[$bucket]['storage_detail'][$policyName]['usage_bytes_sum']  += $usage;
                $dailyBuckets[$bucket]['storage_detail'][$policyName]['entry_count']++;
            }
        }
    }

    // Backup storage entries (per target)
    if (isset($breakdown['backup_storage']) && is_array($breakdown['backup_storage'])) {
        foreach ($breakdown['backup_storage'] as $targetName => $targetData) {
            if (!isset($targetData['historical_data']) || !is_array($targetData['historical_data'])) continue;
            foreach ($targetData['historical_data'] as $entry) {
                [$bucket, $entryHour] = $getBucket($entry['timestamp'] ?? '');
                if ($bucket === null) continue;
                if (!isset($dailyBuckets[$bucket])) $dailyBuckets[$bucket] = $newBucket();
                $dailyBuckets[$bucket]['hours_seen'][$entryHour] = true;
                $usage    = (float) ($entry['usage'] ?? 0);
                $duration = (float) ($entry['duration'] ?? 0);
                if (!isset($dailyBuckets[$bucket]['backup'][$targetName])) $dailyBuckets[$bucket]['backup'][$targetName] = 0;
                $dailyBuckets[$bucket]['backup'][$targetName] += ($usage / $GiB) * $duration / 3600;
                if (!isset($dailyBuckets[$bucket]['backup_detail'][$targetName])) {
                    $dailyBuckets[$bucket]['backup_detail'][$targetName] = ['duration_s' => 0, 'usage_bytes_sum' => 0, 'entry_count' => 0];
                }
                $dailyBuckets[$bucket]['backup_detail'][$targetName]['duration_s']      += $duration;
                $dailyBuckets[$bucket]['backup_detail'][$targetName]['usage_bytes_sum']  += $usage;
                $dailyBuckets[$bucket]['backup_detail'][$targetName]['entry_count']++;
            }
        }
    }

    // ISO storage entries (per target)
    if (isset($breakdown['iso_storage']) && is_array($breakdown['iso_storage'])) {
        foreach ($breakdown['iso_storage'] as $targetName => $targetData) {
            if (!isset($targetData['historical_data']) || !is_array($targetData['historical_data'])) continue;
            foreach ($targetData['historical_data'] as $entry) {
                [$bucket, $entryHour] = $getBucket($entry['timestamp'] ?? '');
                if ($bucket === null) continue;
                if (!isset($dailyBuckets[$bucket])) $dailyBuckets[$bucket] = $newBucket();
                $dailyBuckets[$bucket]['hours_seen'][$entryHour] = true;
                $usage    = (float) ($entry['usage'] ?? 0);
                $duration = (float) ($entry['duration'] ?? 0);
                if (!isset($dailyBuckets[$bucket]['iso'][$targetName])) $dailyBuckets[$bucket]['iso'][$targetName] = 0;
                $dailyBuckets[$bucket]['iso'][$targetName] += ($usage / $GiB) * $duration / 3600;
                if (!isset($dailyBuckets[$bucket]['iso_detail'][$targetName])) {
                    $dailyBuckets[$bucket]['iso_detail'][$targetName] = ['duration_s' => 0, 'usage_bytes_sum' => 0, 'entry_count' => 0];
                }
                $dailyBuckets[$bucket]['iso_detail'][$targetName]['duration_s']      += $duration;
                $dailyBuckets[$bucket]['iso_detail'][$targetName]['usage_bytes_sum']  += $usage;
                $dailyBuckets[$bucket]['iso_detail'][$targetName]['entry_count']++;
            }
        }
    }

    if (empty($dailyBuckets)) {
        return $result;
    }

    // ── Step 2: Sort by day and create one billable item per bucket ──
    ksort($dailyBuckets);

    // Running totals for admin notes
    $totalCpuHours = 0;
    $totalMemGBHours = 0;
    $totalStorageGBHours = [];
    $totalBackupGBHours = [];
    $totalIsoGBHours = [];

    foreach ($dailyBuckets as $bucket => $data) {
        // Calculate charges for this day
        $cpuCharge     = $data['cpu_hours'] * $rates['cpu_per_hour'];
        $memCharge     = $data['mem_gb_hours'] * $rates['memory_per_gb_hour'];
        $storageCharge = 0;
        foreach ($data['storage'] as $gbh) { $storageCharge += $gbh * $rates['storage_per_gb_hour']; }
        $backupCharge  = 0;
        foreach ($data['backup'] as $gbh) { $backupCharge += $gbh * $rates['backup_storage_per_gb_hour']; }
        $isoCharge     = 0;
        foreach ($data['iso'] as $gbh) { $isoCharge += $gbh * $rates['iso_storage_per_gb_hour']; }

        $dayTotalRaw = $cpuCharge + $memCharge + $storageCharge + $backupCharge + $isoCharge;
        $dayTotal = round($dayTotalRaw, 2);
        if ($dayTotal <= 0) continue;

        // Day period strings — today uses $todayFilter start time, completed days use midnight
        $dayStartTime = ($bucket === $todayStr) ? $todayFilter->format('H:i:s') : '00:00:00';
        $dayStart    = $bucket . ' ' . $dayStartTime;
        $dayEnd      = ($bucket === $todayStr) ? $billingEnd->format('H:i:s') : '23:59:59';
        $dayHash     = md5($vdcId . ':' . $dayStart);
        $hoursCount  = count($data['hours_seen']);

        // Calculate day N of M in the month
        $bucketDt    = new DateTime($dayStart);
        $dayNum      = (int) $bucketDt->format('j');  // day of month 1-based
        $daysInMonth = (int) $bucketDt->format('t');
        $monthName   = $bucketDt->format('F');

        // ── Build description ──
        $lines = [];
        $lines[] = sprintf("== VDC DAILY USAGE == [%s]", $dayHash);
        $lines[] = sprintf("Period: %s - %s (%d h)", $dayStart, $dayEnd, $hoursCount);
        $lines[] = sprintf("- Day %d/%d in %s", $dayNum, $daysInMonth, $monthName);
        $lines[] = '';
        $lines[] = sprintf("VDC: %s (%s)", $vdcName, $vdcId);
        $lines[] = '';

        // USAGE SUMMARY
        $lines[] = 'USAGE SUMMARY';
        $lines[] = str_repeat('=', 13);
        if ($data['cpu_hours'] > 0) {
            $lines[] = sprintf("- CPU: %.2f core-hours @ \$%.4f/hour = %s", $data['cpu_hours'], $rates['cpu_per_hour'], $fmtCharge($cpuCharge));
        }
        if ($data['mem_gb_hours'] > 0) {
            $lines[] = sprintf("- RAM: %.2f GB-hours @ \$%.4f/GB-hour = %s", $data['mem_gb_hours'], $rates['memory_per_gb_hour'], $fmtCharge($memCharge));
        }
        foreach ($data['storage'] as $policy => $gbh) {
            if ($gbh > 0) {
                $lines[] = sprintf("- Storage Policy (%s): %.2f GB-hours @ \$%.4f/GB-hour = %s", $policy, $gbh, $rates['storage_per_gb_hour'], $fmtCharge($gbh * $rates['storage_per_gb_hour']));
            }
        }
        foreach ($data['backup'] as $target => $gbh) {
            if ($gbh > 0) {
                $lines[] = sprintf("- Backup Target (%s): %.2f GB-hours @ \$%.4f/GB-hour = %s", $target, $gbh, $rates['backup_storage_per_gb_hour'], $fmtCharge($gbh * $rates['backup_storage_per_gb_hour']));
            }
        }
        foreach ($data['iso'] as $target => $gbh) {
            if ($gbh > 0) {
                $lines[] = sprintf("- ISO Target (%s): %.2f GB-hours @ \$%.4f/GB-hour = %s", $target, $gbh, $rates['iso_storage_per_gb_hour'], $fmtCharge($gbh * $rates['iso_storage_per_gb_hour']));
            }
        }
        $lines[] = str_repeat('=', 13);
        $lines[] = sprintf("=> %s", $fmtCharge($dayTotalRaw));

        // DAILY COMPUTE DETAIL
        if (!empty($data['vm_compute'])) {
            $lines[] = '';
            $lines[] = 'DAILY COMPUTE DETAIL';
            $lines[] = str_repeat('=', 20);
            $computeTotal = 0;
            $vmIdx = 0;
            foreach ($data['vm_compute'] as $vm => $vc) {
                $runtimeH   = round($vc['duration_s'] / 3600, 2);
                $cpuCoreH   = round($vc['cpu_duration'] / 3600, 2);
                $memGBH     = round($vc['mem_gb_duration'] / 3600, 2);
                $avgCpu     = ($vc['duration_s'] > 0) ? round($vc['cpu_duration'] / $vc['duration_s'], 2) : 0;
                $avgRamGB   = ($vc['duration_s'] > 0) ? round($vc['mem_gb_duration'] / $vc['duration_s'], 2) : 0;
                $vmCharge   = ($cpuCoreH * $rates['cpu_per_hour']) + ($memGBH * $rates['memory_per_gb_hour']);
                $computeTotal += $vmCharge;
                if ($vmIdx > 0) $lines[] = '';
                $lines[] = sprintf("%s:", $vm);
                $lines[] = sprintf("- %d vCPUs (%.2f avg), %.2f GB RAM (%.2f avg)", $vc['vcpus'], $avgCpu, $vc['ram_gb'], $avgRamGB);
                $lines[] = sprintf("- %.2f run-hours, %.2f core-hours, %.2f GB-hours] = %s", $runtimeH, $cpuCoreH, $memGBH, $fmtCharge($vmCharge));
                $vmIdx++;
            }
            $lines[] = str_repeat('=', 20);
            $lines[] = sprintf("=> %s", $fmtCharge($computeTotal));
        }

        // DAILY STORAGE DETAIL
        if (!empty($data['storage_detail'])) {
            $lines[] = '';
            $lines[] = 'DAILY STORAGE DETAIL';
            $lines[] = str_repeat('=', 20);
            $storageDetailTotal = 0;
            $sIdx = 0;
            foreach ($data['storage_detail'] as $policy => $sd) {
                $durH    = round($sd['duration_s'] / 3600, 2);
                $avgGB   = round(($sd['usage_bytes_sum'] / $sd['entry_count']) / $GiB, 2);
                $gbH     = $data['storage'][$policy] ?? 0;
                $policyCharge = $gbH * $rates['storage_per_gb_hour'];
                $storageDetailTotal += $policyCharge;
                if ($sIdx > 0) $lines[] = '';
                $lines[] = sprintf("%s:", $policy);
                $lines[] = sprintf("- %.2f duration-hours, %.2f GB (avg), %.2f GB-hours = %s", $durH, $avgGB, round($gbH, 2), $fmtCharge($policyCharge));
                $sIdx++;
            }
            $lines[] = str_repeat('=', 20);
            $lines[] = sprintf("=> %s", $fmtCharge($storageDetailTotal));
        }

        // DAILY BACKUP STORAGE DETAIL
        if (!empty($data['backup_detail'])) {
            $lines[] = '';
            $lines[] = 'DAILY BACKUP STORAGE DETAIL';
            $lines[] = str_repeat('=', 27);
            $backupDetailTotal = 0;
            foreach ($data['backup_detail'] as $target => $bd) {
                $durH    = round($bd['duration_s'] / 3600, 2);
                $avgGB   = round(($bd['usage_bytes_sum'] / $bd['entry_count']) / $GiB, 2);
                $gbH     = $data['backup'][$target] ?? 0;
                $targetCharge = $gbH * $rates['backup_storage_per_gb_hour'];
                $backupDetailTotal += $targetCharge;
                $lines[] = sprintf("%s:", $target);
                $lines[] = sprintf("- %.2f duration-hours, %.2f GB (avg), %.2f GB-hours = %s", $durH, $avgGB, round($gbH, 2), $fmtCharge($targetCharge));
            }
            $lines[] = str_repeat('=', 27);
            $lines[] = sprintf("=> %s", $fmtCharge($backupDetailTotal));
        }

        // DAILY ISO STORAGE DETAIL
        if (!empty($data['iso_detail'])) {
            $lines[] = '';
            $lines[] = 'DAILY ISO STORAGE DETAIL';
            $lines[] = str_repeat('=', 24);
            $isoDetailTotal = 0;
            foreach ($data['iso_detail'] as $target => $id) {
                $durH    = round($id['duration_s'] / 3600, 2);
                $avgGB   = round(($id['usage_bytes_sum'] / $id['entry_count']) / $GiB, 2);
                $gbH     = $data['iso'][$target] ?? 0;
                $targetCharge = $gbH * $rates['iso_storage_per_gb_hour'];
                $isoDetailTotal += $targetCharge;
                $lines[] = sprintf("%s:", $target);
                $lines[] = sprintf("- %.2f duration-hours, %.2f GB (avg), %.2f GB-hours = %s", $durH, $avgGB, round($gbH, 2), $fmtCharge($targetCharge));
            }
            $lines[] = str_repeat('=', 24);
            $lines[] = sprintf("=> %s", $fmtCharge($isoDetailTotal));
        }

        $lines[] = '';
        $lines[] = str_repeat('=', 60);

        $desc = implode("\n", $lines);

        // ── Dedup / live-update logic ──
        // Look for existing uninvoiced item for this VDC+date
        $uninvoicedItem = Capsule::table('tblbillableitems')
            ->where('userid', $userId)
            ->where('invoicecount', 0)
            ->where('duedate', $bucket)
            ->where('description', 'LIKE', '%(' . $vdcId . ')%')
            ->first();

        if ($uninvoicedItem) {
            if ($bucket === $todayStr) {
                // Today's live item — UPDATE in place (no ID churn)
                Capsule::table('tblbillableitems')
                    ->where('id', $uninvoicedItem->id)
                    ->update(['description' => $desc, 'amount' => $dayTotal]);
                $result['items_created']++;
                $result['total_charge'] += $dayTotal;
            }
            // Whether today (updated) or completed day (unchanged) — skip INSERT
            // Accumulate totals below then continue
            $totalCpuHours += $data['cpu_hours'];
            $totalMemGBHours += $data['mem_gb_hours'];
            foreach ($data['storage'] as $p => $gbh) {
                if (!isset($totalStorageGBHours[$p])) $totalStorageGBHours[$p] = 0;
                $totalStorageGBHours[$p] += $gbh;
            }
            foreach ($data['backup'] as $t => $gbh) {
                if (!isset($totalBackupGBHours[$t])) $totalBackupGBHours[$t] = 0;
                $totalBackupGBHours[$t] += $gbh;
            }
            foreach ($data['iso'] as $t => $gbh) {
                if (!isset($totalIsoGBHours[$t])) $totalIsoGBHours[$t] = 0;
                $totalIsoGBHours[$t] += $gbh;
            }
            continue;
        }

        // No uninvoiced item — check for invoiced item on completed days
        if ($bucket !== $todayStr) {
            $invoicedExists = Capsule::table('tblbillableitems')
                ->where('userid', $userId)
                ->where('invoicecount', '>', 0)
                ->where('duedate', $bucket)
                ->where('description', 'LIKE', '%(' . $vdcId . ')%')
                ->exists();
            if ($invoicedExists) {
                continue;  // Already invoiced — skip
            }
        }

        // INSERT new item (first time for this day, OR post-invoice continuation for today)
        $apiParams = [
            'clientid'      => $userId,
            'description'   => $desc,
            'amount'        => $dayTotal,
            'unit'          => 'quantity',
            'quantity'      => 1,
            'invoiceaction' => 'nextinvoice',
            'recur'         => 0,
            'duedate'       => $bucket,
        ];
        $apiResult = localAPI('AddBillableItem', $apiParams);
        if (isset($apiResult['result']) && $apiResult['result'] === 'success') {
            $result['items_created']++;
            $result['total_charge'] += $dayTotal;
        } else {
            $error = $apiResult['message'] ?? 'Unknown error';
            logActivity("MultiPortal PAYG: AddBillableItem failed for client {$userId}: {$error}");
            $result['details'][] = "ERROR creating item for {$bucket}: {$error}";
        }

        // Accumulate totals for admin notes
        $totalCpuHours += $data['cpu_hours'];
        $totalMemGBHours += $data['mem_gb_hours'];
        foreach ($data['storage'] as $p => $gbh) {
            if (!isset($totalStorageGBHours[$p])) $totalStorageGBHours[$p] = 0;
            $totalStorageGBHours[$p] += $gbh;
        }
        foreach ($data['backup'] as $t => $gbh) {
            if (!isset($totalBackupGBHours[$t])) $totalBackupGBHours[$t] = 0;
            $totalBackupGBHours[$t] += $gbh;
        }
        foreach ($data['iso'] as $t => $gbh) {
            if (!isset($totalIsoGBHours[$t])) $totalIsoGBHours[$t] = 0;
            $totalIsoGBHours[$t] += $gbh;
        }
    }

    // ── Build summary details for admin notes ──
    $result['details'][] = sprintf("%d daily items across %s to %s",
        $result['items_created'],
        reset($dailyBuckets) !== false ? array_key_first($dailyBuckets) : '?',
        end($dailyBuckets) !== false ? array_key_last($dailyBuckets) : '?'
    );
    if ($totalCpuHours > 0) {
        $result['details'][] = sprintf("CPU: %.2f core-hours @ \$%.4f = \$%.2f", $totalCpuHours, $rates['cpu_per_hour'], round($totalCpuHours * $rates['cpu_per_hour'], 2));
    }
    if ($totalMemGBHours > 0) {
        $result['details'][] = sprintf("RAM: %.2f GB-hours @ \$%.4f = \$%.2f", $totalMemGBHours, $rates['memory_per_gb_hour'], round($totalMemGBHours * $rates['memory_per_gb_hour'], 2));
    }
    foreach ($totalStorageGBHours as $p => $gbh) {
        $result['details'][] = sprintf("Storage (%s): %.2f GB-hours @ \$%.4f = \$%.2f", $p, $gbh, $rates['storage_per_gb_hour'], round($gbh * $rates['storage_per_gb_hour'], 2));
    }
    foreach ($totalBackupGBHours as $t => $gbh) {
        $result['details'][] = sprintf("Backup (%s): %.2f GB-hours @ \$%.4f = \$%.2f", $t, $gbh, $rates['backup_storage_per_gb_hour'], round($gbh * $rates['backup_storage_per_gb_hour'], 2));
    }
    foreach ($totalIsoGBHours as $t => $gbh) {
        $result['details'][] = sprintf("ISO (%s): %.2f GB-hours @ \$%.4f = \$%.2f", $t, $gbh, $rates['iso_storage_per_gb_hour'], round($gbh * $rates['iso_storage_per_gb_hour'], 2));
    }

    return $result;
}

/**
 * Delete all uninvoiced PAYG billable items for a specific VDC.
 *
 * Identifies items by VDC UUID in the description text. Returns the count
 * of deleted items and the latest invoiced item's duedate (for sync reset).
 *
 * @param int    $userId  WHMCS client ID
 * @param string $vdcId   VDC UUID
 * @return array ['deleted' => int, 'last_invoiced_date' => string|null]
 */
function multiportal_deleteUninvoicedPAYGItems($userId, $vdcId)
{
    // Find latest invoiced item date for this VDC (for sync date reset)
    // tblbillableitems uses invoicecount (not invoiceid) to track invoicing
    $lastInvoicedDate = Capsule::table('tblbillableitems')
        ->where('userid', $userId)
        ->where('invoicecount', '>', 0)
        ->where('description', 'LIKE', '%(' . $vdcId . ')%')
        ->orderBy('duedate', 'desc')
        ->value('duedate');

    // Delete uninvoiced items (invoicecount = 0 means not yet collected)
    $deleted = Capsule::table('tblbillableitems')
        ->where('userid', $userId)
        ->where('invoicecount', 0)
        ->where('description', 'LIKE', '%(' . $vdcId . ')%')
        ->delete();

    return [
        'deleted'            => $deleted,
        'last_invoiced_date' => $lastInvoicedDate,
    ];
}

/**
 * Provision a new VDC: create tenant, user, VDC, and attach storage policies.
 */
function multiportal_CreateAccount(array $params)
{
    try {
        // Check if VDC already exists
        $existingVdcId = isset($params['customfields']['VDC UUID']) ? $params['customfields']['VDC UUID'] : getProductCustomFieldValue($params['serviceid'], 'VDC UUID');
        if (!empty($existingVdcId)) {
            multiportal_log('CreateAccount', ['vdc_id' => $existingVdcId], 'VDC already exists, aborting');
            return 'VDC UUID is already set (' . $existingVdcId . ') — VDC already exists.';
        }
        
        // Validate configuration
        $validationErrors = multiportal_validateConfig($params);
        if (!empty($validationErrors)) {
            throw new Exception('Configuration errors: ' . implode(', ', $validationErrors));
        }

        multiportal_log('CreateAccount', $params['clientsdetails'], 'Starting account creation', [], ['serverpassword' => '***']);

        $api = initiateAPI($params);
        $resellerMgr = new ResellerManager($api);
        $tenantMgr = new TenantManager($api);
        $vdcMgr = new VDCManager($api);

        // Use ModuleConfiguration to get values
        $dataCenterId = ModuleConfiguration::get($params, ModuleConfiguration::FIELD_DATA_CENTER_ID);
        $resellerId = ModuleConfiguration::get($params, ModuleConfiguration::FIELD_RESELLER_UUID);
        $res = $vdcMgr->getStoragePoliciesByDataCenter($dataCenterId);
        $storagePolicyConfig = verifyStoragePolicyOptions($params['configoptions'], $res);

        // Determine allocation type early — needed for storage fallback below
        $allocationType = multiportal_getAllocationType($params);

        // PAYG storage options are hidden (hidden=1) and WHMCS does not pass
        // hidden configurable options through $params['configoptions'] during
        // provisioning. Fall back to reading the config group from the DB.
        if (empty($storagePolicyConfig) && $allocationType === 2) {
            $storagePolicyConfig = getPaygStoragePoliciesFromDb($params['pid'], $res);
        }

        // 1. Check Reseller
        $reseller = $resellerMgr->findResellerByID($resellerId);
        //throw error if reseller not found
        if (!$reseller) {
            throw new Exception('Reseller not found. (ID: ' . $resellerId . ')');
        }

        // 2. Create/check Tenant
        // Ensure per-product Tenant UUID and URL fields exist so they show in admin UI
        ensureProductCustomFields($params['pid']);
        // Check service-level first, then same-server reuse, then client-level fallback
        $tenantUUID = multiportal_getCredential($params, 'Tenant UUID', 'MultiPortal Tenant UUID');

        // Remember if THIS service already had its own Tenant UUID before this run.
        // Used later to decide whether $params['username'] is a real MultiPortal user
        // or just a WHMCS auto-generated placeholder. Only checks service-level custom
        // fields — client-level fallback doesn't count (means un-migrated, not provisioned).
        $serviceAlreadyProvisioned = !empty($params['customfields']['Tenant UUID'] ?? '')
            || !empty(getProductCustomFieldValue($params['serviceid'], 'Tenant UUID'));

        // If no tenant found yet, check if another service on the same server has one
        if (empty($tenantUUID)) {
            $existingCreds = multiportal_findExistingCredentials(
                $params['userid'], $params['serverid'], $params['serviceid']
            );
            if ($existingCreds && !empty($existingCreds['tenant_uuid'])) {
                $tenantUUID = $existingCreds['tenant_uuid'];
            }
        }

        if (empty($tenantUUID)) {
            if (!empty($params['clientsdetails']['companyname']))
                $tenant_name = $params['clientsdetails']['companyname'];
            else
                $tenant_name = $params['clientsdetails']['firstname'] . ' ' . $params['clientsdetails']['lastname'];

            $tenant = $tenantMgr->createTenant(
                $tenant_name,
                $resellerId,
                $params['clientsdetails']['address1'],
                $params['clientsdetails']['firstname'] . ' ' . $params['clientsdetails']['lastname'],
                $params['clientsdetails']['phonenumber']
            );
            // Store tenant UUID in service-level custom field
            setCustomFieldValue($params['serviceid'], 'Tenant UUID', $tenant['uuid']);
        } else {
            $tenant = $tenantMgr->findTenantByID($tenantUUID);
            // Ensure this service has the tenant UUID in its own custom field
            setCustomFieldValue($params['serviceid'], 'Tenant UUID', $tenantUUID);
        }

        if (!$tenant) {
            throw new Exception('Failed to create or find tenant.');
        }

        // 2.5. Create user for the tenant if it doesn't exist
        // One user per client per MultiPortal server — reuse across services on same server
        // Credentials stored in tblhosting.username/tblhosting.password (WHMCS native fields)

        // WHMCS auto-generates a placeholder username from the domain when an order
        // is submitted (e.g. "allocpr"), before CreateAccount runs. We must NOT treat
        // this as a real MultiPortal user. Only trust $params['username'] if the
        // service already had a Tenant UUID (meaning it was previously provisioned).
        $existingUsername = ($serviceAlreadyProvisioned && !empty($params['username']))
            ? $params['username'] : '';

        // If not, check same-server sibling services
        if (empty($existingUsername)) {
            if (!isset($existingCreds)) {
                $existingCreds = multiportal_findExistingCredentials(
                    $params['userid'], $params['serverid'], $params['serviceid']
                );
            }
            if ($existingCreds && !empty($existingCreds['username'])) {
                $existingUsername = $existingCreds['username'];
            }
        }

        // Also check client-level custom field as backwards-compat fallback
        if (empty($existingUsername)) {
            $existingUsername = getClientCustomFieldValue($params, 'MultiPortal Username');
        }

        if (empty($existingUsername)) {
            // No existing credentials — create new user in MultiPortal
            $emailParts = explode('@', $params['clientsdetails']['email']);
            $baseUsername = $emailParts[0];
            $multiportalUsername = $baseUsername . '_' . $params['userid'];
            $multiportalPassword = generateSecurePassword(16);

            try {
                multiportal_log('CreateAccount', [
                    'tenant_uuid' => $tenant['uuid'],
                    'username' => $multiportalUsername,
                    'email' => $params['clientsdetails']['email'],
                    'client_id' => $params['userid']
                ], 'Attempting to create user');

                try {
                    $user = $tenantMgr->createUser(
                        $tenant['uuid'],
                        $multiportalUsername,
                        $multiportalPassword,
                        $params['clientsdetails']['email'],
                        $params['clientsdetails']['firstname'],
                        $params['clientsdetails']['lastname'],
                        'Tenant Administrator'
                    );
                    multiportal_log('CreateAccount', ['user_response' => $user], 'User creation API response');
                } catch (Exception $createEx) {
                    $errorMsg = $createEx->getMessage();
                    if (strpos($errorMsg, 'already been taken') !== false || strpos($errorMsg, '422') !== false) {
                        // User already exists — find them and reset password
                        $existingUser = $tenantMgr->findUserInTenant(
                            $tenant['uuid'], $multiportalUsername, $params['clientsdetails']['email']
                        );
                        if ($existingUser && !empty($existingUser['id'])) {
                            $multiportalUsername = $existingUser['username'];
                            $tenantMgr->updateUser($tenant['uuid'], $existingUser['id'], [
                                'password' => $multiportalPassword,
                                'confirmPassword' => $multiportalPassword,
                            ]);
                            multiportal_log('CreateAccount', [
                                'user_id' => $existingUser['id'],
                                'username' => $multiportalUsername,
                            ], 'Existing user found — password reset');
                        } else {
                            throw new Exception('User already exists but could not be found for password reset.');
                        }
                    } else {
                        throw $createEx;
                    }
                }

                // Store credentials in tblhosting (WHMCS passes these as $params['username']/$params['password'])
                Capsule::table('tblhosting')
                    ->where('id', $params['serviceid'])
                    ->update([
                        'username' => $multiportalUsername,
                        'password' => encrypt($multiportalPassword)
                    ]);

                multiportal_log('CreateAccount', [
                    'username' => $multiportalUsername,
                    'service_id' => $params['serviceid']
                ], 'Credentials stored in tblhosting');
            } catch (Exception $e) {
                multiportal_log('CreateAccount', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'client_id' => $params['userid']
                ], 'Failed to create user - will retry later');
            }
        } else {
            // Reuse existing credentials from same server — copy to this service's tblhosting
            if ($existingCreds && !empty($existingCreds['password_encrypted'])) {
                // Copy encrypted password directly from sibling service
                Capsule::table('tblhosting')
                    ->where('id', $params['serviceid'])
                    ->update([
                        'username' => $existingCreds['username'],
                        'password' => $existingCreds['password_encrypted']
                    ]);
            }

            multiportal_log('CreateAccount', [
                'username' => $existingUsername,
                'service_id' => $params['serviceid']
            ], 'Reusing existing credentials from same server');

            // Copy URL from sibling service if available
            if ($existingCreds && !empty($existingCreds['url'])) {
                setCustomFieldValue($params['serviceid'], 'URL', $existingCreds['url']);
            }
        }

        // Store portal URL in service-level custom field (derive from API URL)
        $apiUrl = ModuleConfiguration::get($params, ModuleConfiguration::FIELD_API_URL);
        if (!empty($apiUrl)) {
            $portalUrl = rtrim($apiUrl, '/');
            $portalUrl = preg_replace('#/api(/v\d+)?$#', '', $portalUrl);
            setCustomFieldValue($params['serviceid'], 'URL', $portalUrl);
        }

        // 3. Create VDC
        // For PAYG, CPU/Memory are not selected by the client.
        // API requires minimum 1 for both, so default to 1.
        $cpu = isset($params['configoptions']['CPU'])
            ? max(1, (int) $params['configoptions']['CPU']) : 1;
        $memory = isset($params['configoptions']['Memory Allocation'])
            ? max(1, (int) $params['configoptions']['Memory Allocation']) : 1;

        $vdc = $vdcMgr->createVDC(
            'VDC - ' . $params['serviceid'],
            $dataCenterId,
            $tenant['uuid'],
            $cpu,
            $memory,
            true,
            $allocationType,
        );

        setCustomFieldValue($params['serviceid'], 'VDC UUID', $vdc['uuid']);
        if (isset($vdc['error'])) {
            throw new Exception('Failed to create VDC: ' . $vdc['error']);
        }


        foreach ($storagePolicyConfig as $storagePolicy) {
            $vdcMgr->addStoragePolicy($vdc['uuid'], [
                'storage_policy_id' => $storagePolicy['storage_policy_id'],
                'capacity' => $storagePolicy['capacity'],
            ]);
        }

        // Seed Last Usage Sync so the billing cron has a proper starting point
        if ($allocationType === 2) {
            setCustomFieldValue($params['serviceid'], 'Last Usage Sync', date('Y-m-d H:i:s'));
        }

        $allocLabel = $allocationType === 2 ? 'PAYG' : 'Allocation';
        multiportal_appendAdminNote($params['serviceid'],
            "CreateAccount: VDC created ({$vdc['uuid']}). Tenant: {$tenant['name']} ({$tenant['uuid']}). "
            . "Type: {$allocLabel}. CPU: {$cpu}, RAM: {$memory}GB. Storage policies: " . count($storagePolicyConfig));

        return 'success';
    } catch (Exception $e) {
        multiportal_appendAdminNote($params['serviceid'],
            "CreateAccount ERROR: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Verify Storage Policy Options
 *
 * @param array $configOptions
 * @param array $res
 * @return array
 */
function verifyStoragePolicyOptions($configOptions, $res)
{
    $storageConfig = [];
    foreach ($res['data'] as $storagePolicy) {
        $configOptionsFormat = "Storage - {$storagePolicy['name']}";
        if (!isset($configOptions[$configOptionsFormat])) {
            continue;
        }
        $storageQty = (int) $configOptions[$configOptionsFormat];
        if ($storageQty < 1) {
            continue; // Skip policies with 0 capacity — not selected
        }
        $storageConfig[] = [
            'name' => $storagePolicy['name'],
            'storage_policy_id' => $storagePolicy['uuid'],
            'capacity' => $storageQty,
        ];
    }
    return $storageConfig;
}

/**
 * Get PAYG storage policies from the database.
 *
 * Hidden configurable options (hidden=1) are not passed through in
 * $params['configoptions'] by WHMCS during provisioning. For PAYG products
 * whose storage options are hidden, this function reads the linked config
 * group directly from the DB and returns policies where qtyminimum >= 1
 * (i.e. admin has opted-in that policy for auto-provisioning).
 *
 * @param int   $productId         WHMCS product ID ($params['pid'])
 * @param array $dcStoragePolicies API response from getStoragePoliciesByDataCenter()
 * @return array Same format as verifyStoragePolicyOptions()
 */
function getPaygStoragePoliciesFromDb($productId, $dcStoragePolicies)
{
    $storageConfig = [];

    // Find the linked MultiPortal config group for this product
    $groupId = Capsule::table('tblproductconfiglinks')
        ->join('tblproductconfiggroups', 'tblproductconfiglinks.gid', '=', 'tblproductconfiggroups.id')
        ->where('tblproductconfiglinks.pid', $productId)
        ->where('tblproductconfiggroups.name', 'LIKE', '%MultiPortal%')
        ->value('tblproductconfiglinks.gid');

    if (!$groupId) {
        return $storageConfig;
    }

    // Get storage options from this group where admin set qtyminimum >= 1
    $storageOptions = Capsule::table('tblproductconfigoptions')
        ->where('gid', (int) $groupId)
        ->where('optionname', 'LIKE', 'Storage - %')
        ->where('qtyminimum', '>=', 1)
        ->get();

    // Build a lookup from policy name to API UUID
    $policyLookup = [];
    if (isset($dcStoragePolicies['data']) && is_array($dcStoragePolicies['data'])) {
        foreach ($dcStoragePolicies['data'] as $policy) {
            $policyLookup[$policy['name']] = $policy['uuid'];
        }
    }

    foreach ($storageOptions as $option) {
        // Extract policy name from "Storage - {name}"
        $policyName = substr($option->optionname, strlen('Storage - '));
        if (isset($policyLookup[$policyName])) {
            $storageConfig[] = [
                'name' => $policyName,
                'storage_policy_id' => $policyLookup[$policyName],
                'capacity' => max(1, (int) $option->qtyminimum),
            ];
        }
    }

    return $storageConfig;
}

/**
 * Client Area Output - Display VDC information to clients
 */
function multiportal_ClientArea(array $params)
{
    // Debug: Create a simple file to track function calls
    file_put_contents(__DIR__ . '/debug_clientarea_calls.log', 
        date('Y-m-d H:i:s') . " - multiportal_ClientArea called for service " . ($params['serviceid'] ?? 'unknown') . "\n", 
        FILE_APPEND | LOCK_EX);
    
    // Debug: Log that the function is being called
    multiportal_log('ClientArea', $params, 'Function called', ['message' => 'multiportal_ClientArea function called']);
    
    try {
        // Get MultiPortal credentials from service fields
        $multiportalUsername = $params['username'];
        $multiportalPassword = $params['password'];
        
        // ALWAYS get URL from the API URL configuration - just remove the /api part
        try {
            $apiUrl = ModuleConfiguration::get($params, ModuleConfiguration::FIELD_API_URL);
            // Remove /api/v1 or /api from the end
            $multiportalUrl = rtrim($apiUrl, '/');
            $multiportalUrl = preg_replace('#/api(/v\d+)?$#', '', $multiportalUrl);
        } catch (Exception $e) {
            throw new Exception('MultiPortal API URL not configured. Please check server configuration.');
        }
        
        // API credentials have been removed - using main portal credentials only
        
        // Debug: Log retrieved credentials
        multiportal_log('ClientArea', $params, 'Credentials retrieved', [
            'multiportal_username' => $multiportalUsername ?: 'EMPTY',
            'multiportal_password' => $multiportalPassword ? '[SET]' : 'EMPTY',
            'multiportal_url' => $multiportalUrl ?: 'EMPTY'
        ]);
        
        // Get VDC UUID from custom field using helper function
        $vdcId = isset($params['customfields']['VDC UUID']) ? $params['customfields']['VDC UUID'] : getProductCustomFieldValue($params['serviceid'], 'VDC UUID');

        // Initialize template variables
        $templateVars = [
            'status' => 'active',
            'multiportal' => [
                'username' => $multiportalUsername,
                'password' => $multiportalPassword,
                'url' => $multiportalUrl
            ],
            // API credentials removed - using main portal credentials
            'vdc' => null,
            'storage_policies' => [],
            'usage' => null
        ];

        // If no VDC created yet, show credentials but pending VDC message
        if (empty($vdcId)) {
            $templateVars['status'] = 'pending';
            $templateVars['message'] = 'Your Virtual Data Center is being provisioned. Please check back later.';
            
            // Still show credentials if they exist
            return [
                'templatefile' => 'templates/clientarea',
                'vars' => $templateVars
            ];
        }

        // Try to get VDC details if VDC exists
        try {
            $api = initiateAPI($params);
            $vdcMgr = new VDCManager($api);

            // Get VDC details
            $vdc = $vdcMgr->getVDCById($vdcId);
            if (!$vdc || isset($vdc['error'])) {
                throw new Exception('Unable to fetch Virtual Data Center details: ' . ($vdc['error'] ?? 'Virtual Data Center not found'));
            }

            // Get storage policies
            $storagePolicies = $vdcMgr->getStoragePolicy($vdcId);

            // Get usage data with specific date range
            $usage = null;
            try {
                // Get current month usage
                // Use standard date format Y-m-d
                $monthStart = date('Y-m-01'); // First day of current month
                $monthEnd = date('Y-m-d'); // Today
                $currentMonthParams = [
                    'date_range' => $monthStart . ' 00:00:00 - ' . $monthEnd . ' 23:59:59'
                ];
                $usage = $vdcMgr->getVDCUsage($vdcId, $currentMonthParams);
                
                // Debug log the usage structure
                multiportal_log('ClientArea', [
                    'vdcId' => $vdcId,
                    'date_range' => $currentMonthParams['date_range'],
                    'usage_data' => $usage,
                    'usage_type' => gettype($usage),
                    'usage_keys' => is_array($usage) ? array_keys($usage) : 'not an array'
                ], 'Current day usage data fetched');
            } catch (Exception $e) {
                // Log but don't fail if usage data is unavailable
                multiportal_log('ClientArea', ['error' => 'Failed to fetch usage: ' . $e->getMessage()], 'Usage fetch failed');
            }

            // Update template variables with VDC data
            $templateVars['vdc'] = [
                'name' => $vdc['vdc_name'],
                'status' => $vdc['is_enabled'] ? 'Active' : 'Suspended',
                'cpu_cores' => $vdc['core_count'],
                'memory_gb' => $vdc['memory_in_gb'],
                'allocation_type' => $vdc['allocation_type'] == 1 ? 'Allocation Pool' : 'Pay As You Go',
                'created_at' => $vdc['created_at'] ?? 'N/A',
            ];
            
            // Format usage data for template - use the formatted_usage section from API
            if ($usage && isset($usage['formatted_usage'])) {
                $formatted = $usage['formatted_usage'];
                $resourceSummary = $formatted['resource_summary'] ?? [];
                $vmStats = $formatted['vm_statistics'] ?? [];
                $storageSummary = $formatted['storage_summary'] ?? [];
                
                // Get VM status
                $runningVMs = $vmStats['active_vms'] ?? 0;
                $totalVMs = $vmStats['total_vms'] ?? 0;
                
                // Get formatted totals from API
                $totalUptimeHours = $vmStats['total_runtime_hours'] ?? 0;
                $totalCpuHours = $resourceSummary['cpu']['total_usage_raw'] ?? 0;
                $totalMemoryGbHours = $resourceSummary['memory']['total_usage_gb_hours'] ?? 0;
                
                // Convert GB-hours to TiB-hours for consistency
                $totalMemoryTiBHours = $totalMemoryGbHours / 1024;
                
                // Get storage usage from formatted storage summary
                $storageBreakdown = [];
                foreach ($storageSummary as $storageName => $storageData) {
                    $avgUsageGB = floatval(str_replace(' GB', '', $storageData['average_usage'] ?? '0'));
                    $capacityGB = floatval(str_replace(' GB', '', $storageData['capacity'] ?? '0'));
                    $storageBreakdown[] = [
                        'name' => $storageName,
                        'usage_gb' => $avgUsageGB,
                        'capacity_gb' => $capacityGB,
                        'utilization' => $storageData['utilization'] ?? '0%'
                    ];
                }
                
                // Get the date range from the API response
                $dateRange = $usage['summary']['date_range'] ?? [];
                $apiStartDate = $dateRange['start'] ?? '';
                $apiEndDate = $dateRange['end'] ?? '';
                
                // Get the date range we requested (use same format as API)
                $requestedStart = $monthStart . ' 00:00:00';
                $requestedEnd = $monthEnd . ' 23:59:59';
                
                // Get pricing from config options using ModuleConfiguration
                $cpuPricePerHour = floatval(ModuleConfiguration::get($params, ModuleConfiguration::FIELD_PAYG_CPU_RATE, false) ?? 0.10);
                $memoryPricePerGBHour = floatval(ModuleConfiguration::get($params, ModuleConfiguration::FIELD_PAYG_MEMORY_RATE, false) ?? 0.05);
                $storagePricePerGBHour = floatval(ModuleConfiguration::get($params, ModuleConfiguration::FIELD_PAYG_STORAGE_RATE, false) ?? 0.01);
                
                // Calculate costs
                $cpuCost = $totalCpuHours * $cpuPricePerHour;
                $memoryCost = $totalMemoryGbHours * $memoryPricePerGBHour;
                
                // Storage cost calculation (total hours * average GB * rate)
                $totalStorageCost = 0;
                $periodHours = (strtotime($apiEndDate) - strtotime($apiStartDate)) / 3600;
                foreach ($storageBreakdown as $storage) {
                    $storageCost = $storage['usage_gb'] * $periodHours * $storagePricePerGBHour;
                    $totalStorageCost += $storageCost;
                }
                
                $totalCost = $cpuCost + $memoryCost + $totalStorageCost;
                
                $formattedUsage = [
                    'total_uptime' => round($totalUptimeHours, 1),
                    'total_cpu_hours' => round($totalCpuHours, 1),
                    'total_memory_tib' => round($totalMemoryTiBHours, 2),
                    'total_memory_gb_hours' => round($totalMemoryGbHours, 1),
                    'storage_breakdown' => $storageBreakdown,
                    'pricing' => [
                        'rates' => [
                            'cpu_per_hour' => $cpuPricePerHour,
                            'memory_per_gb_hour' => $memoryPricePerGBHour,
                            'storage_per_gb_hour' => $storagePricePerGBHour
                        ],
                        'costs' => [
                            'cpu' => round($cpuCost, 2),
                            'memory' => round($memoryCost, 2),
                            'storage' => round($totalStorageCost, 2),
                            'total' => round($totalCost, 2)
                        ]
                    ],
                    'period' => [
                        'requested_start' => $requestedStart,
                        'requested_end' => $requestedEnd,
                        'api_start' => $apiStartDate,
                        'api_end' => $apiEndDate
                    ],
                    'vms' => [
                        'running' => $runningVMs,
                        'total' => $totalVMs
                    ]
                ];
                
                $templateVars['usage'] = $formattedUsage;
            } else {
                $templateVars['usage'] = null;
            }

            // Format storage policies
            if (isset($storagePolicies['data']) && is_array($storagePolicies['data'])) {
                foreach ($storagePolicies['data'] as $policy) {
                    $templateVars['storage_policies'][] = [
                        'name' => $policy['storage_policy_name'] ?? 'Unknown',
                        'capacity' => $policy['capacity'] ?? 0,
                        'used' => $policy['used_capacity'] ?? 0,
                        'available' => ($policy['capacity'] ?? 0) - ($policy['used_capacity'] ?? 0)
                    ];
                }
            }
        } catch (Exception $e) {
            // VDC exists but can't fetch details - show error but still show credentials
            multiportal_log('ClientArea', ['vdcId' => $vdcId, 'error' => $e->getMessage()], 'VDC fetch failed');
            $templateVars['status'] = 'error';
            $templateVars['message'] = 'Unable to load Virtual Data Center information. Please contact support if this persists.';
        }

        // Debug: Log template variables being returned
        multiportal_log('ClientArea', $params, 'Template variables', [
            'template_vars' => $templateVars,
            'template_file' => 'templates/clientarea'
        ]);
        
        return [
            'templatefile' => 'templates/clientarea',
            'vars' => $templateVars,
            'breadcrumb' => [],
            'overrideDisplayName' => 'MultiPortal VDC'
        ];
    } catch (Exception $e) {
        multiportal_log('ClientArea', $params, ['error' => $e->getMessage()]);
        
        $errorVars = [
            'status' => 'error',
            'message' => 'Unable to load Virtual Data Center information. Please contact support.'
        ];
        
        // Debug: Log error template variables
        multiportal_log('ClientArea', $params, 'Error occurred', [
            'template_vars' => $errorVars,
            'template_file' => 'templates/clientarea',
            'error' => $e->getMessage()
        ]);
        
        return [
            'templatefile' => 'templates/clientarea',
            'vars' => $errorVars,
            'breadcrumb' => [],
            'overrideDisplayName' => 'MultiPortal VDC'
        ];
    }
}

/**
 * Suspend/Disable VDC
 */
function multiportal_DisableVdc(array $params)
{
    try {
        $api = initiateAPI($params);
        $vdcMgr = new VDCManager($api);

        $vdcId = isset($params['customfields']['VDC UUID']) ? $params['customfields']['VDC UUID'] : getProductCustomFieldValue($params['serviceid'], 'VDC UUID');
        if (empty($vdcId)) {
            throw new Exception('VDC UUID not found. Cannot suspend non-existent VDC.');
        }

        multiportal_log('DisableVdc', ['vdcId' => $vdcId], 'Suspending VDC');

        // Update VDC to set is_enabled to 0
        $response = $vdcMgr->updateVDC($vdcId, [
            'is_enabled' => 0
        ]);

        multiportal_log('DisableVdc', ['vdcId' => $vdcId], $response);

        if (isset($response['error'])) {
            throw new Exception('Failed to suspend VDC: ' . $response['error']);
        }

        // Update service status in WHMCS
        Capsule::table('tblhosting')
            ->where('id', $params['serviceid'])
            ->update(['domainstatus' => 'Suspended']);

        multiportal_appendAdminNote($params['serviceid'],
            "Suspend VDC: VDC {$vdcId} suspended. WHMCS status set to Suspended.");

        return 'success';
    } catch (Exception $e) {
        multiportal_log('DisableVdc', $params, ['error' => $e->getMessage()]);
        multiportal_appendAdminNote($params['serviceid'],
            "Suspend VDC ERROR: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Unsuspend/Enable VDC
 */
function multiportal_EnableVdc(array $params)
{
    try {
        $api = initiateAPI($params);
        $vdcMgr = new VDCManager($api);

        $vdcId = isset($params['customfields']['VDC UUID']) ? $params['customfields']['VDC UUID'] : getProductCustomFieldValue($params['serviceid'], 'VDC UUID');
        if (empty($vdcId)) {
            throw new Exception('VDC UUID not found. Cannot unsuspend non-existent VDC.');
        }

        multiportal_log('EnableVdc', ['vdcId' => $vdcId], 'Unsuspending VDC');

        // Update VDC to set is_enabled to 1
        $response = $vdcMgr->updateVDC($vdcId, [
            'is_enabled' => 1
        ]);

        multiportal_log('EnableVdc', ['vdcId' => $vdcId], $response);

        if (isset($response['error'])) {
            throw new Exception('Failed to unsuspend VDC: ' . $response['error']);
        }

        // Update service status in WHMCS
        Capsule::table('tblhosting')
            ->where('id', $params['serviceid'])
            ->update(['domainstatus' => 'Active']);

        multiportal_appendAdminNote($params['serviceid'],
            "Unsuspend VDC: VDC {$vdcId} unsuspended. WHMCS status set to Active.");

        return 'success';
    } catch (Exception $e) {
        multiportal_log('EnableVdc', $params, ['error' => $e->getMessage()]);
        multiportal_appendAdminNote($params['serviceid'],
            "Unsuspend VDC ERROR: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Permanently delete a VDC after admin confirmation.
 */
function multiportal_DestroyVdc(array $params)
{
    try {
        // Use database for confirmation tracking
        $confirmKey = 'delete_vdc_confirm';
        $serviceId = $params['serviceid'];

        // Check if confirmation exists
        $confirmation = Capsule::table('tblcustomfieldsvalues')
            ->where('relid', $serviceId)
            ->where('fieldid', function ($query) use ($confirmKey) {
                $query->select('id')
                    ->from('tblcustomfields')
                    ->where('type', 'product')
                    ->where('fieldname', $confirmKey)
                    ->limit(1);
            })
            ->first();

        $now = time();

        if (!$confirmation || !$confirmation->value || ($now - (int)$confirmation->value > 30)) {
            // First click - set confirmation timestamp

            // Ensure the custom field exists
            $field = Capsule::table('tblcustomfields')
                ->where('type', 'product')
                ->where('fieldname', $confirmKey)
                ->first();

            if (!$field) {
                Capsule::table('tblcustomfields')->insert([
                    'type' => 'product',
                    'fieldname' => $confirmKey,
                    'fieldtype' => 'text',
                    'adminonly' => 'on',
                    'sortorder' => 999
                ]);
                $fieldId = Capsule::getPdo()->lastInsertId();
            } else {
                $fieldId = $field->id;
            }

            // Set or update the confirmation timestamp
            if ($confirmation) {
                Capsule::table('tblcustomfieldsvalues')
                    ->where('id', $confirmation->id)
                    ->update(['value' => $now]);
            } else {
                Capsule::table('tblcustomfieldsvalues')->insert([
                    'fieldid' => $fieldId,
                    'relid' => $serviceId,
                    'value' => $now
                ]);
            }

            return 'WARNING: Click Delete Virtual Data Center again within 30 seconds to confirm permanent deletion. This action cannot be undone!';
        }

        // Second click - clear confirmation and proceed with deletion
        Capsule::table('tblcustomfieldsvalues')
            ->where('relid', $serviceId)
            ->where('fieldid', $confirmation->fieldid)
            ->update(['value' => '']);

        $api = initiateAPI($params);
        $vdcMgr = new VDCManager($api);
        $vdcId = isset($params['customfields']['VDC UUID']) ? $params['customfields']['VDC UUID'] : getProductCustomFieldValue($params['serviceid'], 'VDC UUID');

        if (empty($vdcId)) {
            throw new Exception('VDC UUID not found.');
        }

        multiportal_log('DestroyVdc', ['vdcId' => $vdcId], 'Deleting VDC');

        $res = $vdcMgr->deleteVDC($vdcId);
        if (isset($res['error'])) {
            throw new Exception('Failed to delete VDC: ' . $res['error']);
        }

        multiportal_log('DestroyVdc', ['vdcId' => $vdcId], 'VDC deleted successfully');

        setCustomFieldValue($params['serviceid'], 'VDC UUID', '');

        multiportal_appendAdminNote($params['serviceid'],
            "DELETE VDC: VDC {$vdcId} permanently deleted. VDC UUID cleared.");

        return 'success';
    } catch (Exception $e) {
        // Clear confirmation on error
        unset($_SESSION['confirm_delete_vdc_' . $params['serviceid']]);
        multiportal_appendAdminNote($params['serviceid'],
            "DELETE VDC ERROR: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Clean up stale delete confirmations
 */
function multiportal_cleanupConfirmations()
{
    if (isset($_SESSION)) {
        $now = time();
        foreach ($_SESSION as $key => $value) {
            if (strpos($key, 'confirm_delete_vdc_') === 0 && is_array($value)) {
                if ($now - $value['time'] > 30) {
                    unset($_SESSION[$key]);
                }
            }
        }
    }
}

/**
 * Setup Wizard - Automatically create configurable options for this product
 */
function multiportal_SetupWizard(array $params)
{
    try {
        // Validate API credentials and test connection
        try {
            multiportal_log('SetupWizard', [
                'params_keys' => array_keys($params),
                'has_serverusername' => isset($params['serverusername']),
                'has_serverpassword' => isset($params['serverpassword']),
                'serverusername_value' => isset($params['serverusername']) ? substr($params['serverusername'], 0, 5) . '...' : 'NOT SET',
                'server_id' => $params['serverid'] ?? 'NO SERVER ID',
                'product_id' => $params['pid'] ?? 'NO PRODUCT ID'
            ], 'Debug: Checking params structure');

            $clientId = ModuleConfiguration::getClientId($params);
            $clientSecret = ModuleConfiguration::getClientSecret($params);

            $api = initiateAPI($params);

            multiportal_log('SetupWizard', ['action' => 'API initialized'], 'API client created');

        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Client ID') !== false || strpos($e->getMessage(), 'Client Secret') !== false) {
                return 'Error: Server credentials are empty. Go to System Settings > Servers and edit the Multiportal Server to add your API credentials.';
            }
            return 'Error: ' . $e->getMessage();
        }

        // Ensure custom fields exist
        try {
            ensureCustomFieldsExist();
            ensureProductCustomFields($params['pid']);
        } catch (Exception $e) {
            return 'Error creating custom fields: ' . $e->getMessage();
        }

        $productId = $params['pid'];
        $dataCenterId = ModuleConfiguration::get($params, ModuleConfiguration::FIELD_DATA_CENTER_ID);

        if (empty($dataCenterId)) {
            throw new Exception('Data Center UUID must be configured in the product module settings first.');
        }

        // Validate Data Center UUID
        try {
            $dataCenterResponse = $api->get('/data-center/' . $dataCenterId);
            if (!$dataCenterResponse || !isset($dataCenterResponse['data'])) {
                throw new Exception('Data Center UUID is invalid or not found.');
            }
            $dataCenterName = $dataCenterResponse['data']['name'] ?? 'Unknown';
            multiportal_log('SetupWizard', ['datacenter' => $dataCenterName], 'Data Center validated');
        } catch (Exception $e) {
            return 'Data Center Validation Error: ' . $e->getMessage() . ' Please check the Data Center UUID in module settings.';
        }

        // Create product-specific VDC UUID custom field
        $vdcField = Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->where('relid', $productId)
            ->where('fieldname', 'VDC UUID')
            ->first();

        if (!$vdcField) {
            Capsule::table('tblcustomfields')->insert([
                'type' => 'product',
                'relid' => $productId,
                'fieldname' => 'VDC UUID',
                'fieldtype' => 'text',
                'description' => 'Stores the Virtual Data Center identifier',
                'fieldoptions' => '',
                'regexpr' => '',
                'adminonly' => 'on',
                'required' => '',
                'showorder' => '',
                'showinvoice' => '',
                'sortorder' => 0
            ]);
        }

        // Determine this product's allocation type from configoption7
        $allocationType = multiportal_getAllocationType($params);
        $isPayg = ($allocationType === 2);

        multiportal_log('SetupWizard', [
            'allocation_type' => $allocationType,
            'is_payg' => $isPayg,
            'configoption7' => $params['configoption7'] ?? 'NOT SET'
        ], 'Allocation type determined');

        // Check if this product already has a MultiPortal config group linked
        $existingLink = Capsule::table('tblproductconfiglinks')
            ->join('tblproductconfiggroups', 'tblproductconfiglinks.gid', '=', 'tblproductconfiggroups.id')
            ->where('tblproductconfiglinks.pid', $productId)
            ->where('tblproductconfiggroups.name', 'LIKE', '%MultiPortal%')
            ->first();

        if ($existingLink) {
            // If the linked group has options, it's fully set up — nothing to do
            $linkedOptionCount = Capsule::table('tblproductconfigoptions')
                ->where('gid', $existingLink->gid)
                ->count();
            if ($linkedOptionCount > 0) {
                return 'Configurable options already exist for this product. Group: ' . $existingLink->name;
            }
            // Otherwise the group is empty (e.g. PAYG group missing marker option) —
            // remove the link so the wizard can re-run and backfill properly
            Capsule::table('tblproductconfiglinks')
                ->where('pid', $productId)
                ->where('gid', $existingLink->gid)
                ->delete();
            multiportal_log('SetupWizard', [
                'product_id' => $productId,
                'empty_group' => $existingLink->name
            ], 'Removed link to empty config group, re-running wizard');
        }

        // --- Pricing helper ---
        $currencies = Capsule::table('tblcurrencies')->select('id')->get();
        $pricingTemplate = [
            'msetupfee' => '0.00', 'qsetupfee' => '0.00', 'ssetupfee' => '0.00',
            'asetupfee' => '0.00', 'bsetupfee' => '0.00', 'tsetupfee' => '0.00',
            'monthly' => '0.00', 'quarterly' => '0.00', 'semiannually' => '0.00',
            'annually' => '0.00', 'biennially' => '0.00', 'triennially' => '0.00'
        ];
        $ensurePricing = function (int $relId) use ($currencies, $pricingTemplate) {
            foreach ($currencies as $currency) {
                $existing = Capsule::table('tblpricing')
                    ->where('type', 'configoptions')
                    ->where('currency', $currency->id)
                    ->where('relid', $relId)
                    ->first();
                if (!$existing) {
                    Capsule::table('tblpricing')->insert(array_merge([
                        'type' => 'configoptions',
                        'currency' => $currency->id,
                        'relid' => $relId
                    ], $pricingTemplate));
                }
            }
        };

        // Fetch storage policies from the data center (needed for both groups)
        $vdcMgr = new VDCManager($api);
        $storagePolicies = $vdcMgr->getStoragePoliciesByDataCenter($dataCenterId);
        $storagePolicyData = (isset($storagePolicies['data']) && is_array($storagePolicies['data']))
            ? $storagePolicies['data'] : [];

        // =====================================================================
        // GROUP 1: MultiPortal Allocation Options (per data center)
        // =====================================================================
        $allocationGroupName = 'MultiPortal Allocation Options - ' . $dataCenterName;
        $allocationGroup = Capsule::table('tblproductconfiggroups')
            ->where('name', $allocationGroupName)
            ->first();

        if (!$allocationGroup) {
            Capsule::table('tblproductconfiggroups')->insert([
                'name' => $allocationGroupName,
                'description' => 'Auto-generated MultiPortal options for Allocation type products (CPU, Memory, Storage)'
            ]);
            $allocationGroupId = (int) Capsule::getPdo()->lastInsertId();

            // CPU option
            Capsule::table('tblproductconfigoptions')->insert([
                'gid' => $allocationGroupId,
                'optionname' => 'CPU',
                'optiontype' => 4, // Quantity
                'qtyminimum' => 1,
                'qtymaximum' => 128,
                'order' => 1,
                'hidden' => 0
            ]);
            $cpuOptionId = Capsule::getPdo()->lastInsertId();
            Capsule::table('tblproductconfigoptionssub')->insert([
                'configid' => $cpuOptionId,
                'optionname' => 'CPU Core',
                'sortorder' => 0,
                'hidden' => 0
            ]);
            $ensurePricing((int) Capsule::getPdo()->lastInsertId());

            // Memory option
            Capsule::table('tblproductconfigoptions')->insert([
                'gid' => $allocationGroupId,
                'optionname' => 'Memory Allocation',
                'optiontype' => 4, // Quantity
                'qtyminimum' => 1,
                'qtymaximum' => 512,
                'order' => 2,
                'hidden' => 0
            ]);
            $memoryOptionId = Capsule::getPdo()->lastInsertId();
            Capsule::table('tblproductconfigoptionssub')->insert([
                'configid' => $memoryOptionId,
                'optionname' => 'GB',
                'sortorder' => 0,
                'hidden' => 0
            ]);
            $ensurePricing((int) Capsule::getPdo()->lastInsertId());

            // Storage policy options
            $order = 3;
            foreach ($storagePolicyData as $policy) {
                if (!empty($policy['name'])) {
                    Capsule::table('tblproductconfigoptions')->insert([
                        'gid' => $allocationGroupId,
                        'optionname' => 'Storage - ' . $policy['name'],
                        'optiontype' => 4, // Quantity
                        'qtyminimum' => 0,
                        'qtymaximum' => 10000,
                        'order' => $order++,
                        'hidden' => 0
                    ]);
                    $storageOptionId = Capsule::getPdo()->lastInsertId();
                    Capsule::table('tblproductconfigoptionssub')->insert([
                        'configid' => $storageOptionId,
                        'optionname' => 'GB',
                        'sortorder' => 0,
                        'hidden' => 0
                    ]);
                    $ensurePricing((int) Capsule::getPdo()->lastInsertId());
                }
            }

            multiportal_log('SetupWizard', ['group_id' => $allocationGroupId], 'Created Allocation Options group');
        } else {
            $allocationGroupId = (int) $allocationGroup->id;
            multiportal_log('SetupWizard', ['group_id' => $allocationGroupId], 'Allocation Options group already exists');
        }

        // =====================================================================
        // GROUP 2: MultiPortal PAYG Options (per data center)
        // =====================================================================
        $paygGroupName = 'MultiPortal PAYG Options - ' . $dataCenterName;
        $paygGroup = Capsule::table('tblproductconfiggroups')
            ->where('name', $paygGroupName)
            ->first();

        if (!$paygGroup) {
            Capsule::table('tblproductconfiggroups')->insert([
                'name' => $paygGroupName,
                'description' => 'Auto-generated MultiPortal options for Pay As You Go products (Storage)'
            ]);
            $paygGroupId = (int) Capsule::getPdo()->lastInsertId();

            // WHMCS requires at least one option in a config group for the product
            // to be recognized as having configurable options set up. Add a hidden
            // marker option that is invisible to clients on the order page.
            Capsule::table('tblproductconfigoptions')->insert([
                'gid' => $paygGroupId,
                'optionname' => 'MP_INIT_FLAG',
                'optiontype' => 2, // Radio
                'qtyminimum' => 0,
                'qtymaximum' => 0,
                'order' => 1,
                'hidden' => 1
            ]);
            $paygMarkerOptionId = Capsule::getPdo()->lastInsertId();
            Capsule::table('tblproductconfigoptionssub')->insert([
                'configid' => $paygMarkerOptionId,
                'optionname' => 'Enabled',
                'sortorder' => 0,
                'hidden' => 0
            ]);
            $ensurePricing((int) Capsule::getPdo()->lastInsertId());

            // Storage policy options — hidden from clients, admin-only.
            // Default 0 (not provisioned). Admin sets qtyminimum=1 on
            // specific policies to always attach them during provisioning.
            $paygOrder = 2;
            foreach ($storagePolicyData as $policy) {
                if (!empty($policy['name'])) {
                    Capsule::table('tblproductconfigoptions')->insert([
                        'gid' => $paygGroupId,
                        'optionname' => 'Storage - ' . $policy['name'],
                        'optiontype' => 4, // Quantity
                        'qtyminimum' => 0,
                        'qtymaximum' => 10000,
                        'order' => $paygOrder++,
                        'hidden' => 1
                    ]);
                    $storageOptionId = Capsule::getPdo()->lastInsertId();
                    Capsule::table('tblproductconfigoptionssub')->insert([
                        'configid' => $storageOptionId,
                        'optionname' => 'GB',
                        'sortorder' => 0,
                        'hidden' => 0
                    ]);
                    $ensurePricing((int) Capsule::getPdo()->lastInsertId());
                }
            }

            multiportal_log('SetupWizard', ['group_id' => $paygGroupId, 'storage_policies' => count($storagePolicyData)], 'Created PAYG Options group');
        } else {
            $paygGroupId = (int) $paygGroup->id;

            // Backfill: if PAYG group exists but has no options, add the hidden marker
            $paygOptionCount = Capsule::table('tblproductconfigoptions')
                ->where('gid', $paygGroupId)
                ->count();
            if ($paygOptionCount === 0) {
                Capsule::table('tblproductconfigoptions')->insert([
                    'gid' => $paygGroupId,
                    'optionname' => 'MP_INIT_FLAG',
                    'optiontype' => 2, // Radio
                    'qtyminimum' => 0,
                    'qtymaximum' => 0,
                    'order' => 1,
                    'hidden' => 1
                ]);
                $paygMarkerOptionId = Capsule::getPdo()->lastInsertId();
                Capsule::table('tblproductconfigoptionssub')->insert([
                    'configid' => $paygMarkerOptionId,
                    'optionname' => 'Enabled',
                    'sortorder' => 0,
                    'hidden' => 0
                ]);
                $ensurePricing((int) Capsule::getPdo()->lastInsertId());
                multiportal_log('SetupWizard', ['group_id' => $paygGroupId], 'Backfilled MP_INIT_FLAG marker option');
            }

            // Backfill: add storage policies if missing from existing PAYG group
            $hasStorageOptions = Capsule::table('tblproductconfigoptions')
                ->where('gid', $paygGroupId)
                ->where('optionname', 'like', 'Storage - %')
                ->exists();
            if (!$hasStorageOptions && !empty($storagePolicyData)) {
                $paygOrder = Capsule::table('tblproductconfigoptions')
                    ->where('gid', $paygGroupId)
                    ->max('order') + 1;
                foreach ($storagePolicyData as $policy) {
                    if (!empty($policy['name'])) {
                        Capsule::table('tblproductconfigoptions')->insert([
                            'gid' => $paygGroupId,
                            'optionname' => 'Storage - ' . $policy['name'],
                            'optiontype' => 4, // Quantity
                            'qtyminimum' => 0,
                            'qtymaximum' => 10000,
                            'order' => $paygOrder++,
                            'hidden' => 1
                        ]);
                        $storageOptionId = Capsule::getPdo()->lastInsertId();
                        Capsule::table('tblproductconfigoptionssub')->insert([
                            'configid' => $storageOptionId,
                            'optionname' => 'GB',
                            'sortorder' => 0,
                            'hidden' => 0
                        ]);
                        $ensurePricing((int) Capsule::getPdo()->lastInsertId());
                    }
                }
                multiportal_log('SetupWizard', ['group_id' => $paygGroupId, 'storage_policies' => count($storagePolicyData)], 'Backfilled storage policy options');
            }

            multiportal_log('SetupWizard', ['group_id' => $paygGroupId], 'PAYG Options group already exists');
        }

        // =====================================================================
        // Link the correct group to THIS product based on configoption7
        // =====================================================================
        $linkedGroupId = $isPayg ? $paygGroupId : $allocationGroupId;
        $linkedGroupName = $isPayg ? $paygGroupName : $allocationGroupName;

        Capsule::table('tblproductconfiglinks')->insert([
            'gid' => $linkedGroupId,
            'pid' => $productId
        ]);

        multiportal_log('SetupWizard', [
            'product_id' => $productId,
            'linked_group' => $linkedGroupName,
            'linked_group_id' => $linkedGroupId,
            'allocation_group_id' => $allocationGroupId,
            'payg_group_id' => $paygGroupId,
            'storage_policies' => count($storagePolicyData)
        ], 'Setup Wizard completed');

        return 'success';
    } catch (Exception $e) {
        multiportal_log('SetupWizard', $params, ['error' => $e->getMessage()]);
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Re-Setup Product Options — sync new storage policies from the data center.
 *
 * Adds any storage policies that exist in the data center but are missing
 * from the product's linked configurable option group. Never removes
 * existing options so admin customizations and client data are preserved.
 *
 * @param array $params WHMCS module parameters
 * @return string 'success' or error message
 */
function multiportal_ReSetupProductOptions(array $params)
{
    try {
        $productId = $params['pid'];
        $dataCenterId = ModuleConfiguration::get($params, ModuleConfiguration::FIELD_DATA_CENTER_ID);

        // Find the linked MultiPortal config group for this product
        $link = Capsule::table('tblproductconfiglinks')
            ->join('tblproductconfiggroups', 'tblproductconfiglinks.gid', '=', 'tblproductconfiggroups.id')
            ->where('tblproductconfiglinks.pid', $productId)
            ->where('tblproductconfiggroups.name', 'LIKE', '%MultiPortal%')
            ->first();

        if (!$link) {
            return 'Error: No MultiPortal config group linked to this product. Run Setup Product Options first.';
        }

        $groupId = (int) $link->gid;
        $groupName = $link->name;
        $isPaygGroup = (stripos($groupName, 'PAYG') !== false);

        // Fetch current storage policies from the data center
        $api = initiateAPI($params);
        $vdcMgr = new VDCManager($api);
        $storagePolicies = $vdcMgr->getStoragePoliciesByDataCenter($dataCenterId);
        $storagePolicyData = (isset($storagePolicies['data']) && is_array($storagePolicies['data']))
            ? $storagePolicies['data'] : [];

        if (empty($storagePolicyData)) {
            return 'No storage policies found in the data center. Nothing to add.';
        }

        // Get existing storage option names in this group
        $existingOptions = Capsule::table('tblproductconfigoptions')
            ->where('gid', $groupId)
            ->where('optionname', 'like', 'Storage - %')
            ->pluck('optionname')
            ->toArray();

        // Pricing helper
        $currencies = Capsule::table('tblcurrencies')->select('id')->get();
        $pricingTemplate = [
            'msetupfee' => '0.00', 'qsetupfee' => '0.00', 'ssetupfee' => '0.00',
            'asetupfee' => '0.00', 'bsetupfee' => '0.00', 'tsetupfee' => '0.00',
            'monthly' => '0.00', 'quarterly' => '0.00', 'semiannually' => '0.00',
            'annually' => '0.00', 'biennially' => '0.00', 'triennially' => '0.00'
        ];
        $ensurePricing = function (int $relId) use ($currencies, $pricingTemplate) {
            foreach ($currencies as $currency) {
                $existing = Capsule::table('tblpricing')
                    ->where('type', 'configoptions')
                    ->where('currency', $currency->id)
                    ->where('relid', $relId)
                    ->first();
                if (!$existing) {
                    Capsule::table('tblpricing')->insert(array_merge([
                        'type' => 'configoptions',
                        'currency' => $currency->id,
                        'relid' => $relId
                    ], $pricingTemplate));
                }
            }
        };

        // Add missing storage policies
        $nextOrder = Capsule::table('tblproductconfigoptions')
            ->where('gid', $groupId)
            ->max('order') + 1;
        $added = 0;

        foreach ($storagePolicyData as $policy) {
            if (empty($policy['name'])) {
                continue;
            }
            $optionName = 'Storage - ' . $policy['name'];
            if (in_array($optionName, $existingOptions)) {
                continue;
            }

            Capsule::table('tblproductconfigoptions')->insert([
                'gid' => $groupId,
                'optionname' => $optionName,
                'optiontype' => 4, // Quantity
                'qtyminimum' => 0,
                'qtymaximum' => 10000,
                'order' => $nextOrder++,
                'hidden' => $isPaygGroup ? 1 : 0
            ]);
            $storageOptionId = Capsule::getPdo()->lastInsertId();
            Capsule::table('tblproductconfigoptionssub')->insert([
                'configid' => $storageOptionId,
                'optionname' => 'GB',
                'sortorder' => 0,
                'hidden' => 0
            ]);
            $ensurePricing((int) Capsule::getPdo()->lastInsertId());
            $added++;
        }

        multiportal_log('ReSetupProductOptions', [
            'product_id' => $productId,
            'group' => $groupName,
            'existing_storage' => count($existingOptions),
            'dc_policies' => count($storagePolicyData),
            'added' => $added
        ], 'Re-setup completed');

        if ($added === 0) {
            multiportal_appendAdminNote($params['serviceid'],
                "Re-Sync Storage Policies: No new policies to add ({$groupName}). "
                . "Existing: " . count($existingOptions) . ", DC total: " . count($storagePolicyData));
            return 'success';
        }

        multiportal_appendAdminNote($params['serviceid'],
            "Re-Sync Storage Policies: Added {$added} new storage policies to '{$groupName}'. "
            . "Existing: " . count($existingOptions) . ", DC total: " . count($storagePolicyData));

        return 'success';
    } catch (Exception $e) {
        multiportal_log('ReSetupProductOptions', $params, ['error' => $e->getMessage()]);
        multiportal_appendAdminNote($params['serviceid'],
            "Re-Sync Storage Policies ERROR: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Migrate credentials from client-level to service-level custom fields.
 *
 * For existing services that predate the per-service credential storage.
 * Copies Tenant UUID, Username, Password, and URL from client-level fields
 * into the service's product-level custom fields and tblhosting, then zeroes
 * out the legacy client-level fields so they don't linger.
 */
function multiportal_MigrateCredentials(array $params)
{
    try {
        // Ensure product-level fields exist for this product
        ensureCustomFieldsExist();
        $productId = Capsule::table('tblhosting')->where('id', $params['serviceid'])->value('packageid');
        ensureProductCustomFields($productId);

        $migrated = [];
        $skipped = [];

        // Migrate Tenant UUID from client-level to service-level custom field
        $tenantUUID = getClientCustomFieldValue($params, 'MultiPortal Tenant UUID');
        $existingTenantUUID = getProductCustomFieldValue($params['serviceid'], 'Tenant UUID');
        if (!empty($tenantUUID)) {
            if (empty($existingTenantUUID)) {
                setCustomFieldValue($params['serviceid'], 'Tenant UUID', $tenantUUID);
                $migrated[] = 'Tenant UUID';
            } else {
                $skipped[] = 'Tenant UUID (already has value)';
            }
        }

        // Migrate Username/Password from client-level custom fields to tblhosting.
        // The 'MultiPortal Password' client field has fieldtype=password, so WHMCS
        // stores the value already encrypted in tblcustomfieldsvalues. We read the
        // raw DB value and copy it directly into tblhosting.password (which also
        // expects WHMCS-encrypted format) — no encrypt() call needed.
        $username = getClientCustomFieldValue($params, 'MultiPortal Username');
        $passwordField = Capsule::table('tblcustomfields')
            ->where('type', 'client')
            ->where('fieldname', 'MultiPortal Password')
            ->first();
        $password = null;
        if ($passwordField) {
            $password = Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $passwordField->id)
                ->where('relid', $params['userid'])
                ->value('value');
        }
        if (!empty($username) && !empty($password)) {
            // Only migrate if tblhosting doesn't already have credentials
            $hosting = Capsule::table('tblhosting')->where('id', $params['serviceid'])->first();
            if (empty($hosting->username)) {
                Capsule::table('tblhosting')
                    ->where('id', $params['serviceid'])
                    ->update([
                        'username' => $username,
                        'password' => $password
                    ]);
                $migrated[] = 'Username/Password (to service record)';
            } else {
                $skipped[] = 'Username/Password (already has value)';
            }
        }

        // Migrate URL from client-level custom field to service-level
        $existingURL = getProductCustomFieldValue($params['serviceid'], 'URL');
        if (empty($existingURL)) {
            $url = getClientCustomFieldValue($params, 'MultiPortal URL');
            if (!empty($url)) {
                setCustomFieldValue($params['serviceid'], 'URL', $url);
                $migrated[] = 'URL';
            } else {
                // Derive URL from server API config if no client-level field exists
                $apiUrl = ModuleConfiguration::get($params, ModuleConfiguration::FIELD_API_URL);
                if (!empty($apiUrl)) {
                    $portalUrl = rtrim($apiUrl, '/');
                    $portalUrl = preg_replace('#/api(/v\d+)?$#', '', $portalUrl);
                    setCustomFieldValue($params['serviceid'], 'URL', $portalUrl);
                    $migrated[] = 'URL (derived from server config)';
                }
            }
        } else {
            $skipped[] = 'URL (already has value)';
        }

        // Migrate Last Usage Sync from old relid=0 field to per-product field
        $oldSyncField = Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->where('fieldname', 'Last Usage Sync')
            ->where('relid', 0)
            ->first();
        $newSyncField = Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->where('fieldname', 'Last Usage Sync')
            ->where('relid', $productId)
            ->first();
        if ($oldSyncField && $newSyncField) {
            $oldValue = Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $oldSyncField->id)
                ->where('relid', $params['serviceid'])
                ->value('value');
            if (!empty($oldValue)) {
                // Only copy if the new field is empty
                $newValue = Capsule::table('tblcustomfieldsvalues')
                    ->where('fieldid', $newSyncField->id)
                    ->where('relid', $params['serviceid'])
                    ->value('value');
                if (empty($newValue)) {
                    Capsule::table('tblcustomfieldsvalues')->updateOrInsert(
                        ['fieldid' => $newSyncField->id, 'relid' => $params['serviceid']],
                        ['value' => $oldValue]
                    );
                    $migrated[] = 'Last Usage Sync';
                } else {
                    $skipped[] = 'Last Usage Sync (already has value)';
                }
                // Clear old value regardless (legacy field cleanup)
                Capsule::table('tblcustomfieldsvalues')
                    ->where('fieldid', $oldSyncField->id)
                    ->where('relid', $params['serviceid'])
                    ->update(['value' => '']);
            }
        }

        if (empty($migrated) && empty($skipped)) {
            return 'No legacy data found to migrate.';
        }

        // Zero out legacy client-level fields now that values are migrated or skipped
        $legacyFields = ['MultiPortal Tenant UUID', 'MultiPortal Username', 'MultiPortal Password', 'MultiPortal URL'];
        foreach ($legacyFields as $legacyFieldName) {
            $legacyField = Capsule::table('tblcustomfields')
                ->where('type', 'client')
                ->where('fieldname', $legacyFieldName)
                ->first();
            if ($legacyField) {
                $legacyValue = Capsule::table('tblcustomfieldsvalues')
                    ->where('fieldid', $legacyField->id)
                    ->where('relid', $params['userid'])
                    ->value('value');
                if (!empty($legacyValue)) {
                    Capsule::table('tblcustomfieldsvalues')
                        ->where('fieldid', $legacyField->id)
                        ->where('relid', $params['userid'])
                        ->update(['value' => '']);
                }
            }
        }
        $migrated[] = 'Cleared legacy client-level fields';

        $noteText = "Migrate: " . implode(', ', $migrated);
        if (!empty($skipped)) {
            $noteText .= ". Skipped: " . implode(', ', $skipped);
        }

        multiportal_log('MigrateCredentials', [
            'service_id' => $params['serviceid'],
            'migrated' => $migrated,
            'skipped' => $skipped
        ], $noteText);

        multiportal_appendAdminNote($params['serviceid'], $noteText);

        return 'success';
    } catch (Exception $e) {
        multiportal_appendAdminNote($params['serviceid'],
            "Migrate Credentials ERROR: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Test Connection button for server configuration page
 * This function performs connection test AND sets up custom fields
 */
function multiportal_TestConnection(array $params)
{
    try {
        // Debug what params we have
        multiportal_log('TestConnection', [
            'serverhostname' => $params['serverhostname'] ?? 'NOT SET',
            'serversecure' => $params['serversecure'] ?? 'NOT SET',
            'serverport' => $params['serverport'] ?? 'NOT SET',
            'serverid' => $params['serverid'] ?? 'NOT SET',
            'serveraccesshash' => $params['serveraccesshash'] ?? 'NOT SET',
            'all_params' => array_keys($params),
            'has_configoptions' => isset($params['configoption1'])
        ], 'Server params received');
        
        // First, test the API connection
        $clientId = ModuleConfiguration::getClientId($params);
        $clientSecret = ModuleConfiguration::getClientSecret($params);
        
        if (empty($clientId) || empty($clientSecret)) {
            return ['success' => false, 'error' => 'Client ID and Client Secret are required'];
        }
        
        // For TestConnection on server config page, we need to handle API URL differently
        // The configoption1 might not be available yet, so we'll use server hostname/IP
        $apiUrl = null;
        
        // Try to get API URL from module config first
        try {
            $apiUrl = ModuleConfiguration::get($params, ModuleConfiguration::FIELD_API_URL);
        } catch (Exception $e) {
            // If not in module config, try other sources
            multiportal_log('TestConnection', ['api_url_error' => $e->getMessage()], 'Could not get API URL from module config');
        }
        
        // If no API URL from module config, check if we have it in serverhostname
        if (empty($apiUrl) && !empty($params['serverhostname'])) {
            // If serverhostname is a full URL, use it
            if (filter_var($params['serverhostname'], FILTER_VALIDATE_URL)) {
                $apiUrl = $params['serverhostname'];
            } else {
                // Otherwise, construct URL from hostname
                // Check serversecure - it's a boolean in the params you showed
                $protocol = (!empty($params['serversecure']) && $params['serversecure'] === true) ? 'https' : 'http';
                // Don't add port if it's the default for the protocol
                $port = '';
                if (!empty($params['serverport'])) {
                    if (($protocol === 'https' && $params['serverport'] !== '443') ||
                        ($protocol === 'http' && $params['serverport'] !== '80')) {
                        $port = ':' . $params['serverport'];
                    }
                }
                $apiUrl = $protocol . '://' . $params['serverhostname'] . $port . '/api/v1';
            }
        }
        
        // If still no API URL, return error
        if (empty($apiUrl)) {
            return ['success' => false, 'error' => 'API URL could not be determined. Please ensure Server Hostname is set or save the configuration first.'];
        }
        
        multiportal_log('TestConnection', ['api_url' => $apiUrl], 'Using API URL');
        
        // Create API client directly since initiateAPI might fail
        try {
            $api = new ApiClient($clientId, $clientSecret, $apiUrl, false);
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Failed to create API client: ' . $e->getMessage()];
        }
        
        // Test connection - we'll use the /reseller endpoint as a simple test
        // Since we need a reseller ID from config, we'll check if we can access the API
        try {
            multiportal_log('TestConnection', ['action' => 'Testing API connection'], 'About to test API');
            
            // First, let's see if we have a reseller UUID configured
            $resellerUuid = null;
            try {
                $resellerUuid = ModuleConfiguration::get($params, ModuleConfiguration::FIELD_RESELLER_UUID, false);
            } catch (Exception $e) {
                // Reseller UUID not configured yet, that's ok for initial test
                multiportal_log('TestConnection', ['reseller_uuid' => 'not configured'], 'No reseller UUID');
            }
            
            $testSuccessful = false;
            $testMessage = '';
            
            if (!empty($resellerUuid)) {
                // If we have a reseller UUID, test with that
                try {
                    $resellerResponse = $api->get('/reseller/' . $resellerUuid);
                    if ($resellerResponse && isset($resellerResponse['data'])) {
                        $testSuccessful = true;
                        $resellerName = $resellerResponse['data']['name'] ?? 'Unknown';
                        $testMessage = "Connected to reseller: {$resellerName}";
                    }
                } catch (Exception $e) {
                    // Fall back to company endpoint
                    multiportal_log('TestConnection', ['reseller_error' => $e->getMessage()], 'Reseller test failed');
                }
            }
            
            // If reseller test didn't work, try company endpoint
            if (!$testSuccessful) {
                try {
                    $companyResponse = $api->get('/company');
                    multiportal_log('TestConnection', [
                        'company_response' => array_keys($companyResponse ?? []),
                        'has_data' => isset($companyResponse['data'])
                    ], 'Company endpoint response');
                    
                    if ($companyResponse) {
                        $testSuccessful = true;
                        if (isset($companyResponse['data']['data']['items'])) {
                            $companyCount = count($companyResponse['data']['data']['items']);
                            $testMessage = "Found {$companyCount} companies";
                        } else {
                            $testMessage = "API connection successful";
                        }
                    }
                } catch (Exception $e) {
                    multiportal_log('TestConnection', ['company_error' => $e->getMessage()], 'Company test failed');
                }
            }
            
            // If still no success, try tenant endpoint
            if (!$testSuccessful) {
                try {
                    $tenantResponse = $api->get('/tenant');
                    if ($tenantResponse) {
                        $testSuccessful = true;
                        $testMessage = "API connection successful (tenant endpoint)";
                    }
                } catch (Exception $e) {
                    multiportal_log('TestConnection', ['tenant_error' => $e->getMessage()], 'Tenant test failed');
                }
            }
            
            if (!$testSuccessful) {
                return ['success' => false, 'error' => 'Could not verify API connection. Please check credentials and API URL.'];
            }
            
        } catch (Exception $e) {
            multiportal_log('TestConnection', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'Exception during API call');
            return ['success' => false, 'error' => 'API Error: ' . $e->getMessage()];
        }
        
        // Check if Data Center ID is provided in Access Hash field
        $dataCenterId = isset($params['serveraccesshash']) ? trim($params['serveraccesshash']) : '';
        $dataCenterInfo = '';
        
        if (!empty($dataCenterId)) {
            // Validate the Data Center ID
            try {
                $dataCenterResponse = $api->get('/data-center/' . $dataCenterId);
                if ($dataCenterResponse && isset($dataCenterResponse['data'])) {
                    $dcName = $dataCenterResponse['data']['name'] ?? 'Unknown';
                    $dataCenterInfo = " Data Center '{$dcName}' verified.";
                    
                    // Also try to fetch storage policies to ensure full access
                    try {
                        $storagePolicies = $api->get('/data-center/' . $dataCenterId . '/storage-policy');
                        if ($storagePolicies && isset($storagePolicies['data'])) {
                            $policyCount = count($storagePolicies['data']);
                            $dataCenterInfo .= " Found {$policyCount} storage policies.";
                        }
                    } catch (Exception $e) {
                        // Storage policy fetch failed, but that's ok for connection test
                        multiportal_log('TestConnection', ['storage_policy_error' => $e->getMessage()], 'Could not fetch storage policies');
                    }
                } else {
                    $dataCenterInfo = " WARNING: Data Center ID not found!";
                }
            } catch (Exception $e) {
                $dataCenterInfo = " WARNING: Could not validate Data Center ID: " . $e->getMessage();
            }
        } else {
            $dataCenterInfo = " No Data Center ID configured in Access Hash field.";
        }
        
        // Now ensure custom fields exist (this creates global custom fields)
        $customFieldsCreated = [];
        try {
            $customFieldsCreated = ensureCustomFieldsExist();
            multiportal_log('TestConnection', ['custom_fields' => $customFieldsCreated], 'Custom fields created/verified');
        } catch (Exception $e) {
            // Don't fail the connection test if custom fields already exist
            multiportal_log('TestConnection', ['custom_fields_error' => $e->getMessage()], 'Custom fields may already exist');
        }
        
        // Build success message
        $message = $testMessage . $dataCenterInfo;
        
        if (!empty($customFieldsCreated)) {
            $message .= " Created " . count($customFieldsCreated) . " custom field(s).";
        } else {
            $message .= " Custom fields already configured.";
        }
        
        $message .= " To set up product options, go to the product and click 'Setup Product Options'.";
        
        multiportal_log('TestConnection', [
            'test_message' => $testMessage,
            'api_url' => $apiUrl,
            'custom_fields_created' => count($customFieldsCreated)
        ], 'Connection test and setup successful');
        
        return ['success' => true, 'error' => $message];
        
    } catch (Exception $e) {
        multiportal_log('TestConnection', $params, ['error' => $e->getMessage()]);
        
        // Provide more helpful error messages
        if (strpos($e->getMessage(), 'Client ID') !== false || strpos($e->getMessage(), 'Client Secret') !== false) {
            return ['success' => false, 'error' => 'Server credentials are empty. Please add your API credentials.'];
        }
        
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
