<?php
/**
 * @package Teya Payments for HikaShop
 * @author Webensol
 * @license GNU General Public License version 3; see LICENSE.txt
 */
defined('_JEXEC') or die('Restricted access');

/** Financial operations have injectable persistence and transport for failure testing. */
class TeyaHikashopGateway
{
    private $store;
    private $factory;
    public function __construct($store, callable $factory) { $this->store=$store; $this->factory=$factory; }
    private function operation($methodId, $orderId, callable $callback)
    {
        return $this->store->locked('method_' . (int) $methodId, function () use ($methodId,$orderId,$callback) {
            $method=$this->store->method($methodId);
            return $this->store->locked('order_' . (int) $orderId, function () use ($method,$orderId,$callback) {
                $order=$this->store->order($orderId);
                if ((int) $order->order_payment_id !== (int) $method->payment_id) { throw new RuntimeException('Payment method mismatch.'); }
                return $callback($method,$order);
            });
        });
    }
    private function token($method,$environment,$force=false)
    {
        $params=$method->payment_params;
        $prefix=$environment === 'test' ? 'test_' : '';
        if (!$force && (int) ($params->{$prefix.'access_token_expires_at'} ?? 0) > time()) {
            $token=TeyaHikashopCredentials::value($params,'access_token',$environment);
            if ($token !== '') { return $token; }
        }
        $id=TeyaHikashopCredentials::value($params,'client_id',$environment);
        $secret=TeyaHikashopCredentials::value($params,'client_secret',$environment);
        if ($id === '' || $secret === '') { throw new RuntimeException('Teya is not connected for this payment environment.'); }
        $token=call_user_func($this->factory,$params)->requestAccessToken($id,$secret,$environment);
        if (!is_string($token['access_token'] ?? null) || $token['access_token'] === '') { throw new RuntimeException('Invalid OAuth response.'); }
        $params->{$prefix.'access_token'}=$token['access_token'];
        $params->{$prefix.'access_token_expires_at'}=time()+max(0,(int) ($token['expires_in'] ?? 0)-60);
        $this->store->saveMethod($method);
        return $token['access_token'];
    }
    private function request($method,$environment,$operation,$payload,$key)
    {
        $client=call_user_func($this->factory,$method->payment_params);
        try { return $client->$operation($payload,$this->token($method,$environment),$environment,$key); }
        catch (TeyaHikashopApiException $e) {
            if ($e->getStatusCode() !== 401) { throw $e; }
            return $client->$operation($payload,$this->token($method,$environment,true),$environment,$key);
        }
    }
    public function checkout($methodId,$orderId,array $payload)
    {
        return $this->operation($methodId,$orderId,function ($method,$order) use ($payload) {
            $params=$order->order_payment_params;
            if (!empty($params->teya_transaction_id)) { throw new RuntimeException('This order already has a payment.'); }
            $environment=TeyaHikashopUtils::environmentFromParams($method->payment_params);
            if (!TeyaHikashopCredentials::isConnected($method->payment_params,$environment)) { throw new RuntimeException('Teya is not connected.'); }
            $attempt=(array) ($params->teya_checkout ?? array());
            if (!$attempt || ($attempt['state'] ?? '') === 'rejected') {
                $attempt=array('key'=>TeyaHikashopUtils::createIdempotencyKey(),'payload'=>$payload,'original_payload'=>$payload,'environment'=>$environment,'state'=>'pending');
                $params->teya_checkout=$attempt;
                $this->store->saveOrder($order);
            } elseif ($attempt['environment'] !== $environment || json_encode($attempt['original_payload'] ?? $attempt['payload']) !== json_encode($payload)) {
                throw new RuntimeException('An existing checkout uses different payment details. Reconcile it before starting another payment.');
            }
            if (!empty($attempt['response'])) {
                if (self::outcome((array) ($params->teya_webhook_payload ?? array())) === 'failure') { throw new RuntimeException('This checkout has ended. Please place a new order.'); }
                return $attempt['response'];
            }
            try { $response=$this->request($method,$attempt['environment'],'createCheckoutSession',$attempt['payload'],$attempt['key']); }
            catch (TeyaHikashopApiException $e) {
                $code=$e->getStatusCode();
                if ($code>=400 && $code<500 && !in_array($code,array(401,403,408,409,429),true)) {
                    $attempt['state']='rejected'; $params->teya_checkout=$attempt; $this->store->saveOrder($order);
                }
                throw $e;
            }
            if (in_array($response['code'] ?? '',array('LINE_ITEMS_TOTAL_MISMATCH','INVALID_LINE_ITEMS'),true)
                && count($attempt['payload']['line_items'] ?? array())>1 && ($response['_http_status'] ?? 0)>=400 && ($response['_http_status'] ?? 0)<500) {
                // Definitively rejected basket: a changed body requires a new key.
                $attempt['payload']['line_items']=array(array('description'=>'Order '.$order->order_id,'quantity'=>1,'unit_price'=>$attempt['payload']['amount']['value']));
                $attempt['key']=TeyaHikashopUtils::createIdempotencyKey();
                $params->teya_checkout=$attempt; $this->store->saveOrder($order);
                $response=$this->request($method,$attempt['environment'],'createCheckoutSession',$attempt['payload'],$attempt['key']);
            }
            if (($response['_http_status'] ?? 0)>=400 && ($response['_http_status'] ?? 0)<500
                && !in_array($response['_http_status'],array(401,403,408,409,429),true)) {
                $attempt['state']='rejected'; $params->teya_checkout=$attempt; $this->store->saveOrder($order);
                throw new RuntimeException('Teya rejected this checkout request.');
            }
            if (!is_string($response['session_id'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D',$response['session_id'])
                || !is_string($response['session_url'] ?? null) || !filter_var($response['session_url'],FILTER_VALIDATE_URL)
                || parse_url($response['session_url'],PHP_URL_SCHEME) !== 'https' || parse_url($response['session_url'],PHP_URL_USER) !== null) {
                throw new RuntimeException('Teya did not return a valid checkout session. Retry the existing payment.');
            }
            $attempt['response']=$response;
            $attempt['state']='created';
            $params->teya_checkout=$attempt;
            $params->teya_session_id=$response['session_id'];
            $params->teya_environment=$attempt['environment'];
            $params->teya_checkout_amount=$attempt['payload']['amount'];
            $params->teya_checkout_public_key=TeyaHikashopCredentials::value($method->payment_params,'payment_webhook_public_key',$attempt['environment']);
            $this->store->saveOrder($order,null,'Teya checkout created: '.$response['session_id']);
            return $response;
        });
    }
    public static function outcome(array $payload)
    {
        $event=$payload['event'] ?? '';
        $data=$payload['data'] ?? array();
        $status=$data['status'] ?? ($data['payment_status'] ?? '');
        if ($event === 'payment.succeeded.v1' && $status === 'SUCCESS' && is_string($data['transaction_id'] ?? null) && $data['transaction_id'] !== '') { return 'success'; }
        if (in_array($event,array('payment.failed.v1','payment.cancelled.v1','payment.canceled.v1','payment.expired.v1'),true)
            && in_array($status,array('','FAILURE','FAILED','CANCELED','CANCELLED','EXPIRED'),true)) { return 'failure'; }
        return 'unsupported';
    }
    public function notification($methodId,array $payload,$raw,$signature)
    {
        $session=$payload['data']['session_id'] ?? null;
        if (!is_string($session)) { throw new RuntimeException('Invalid payment notification.'); }
        $orderId=$this->store->findSession($session,$methodId);
        return $this->operation($methodId,$orderId,function ($method,$order) use ($payload,$raw,$signature,$session) {
            $params=$order->order_payment_params;
            $environment=$params->teya_environment ?? '';
            if (!in_array($environment,array('live','test'),true) || ($params->teya_session_id ?? '') !== $session) { throw new RuntimeException('Payment session mismatch.'); }
            TeyaHikashopWebhookVerifier::verify($raw,$signature,($params->teya_checkout_public_key ?? TeyaHikashopCredentials::value($method->payment_params,'payment_webhook_public_key',$environment)));
            $outcome=self::outcome($payload);
            if ($outcome === 'unsupported') { return; }
            $data=$payload['data'];
            if ($outcome === 'success') {
                $expected=(array) ($params->teya_checkout_amount ?? array('currency'=>TeyaHikashopCheckoutBuilder::currencyCode($order),
                    'value'=>TeyaHikashopUtils::toMinorUnits($order->order_full_price,TeyaHikashopCheckoutBuilder::currencyCode($order))));
                if (!isset($data['amount']['value'],$data['amount']['currency']) || (string) $data['amount']['value'] !== (string) $expected['value']
                    || $data['amount']['currency'] !== $expected['currency']) { throw new RuntimeException('Payment amount or currency mismatch.'); }
            }
            $existing=self::outcome((array) ($params->teya_webhook_payload ?? array()));
            if ($existing === 'success' || ($existing === 'failure' && $outcome === 'failure')) { return; }
            $params->teya_webhook_payload=$payload;
            $params->teya_webhook_data=$data;
            if ($outcome === 'success') { $params->teya_transaction_id=$data['transaction_id']; }
            $status=$outcome === 'success' ? ($method->payment_params->verified_status ?? 'confirmed') : ($method->payment_params->invalid_status ?? 'cancelled');
            $this->store->saveOrder($order,$status,'Teya payment '.$outcome.'. Session: '.$session);
        });
    }
    public function beginConnection($methodId,$environment)
    {
        if (!in_array($environment,array('test','live'),true)) { throw new RuntimeException('Invalid environment.'); }
        return $this->store->locked('method_'.(int) $methodId,function () use ($methodId,$environment) {
            if ($this->store->pending($methodId)) { throw new RuntimeException('Resolve pending refunds before changing the connection.'); }
            $method=$this->store->method($methodId);
            $nonce=bin2hex(random_bytes(32));
            $intents=(array) ($method->payment_params->teya_onboarding ?? array());
            $intents[$environment]=array('hash'=>hash('sha256',$nonce),'expires'=>time()+1800);
            $method->payment_params->teya_onboarding=$intents;
            $this->store->saveMethod($method);
            return $nonce;
        });
    }
    public function registration($methodId,$environment,$nonce,$raw,$signature)
    {
        if (!in_array($environment,array('test','live'),true)) { throw new RuntimeException('Invalid environment.'); }
        $method=$this->store->method($methodId);
        $api=call_user_func($this->factory,$method->payment_params);
        TeyaHikashopWebhookVerifier::verify($raw,$signature,$api->fetchRegistrationPublicKey($environment));
        $payload=json_decode($raw,true);
        foreach (array('client_id','client_secret','payment_webhook_public_key') as $key) {
            if (!is_string($payload[$key] ?? null) || $payload[$key]==='') { throw new RuntimeException('Invalid registration payload.'); }
        }
        return $this->store->locked('method_'.(int) $methodId,function () use ($methodId,$environment,$nonce,$payload,$raw,$api) {
            $method=$this->store->method($methodId);
            $intents=(array) ($method->payment_params->teya_onboarding ?? array());
            $intent=$intents[$environment] ?? array();
            if (!isset($intent['hash']) || !hash_equals($intent['hash'],hash('sha256',$nonce))) { throw new RuntimeException('Unknown registration attempt.'); }
            if (isset($intent['completed'])) {
                if (hash_equals($intent['completed'],hash('sha256',$raw))) { return; }
                throw new RuntimeException('Registration attempt already used.');
            }
            if (($intent['expires'] ?? 0)<time() || $this->store->pending($methodId)) { throw new RuntimeException('Registration attempt expired or refunds pending.'); }
            $token=$api->requestAccessToken($payload['client_id'],$payload['client_secret'],$environment);
            if (!is_string($token['access_token'] ?? null) || $token['access_token']==='') { throw new RuntimeException('Invalid OAuth response.'); }
            $prefix=$environment==='test' ? 'test_' : '';
            foreach (array('client_id','client_secret','payment_webhook_public_key') as $key) { $method->payment_params->{$prefix.$key}=$payload[$key]; }
            $method->payment_params->{$prefix.'access_token'}=$token['access_token'];
            $method->payment_params->{$prefix.'access_token_expires_at'}=time()+max(0,(int) ($token['expires_in'] ?? 0)-60);
            $intents[$environment]['completed']=hash('sha256',$raw);
            $method->payment_params->teya_onboarding=$intents;
            $this->store->saveMethod($method);
        });
    }
    public function disconnect($methodId,$environment)
    {
        if (!in_array($environment,array('test','live'),true)) { throw new RuntimeException('Invalid environment.'); }
        return $this->store->locked('method_'.(int) $methodId,function () use ($methodId,$environment) {
            if ($this->store->pending($methodId)) { throw new RuntimeException('Resolve pending refunds before disconnecting Teya.'); }
            $method=$this->store->method($methodId);
            // Persist invalidation even if the remote delete subsequently fails.
            $intents=(array) ($method->payment_params->teya_onboarding ?? array()); unset($intents[$environment]);
            $method->payment_params->teya_onboarding=$intents; $this->store->saveMethod($method);
            if (TeyaHikashopCredentials::isConnected($method->payment_params,$environment)) {
                $api=call_user_func($this->factory,$method->payment_params);
                try { $api->deleteCurrentRegistration($this->token($method,$environment),$environment); }
                catch (TeyaHikashopApiException $e) {
                    if ($e->getStatusCode()===401) { $api->deleteCurrentRegistration($this->token($method,$environment,true),$environment); }
                    elseif ($e->getStatusCode()!==404) { throw $e; }
                }
            }
            $prefix=$environment==='test' ? 'test_' : '';
            foreach (array('client_id','client_secret','payment_webhook_public_key','access_token','access_token_expires_at') as $key) { $method->payment_params->{$prefix.$key}=''; }
            $this->store->saveMethod($method);
        });
    }
    public static function ledger($params)
    {
        if (isset($params->teya_refunds)) { return (array) $params->teya_refunds; }
        $state=array('refunds'=>array());
        $old=(array) ($params->teya_refund_data ?? array());
        if (($old['status'] ?? '') === 'success') { $state['refunds'][]=array('key'=>$old['key'],'amount'=>$old['amount']); }
        if (($old['status'] ?? '') === 'pending') { $state['pending']=$old; }
        return $state;
    }
    public static function balance($order)
    {
        $params=$order->order_payment_params;
        $payload=(array) ($params->teya_webhook_payload ?? array());
        if (self::outcome($payload) !== 'success' || ($params->teya_transaction_id ?? '') !== ($payload['data']['transaction_id'] ?? null)) { throw new RuntimeException('A verified successful payment is required.'); }
        $paid=$payload['data']['amount'];
        $state=self::ledger($params);
        return array('currency'=>$paid['currency'],'remaining'=>(int) $paid['value']-array_sum(array_column($state['refunds'],'amount')),'pending'=>!empty($state['pending']), 'blocked'=>!empty($state['pending']['blocked']), 'retry_at'=>(int) ($state['pending']['next_attempt'] ?? 0), 'message'=>!empty($state['pending']['blocked']) ? 'Teya refused authorization for this refund. Resolve the merchant/API permissions before retrying the same refund.' : (!empty($state['pending']) ? 'Refund outcome is not confirmed. Retry uses the existing refund reference.' : ''));
    }
    public function refund($methodId,$orderId,$amount,$requestKey,$retry=false,$resumeBlocked=false)
    {
        if (!is_string($requestKey) || !preg_match('/^[a-zA-Z0-9_-]{16,128}$/D',$requestKey)) { throw new RuntimeException('Invalid refund request reference.'); }
        return $this->operation($methodId,$orderId,function ($method,$order) use ($amount,$requestKey,$retry,$resumeBlocked) {
            $params=$order->order_payment_params;
            $balance=self::balance($order);
            $state=self::ledger($params);
            if (in_array($requestKey,array_column($state['refunds'],'key'),true)) { return $balance; }
            if (!empty($state['pending']) && !$retry && $state['pending']['key'] !== $requestKey) { throw new RuntimeException('A refund is already awaiting confirmation.'); }
            if (empty($state['pending'])) {
                if ($retry) { return $balance; }
                if (!is_string($amount) || !preg_match('/^[0-9]+(?:\.[0-9]{1,3})?$/D',$amount)) { throw new RuntimeException('Enter a positive refund amount.'); }
                $minor=TeyaHikashopUtils::toMinorUnits($amount,$balance['currency']);
                if ($minor <= 0 || $minor > $balance['remaining'] || abs((float) $amount*TeyaHikashopUtils::toMinorUnits(1,$balance['currency'])-$minor)>0.000001) { throw new RuntimeException('Refund exceeds the remaining balance or currency precision.'); }
                $state['pending']=array('key'=>$requestKey,'environment'=>$params->teya_environment,'tries'=>0,'next_attempt'=>0,
                    'payload'=>array('transaction_id'=>$params->teya_transaction_id,'amount'=>$minor,'source_reference_id'=>substr('HikaShopOrder'.$order->order_id,0,30),'reason'=>'HikaShop refund'));
            }
            $attempt=$state['pending'];
            if (!empty($attempt['blocked']) && !$resumeBlocked) { return $balance; }
            if (!empty($attempt['blocked'])) { unset($attempt['blocked']); $attempt['next_attempt']=0; }
            if (($attempt['next_attempt'] ?? 0)>time()) { return array_merge($balance,array('pending'=>true)); }
            $attempt['tries']=($attempt['tries'] ?? 0)+1;
            $attempt['next_attempt']=time()+min(3600,5*(2**min(10,$attempt['tries']-1)));
            $state['pending']=$attempt;
            $params->teya_refunds=$state;
            $this->store->saveOrder($order,null,'Teya refund awaiting confirmation. Reference: '.$attempt['key']);
            try { $response=$this->request($method,$attempt['environment'] ?? $params->teya_environment,'createRefund',$attempt['payload'],$attempt['key']); }
            catch (TeyaHikashopApiException $e) {
                $code=$e->getStatusCode();
                if (in_array($code,array(401,403),true)) {
                    $state['pending']['blocked']=true;
                    $state['pending']['next_attempt']=0;
                    $params->teya_refunds=$state;
                    $this->store->saveOrder($order,null,'Teya refund authorization refused (HTTP '.(int) $code.'). Automatic retries paused.');
                    return self::balance($order);
                }
                if ($code>=400 && $code<500 && !in_array($code,array(401,403,408,409,429),true) && ($e->getResponseBody()['code'] ?? '') !== 'DUPLICATE_REFUND') {
                    unset($state['pending']); $state['last_error']='Refund rejected by Teya.';
                    $params->teya_refunds=$state; $this->store->saveOrder($order,null,$state['last_error']); throw new RuntimeException('Teya rejected the refund request (HTTP '.(int) $code.').');
                }
                $delay=(int) ($e->getResponseBody()['_retry_after'] ?? 0);
                if ($delay>0) { $state['pending']['next_attempt']=max($attempt['next_attempt'],time()+$delay); $params->teya_refunds=$state; $this->store->saveOrder($order); }
                return array_merge($balance,array('pending'=>true));
            } catch (RuntimeException $e) { return array_merge($balance,array('pending'=>true)); }
            if (!empty($response['_retry_after'])) {
                $state['pending']['next_attempt']=max($attempt['next_attempt'],time()+(int) $response['_retry_after']); $params->teya_refunds=$state; $this->store->saveOrder($order);
            }
            if (($response['_http_status'] ?? 0) === 201 && ($response['status'] ?? '') === 'SUCCESS') {
                if (isset($response['refund_amount']) && (($response['refund_amount']['currency'] ?? '') !== $balance['currency']
                    || (string) ($response['refund_amount']['amount'] ?? '') !== (string) $attempt['payload']['amount'])) { throw new RuntimeException('Refund response mismatch; reconciliation required.'); }
                $state['refunds'][]=array('key'=>$attempt['key'],'amount'=>$attempt['payload']['amount'],'status'=>'SUCCESS','confirmed_at'=>gmdate('c'));
                unset($state['pending']); $params->teya_refunds=$state;
                $remaining=$balance['remaining']-$attempt['payload']['amount'];
                $this->store->saveOrder($order,$remaining===0 ? ($method->payment_params->refund_status ?? 'refunded') : null,'Teya refund confirmed. Reference: '.$attempt['key']);
            } elseif (($response['_http_status'] ?? 0) === 201 && ($response['status'] ?? '') === 'FAILURE') {
                unset($state['pending']); $params->teya_refunds=$state;
                $this->store->saveOrder($order,null,'Teya declined the refund. Reference: '.$attempt['key']);
                throw new RuntimeException('Teya declined the refund.');
            }
            return self::balance($order);
        });
    }
}
