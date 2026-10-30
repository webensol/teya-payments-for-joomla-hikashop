<?php
define('_JEXEC', 1);
require __DIR__ . '/../plugins/hikashoppayment/teya/src/TeyaHikashopApiClient.php';
function verify($condition) { if (!$condition) { throw new RuntimeException('Diagnostic regression failed'); } }
$headers = array();
foreach (array('Content-Type: text/html; charset=UTF-8', 'X-Request-ID: trace-123', 'Set-Cookie: SECRET', 'Authorization: Bearer SECRET', "X-Correlation-ID: bad\r\nSECRET") as $line) {
    TeyaHikashopApiClient::captureDiagnosticHeader($headers, $line);
}
$result = TeyaHikashopApiClient::responseMetadata(403, '<html>SECRET customer data</html>', $headers);
verify($result['body_format'] === 'non-json' && $result['body_bytes'] === 33);
verify($result['headers'] === array('content-type'=>'text/html','x-request-id'=>'trace-123'));
verify(strpos(json_encode($result), 'SECRET') === false);
verify(TeyaHikashopApiClient::responseMetadata(403, '', array())['body_format'] === 'empty');
verify(TeyaHikashopApiClient::responseMetadata(403, '{"secret":"SECRET"}', array())['body_format'] === 'json');
TeyaHikashopApiClient::captureDiagnosticHeader($headers, 'HTTP/1.1 403 Forbidden');
verify($headers === array());
echo "PASS: response classification, strict header allowlist, body omission and header-block reset\n";
