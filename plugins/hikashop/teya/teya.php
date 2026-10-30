<?php
/**
 * @package    Teya Payments for HikaShop
 * @license    GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
defined('_JEXEC') or die('Restricted access');

if (!is_file(JPATH_SITE . '/plugins/hikashoppayment/teya/src/TeyaHikashopGateway.php')) { return; }
foreach (array('JPlugin'=>'\Joomla\CMS\Plugin\CMSPlugin','JFactory'=>'\Joomla\CMS\Factory','JUri'=>'\Joomla\CMS\Uri\Uri') as $legacy=>$modern) {
    if (!class_exists($legacy) && class_exists($modern)) { class_alias($modern,$legacy); }
}
require_once JPATH_SITE . '/plugins/hikashoppayment/teya/src/TeyaHikashopUtils.php';
require_once JPATH_SITE . '/plugins/hikashoppayment/teya/src/TeyaHikashopCredentials.php';
require_once JPATH_SITE . '/plugins/hikashoppayment/teya/src/TeyaHikashopApiClient.php';

foreach (array('Storage','Gateway','WebhookVerifier','CheckoutBuilder') as $part) {
    require_once JPATH_SITE . '/plugins/hikashoppayment/teya/src/TeyaHikashop'.$part.'.php';
}

class plgHikashopTeya extends JPlugin
{
    private $saveLocks=array();
    private function gateway()
    {
        return new TeyaHikashopGateway(new TeyaHikashopStorage(),function ($params) { return $this->apiClient($params); });
    }
    public static function canManage()
    {
        $app=JFactory::getApplication();
        $user=$app->getIdentity();
        return $app->isClient('administrator') && $user && !$user->guest
            && ($user->authorise('core.admin','com_hikashop') || ($user->authorise('core.manage','com_hikashop') && $user->authorise('core.edit','com_hikashop')));
    }
    public function onAjaxTeya()
    {
        $app=JFactory::getApplication();
        try {
            if (!self::canManage()) { throw new RuntimeException('Administrator permission required.',403); }
            if (!function_exists('hikashop_get')) { require_once JPATH_ADMINISTRATOR.'/components/com_hikashop/helpers/helper.php'; }
            $input=$app->input; $action=$input->getCmd('teya_action');
            if ($action!=='status' && (strtoupper($_SERVER['REQUEST_METHOD'] ?? '')!=='POST' || !\Joomla\CMS\Session\Session::checkToken('post'))) {
                throw new RuntimeException('Invalid session token.',403);
            }
            $id=$input->getInt('notif_id'); $env=$input->getCmd('environment','test');
            if (!in_array($env,array('live','test'),true)) { throw new RuntimeException('Invalid environment.'); }
            $store=new TeyaHikashopStorage(); $method=$store->method($id);
            if ($action==='status') {
                $intent=((array) ($method->payment_params->teya_onboarding ?? array()))[$env] ?? array();
                $result=array('environment'=>$env,'active_environment'=>TeyaHikashopUtils::environmentFromParams($method->payment_params),
                    'connected'=>TeyaHikashopCredentials::isConnected($method->payment_params,$env),
                    'csrf_token'=>\Joomla\CMS\Session\Session::getFormToken(), 'completed_attempt'=>isset($intent['completed']) ? $intent['hash'] : null);
            } elseif ($action==='connect') {
                $nonce=$this->gateway()->beginConnection($id,$env);
                $base=rtrim(JUri::root(),'/');
                $notify=$base.'/index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment=teya&notif_id='.$id;
                $url=TeyaHikashopUtils::businessRegistrationUrl($env).'?'.http_build_query(array(
                    'registrationWebhook'=>$notify.'&teya_action=registration&environment='.$env.'&nonce='.$nonce,
                    'paymentsWebhook'=>$notify.'&teya_action=payment','pluginType'=>'HIKASHOP_WEBENSOL','pluginVersion'=>TeyaHikashopUtils::VERSION,
                    'returnUrl'=>$base.'/administrator/index.php?option=com_hikashop&ctrl=plugins&task=edit&name=teya&plugin_type=payment&payment_id='.$id));
                $result=array('url'=>$url,'attempt'=>hash('sha256',$nonce));
            } elseif ($action==='disconnect') {
                $this->gateway()->disconnect($id,$env); $result=array('environment'=>$env,'connected'=>false);
            } elseif ($action==='refund' || $action==='refund_retry') {
                $result=$this->gateway()->refund($id,$input->getInt('order_id'),$input->getString('amount'),$input->getString('request_key'),$action==='refund_retry',$action==='refund_retry');
            } else { throw new RuntimeException('Unknown Teya action.'); }
            $code=200;
        } catch (Throwable $e) {
            $code=(!($e instanceof TeyaHikashopApiException) && $e->getCode()===403) ? 403 : 400;
            $this->writeLog('Teya administrator operation failed: '.get_class($e).'; code='.(int)$e->getCode());
            $result=array('error'=>$e instanceof TeyaHikashopApiException ? TeyaHikashopLanguage::text('API_ERROR', $e->getStatusCode()) : TeyaHikashopLanguage::text($code===403 ? 'ADMIN_AUTH_ERROR' : 'ADMIN_ERROR'));
        }
        http_response_code($code); header('Content-Type: application/json'); header('Cache-Control: no-store');
        echo json_encode($result,JSON_THROW_ON_ERROR); $app->close();
    }
    public function onAfterOrderProductsListingDisplay(&$order, $location)
    {
        if (!in_array($location, array('order_back_show', 'order_back_form'), true) || !self::canManage()
            || ($order->order_payment_method ?? '') !== 'teya') { return; }
        try {
            $fresh = (new TeyaHikashopStorage())->order($order->order_id);
            $balance = TeyaHikashopGateway::balance($fresh);
            $state = TeyaHikashopGateway::ledger($fresh->order_payment_params);
            $amount = $balance['remaining'] / TeyaHikashopUtils::toMinorUnits(1, $balance['currency']);
            $key = $state['pending']['key'] ?? bin2hex(random_bytes(16));
            $url = rtrim(JUri::root(), '/') . '/administrator/index.php?option=com_ajax&group=hikashop&plugin=teya&format=raw&notif_id=' . (int) $fresh->order_payment_id;
            $escape = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
            JFactory::getApplication()->getDocument()->addScript(rtrim(JUri::root(), '/') . '/plugins/hikashoppayment/teya/assets/teya-refund.js?v=' . hash_file('sha256', JPATH_SITE . '/plugins/hikashoppayment/teya/assets/teya-refund.js'));
            $messages = TeyaHikashopLanguage::messages(array('REFUND_CONFIRM', 'REFUND_CONFIRM_RETRY', 'REFUND_ERROR', 'REFUND_PENDING', 'REFUND_CONFIRMED', 'REFUND_REFRESH'));
            echo '<div class="teya-refund-panel card card-body" data-messages="' . $messages . '" data-url="' . $escape($url) . '" data-token="' . $escape(\Joomla\CMS\Session\Session::getFormToken()) . '" data-order="' . (int) $fresh->order_id . '" data-key="' . $escape($key) . '">';
            echo '<h3>' . $escape(TeyaHikashopLanguage::text('REFUND_TITLE')) . '</h3><p>' . $escape(TeyaHikashopLanguage::text('REFUND_REMAINING', $amount, $balance['currency'])) . '</p>';
            if ($balance['pending']) {
                echo '<p>' . $escape($balance['message']) . '</p>';
                $retryAt = $balance['retry_at'] > time() ? gmdate('Y-m-d H:i:s', $balance['retry_at']) . ' UTC' : TeyaHikashopLanguage::text('REFUND_AVAILABLE_NOW');
                echo '<p>' . $escape(TeyaHikashopLanguage::text($balance['blocked'] ? 'REFUND_PAUSED' : 'REFUND_NEXT_RETRY', $retryAt)) . '</p>';
                echo '<button type="button" class="btn btn-secondary" data-action="refund_retry">' . $escape(TeyaHikashopLanguage::text('REFUND_RETRY')) . '</button>';
            } elseif ($balance['remaining'] > 0) {
                echo '<label>' . $escape(TeyaHikashopLanguage::text('REFUND_AMOUNT')) . ' <input class="form-control teya-refund-amount" inputmode="decimal" value="' . $escape($amount) . '"></label><button type="button" class="btn btn-primary" data-action="refund">' . $escape(TeyaHikashopLanguage::text('REFUND_SEND')) . '</button>';
            }
            echo '<p role="status" class="teya-refund-result"></p></div>';
        } catch (Throwable $exception) { /* No executable refund controls without verified payment evidence. */ }
    }
    public function onBeforeHikaPluginCreate($type,&$element,&$do)
    {
        if ($type!=='payment' || ($element->payment_type ?? '')!=='teya' || !$do) { return; }
        $params=TeyaHikashopStorage::params($element->payment_params ?? null);
        foreach (TeyaHikashopCredentials::protectedKeys() as $key) { unset($params->$key); }
        $element->payment_params=$params;
    }
    public function onBeforeHikaPluginUpdate($type,&$element,&$do)
    {
        if ($type!=='payment' || !$do || !isset($element->payment_params)) { return; }
        $id=(int) $element->payment_id; $db=JFactory::getDBO();
        $db->setQuery('SELECT payment_type FROM #__hikashop_payment WHERE payment_id='.$id);
        if ($db->loadResult()!=='teya') { return; }
        try {
            $lock='teya_hika_method_'.$id;
            $db->setQuery('SELECT GET_LOCK('.$db->quote($lock).', 5)');
            if ((int) $db->loadResult()!==1) { throw new RuntimeException('Teya connection is being updated.'); }
            $this->saveLocks[$id]=$lock;
            register_shutdown_function(function () use ($id) { $this->releaseSaveLock($id); });
            $stored=(new TeyaHikashopStorage())->method($id)->payment_params;
            $params=TeyaHikashopStorage::params($element->payment_params);
            foreach (TeyaHikashopCredentials::protectedKeys() as $key) {
                unset($params->$key); if (isset($stored->$key)) { $params->$key=$stored->$key; }
            }
            $element->payment_params=TeyaHikashopCredentials::protect($params);
        } catch (Throwable $e) { $do=false; $this->releaseSaveLock($id); JFactory::getApplication()->enqueueMessage(TeyaHikashopLanguage::text('SAVE_ERROR'),'error'); }
    }
    private function releaseSaveLock($id)
    {
        if (!isset($this->saveLocks[$id])) { return; }
        $db=JFactory::getDBO(); $db->setQuery('SELECT RELEASE_LOCK('.$db->quote($this->saveLocks[$id]).')'); $db->loadResult(); unset($this->saveLocks[$id]);
    }
    public function onAfterHikaPluginUpdate($type,&$element) { if ($type==='payment') { $this->releaseSaveLock((int) $element->payment_id); } }
    public function onBeforeHikaPluginDelete($type, &$elements, &$do)
    {
        if ($type !== 'payment' || !$do) {
            return;
        }
        $ids = is_array($elements) ? $elements : array($elements);
        foreach ($ids as $id) {
            $method = $this->loadTeyaPaymentMethod((int) $id);
            if (empty($method)) {
                continue;
            }
            try {
                $db=JFactory::getDBO(); $lock='teya_hika_method_'.(int) $id;
                $db->setQuery('SELECT GET_LOCK('.$db->quote($lock).', 5)');
                if ((int) $db->loadResult()!==1) { throw new RuntimeException('Teya connection is being updated.'); }
                $this->saveLocks[(int) $id]=$lock;
                register_shutdown_function(function () use ($id) { $this->releaseSaveLock((int) $id); });
                foreach (array('test','live') as $environment) { $this->gateway()->disconnect((int) $id,$environment); }
            } catch (Exception $exception) {
                $do = false;
                $message = TeyaHikashopLanguage::text('DELETE_ERROR');
                $this->writeLog($message);
                $app = JFactory::getApplication();
                if (method_exists($app, 'enqueueMessage')) {
                    $app->enqueueMessage($message, 'error');
                }
                return;
            }
        }
    }

    public function onAfterHikaPluginDelete($type,&$ids)
    {
        if ($type==='payment') { foreach ((array) $ids as $id) { $this->releaseSaveLock((int) $id); } }
    }

    public function onExtensionBeforeUninstall($extensionId)
    {
        $db=JFactory::getDBO();
        $db->setQuery('SELECT type,folder,element FROM #__extensions WHERE extension_id='.(int)$extensionId);
        $extension=$db->loadObject();
        if (!$extension || !(($extension->type==='package' && $extension->element==='pkg_teya_hikashop')
            || ($extension->type==='plugin' && $extension->element==='teya' && in_array($extension->folder,array('hikashop','hikashoppayment'),true)))) { return; }
        if (!in_array($db->getPrefix().'hikashop_payment',$db->getTableList(),true)) { return; }
        $store=new TeyaHikashopStorage($db);
        if ($store->pending()) { throw new RuntimeException(TeyaHikashopLanguage::text('UNINSTALL_PENDING')); }
        $db->setQuery('SELECT payment_params FROM #__hikashop_payment WHERE payment_type='.$db->quote('teya'));
        foreach ($db->loadColumn() as $raw) {
            $params=TeyaHikashopStorage::params($raw);
            foreach (array('test','live') as $environment) {
                if (TeyaHikashopCredentials::isConnected($params,$environment)) {
                    throw new RuntimeException(TeyaHikashopLanguage::text('UNINSTALL_CONNECTED'));
                }
            }
        }
    }

    private function loadTeyaPaymentMethod($methodId)
    {
        if ($methodId <= 0 || !function_exists('hikashop_table')) {
            return null;
        }
        $db = JFactory::getDBO();
        $query = 'SELECT * FROM ' . hikashop_table('payment') . ' WHERE payment_id = ' . (int) $methodId . ' AND payment_type = ' . $db->Quote('teya');
        $db->setQuery($query, 0, 1);
        $method = $db->loadObject();
        if (empty($method)) {
            return null;
        }
        $method->payment_params = TeyaHikashopStorage::params($method->payment_params);
        if (!is_object($method->payment_params)) {
            $method->payment_params = new stdClass();
        }
        return $method;
    }

    private function apiClient($params)
    {
        return new TeyaHikashopApiClient(!empty($params->debug), array($this, 'writeLog'));
    }

    public function writeLog($message)
    {
        if (function_exists('error_log')) {
            error_log('[Teya HikaShop Delete Sync] ' . $message);
        }
    }
}
