<?php

$companyDatabases = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('SAP_COMPANY_DATABASES', env('SAP_COMPANY_DB', ''))),
)));
$companyCredentials = [];
$serialViewCredentials = [];

foreach ($companyDatabases as $companyDatabase) {
    $environmentKey = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $companyDatabase));
    $username = env("SAP_{$environmentKey}_USERNAME");
    $password = env("SAP_{$environmentKey}_PASSWORD");

    if (filled($username) && filled($password)) {
        $companyCredentials[$companyDatabase] = [
            'username' => $username,
            'password' => $password,
        ];
    }

    $serialViewUsername = env("SAP_GI_SERIAL_VIEW_{$environmentKey}_USERNAME");
    $serialViewPassword = env("SAP_GI_SERIAL_VIEW_{$environmentKey}_PASSWORD");

    if (filled($serialViewUsername) && filled($serialViewPassword)) {
        $serialViewCredentials[$companyDatabase] = [
            'username' => $serialViewUsername,
            'password' => $serialViewPassword,
        ];
    }
}

return [
    'base_url' => env('SAP_SERVICE_LAYER_URL'),
    'company_db' => env('SAP_COMPANY_DB'),
    'company_databases' => $companyDatabases,
    'company_credentials' => $companyCredentials,
    'username' => env('SAP_USERNAME'),
    'password' => env('SAP_PASSWORD'),
    'verify_tls' => env('SAP_VERIFY_TLS', true),
    'connect_timeout_seconds' => (int) env('SAP_CONNECT_TIMEOUT_SECONDS', 5),
    'timeout_seconds' => (int) env('SAP_TIMEOUT_SECONDS', 30),

    'session' => [
        'cache_store' => env('SAP_SESSION_CACHE_STORE', 'redis'),
        'cache_key' => 'sap:service-layer:session',
        'lock_key' => 'sap:service-layer:login-lock',
        'ttl_seconds' => (int) env('SAP_SESSION_TTL_SECONDS', 1500),
    ],

    'metadata' => [
        'ttl_seconds' => (int) env('SAP_METADATA_TTL_SECONDS', 3600),
    ],

    'endpoints' => [
        'login' => '/Login',
        'purchase_requests' => '/PurchaseRequests',
        'purchase_quotations' => '/PurchaseQuotations',
        'purchase_orders' => '/PurchaseOrders',
        'goods_receipts' => '/PurchaseDeliveryNotes',
        'goods_issues' => '/InventoryGenExits',
        'items' => '/Items',
        'item_groups' => '/ItemGroups',
        'warehouses' => '/Warehouses',
        'profit_centers' => '/ProfitCenters',
        'company_admin_info' => '/CompanyService_GetAdminInfo',
    ],

    'serial_numbers' => [
        'configuration_ttl_seconds' => (int) env('SAP_SERIAL_CONFIGURATION_TTL_SECONDS', 900),
    ],

    'object_types' => [
        'purchase_request' => (int) env('SAP_OBJECT_TYPE_PURCHASE_REQUEST', 1470000113),
        'purchase_quotation' => (int) env('SAP_OBJECT_TYPE_PURCHASE_QUOTATION', 540000006),
        'purchase_order' => (int) env('SAP_OBJECT_TYPE_PURCHASE_ORDER', 22),
    ],

    'watchers' => [
        'interval_seconds' => (int) env('SAP_WATCHER_INTERVAL_SECONDS', 120),
        'batch_size' => (int) env('SAP_WATCHER_BATCH_SIZE', 50),
    ],

    // Confirm these values against the target environment's /b1s/v2/$metadata.
    'fields' => [
        'comments' => env('SAP_FIELD_COMMENTS', 'Comments'),
        'web_transaction_id' => env('SAP_FIELD_WEB_TRANSACTION_ID', 'U_U_WebTxnId'),
        'fpk_number' => env('SAP_FIELD_FPK_NUMBER', 'U_FPK'),
        'vehicle' => env('SAP_FIELD_VEHICLE', 'U_Nopol'),
        'pool' => env('SAP_FIELD_POOL', 'U_Pool'),
        'department' => env('SAP_FIELD_DEPARTMENT', 'U_Department'),
        'priority' => env('SAP_FIELD_PRIORITY', 'U_VPRIOR'),
        'issue_type' => env('SAP_FIELD_ISSUE_TYPE'),
        'current_km' => env('SAP_FIELD_CURRENT_KM'),
        'web_created_by_user' => env('SAP_FIELD_WEB_CREATED_BY_USER', 'U_UserWebSAP'),
    ],

    'dimensions' => [
        'department' => (int) env('SAP_DIMENSION_DEPARTMENT', 1),
        'pool' => (int) env('SAP_DIMENSION_POOL', 2),
        'vehicle' => (int) env('SAP_DIMENSION_VEHICLE', 3),
    ],

    'purchase_request' => [
        'department_costing_field' => env('SAP_PR_DEPARTMENT_COSTING_FIELD', 'CostingCode'),
        'pool_costing_field' => env('SAP_PR_POOL_COSTING_FIELD', 'CostingCode2'),
        'vehicle_costing_field' => env('SAP_PR_VEHICLE_COSTING_FIELD', 'CostingCode3'),
        'current_km_field' => env('SAP_PR_CURRENT_KM_FIELD', 'U_KM1'),
    ],

    'goods_issue' => [
        // Account codes are company-specific and must be confirmed with Accounting/SAP master data.
        'account_codes' => [
            'SPR-MT' => env('SAP_GI_ACCOUNT_SPR_MT'),
            'SPR-KO' => env('SAP_GI_ACCOUNT_SPR_KO'),
            'SPR-MEKANIK' => env('SAP_GI_ACCOUNT_SPR_MEKANIK'),
            'BAN-MT' => env('SAP_GI_ACCOUNT_BAN_MT'),
            'BBM-KO' => env('SAP_GI_ACCOUNT_BBM_KO'),
        ],
        // IA SPR-IK: 134 non-cancelled SAP GI lines across 10 warehouses used AcctCode 6119.1001
        // in the read-only OIGE/IGE1 audit on 2026-11-17. IAL_DEV requires its own evidence.
        'account_codes_by_company' => [
            'IA' => ['SPR-IK' => env('SAP_GI_ACCOUNT_IA_SPR_IK', '6119.1001')],
            'IAL_DEV' => ['SPR-IK' => env('SAP_GI_ACCOUNT_IAL_DEV_SPR_IK')],
        ],
        // IA I10983/PLB-01/BBM-OU: 54 non-cancelled SAP GI lines used AcctCode 5114.3002
        // in the read-only OIGE/IGE1 audit on 2026-11-17. Other BBM-OU contexts need separate evidence.
        'account_codes_by_company_item_warehouse' => [
            'IA' => ['BBM-OU' => ['I10983' => ['PLB-01' => env('SAP_GI_ACCOUNT_IA_BBM_OU_I10983_PLB_01', '5114.3002')]]],
        ],
        // IA I00213/CMR-01/TSP/MDN/L8498US: 801 non-cancelled BBM-OU GI lines used 5114.3002
        // in the read-only OIGE/IGE1 audit on 2026-11-24. Other cost-center combinations had other accounts.
        'account_codes_by_company_item_warehouse_cost_centers' => [
            'IA' => ['BBM-OU' => ['I00213' => ['CMR-01' => ['TSP' => ['MDN' => ['L8498US' => env('SAP_GI_ACCOUNT_IA_BBM_OU_I00213_CMR_01_TSP_MDN_L8498US', '5114.3002')]]]]]],
        ],
        'department_costing_field' => env('SAP_GI_DEPARTMENT_COSTING_FIELD', 'CostingCode'),
        'pool_costing_field' => env('SAP_GI_POOL_COSTING_FIELD', 'CostingCode2'),
        'vehicle_costing_field' => env('SAP_GI_VEHICLE_COSTING_FIELD', 'CostingCode3'),
        'issue_type_field' => env('SAP_GI_ISSUE_TYPE_FIELD', 'U_VTPI'),
        'current_km_field' => env('SAP_GI_CURRENT_KM_FIELD', 'U_KM1'),
        'serial_issue_type_codes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SAP_GI_SERIAL_ISSUE_TYPE_CODES', 'BAN-MT')),
        ))),
        // BP-12 / TD-13: serial-managed items are issued from serials chosen from SAP. Off by default, because the
        // SAP team has to provide the read source first (docs/integration-contracts.md). While off, the legacy
        // free-text serial field and BAN-MT rule stay in force.
        'serial_selection' => [
            'enabled' => (bool) env('SAP_GI_SERIAL_SELECTION_ENABLED', false),
            'entity' => env('SAP_GI_SERIAL_STOCK_ENTITY', 'SERIALNUMBERBANQUERY'),
            'semantic_base_url' => env('SAP_GI_SERIAL_VIEW_URL') ?: preg_replace('#/v2/?$#i', '/v1/sml.svc', (string) env('SAP_SERVICE_LAYER_URL')),
            'view_username' => env('SAP_GI_SERIAL_VIEW_USERNAME', 'IT01'),
            'view_password' => env('SAP_GI_SERIAL_VIEW_PASSWORD'),
            'view_credentials_by_company' => $serialViewCredentials,
            'fields' => [
                'item_code' => env('SAP_GI_SERIAL_FIELD_ITEM_CODE', 'ItemCode'),
                'warehouse_code' => env('SAP_GI_SERIAL_FIELD_WAREHOUSE', 'WhsCode'),
                'system_number' => env('SAP_GI_SERIAL_FIELD_SYSTEM_NUMBER', 'SysNumber'),
                'abs_entry' => env('SAP_GI_SERIAL_FIELD_ABS_ENTRY', 'AbsEntry'),
                'manufacturer_serial' => env('SAP_GI_SERIAL_FIELD_MANUFACTURER', 'MnfSerial'),
                'internal_serial' => env('SAP_GI_SERIAL_FIELD_INTERNAL', 'DistNumber'),
                'quantity' => env('SAP_GI_SERIAL_FIELD_QUANTITY', 'Quantity'),
            ],
            'page_size' => (int) env('SAP_GI_SERIAL_PAGE_SIZE', 50),
        ],
    ],

    'stock' => [
        'low_threshold' => (float) env('SAP_STOCK_LOW_THRESHOLD', 5),
        'ledger_cache_seconds' => (int) env('SAP_STOCK_LEDGER_CACHE_SECONDS', 120),
        'ledger_limit' => (int) env('SAP_STOCK_LEDGER_LIMIT', 100),
        'warehouses' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SAP_STOCK_WAREHOUSES', '')),
        ))),
    ],

    'documents' => [
        'goods_receipt_attachments' => [
            'disk' => 'local',
            // TD-10: 5 photos x 1 MB per upload. Nginx client_max_body_size and PHP post_max_size must stay a little
            // above 5 MB + form overhead (docs/application/runbook.md, "413 Request Entity Too Large").
            'max_files_per_upload' => 5,
            'max_size_kb' => 1024,
        ],
        'goods_issue_attachments' => [
            'disk' => 'local',
            'max_files_per_upload' => 3,
            'max_size_kb' => 1024,
        ],
    ],

    'goods_receipt' => [
        // TD-05: several GRPOs per PO with a per-request UUID stored in the UDF `fields.web_transaction_id`.
        // Keep false until the UDF write has been proven on IA and IAL_DEV (bridge Q-20261107-11).
        'partial_receipts_enabled' => filter_var(env('SAP_GRPO_PARTIAL_RECEIPTS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        // A POSTING command younger than this is treated as still in flight by the reconciler.
        'reconcile_min_age_seconds' => (int) env('SAP_GRPO_RECONCILE_MIN_AGE_SECONDS', 120),
    ],

    'ready_to_receive' => [
        'series' => array_values(array_unique(array_filter(array_map(
            static fn (string $series): int => (int) trim($series),
            explode(',', (string) env('SAP_READY_PO_SERIES', '887,888')),
        ), static fn (int $series): bool => $series > 0))),
        'series_by_company' => [
            'IAL_DEV' => [939, 941],
        ],
        'lookback_months' => (int) env('SAP_READY_PO_LOOKBACK_MONTHS', 2),
        'sync_interval_seconds' => (int) env('SAP_READY_PO_SYNC_INTERVAL_SECONDS', 120),
        'page_size' => (int) env('SAP_READY_PO_PAGE_SIZE', 100),
        'maximum_rows' => (int) env('SAP_READY_PO_MAXIMUM_ROWS', 1000),
    ],

    'sparepart_report' => [
        'service_layer_url' => env('SAP_SQL_QUERY_SERVICE_LAYER_URL'),
        'account_pattern' => env('SAP_SPAREPART_ACCOUNT_PATTERN', '5114.500%'),
        'payment_account_codes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SAP_SPAREPART_PAYMENT_ACCOUNTS', '5114.5001,5114.5003')),
        ))),
        'queries' => [
            'goods_issue' => env('SAP_SPAREPART_QUERY_GOODS_ISSUE_V2', 'WEB_SP_GI_V2'),
            'ap_invoice' => env('SAP_SPAREPART_QUERY_AP_INVOICE_V2', 'WEB_SP_AP_V2'),
            'payment_request' => env('SAP_SPAREPART_QUERY_PAYMENT_REQUEST_V2', 'WEB_SP_PAY_V2'),
            'latest_goods_issue' => env('SAP_SPAREPART_QUERY_LATEST_GOODS_ISSUE_V2', 'WEB_SP_GI_LAST_V2'),
            'serial_numbers' => env('SAP_SPAREPART_QUERY_SERIAL_NUMBERS_V2', 'WEB_SP_SN_V2'),
        ],
        'query_batch_size' => (int) env('SAP_SPAREPART_QUERY_BATCH_SIZE', 10),
        'serial_batch_size' => (int) env('SAP_SPAREPART_SERIAL_BATCH_SIZE', 10),
        'cache_seconds' => (int) env('SAP_SPAREPART_CACHE_SECONDS', 300),
        'history_months' => (int) env('SAP_SPAREPART_HISTORY_MONTHS', 24),
        'history_limit' => (int) env('SAP_SPAREPART_HISTORY_LIMIT', 10),
        'usage_history_start_date' => env('SAP_SPAREPART_USAGE_HISTORY_START_DATE', '2000-01-01'),
        'limit' => (int) env('SAP_SPAREPART_REPORT_LIMIT', 250),
    ],
];
