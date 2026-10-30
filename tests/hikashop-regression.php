<?php
namespace Joomla\CMS {
    class Factory {
        public static $secret = 'offline-test-site-secret-0123456789';
        public static function getConfig() { return new class { public function get($key) { return Factory::$secret; } }; }
    }
}
namespace {
define('_JEXEC', 1);
class hikashopPaymentPlugin { function onPaymentConfigurationSave(&$element) { return true; } }
class JPlugin {}
class OfflineDatabase { public $query; public $stored; function loadResult() { return $this->stored; } function Quote($v) { return "'" . $v . "'"; } function setQuery($q) { $this->query=$q; } function execute() {} }
function hikashop_table($name) { return "offline_".$name; }
class JFactory {
    static $db;
    static function getDBO() { return self::$db; }
    static function getLanguage() { return new class { function get($name) { return 'en-GB'; } }; }
}
function hikashop_get($name) { return new class { function get($id) { return (object) array('currency_code'=>($id === 1 ? 'EUR' : 'HUF')); } }; }
$source = getenv('TEYA_HIKA_SOURCE') ?: dirname(__DIR__) . '/plugins/hikashoppayment/teya';
require $source . '/teya.php';
define('JPATH_SITE', dirname(dirname(dirname($source))));
require dirname(dirname($source)) . '/hikashop/teya/teya.php';
$checks = array();
function check($ok) { if (!$ok) { throw new RuntimeException('Expectation failed'); } }
function rejects($fn) { try { $fn(); } catch (RuntimeException $e) { return; } throw new RuntimeException('Expected rejection'); }
$checks['Nested credentials and personal data are redacted'] = function () {
    $value = TeyaHikashopUtils::redact(array('test_client_secret'=>'SENSITIVE_SECRET','customer'=>(object) array('email'=>'PRIVATE_EMAIL')));
    check(strpos(json_encode($value),'SENSITIVE_SECRET') === false && strpos(json_encode($value),'PRIVATE_EMAIL') === false);
};
$checks['Refund success cannot mark an order paid'] = function () {
    check(TeyaHikashopGateway::outcome( array('event'=>'refund.succeeded.v1','data'=>array('status'=>'SUCCESS','transaction_id'=>'fake'))) === 'unsupported');
};
$checks['Payment success requires a consistent success status'] = function () {
    check(TeyaHikashopGateway::outcome(array('event'=>'payment.succeeded.v1','data'=>array('status'=>'FAILED','transaction_id'=>'fake'))) !== 'success');
};
$checks['Unknown currency is rejected rather than relabelled EUR'] = function () {
    rejects(function () { TeyaHikashopCheckoutBuilder::currencyCode((object) array()); });
};
$checks['Fractional HUF total is not silently charged at another amount'] = function () {
    rejects(function () { TeyaHikashopCheckoutBuilder::build((object) array('order_currency_id'=>2,'order_full_price'=>1359.5,'order_id'=>1), (object) array(), array('success_url'=>'https://example.invalid','failure_url'=>'https://example.invalid','cancel_url'=>'https://example.invalid')); });
};
$checks['Encrypted credentials round-trip without exposing plaintext'] = function () {
    $params = (object) array('client_secret'=>'DUMMY_SECRET','test_access_token'=>'DUMMY_TOKEN','debug'=>1);
    $protected = TeyaHikashopCredentials::protect($params);
    check(strpos(serialize($protected),'DUMMY_SECRET') === false && strpos(serialize($protected),'DUMMY_TOKEN') === false);
    check(TeyaHikashopCredentials::value($protected,'client_secret','live') === 'DUMMY_SECRET');
    check(TeyaHikashopCredentials::value($protected,'access_token','test') === 'DUMMY_TOKEN');
    check($protected->debug === 1 && $params->client_secret === 'DUMMY_SECRET');
    check(serialize(TeyaHikashopCredentials::protect($protected)) === serialize($protected));
};
$checks['Legacy plaintext remains readable until migrated'] = function () {
    check(TeyaHikashopCredentials::value((object) array('client_secret'=>'LEGACY'),'client_secret','live') === 'LEGACY');
};
$checks['Encryption is randomized and authenticated'] = function () {
    $one = TeyaHikashopCredentials::encrypt('DUMMY','client_secret');
    $two = TeyaHikashopCredentials::encrypt('DUMMY','client_secret');
    check($one !== $two);
    rejects(function () use ($one) { TeyaHikashopCredentials::decrypt($one,'test_client_secret'); });
    $tampered = substr($one,0,-6) . 'AAAAAA';
    rejects(function () use ($tampered) { TeyaHikashopCredentials::decrypt($tampered,'client_secret'); });
};
$checks['Wrong Joomla key fails closed'] = function () {
    $encrypted = TeyaHikashopCredentials::encrypt('DUMMY','client_secret');
    $before = \Joomla\CMS\Factory::$secret;
    \Joomla\CMS\Factory::$secret = 'different-site-secret-0123456789';
    try { rejects(function () use ($encrypted) { TeyaHikashopCredentials::decrypt($encrypted,'client_secret'); }); }
    finally { \Joomla\CMS\Factory::$secret = $before; }
};
$checks['Line items reconcile tax, fees and discounts'] = function () {
    $order = (object) array('order_currency_id'=>1,'order_full_price'=>15,'order_id'=>1,'products'=>array((object) array('order_product_quantity'=>1,'order_product_price'=>10,'order_product_tax'=>2,'order_product_name'=>'Item')));
    $payload = TeyaHikashopCheckoutBuilder::build($order,(object) array(),array('success_url'=>'https://example.invalid','failure_url'=>'https://example.invalid','cancel_url'=>'https://example.invalid'));
    check($payload['line_items'][0]['unit_price'] === 1200);
    check(array_sum(array_map(function ($item) { return $item['quantity']*$item['unit_price']; },$payload['line_items'])) === 1500);
};
$checks['Admin configuration saves preserve server credentials and encrypt legacy values'] = function () {
    JFactory::$db = new OfflineDatabase();
    JFactory::$db->stored = serialize((object) array('client_secret'=>'SERVER_SECRET','test_access_token'=>'SERVER_TOKEN'));
    $element = (object) array('payment_id'=>3,'payment_type'=>'teya','payment_params'=>(object) array('client_secret'=>'FORGED','test_access_token'=>'STALE','debug'=>1));
    $plugin = new plgHikashoppaymentTeya();
    check($plugin->onPaymentConfigurationSave($element) === true);
    check(TeyaHikashopCredentials::value($element->payment_params,'client_secret','live') === 'SERVER_SECRET');
    check(TeyaHikashopCredentials::value($element->payment_params,'access_token','test') === 'SERVER_TOKEN');
    check(strpos(serialize($element->payment_params),'SERVER_SECRET') === false);
    check($element->payment_params->debug === 1);
};
$checks['Native cart products are itemised with tax-inclusive prices and adjustments'] = function () {
    $order=(object)array('order_id'=>7,'order_number'=>'B3J1','order_currency_id'=>1,'order_full_price'=>29,'cart'=>(object)array('products'=>array(
        (object)array('product_name'=>'First item','cart_product_quantity'=>2,'prices'=>array((object)array('price_value_with_tax'=>12))),
        (object)array('product_name'=>'Second item','cart_product_quantity'=>1,'prices'=>array((object)array('price_value_with_tax'=>3)))
    )));
    $urls=array('success_url'=>'https://example.invalid','failure_url'=>'https://example.invalid','cancel_url'=>'https://example.invalid');
    $payload=TeyaHikashopCheckoutBuilder::build($order,(object)array('total_line_item'=>0),$urls);
    check(count($payload['line_items'])===3 && $payload['line_items'][0]['description']==='First item');
    check($payload['line_items'][0]['quantity']===2 && $payload['line_items'][0]['unit_price']===1200);
    check($payload['line_items'][1]['unit_price']===300 && $payload['line_items'][2]['unit_price']===200);
    $single=TeyaHikashopCheckoutBuilder::build($order,(object)array('total_line_item'=>1),$urls);
    check(count($single['line_items'])===1 && $single['line_items'][0]['unit_price']===2900);
};
$checks['Actual request logging redacts OAuth forms and JSON customers'] = function () {
    foreach (array(
        array('Content-Type: application/x-www-form-urlencoded', 'client_secret=FORM_SECRET&client_id=dummy'),
        array('Content-Type: application/json', '{"customer":{"email":"PRIVATE_EMAIL"},"test_access_token":"JSON_TOKEN"}'),
        array('Content-Type: text/plain', 'RAW_SECRET')
    ) as $case) {
        $logs = array();
        $api = new TeyaHikashopApiClient(true,function ($message) use (&$logs) { $logs[]=$message; });
        $request = new ReflectionMethod($api,'request');
        // Unsupported protocol fails locally; no network service is contacted.
        try { @$request->invoke($api,'POST','teya-offline-test://invalid',array($case[0]),$case[1]); }
        catch (RuntimeException $expected) {}
        check(count($logs) > 0);
        foreach (array('FORM_SECRET','PRIVATE_EMAIL','JSON_TOKEN','RAW_SECRET') as $secret) { check(strpos(implode(' ', $logs),$secret) === false); }
    }
};
$checks['RSA callbacks require the original body and correct signing key'] = function () {
    $key = openssl_pkey_new(array('private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA));
    $public = preg_replace('/-----[^-]+-----|\s+/', '', openssl_pkey_get_details($key)['key']);
    $body = '{"event":"payment.succeeded.v1"}';
    openssl_sign($body,$signature,$key,OPENSSL_ALGO_SHA256);
    TeyaHikashopWebhookVerifier::verify($body,base64_encode($signature),$public);
    rejects(function () use ($body,$signature,$public) { TeyaHikashopWebhookVerifier::verify($body.' ',base64_encode($signature),$public); });
    rejects(function () use ($body,$public) { TeyaHikashopWebhookVerifier::verify($body,'invalid!',$public); });
    rejects(function () use ($body,$public) { TeyaHikashopWebhookVerifier::verify($body,'',$public); });
};
$failures=0;
foreach ($checks as $name=>$test) {
    try { $test(); echo 'PASS: '.$name.PHP_EOL; }
    catch (\Throwable $e) { ++$failures; echo 'FAIL: '.$name.' ('.get_class($e).')'.PHP_EOL; }
}
echo count($checks).' checks; '.$failures.' failures'.PHP_EOL;
exit($failures ? 1 : 0);
}
