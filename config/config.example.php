<?php
/**
 * Application configuration template.
 * Copy this file to /config/config.php and fill in values.
 */

return [
    'app' => [
        'name'      => 'F&B Revenue & Claim Control BOS',
        'env'       => 'development',
        'debug'     => true,
        'base_url'  => 'http://localhost/FNBbos/public',
        'timezone'  => 'Asia/Kuala_Lumpur',
        'locale'    => 'en_MY',
        'currency'  => 'RM',
    ],
    'db' => [
        'host'      => '127.0.0.1',
        'port'      => 3306,
        'name'      => 'fnb_bos',
        'user'      => 'root',
        'pass'      => '',
        'charset'   => 'utf8mb4',
    ],
    'security' => [
        'session_name'    => 'fnbbos_sid',
        'session_lifetime'=> 3600,
        'csrf_token_name' => '_csrf',
        'password_algo'   => PASSWORD_BCRYPT,
        'password_cost'   => 12,
    ],
    'storage' => [
        'uploads' => __DIR__ . '/../storage/uploads',
        'exports' => __DIR__ . '/../storage/exports',
        'max_upload_mb' => 8,
        'allowed_receipt_ext' => ['pdf','jpg','jpeg','png','webp','heic'],
        'allowed_csv_ext'     => ['csv','xlsx','xls'],
    ],
    'notifications' => [
        'evolution_api' => [
            'enabled'  => false,
            'base_url' => 'https://evo.example.com',
            'api_key'  => '',
            'instance' => '',
        ],
        'email' => [
            'enabled' => false,
            'from'    => 'no-reply@example.com',
        ],
        // Where the SaaS operator wants demo-request alerts to be delivered.
        // Leave channels empty to skip; entries here flow into Notifier::dispatch.
        'operator_alerts' => [
            'email_to'    => '',          // e.g. 'sales@yourdomain.com'
            'whatsapp_to' => '',          // e.g. '60123456789' (no +)
            'channels'    => ['in_app'],  // add 'email', 'whatsapp' to enable external delivery
        ],
    ],
    'risk' => [
        'thresholds' => [
            'low'      => 30,
            'medium'   => 60,
            'high'     => 80,
            'critical' => 100,
        ],
        'amount_anomaly_multiplier_high' => 2.0,
        'amount_anomaly_multiplier_critical' => 3.0,
        'frequency_window_days' => 3,
        'frequency_threshold'   => 5,
        'budget_warning_pct'    => 0.85,
    ],
    // Phase 3: OCR receipt reading. Provider 'ocr_space' uses
    // https://api.ocr.space (free tier with API key); 'noop' disables OCR.
    'ocr' => [
        'enabled'   => false,
        'provider'  => 'ocr_space',
        'api_key'   => '',
        'language'  => 'eng',
        'engine'    => 2,            // OCR.space engine 2 handles receipts well
    ],
    // Phase 3: Optional LLM hook for richer claim risk explanations. When
    // disabled, RiskEngine falls back to its rule-based narrative.
    'ai' => [
        'enabled'  => false,
        'provider' => 'anthropic',
        'api_key'  => '',
        'model'    => 'claude-haiku-4-5',
        'max_tokens' => 400,
    ],
    // Phase 3: settlement / budget alert thresholds.
    'alerts' => [
        'settlement_delay_days' => 3,   // warn once a settlement is more than N days late
        'budget_warn_pct'       => 0.85,
        'budget_exceed_pct'     => 1.00,
    ],
];
