<?php

return [
    'base_url' => env('SAP_SERVICE_LAYER_URL'),
    'company_db' => env('SAP_COMPANY_DB'),
    'company_databases' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SAP_COMPANY_DATABASES', env('SAP_COMPANY_DB', ''))),
    ))),
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
        'profit_centers' => '/ProfitCenters',
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
        'web_transaction_id' => env('SAP_FIELD_WEB_TRANSACTION_ID', 'U_WebTxnId'),
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
        'department_costing_field' => env('SAP_GI_DEPARTMENT_COSTING_FIELD', 'CostingCode'),
        'pool_costing_field' => env('SAP_GI_POOL_COSTING_FIELD', 'CostingCode2'),
        'vehicle_costing_field' => env('SAP_GI_VEHICLE_COSTING_FIELD', 'CostingCode3'),
        'serial_issue_type_codes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SAP_GI_SERIAL_ISSUE_TYPE_CODES', 'BAN-MT')),
        ))),
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
            'max_files_per_upload' => 5,
            'max_size_kb' => 5120,
        ],
    ],

    'sparepart_report' => [
        'service_layer_url' => env('SAP_SQL_QUERY_SERVICE_LAYER_URL'),
        'account_pattern' => env('SAP_SPAREPART_ACCOUNT_PATTERN', '%5114.500%'),
        'payment_account_codes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SAP_SPAREPART_PAYMENT_ACCOUNTS', '5114.5001,5114.5003')),
        ))),
        'queries' => [
            'goods_issue' => env('SAP_SPAREPART_QUERY_GOODS_ISSUE', 'WEB_SPARE_GI'),
            'ap_invoice' => env('SAP_SPAREPART_QUERY_AP_INVOICE', 'WEB_SPARE_AP'),
            'payment_request' => env('SAP_SPAREPART_QUERY_PAYMENT_REQUEST', 'WEB_SPARE_PAY'),
            'serial_numbers' => env('SAP_SPAREPART_QUERY_SERIAL_NUMBERS', 'WEB_SPARE_SN'),
        ],
        'cache_seconds' => (int) env('SAP_SPAREPART_CACHE_SECONDS', 300),
        'history_months' => (int) env('SAP_SPAREPART_HISTORY_MONTHS', 24),
        'history_limit' => (int) env('SAP_SPAREPART_HISTORY_LIMIT', 10),
        'usage_history_start_date' => env('SAP_SPAREPART_USAGE_HISTORY_START_DATE', '2000-01-01'),
        'usage_history_limit' => (int) env('SAP_SPAREPART_USAGE_HISTORY_LIMIT', 1000),
        'limit' => (int) env('SAP_SPAREPART_REPORT_LIMIT', 250),
    ],
];
