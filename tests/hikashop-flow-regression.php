<?php
namespace Joomla\CMS { class Factory { static function getConfig() { return new class { function get($key) { return 'offline-secret-for-payment-tests'; } }; } } }
namespace {
define('_JEXEC',1);
$source=dirname(__DIR__).'/plugins/hikashoppayment/teya/src/';
foreach (array('Utils','Credentials','ApiClient','WebhookVerifier','CheckoutBuilder','Gateway') as $class) { require $source.'TeyaHikashop'.$class.'.php'; }
function check($ok,$label) { global $checks; ++$checks; if (!$ok) { throw new RuntimeException($label); } }
function rejects($fn,$label) { try { $fn(); } catch (RuntimeException $e) { check(true,$label); return; } throw new RuntimeException($label); }
class Store {
 public $method; public $order; public $locks=array(); public $fail=false; public $writes=0; public $statuses=array(); public $notifications=0;
 function __construct() {
  $this->method=(object) array('payment_id'=>1,'payment_params'=>(object) array('sandbox'=>1,'test_payment_webhook_public_key'=>'test-public-key','test_client_id'=>'id','test_client_secret'=>'secret','test_access_token'=>'token','test_access_token_expires_at'=>time()+3600));
  $this->order=(object) array('order_id'=>1,'order_payment_id'=>1,'order_payment_params'=>new \stdClass());
 }
 function pending($id=null) { return !empty($this->order->order_payment_params->teya_refunds['pending']) ? array($this->order) : array(); }
 function findSession($session,$methodId) { if (($this->order->order_payment_params->teya_session_id ?? '') !== $session) { throw new RuntimeException('missing session'); } return 1; }
 function notifyMerchant($order,$status) { ++$this->notifications; }
 function copy($v) { return unserialize(serialize($v)); }
 function locked($key,$fn) { if (isset($this->locks[$key])) { throw new RuntimeException('busy'); } $this->locks[$key]=true; try { return $fn(); } finally { unset($this->locks[$key]); } }
 function method($id) { return $this->copy($this->method); }
 function order($id) { return $this->copy($this->order); }
 function saveMethod($row) { if ($this->fail) { throw new RuntimeException('disk failure'); } $this->method=$this->copy($row); }
 function saveOrder($row,$status=null,$note='') { if ($this->fail) { throw new RuntimeException('disk failure'); } ++$this->writes; if ($status!==null) { $this->statuses[]=$status; } $this->order=$this->copy($row); }
}
class Api {
 public $store; public $calls=array(); public $responses=array(); public $refresh=0; public $publicKey; public $deletions=0; public $tokens=array();
 function fetchRegistrationPublicKey($env) { return $this->publicKey; }
 function deleteCurrentRegistration($token,$env) { ++$this->deletions; }
 function requestAccessToken(...$args) { ++$this->refresh; return array('access_token'=>'refreshed','expires_in'=>3600); }
 function createCheckoutSession($payload,$token,$env,$key) { $this->tokens[]=$token; return $this->request('checkout',$payload,$env,$key); }
 function createRefund($payload,$token,$env,$key) { return $this->request('refund',$payload,$env,$key); }
 function request($type,$payload,$env,$key) {
  check(count($this->store->locks)===2,'method and order locks held');
  $params=$this->store->order->order_payment_params;
  $saved=$type==='checkout' ? $params->teya_checkout : $params->teya_refunds['pending'];
  check($saved['key']===$key && $saved['payload']===$payload,'exact request persisted before API');
  $this->calls[]=compact('type','payload','env','key'); $result=array_shift($this->responses);
  if ($result instanceof \Throwable) { throw $result; } return is_callable($result) ? $result() : $result;
 }
}
$checks=0;
$store=new Store(); $api=new Api(); $api->store=$store;
$gateway=new TeyaHikashopGateway($store,function ($params) use ($api) { return $api; });
$payload=array('amount'=>array('value'=>1200,'currency'=>'EUR'));
$api->responses=array(new RuntimeException('timeout'),array('session_id'=>'session-one','session_url'=>'https://checkout.example.test/pay'));
rejects(function () use ($gateway,$payload) { $gateway->checkout(1,1,$payload); },'timeout leaves durable checkout');
$gateway->checkout(1,1,$payload);
check($api->calls[0]['key']===$api->calls[1]['key'],'checkout retry keeps key');
$gateway->checkout(1,1,$payload); check(count($api->calls)===2,'created session reused');
rejects(function () use ($gateway) { $gateway->checkout(1,1,array('amount'=>array('value'=>999,'currency'=>'EUR'))); },'changed ambiguous checkout rejected');
$store->order->order_payment_params=new \stdClass(); $store->fail=true; $before=count($api->calls);
rejects(function () use ($gateway,$payload) { $gateway->checkout(1,1,$payload); },'failed persistence stops request');
check(count($api->calls)===$before,'no network after failed persistence'); $store->fail=false;
$api->responses=array(new TeyaHikashopApiException('expired',401),array('session_id'=>'session-two','session_url'=>'https://checkout.example.test/pay'));
$gateway->checkout(1,1,$payload);
check($api->refresh===1,'401 refreshes token');
check($api->calls[count($api->calls)-1]['key']===$api->calls[count($api->calls)-2]['key'],'401 preserves idempotency');
$p=$store->order->order_payment_params;
$p->teya_transaction_id='transaction-one';
$p->teya_webhook_payload=array('event'=>'payment.succeeded.v1','data'=>array('status'=>'SUCCESS','transaction_id'=>'transaction-one','amount'=>$payload['amount']));
$api->responses=array(array('_http_status'=>202));
$result=$gateway->refund(1,1,'5.00','refund-request-0001'); check($result['pending'] && $result['remaining']===1200,'202 does not increment refunded');
rejects(function () use ($gateway) { $gateway->refund(1,1,'5.00','refund-request-0002'); },'pending blocks second operation');
$store->order->order_payment_params->teya_refunds['pending']['next_attempt']=0;
$api->responses=array(new RuntimeException('timeout'));
$result=$gateway->refund(1,1,null,'refund-request-0001',true); check($result['pending'],'timeout stays pending');
$store->order->order_payment_params->teya_refunds['pending']['next_attempt']=0;
$api->responses=array(array('_http_status'=>201,'status'=>'SUCCESS'));
$result=$gateway->refund(1,1,null,'refund-request-0001',true); check(!$result['pending'] && $result['remaining']===700,'partial refund confirmed once');
$before=count($api->calls); $gateway->refund(1,1,'5.00','refund-request-0001'); check(count($api->calls)===$before,'repeated submit returns existing result');
rejects(function () use ($gateway) { $gateway->refund(1,1,'7.01','refund-request-0002'); },'over-refund rejected');
rejects(function () use ($gateway) { $gateway->refund(1,1,'0.001','refund-request-0002'); },'fractional cent rejected');
$api->responses=array(array('_http_status'=>201,'status'=>'SUCCESS'));
$result=$gateway->refund(1,1,'7.00','refund-request-0002'); check($result['remaining']===0,'second partial can finish full refund');
check(count($store->order->order_payment_params->teya_refunds['refunds'])===2,'both refunds retained');
check($store->locks===array(),'locks released after success and failures');
check(end($store->statuses)==='refunded','full refund updates order status after confirmation');
$key=openssl_pkey_new(array('private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA));
$public=preg_replace('/-----[^-]+-----|\s+/','',openssl_pkey_get_details($key)['key']);
$api->publicKey=$public;
$nonce=$gateway->beginConnection(1,'test');
$raw=json_encode(array('client_id'=>'new-id','client_secret'=>'new-secret','payment_webhook_public_key'=>$public));
openssl_sign($raw,$signature,$key,OPENSSL_ALGO_SHA256);
rejects(function () use ($gateway,$raw,$signature) { $gateway->registration(1,'test','wrong',$raw,base64_encode($signature)); },'signed registration requires matching intent');
$gateway->registration(1,'test',$nonce,$raw,base64_encode($signature));
check($store->method->payment_params->test_client_id==='new-id','matching registration updates credentials');
$before=$api->refresh;
$gateway->registration(1,'test',$nonce,$raw,base64_encode($signature));
check($before===$api->refresh,'registration duplicate is no-op');
$gateway->disconnect(1,'test');
check($api->deletions===1,'disconnect calls Teya');
rejects(function () use ($gateway,$nonce,$raw,$signature) { $gateway->registration(1,'test',$nonce,$raw,base64_encode($signature)); },'disconnect invalidates late registration');
check(!TeyaHikashopCredentials::isConnected($store->method->payment_params,'test'),'disconnect clears credentials');
$nonce=$gateway->beginConnection(1,'test');
$store->method->payment_params->teya_onboarding['test']['expires']=time()-1;
rejects(function () use ($gateway,$nonce,$raw,$signature) { $gateway->registration(1,'test',$nonce,$raw,base64_encode($signature)); },'expired registration rejected');
$store->order->order_payment_params->teya_refunds['pending']=array('key'=>'refund-pending');
rejects(function () use ($gateway) { $gateway->disconnect(1,'test'); },'pending refund blocks disconnect');
rejects(function () use ($gateway) { $gateway->beginConnection(1,'test'); },'pending refund blocks reconnect');
unset($store->order->order_payment_params->teya_refunds['pending']);
$store->method->payment_params->test_payment_webhook_public_key=$public;
// Reset to an unpaid checkout to test signed callback and retry behavior.
$p=$store->order->order_payment_params;
$p->teya_checkout_public_key=$public;
unset($p->teya_webhook_payload,$p->teya_transaction_id,$p->teya_refunds);
$payload=array('event'=>'payment.succeeded.v1','data'=>array('transaction_id'=>'new-tx','session_id'=>$p->teya_session_id,'status'=>'SUCCESS','amount'=>$p->teya_checkout_amount));
$raw=json_encode($payload); openssl_sign($raw,$signature,$key,OPENSSL_ALGO_SHA256);
$store->fail=true;
rejects(function () use ($gateway,$payload,$raw,$signature) { $gateway->notification(1,$payload,$raw,base64_encode($signature)); },'failed callback persistence returns failure');
check(empty($store->order->order_payment_params->teya_transaction_id),'failed callback does not mark paid');
$store->fail=false;
$gateway->notification(1,$payload,$raw,base64_encode($signature));
$before=$store->writes;
$gateway->notification(1,$payload,$raw,base64_encode($signature));
check($before===$store->writes,'duplicate callback does not repeat order update');
$bad=$payload; $bad['data']['amount']['value']=1; $raw=json_encode($bad); openssl_sign($raw,$signature,$key,OPENSSL_ALGO_SHA256);
rejects(function () use ($gateway,$bad,$raw,$signature) { $gateway->notification(1,$bad,$raw,base64_encode($signature)); },'signed wrong amount rejected');
$bad=$payload; $bad['event']='payment.failed.v1'; $bad['data']['status']='FAILED'; $raw=json_encode($bad); openssl_sign($raw,$signature,$key,OPENSSL_ALGO_SHA256);
$gateway->notification(1,$bad,$raw,base64_encode($signature));
check($store->order->order_payment_params->teya_transaction_id==='new-tx','late failure cannot overwrite successful payment');
check($store->locks===array(),'callback and onboarding errors release locks');
// Basket fallback gets a new key only after a definitive rejection.
$store=new Store(); $api=new Api(); $api->store=$store;
$gateway=new TeyaHikashopGateway($store,function ($params) use ($api) { return $api; });
$basket=array('amount'=>array('value'=>1200,'currency'=>'EUR'),'line_items'=>array(array('quantity'=>1,'unit_price'=>1000),array('quantity'=>1,'unit_price'=>200)));
$api->responses=array(array('_http_status'=>400,'code'=>'INVALID_LINE_ITEMS'),array('session_id'=>'fallback-session','session_url'=>'https://checkout.example.test/pay'));
$gateway->checkout(1,1,$basket);
check($api->calls[0]['key']!==$api->calls[1]['key'],'changed fallback request uses fresh key');
$gateway->checkout(1,1,$basket); check(count($api->calls)===2,'fallback session reused on browser retry');
$p=$store->order->order_payment_params; $p->teya_transaction_id='tx'; $p->teya_webhook_payload=array('event'=>'payment.succeeded.v1','data'=>array('transaction_id'=>'tx','status'=>'SUCCESS','amount'=>$basket['amount']));
$api->responses=array(function () use ($store) { $store->fail=true; return array('_http_status'=>201,'status'=>'SUCCESS'); });
rejects(function () use ($gateway) { $gateway->refund(1,1,'1.00','crash-recovery-0001'); },'confirmed API with failed local write surfaces failure');
check(!empty($store->order->order_payment_params->teya_refunds['pending']),'local crash leaves original durable refund');
$store->fail=false; $store->order->order_payment_params->teya_refunds['pending']['next_attempt']=0;
$api->responses=array(array('_http_status'=>201,'status'=>'SUCCESS'));
$result=$gateway->refund(1,1,null,'crash-recovery-0001',true);
check($result['remaining']===1100,'crash recovery counts refund once');
check($api->calls[count($api->calls)-1]['key']===$api->calls[count($api->calls)-2]['key'],'crash recovery replays identical key');
$before=count($api->calls); $store->locks['order_1']=true;
rejects(function () use ($gateway) { $gateway->refund(1,1,'1.00','concurrent-0000001'); },'concurrent order update rejected');
check(count($api->calls)===$before,'concurrent request never reaches API'); unset($store->locks['order_1']);
check(TeyaHikashopApiClient::retryDelay('120')===120,'numeric Retry-After retained');
check(TeyaHikashopApiClient::retryDelay('')===0,'missing Retry-After is zero');
$api->responses=array(new TeyaHikashopApiException('Rate limited',429,array('_retry_after'=>7200)));
$result=$gateway->refund(1,1,'1.00','rate-limit-000001');
check($result['pending'],'rate limit retains pending refund');
check($store->order->order_payment_params->teya_refunds['pending']['next_attempt']>=time()+7199,'long Retry-After overrides normal backoff');
// A checkout authorization failure must not create refund state or report a refund error.
$checkoutStore=new Store(); $checkoutApi=new Api(); $checkoutApi->store=$checkoutStore;
$checkoutGateway=new TeyaHikashopGateway($checkoutStore,fn()=>$checkoutApi);
$checkoutApi->responses=array(new TeyaHikashopApiException('PRIVATE provider error',403));
rejects(fn()=>$checkoutGateway->checkout(1,1,array('amount'=>array('value'=>1000,'currency'=>'EUR'))),'checkout 403 fails without mixing refund state');
check(!isset($checkoutStore->order->order_payment_params->teya_refunds),'checkout failure never writes refund ledger');
check(!empty($checkoutStore->order->order_payment_params->teya_checkout['key']),'checkout authorization failure retains its own retry reference');
// Authorization refusal must preserve the attempt without automatic resubmission.
unset($store->order->order_payment_params->teya_refunds);
$api->responses=array(new TeyaHikashopApiException('SECRET upstream response',403));
$result=$gateway->refund(1,1,'1.00','authorization-0001');
check($result['blocked'] && $result['pending'],'403 blocks recovery');
check(strpos(json_encode($result),'SECRET')===false,'raw provider message never returned');
$saved=$store->order->order_payment_params->teya_refunds['pending'];
$before=count($api->calls);
$gateway->refund(1,1,null,'authorization-0001',true);
check(count($api->calls)===$before,'background recovery cannot retry blocked authorization');
$api->responses=array(array('_http_status'=>201,'status'=>'SUCCESS','sensitive'=>'SECRET'));
$result=$gateway->refund(1,1,null,'authorization-0001',true,true);
check(!$result['pending'] && !$result['blocked'],'explicit recovery confirms refund');
$last=end($api->calls);
check($last['key']===$saved['key'] && $last['payload']===$saved['payload'],'manual retry preserves exact request');
check(strpos(json_encode($store->order->order_payment_params->teya_refunds),'SECRET')===false,'unnecessary raw response not persisted');
// Test the active credential path, not the removed plugin helpers.
$cachedStore=new Store(); $cachedApi=new Api(); $cachedApi->store=$cachedStore;
$cachedStore->method->payment_params=TeyaHikashopCredentials::protect($cachedStore->method->payment_params);
$cachedApi->responses=array(array('session_id'=>'encrypted-token-session','session_url'=>'https://checkout.example.test/pay'));
(new TeyaHikashopGateway($cachedStore,fn()=>$cachedApi))->checkout(1,1,array('amount'=>array('value'=>1200,'currency'=>'EUR')));
check($cachedApi->tokens===array('token'),'active gateway decrypts the stored cached token');
// Signature, session, amount/currency and method binding apply on the real notification entry point.
foreach (array('signature','session','method','environment','amount','currency','missing') as $case) {
 $s=new Store(); $a=new Api(); $a->store=$s;
 $s->order->order_payment_params=(object)array('teya_session_id'=>'bound-session','teya_environment'=>'test','teya_checkout_amount'=>array('value'=>1200,'currency'=>'EUR'),'teya_checkout_public_key'=>$public);
 $event=array('event'=>'payment.succeeded.v1','data'=>array('session_id'=>'bound-session','transaction_id'=>'bound-transaction','status'=>'SUCCESS','amount'=>array('value'=>1200,'currency'=>'EUR')));
 if ($case==='session') $event['data']['session_id']='different-session';
 if ($case==='method') $s->order->order_payment_id=2;
 if ($case==='environment') $s->order->order_payment_params->teya_environment='invalid';
 if ($case==='amount') $event['data']['amount']['value']=1;
 if ($case==='currency') $event['data']['amount']['currency']='HUF';
 if ($case==='missing') unset($s->order->order_payment_params->teya_session_id);
 $body=json_encode($event); openssl_sign($body,$sig,$key,OPENSSL_ALGO_SHA256);
 rejects(fn()=>(new TeyaHikashopGateway($s,fn()=>$a))->notification(1,$event,$body,$case==='signature' ? 'invalid' : base64_encode($sig)),'active notification rejects '.$case);
 check($s->writes===0,'rejected notification never writes payment state');
}
$s=new Store(); $a=new Api(); $a->store=$s;
$s->method->payment_params->hikashop_merchant_email=1;
$s->order->order_payment_params=(object)array('teya_session_id'=>'mail-session','teya_environment'=>'test','teya_checkout_amount'=>array('value'=>1200,'currency'=>'EUR'),'teya_checkout_public_key'=>$public);
$event=array('event'=>'payment.succeeded.v1','data'=>array('session_id'=>'mail-session','transaction_id'=>'mail-transaction','status'=>'SUCCESS','amount'=>array('value'=>1200,'currency'=>'EUR')));
$body=json_encode($event); openssl_sign($body,$sig,$key,OPENSSL_ALGO_SHA256);
$g=new TeyaHikashopGateway($s,fn()=>$a); $g->notification(1,$event,$body,base64_encode($sig)); $g->notification(1,$event,$body,base64_encode($sig));
check($s->notifications===1,'opt-in native merchant notification is sent only once');
echo $checks.' payment-flow checks passed'.PHP_EOL;
}
