<?php
// Real local HTTP/cURL traffic, no external provider and no merchant credentials.
define('_JEXEC', 1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
class TeyaHikashopUtils {
    public const VERSION = '1.0.1';
    public static $base;
    public static function apiBaseUrl($environment) { return self::$base; }
    public static function identityTokenUrl($environment) { return self::$base . '/oauth/token'; }
}
require dirname(__DIR__) . '/plugins/hikashoppayment/teya/src/TeyaHikashopApiClient.php';
function transportCheck($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
transportCheck((bool) $server, 'Local HTTP server could not start');
TeyaHikashopUtils::$base = 'http://' . stream_socket_get_name($server, false);
$pid = pcntl_fork();
transportCheck($pid >= 0, 'Local HTTP server could not fork');
if ($pid === 0) {
    for ($index = 0; $index < 4; ++$index) {
        $socket = stream_socket_accept($server, 10);
        if (!$socket) { exit(1); }
        $requestLine = trim(fgets($socket)); $headers = array();
        while (($line = fgets($socket)) !== false && trim($line) !== '') {
            $parts = explode(':', $line, 2); $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        $body = ''; $length = (int) ($headers['content-length'] ?? 0);
        while (strlen($body) < $length) { $body .= fread($socket, $length - strlen($body)); }
        $payload = json_decode($body, true);
        $status = '201 Created'; $type = 'application/json'; $extra = '';
        $response = json_encode(array('status'=>'SUCCESS','seen'=>array('headers'=>$headers,'body'=>$payload)));
        if (strpos($requestLine, '/oauth/token') !== false) {
            parse_str($body, $form);
            if (($form['client_secret'] ?? '') !== 'FIXTURE_SECRET' || ($form['grant_type'] ?? '') !== 'client_credentials') { exit(2); }
            $status = '200 OK'; $response = '{"access_token":"FIXTURE_TOKEN","expires_in":600}';
        } elseif (($payload['transaction_id'] ?? '') === 'denied') {
            $status = '403 Forbidden'; $type = 'text/html';
            $response = '<html>PRIVATE_PROVIDER_RESPONSE</html>';
            $extra = "X-Request-ID: fixture-request-123\r\nSet-Cookie: PRIVATE_COOKIE\r\n";
        } elseif (($payload['transaction_id'] ?? '') === 'redirect') {
            $status = '302 Found'; $response = '{}'; $extra = "Location: https://example.invalid/must-not-follow\r\n";
        }
        fwrite($socket, "HTTP/1.1 $status\r\nContent-Type: $type\r\n" . $extra . 'Content-Length: ' . strlen($response) . "\r\nConnection: close\r\n\r\n" . $response);
        fclose($socket);
    }
    fclose($server); exit(0);
}
$logs = array();
try {
    $api = new TeyaHikashopApiClient(true, function ($message) use (&$logs) { $logs[] = $message; });
    $token = $api->requestAccessToken('fixture-client', 'FIXTURE_SECRET', 'test');
    transportCheck($token['access_token'] === 'FIXTURE_TOKEN', 'OAuth token response');
    $payload = array('transaction_id'=>'fixture-transaction','amount'=>300000,'reason'=>'HikaShop refund');
    $refund = $api->createRefund($payload, $token['access_token'], 'test', 'fixture-refund-key');
    transportCheck($refund['_http_status'] === 201 && $refund['seen']['body'] === $payload, 'Refund body and status');
    $sent = $refund['seen']['headers'];
    transportCheck($sent['authorization'] === 'Bearer FIXTURE_TOKEN' && $sent['idempotency-key'] === 'fixture-refund-key', 'Authentication/idempotency headers');
    transportCheck($sent['user-agent'] === 'Teya-HikaShop/1.0.1' && $sent['content-type'] === 'application/json', 'User-Agent and JSON headers');
    try { $api->createRefund(array('transaction_id'=>'denied'), 'FIXTURE_TOKEN', 'test', 'denied-refund-key'); throw new LogicException('403 accepted'); }
    catch (TeyaHikashopApiException $exception) {
        transportCheck($exception->getStatusCode() === 403 && $exception->getMessage() === TeyaHikashopLanguage::text('API_ERROR', 403), 'Safe localized API error');
    }
    $response = $api->createRefund(array('transaction_id'=>'redirect'), 'FIXTURE_TOKEN', 'test', 'redirect-refund-key');
    transportCheck($response['_http_status'] === 302, 'Redirect is never followed');
    foreach (array('FIXTURE_SECRET','FIXTURE_TOKEN','PRIVATE_PROVIDER_RESPONSE','PRIVATE_COOKIE') as $secret) {
        transportCheck(strpos(implode('\n', $logs), $secret) === false, 'Sensitive data reached logs');
    }
    transportCheck(strpos(implode('\n', $logs), 'fixture-request-123') !== false, 'Safe diagnostic request ID missing');
    pcntl_waitpid($pid, $status);
    transportCheck(pcntl_wexitstatus($status) === 0, 'Local HTTP fixture failed');
    echo 'PASS: real cURL OAuth/refund requests, safe API errors/logs, no redirects and no PHP notices on PHP ' . PHP_VERSION . PHP_EOL;
} finally {
    fclose($server);
    if (pcntl_waitpid($pid, $status, WNOHANG) === 0) { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); }
}
