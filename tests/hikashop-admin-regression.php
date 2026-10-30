<?php
namespace Joomla\CMS\Session { class Session { public static $valid=false; static function checkToken($method) { return self::$valid; } static function getFormToken() { return 'offline_csrf'; } } }
namespace {
define('_JEXEC',1); define('JPATH_SITE',dirname(__DIR__));
class JPlugin {}
class Finished extends \RuntimeException {}
class User { public $guest=true; public $allowed=false; function authorise($action,$asset) { return $this->allowed; } }
class Input { public $action='status'; function getCmd($key,$default='') { return $key==='teya_action' ? $this->action : 'test'; } function getInt($key) { return 1; } }
class App { public $admin=false; public $user; public $input; function __construct() { $this->user=new User(); $this->input=new Input(); } function isClient($name) { return $this->admin; } function getIdentity() { return $this->user; } function close() { throw new Finished(); } }
class DB { public $queries=array(); function setQuery($q) { $this->queries[]=$q; } function quote($v) { return "'".$v."'"; } function loadObject() { return (object) array('payment_id'=>1,'payment_params'=>serialize((object) array('sandbox'=>1))); } }
class JFactory { static $app; static $db; static function getApplication() { return self::$app; } static function getDBO() { return self::$db; } }
function hikashop_get($name) {}
require JPATH_SITE.'/plugins/hikashop/teya/teya.php';
JFactory::$app=new App(); JFactory::$db=new DB();
$plugin=new plgHikashopTeya(); $checks=0;
function runRequest($plugin) { ob_start(); try { $plugin->onAjaxTeya(); } catch (Finished $e) {} return json_decode(ob_get_clean(),true); }
function check($ok) { global $checks; ++$checks; if (!$ok) { throw new \RuntimeException('Admin protection assertion failed'); } }
check(isset(runRequest($plugin)['error']));
JFactory::$app->admin=true;
check(isset(runRequest($plugin)['error']));
JFactory::$app->user->guest=false;
check(isset(runRequest($plugin)['error']));
JFactory::$app->user->allowed=true;
$result=runRequest($plugin); check($result['connected']===false && $result['csrf_token']==='offline_csrf');
check(count(array_filter(JFactory::$db->queries,fn($q)=>strpos($q,'UPDATE')!==false || strpos($q,'DELETE')!==false))===0);
JFactory::$app->input->action='disconnect'; $_SERVER['REQUEST_METHOD']='GET'; \Joomla\CMS\Session\Session::$valid=true;
check(isset(runRequest($plugin)['error']));
$_SERVER['REQUEST_METHOD']='POST'; \Joomla\CMS\Session\Session::$valid=false;
check(isset(runRequest($plugin)['error']));
echo 'PASS: '.$checks.' administrator ACL/CSRF/read-only status checks'.PHP_EOL;
}
