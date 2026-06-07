<?php
declare(strict_types=1);

/*
 |---------------------------------------------------------------------------
 | Discord webhook configuration
 |---------------------------------------------------------------------------
 |
 | Preferred approach in production:
 |   Set environment variables and keep this file out of deployment.
 |
 | Supported environment variables:
 |   CVE_WEBHOOK_URL
 |   EUROPOL_WEBHOOK_URL
 |   FBI_WEBHOOK_URL
 |   FUN_WEBHOOK_URL
 |   RANSOMWARE_WEBHOOK_URL
 |
 | Fallback approach:
 |   Copy this file to config.php and set the values below.
 |
 */

return [
    'cve_webhook_url' => 'YOUR_CVE_WEBHOOK_URL',
    'europol_webhook_url' => 'YOUR_EUROPOL_WEBHOOK_URL',
    'fbi_webhook_url' => 'YOUR_FBI_WEBHOOK_URL',
    'fun_webhook_url' => 'YOUR_FUN_WEBHOOK_URL',
    'ransomware_webhook_url' => 'YOUR_RANSOMWARE_WEBHOOK_URL',
];
