<?php
namespace Joomla\CMS { class Factory { static function getConfig() { return new class { function get($key) { return 'connection-test-site-secret-123456789'; } }; } } }
namespace {
define('_JEXEC',1);
$source=dirname(__DIR__).'/plugins/hikashoppayment/teya/src/';
foreach (array('Utils','Credentials','ApiClient','WebhookVerifier','Storage','Gateway') as $part) require $source.'TeyaHikashop'.$part.'.php';
$checks=0;
function check($ok,$message) { global $checks; ++$checks; if (!$ok) throw new RuntimeException($message); }
class ConnectionDatabase {
 public $rows=array(); public $query; public $fail=false; public $affected=0; public $locks=array();
 function quote($value) { return "'".base64_encode($value)."'"; }
 function setQuery($sql) { $this->query=$sql; return $this; }
 function loadObject() { preg_match('/payment_id = ([0-9]+)/',$this->query,$match); $row=$this->rows[(int) $match[1]] ?? null; return $row ? clone $row : null; }
 function loadColumn() { return array(); }
 function loadResult() {
  preg_match("/LOCK\('([^']+)'/",$this->query,$match); $name=$match[1];
  if (strpos($this->query,'GET_LOCK')!==false) { if (isset($this->locks[$name])) return 0; $this->locks[$name]=true; return 1; }
  unset($this->locks[$name]); return 1;
 }
 function execute() {
  if ($this->fail) throw new RuntimeException('Simulated database save failure');
  preg_match("/SET payment_params = '([^']+)' WHERE payment_id = ([0-9]+) AND payment_params <=> '([^']*)'/",$this->query,$match);
  if (!$match) throw new RuntimeException('Unexpected test query');
  $row=$this->rows[(int)$match[2]]; $this->affected=0;
  if ($row->payment_params===base64_decode($match[3])) { $row->payment_params=base64_decode($match[1]); $this->affected=1; }
  return true;
 }
 function getAffectedRows() { return $this->affected; }
}
class ConnectionApi {
 public $public; public $tokens=0; public $deleted=array();
 function fetchRegistrationPublicKey($environment) { return $this->public; }
 function requestAccessToken($id,$secret,$environment) { ++$this->tokens; return array('access_token'=>'fixture-'.$environment.'-token','expires_in'=>3600); }
 function deleteCurrentRegistration($token,$environment) { $this->deleted[]=array($environment,$token); }
}
$key=openssl_pkey_new(array('private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA));
$public=preg_replace('/-----[^-]+-----|\s+/','',openssl_pkey_get_details($key)['key']);
$db=new ConnectionDatabase();
$live=TeyaHikashopCredentials::protect((object) array('sandbox'=>0,'debug'=>1,'client_id'=>'fixture-live-id','client_secret'=>'fixture-live-secret','access_token'=>'fixture-live-token','access_token_expires_at'=>time()+3600,'payment_webhook_public_key'=>$public));
$db->rows[7]=(object) array('payment_id'=>7,'payment_params'=>serialize($live));
$db->rows[8]=(object) array('payment_id'=>8,'payment_params'=>serialize((object)array('sandbox'=>1)));
$unrelated=$db->rows[8]->payment_params; $store=new TeyaHikashopStorage($db); $api=new ConnectionApi(); $api->public=$public;
$gateway=new TeyaHikashopGateway($store,fn($params)=>$api);
$nonce=$gateway->beginConnection(7,'test');
$raw=json_encode(array('client_id'=>'fixture-test-id','client_secret'=>'fixture-test-secret','payment_webhook_public_key'=>$public));
openssl_sign($raw,$signature,$key,OPENSSL_ALGO_SHA256);
$gateway->registration(7,'test',$nonce,$raw,base64_encode($signature));
$saved=$store->method(7)->payment_params;
check(TeyaHikashopCredentials::isConnected($saved,'test'),'fresh DB read reports test connected');
check(TeyaHikashopCredentials::isConnected($saved,'live'),'live remains connected');
check(TeyaHikashopCredentials::value($saved,'client_secret','test')==='fixture-test-secret','test secret decrypts from stored row');
check(TeyaHikashopCredentials::value($saved,'client_secret','live')==='fixture-live-secret','live secret unchanged');
check($saved->client_secret===$live->client_secret && $saved->access_token===$live->access_token,'test callback preserves live ciphertext');
check($saved->sandbox===0 && $saved->debug===1,'callback does not change shop configuration');
check($db->rows[8]->payment_params===$unrelated,'another payment method is untouched');
foreach (array('fixture-test-secret','fixture-live-secret','fixture-test-token','fixture-live-token') as $secret) check(strpos($db->rows[7]->payment_params,$secret)===false,'secret/token absent from serialized database row');
check(isset($saved->teya_onboarding['test']['completed']),'completion saved with credentials');
$before=$api->tokens; $gateway->registration(7,'test',$nonce,$raw,base64_encode($signature)); check($before===$api->tokens,'duplicate callback does not repeat OAuth');
$nonce=$gateway->beginConnection(7,'test'); $before=$db->rows[7]->payment_params; $db->fail=true;
try { $gateway->registration(7,'test',$nonce,$raw,base64_encode($signature)); throw new LogicException('failure ignored'); } catch (RuntimeException $expected) { ++$checks; }
$db->fail=false;
check($db->rows[7]->payment_params===$before,'failed save cannot publish completion');
check(!isset($store->method(7)->payment_params->teya_onboarding['test']['completed']),'polling cannot complete a failed save');
$gateway->registration(7,'test',$nonce,$raw,base64_encode($signature));
$gateway->disconnect(7,'test'); $saved=$store->method(7)->payment_params;
check(!TeyaHikashopCredentials::isConnected($saved,'test') && TeyaHikashopCredentials::isConnected($saved,'live'),'disconnect only clears selected environment');
check($api->deleted===array(array('test','fixture-test-token')),'disconnect uses decrypted test token');
check($saved->client_secret===$live->client_secret,'disconnect does not replace live secret');
check($db->locks===array(),'all connection locks released');
echo 'PASS: '.$checks.' registration persistence/environment/encryption checks'.PHP_EOL;
}
