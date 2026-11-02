<?php
namespace Joomla\CMS {
    class Factory {
        public static function getConfig() { return new class { public function get($key) { return 'isolated-return-test-site-secret'; } }; }
    }
}
namespace {
define('_JEXEC',1);
error_reporting(E_ALL);
set_error_handler(function ($severity,$message,$file,$line) { throw new \ErrorException($message,0,$severity,$file,$line); });
class hikashopPaymentPlugin {}
class ReturnApp {
    public $site=true; public $token='';
    public function isClient($name) { return $this->site && $name==='site'; }
    public function getUserState($name,$default='') { return $this->token; }
}
class JFactory {
    public static $store; public static $app;
    public static function getApplication() { return self::$app; }
    public static function getDBO() {
        return new class {
            public function setQuery($query) {}
            public function quote($value) { return "'".$value."'"; }
            public function loadObject() { $method=unserialize(serialize(JFactory::$store->method)); $method->payment_params=serialize($method->payment_params); return $method; }
        };
    }
}
class hikaInput {
    public static $token='';
    public static function get() { return new self(); }
    public function getString($key) { return self::$token; }
}
function hikashop_loadUser($full) { return $GLOBALS['returnUser'] ?? 0; }
function hikashop_get($name) {
    if ($name!=='class.order') { throw new \RuntimeException('Unexpected HikaShop class'); }
    return new class { public function get($id) { return unserialize(serialize(JFactory::$store->order)); } };
}
require dirname(__DIR__).'/plugins/hikashoppayment/teya/teya.php';
$checks=0;
function verifyReturn($ok,$label) { global $checks; ++$checks; if (!$ok) { throw new \RuntimeException($label); } }
function rejectReturn($callback,$label) { try { $callback(); } catch (\RuntimeException $e) { verifyReturn(true,$label); return; } throw new \RuntimeException($label); }
class ReturnStore {
    public $method; public $order; public $locks=array(); public $statuses=array(); public $notes=array(); public $notifications=0; public $fail=false;
    public function __construct($public) {
        $this->method=(object)array('payment_id'=>3,'payment_params'=>(object)array('sandbox'=>0,'test_client_id'=>'fixture-client','test_client_secret'=>'FIXTURE_SECRET','test_access_token'=>'OLD_TOKEN','test_access_token_expires_at'=>time()+3600,'test_payment_webhook_public_key'=>$public,'verified_status'=>'confirmed','refund_status'=>'refunded','hikashop_merchant_email'=>1));
        $this->order=(object)array('order_id'=>7,'order_payment_id'=>3,'order_payment_method'=>'teya','order_type'=>'sale','order_user_id'=>9,'order_token'=>'private-order-token','order_status'=>'created','order_payment_params'=>(object)array(
            'teya_session_id'=>'private-session','teya_environment'=>'test','teya_checkout_amount'=>array('value'=>1200,'currency'=>'EUR'),'teya_checkout_public_key'=>$public,
            'teya_checkout'=>array('original_payload'=>array('merchant_reference'=>'joomla_hikashop#B7'))
        ));
    }
    private function copy($value) { return unserialize(serialize($value)); }
    public function locked($key,$callback) { if (isset($this->locks[$key])) { throw new \RuntimeException('Busy'); } $this->locks[$key]=true; try { return $callback(); } finally { unset($this->locks[$key]); } }
    public function method($id) { return $this->copy($this->method); }
    public function order($id) { return $this->copy($this->order); }
    public function findSession($session,$method) { if ($session!==$this->order->order_payment_params->teya_session_id) { throw new \RuntimeException('Unknown session'); } return 7; }
    public function saveMethod($row) { $row->payment_params=TeyaHikashopCredentials::protect($row->payment_params); $this->method=$this->copy($row); }
    public function saveOrder($row,$status=null,$note='') {
        if ($this->fail) { throw new \RuntimeException('Persistence failed'); }
        if ($status!==null) { $row->order_status=$status; $this->statuses[]=$status; }
        if ($note!=='') { $this->notes[]=$note; }
        $this->order=$this->copy($row);
    }
    public function notifyMerchant($order,$status) { ++$this->notifications; }
}
class ReturnApi {
    public $store; public $responses=array(); public $calls=array(); public $scopes=array(); public $tokenError;
    public function requestAccessToken($id,$secret,$environment,$sessionRead=false) {
        $this->scopes[]=$sessionRead;
        if ($this->tokenError) { throw $this->tokenError; }
        return array('access_token'=>'NEW_TOKEN','expires_in'=>600);
    }
    public function getCheckoutSession($session,$token,$environment) {
        verifyReturn(count($this->store->locks)===2,'Return lookup holds financial locks');
        $this->calls[]=array($session,$token,$environment);
        $response=array_shift($this->responses);
        if ($response instanceof \Throwable) { throw $response; }
        return $response;
    }
    public function createRefund($payload,$token,$environment,$key) { return array('_http_status'=>201,'status'=>'SUCCESS'); }
}
class ReturnPlugin extends plgHikashoppaymentTeya {
    public $testGateway; public $logs=array();
    public function writeToLog($message) { $this->logs[]=$message; }
    protected function gateway() { return $this->testGateway; }
}
$key=openssl_pkey_new(array('private_key_bits'=>2048));
$public=preg_replace('/-----[^-]+-----|\s+/','',openssl_pkey_get_details($key)['key']);
function fixtureReturn() {
    global $public;
    $store=new ReturnStore($public); $api=new ReturnApi(); $api->store=$store;
    return array($store,$api,new TeyaHikashopGateway($store,fn()=>$api));
}
function responseReturn() {
    return array('_http_status'=>200,'session_id'=>'private-session','session_status'=>'COMPLETED','payment_status'=>'SUCCESS','transaction_id'=>'verified-transaction','type'=>'SALE','amount'=>array('value'=>1200,'currency'=>'EUR'),'merchant_reference'=>'joomla_hikashop#B7','customer'=>array('email'=>'PRIVATE_CUSTOMER'),'session_url'=>'PRIVATE_ACCESS_URL');
}
function notifyReturn($gateway,$key,$failure=false) {
    $data=array('session_id'=>'private-session','transaction_id'=>'verified-transaction','status'=>$failure ? 'FAILED' : 'SUCCESS','amount'=>array('value'=>1200,'currency'=>'EUR'));
    $event=array('event'=>$failure ? 'payment.failed.v1' : 'payment.succeeded.v1','data'=>$data);
    $raw=json_encode($event); openssl_sign($raw,$signature,$key,OPENSSL_ALGO_SHA256);
    $gateway->notification(3,$event,$raw,base64_encode($signature));
}
[$s,$a,$g]=fixtureReturn(); $a->responses[]=responseReturn();
$a->responses[0]['amount']['private']='PRIVATE_NESTED_AMOUNT';
verifyReturn($g->reconcileReturn(3,7)===true,'Return confirms authenticated success');
verifyReturn($s->order->order_status==='confirmed' && $s->notifications===1,'Return uses normal status/email transition');
verifyReturn($a->calls===array(array('private-session','NEW_TOKEN','test')),'Stored session/environment used even when method is live');
verifyReturn($a->scopes===array(true),'Old cached token upgraded to session read scope');
verifyReturn($s->method->payment_params->test_access_token_scopes===TeyaHikashopApiClient::SESSION_READ_SCOPE,'Scope cache marker saved');
verifyReturn(strpos(serialize($s->method),'NEW_TOKEN')===false,'Refreshed token encrypted');
verifyReturn($s->order->order_payment_params->teya_payment_confirmation_source==='session_lookup','Evidence source recorded');
verifyReturn(strpos(serialize($s->order),'PRIVATE_CUSTOMER')===false && strpos(serialize($s->order),'PRIVATE_ACCESS_URL')===false && strpos(serialize($s->order),'PRIVATE_NESTED_AMOUNT')===false,'Session customer/access URL never stored');
$g->reconcileReturn(3,7); notifyReturn($g,$key); notifyReturn($g,$key,true);
verifyReturn(count($a->calls)===1 && count($s->statuses)===1 && $s->notifications===1,'Repeated returns and late webhooks never duplicate/downgrade confirmation');
verifyReturn($g->refund(3,7,'2.00','return-refund-0001')['remaining']===1000,'Partial refund after return confirmation');
verifyReturn($g->refund(3,7,'10.00','return-refund-0002')['remaining']===0,'Full remainder after return confirmation');
$g->reconcileReturn(3,7); notifyReturn($g,$key);
verifyReturn($s->order->order_status==='refunded' && count($s->statuses)===2 && $s->notifications===1,'Late returns/webhooks preserve completed refunds');
[$s,$a,$g]=fixtureReturn(); notifyReturn($g,$key); $g->reconcileReturn(3,7);
verifyReturn(!$a->calls && !$a->scopes && count($s->statuses)===1,'Webhook-first order does not require a lookup or new scope');
foreach (array(array('ACTIVE','SUCCESS'),array('PROCESSING','SUCCESS'),array('COMPLETED','NONE'),array('COMPLETED','FAILED'),array('EXPIRED','FAILED')) as [$state,$payment]) {
    [$s,$a,$g]=fixtureReturn(); $response=responseReturn(); $response['session_status']=$state; $response['payment_status']=$payment; $a->responses[]=$response;
    verifyReturn($g->reconcileReturn(3,7)===false && !$s->statuses && !$s->notifications,'Unsettled/unsuccessful session never confirms');
    $g->reconcileReturn(3,7); verifyReturn(count($a->calls)===1,'Repeated pending returns throttled');
    notifyReturn($g,$key); verifyReturn($s->order->order_status==='confirmed','Later signed webhook still works');
}
[$s,$a,$g]=fixtureReturn(); $pending=responseReturn(); $pending['session_status']='PROCESSING'; $a->responses=array($pending,responseReturn());
$g->reconcileReturn(3,7); $s->order->order_payment_params->teya_session_check_after=0;
verifyReturn($g->reconcileReturn(3,7) && $a->scopes===array(true) && count($a->calls)===2,'Pending return can be retried with cached scoped token after throttle');
foreach (array('session','amount','currency','reference','type','transaction','http','missing_amount','malformed_value','malformed_amount','conflicting_transaction') as $case) {
    [$s,$a,$g]=fixtureReturn(); $response=responseReturn();
    if ($case==='session') $response['session_id']='other-session';
    if ($case==='amount') $response['amount']['value']=1;
    if ($case==='currency') $response['amount']['currency']='HUF';
    if ($case==='reference') $response['merchant_reference']='another-order';
    if ($case==='type') $response['type']='PRE_AUTHORISATION';
    if ($case==='transaction') unset($response['transaction_id']);
    if ($case==='http') $response['_http_status']=302;
    if ($case==='missing_amount') unset($response['amount']);
    if ($case==='malformed_value') $response['amount']['value']=array('PRIVATE');
    if ($case==='malformed_amount') $response['amount']='PRIVATE';
    if ($case==='conflicting_transaction') $s->order->order_payment_params->teya_transaction_id='other-transaction';
    $a->responses[]=$response;
    rejectReturn(fn()=>$g->reconcileReturn(3,7),'Reject mismatched '.$case);
    verifyReturn(!$s->statuses && !$s->notifications,'Rejected lookup has no financial side effects: '.$case);
}
foreach (array(401,403,404,429,500,'timeout') as $error) {
    [$s,$a,$g]=fixtureReturn(); $exception=$error==='timeout' ? new \RuntimeException('PRIVATE provider detail') : new TeyaHikashopApiException('PRIVATE provider detail',$error);
    $a->responses=array($exception,$exception);
    rejectReturn(fn()=>$g->reconcileReturn(3,7),'Lookup error remains unconfirmed');
    verifyReturn(!$s->statuses && !$s->notifications,'Lookup error never marks paid');
    notifyReturn($g,$key); verifyReturn($s->order->order_status==='confirmed','Webhook works after lookup error');
}
[$s,$a,$g]=fixtureReturn(); $response=responseReturn(); $response['source_reference_id']=$response['merchant_reference']; unset($response['merchant_reference']); $a->responses[]=$response;
verifyReturn($g->reconcileReturn(3,7),'Legacy provider reference field is verified');
[$s,$a,$g]=fixtureReturn(); $a->responses=array(new TeyaHikashopApiException('Expired',401),responseReturn());
$g->reconcileReturn(3,7);
verifyReturn($a->scopes===array(true,true) && count($a->calls)===2,'One refresh/retry with read scope after 401');
[$s,$a,$g]=fixtureReturn(); $a->tokenError=new TeyaHikashopApiException('Invalid scope',400);
rejectReturn(fn()=>$g->reconcileReturn(3,7),'Unavailable session scope does not confirm');
verifyReturn(!$a->calls && !$s->statuses && TeyaHikashopCredentials::value($s->method->payment_params,'access_token','test')==='OLD_TOKEN','Scope refusal preserves existing credentials/token');
[$s,$a,$g]=fixtureReturn(); $s->fail=true;
rejectReturn(fn()=>$g->reconcileReturn(3,7),'Persistence failure rejected');
verifyReturn(!$a->calls && !$a->scopes,'No API before durable throttle save');
foreach (array('method','lock','missing_session','environment') as $case) {
    [$s,$a,$g]=fixtureReturn();
    if ($case==='method') $s->order->order_payment_id=99;
    if ($case==='lock') $s->locks['order_7']=true;
    if ($case==='missing_session') unset($s->order->order_payment_params->teya_session_id);
    if ($case==='environment') $s->order->order_payment_params->teya_environment='other';
    if (in_array($case,array('method','lock'))) rejectReturn(fn()=>$g->reconcileReturn(3,7),'Wrong method/busy order rejected');
    else verifyReturn($g->reconcileReturn(3,7)===false,'Missing trusted context skipped');
    verifyReturn(!$a->calls && !$a->scopes,'No API without valid order context');
}
// Native hook contract: authorization, page selection, safe failure, and refreshing the displayed status.
foreach (array('owner','guest_query','guest_session','stranger','empty_guest','wrong_token','admin','other_page','lookup_error') as $case) {
    [$s,$a,$g]=fixtureReturn(); JFactory::$store=$s; JFactory::$app=new ReturnApp(); hikaInput::$token=''; $GLOBALS['returnUser']=0;
    $view=new class($s->order) {
        public $order; public $layout='after_end';
        public function __construct($order) { $this->order=unserialize(serialize($order)); }
        public function getName() { return 'checkout'; }
        public function getLayout() { return $this->layout; }
    };
    if ($case==='owner' || $case==='lookup_error') $GLOBALS['returnUser']=9;
    if ($case==='guest_query') hikaInput::$token='private-order-token';
    if ($case==='guest_session') JFactory::$app->token='private-order-token';
    if ($case==='stranger') $GLOBALS['returnUser']=10;
    if ($case==='wrong_token') hikaInput::$token='forged';
    if ($case==='admin') { JFactory::$app->site=false; $GLOBALS['returnUser']=9; }
    if ($case==='other_page') { $view->layout='show'; $GLOBALS['returnUser']=9; }
    $a->responses[]=$case==='lookup_error' ? new TeyaHikashopApiException('PRIVATE provider detail',403) : responseReturn();
    $plugin=new ReturnPlugin(); $plugin->testGateway=$g;
    ob_start(); $plugin->onHikashopBeforeDisplayView($view); $output=ob_get_clean();
    verifyReturn($output==='' && strpos($output,'PRIVATE')===false,'Hook never prints errors/provider data');
    $allowed=in_array($case,array('owner','guest_query','guest_session','lookup_error'));
    verifyReturn(count($a->calls)===($allowed ? 1 : 0),'Return ownership/page gate: '.$case);
    verifyReturn($view->order->order_status===($allowed && $case!=='lookup_error' ? 'confirmed' : 'created'),'Return view reflects verified status only: '.$case);
}
// Debug uses the requested payment method even when the plugin instance has no loaded payment_params.
foreach (array(true,false) as $debug) {
    $plugin=new ReturnPlugin();
    $client=(new \ReflectionMethod(plgHikashoppaymentTeya::class,'apiClient'))->invoke($plugin,(object)array('debug'=>$debug));
    try { (new \ReflectionMethod($client,'request'))->invoke($client,'GET','teya-offline-test://invalid/v2/checkout/sessions/private-session'); }
    catch (\RuntimeException $expected) {}
    verifyReturn((bool)$plugin->logs===$debug,'Actual plugin logger honors the method debug setting');
    verifyReturn(strpos(implode(' ',$plugin->logs),'private-session')===false,'Plugin logger never exposes the session identifier');
}
echo 'PASS: '.$checks.' return reconciliation, ownership, duplicate, refund and failure checks'.PHP_EOL;
}
