<?php
/**
 * Bachs webhook endpoint.
 *
 * URL: https://your-whmcs/modules/gateways/callback/bachs.php
 *
 * Every delivery is signature-verified (X-Bachs-Signature / X-Bachs-Timestamp),
 * de-duplicated by event ID, and then the referenced checkout/payment is
 * re-fetched from the Bachs API before any invoice is touched.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../bachs/lib/bootstrap.php';

use HarmonWeb\Bachs\ApiClient;
use HarmonWeb\Bachs\Config;
use HarmonWeb\Bachs\Logger;
use HarmonWeb\Bachs\Schema;
use HarmonWeb\Bachs\WebhookHandler;

function bachs_webhook_respond($status, array $body)
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($body);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    bachs_webhook_respond(405, ['error' => 'method not allowed']);
}

$gatewayParams = getGatewayVariables('bachs');
if (empty($gatewayParams['type'])) {
    bachs_webhook_respond(503, ['error' => 'gateway not active']);
}

try {
    $config = Config::fromParams($gatewayParams);
    Schema::ensure();
    $api = ApiClient::fromConfig($config);
} catch (\Throwable $e) {
    Logger::activity('Webhook received but module is misconfigured: ' . $e->getMessage());
    bachs_webhook_respond(503, ['error' => 'not configured']);
}

$raw = file_get_contents('php://input', false, null, 0, WebhookHandler::MAX_BODY_BYTES + 1);
$raw = $raw === false ? '' : $raw;

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
    }
}

list($status, $body) = (new WebhookHandler($config, $api))->handle($raw, $headers);
bachs_webhook_respond($status, $body);
