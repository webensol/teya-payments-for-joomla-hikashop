<?php
/**
 * @package    Teya Payments for HikaShop
 * @author     Webensol
 * @license    GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
defined('_JEXEC') or defined('ABSPATH') or die('Restricted access');

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/src/TeyaHikashopUtils.php';
require_once __DIR__ . '/src/TeyaHikashopCredentials.php';
require_once __DIR__ . '/src/TeyaHikashopApiClient.php';
require_once __DIR__ . '/src/TeyaHikashopWebhookVerifier.php';
require_once __DIR__ . '/src/TeyaHikashopCheckoutBuilder.php';
require_once __DIR__ . '/src/TeyaHikashopOnboardingRenderer.php';
require_once __DIR__ . '/src/TeyaHikashopStorage.php';
require_once __DIR__ . '/src/TeyaHikashopGateway.php';

class plgHikashoppaymentTeya extends hikashopPaymentPlugin
{
    public $accepted_currencies = array('EUR', 'GBP', 'HUF', 'CZK', 'USD', 'ISK', 'PLN', 'DKK', 'NOK', 'SEK', 'CHF');
    public $multiple = true;
    public $name = 'teya';
    public $doc_form = 'teya';
    public $features = array('authorize_capture' => false, 'recurring' => false, 'refund' => true);
    public $pluginConfig = array();

    public function onPaymentConfiguration(&$element)
    {
        parent::onPaymentConfiguration($element);
        $this->loadAdminAssets();
        if (empty($element->payment_params)) {
            $element->payment_params = new stdClass();
        }
        $this->pluginConfig = $this->buildPluginConfig($element);
    }

    public function onPaymentConfigurationSave(&$element)
    {
        if (parent::onPaymentConfigurationSave($element) === false) { return false; }
        if (($element->payment_type ?? '') !== $this->name) { return true; }
        $stored = new stdClass();
        if (!empty($element->payment_id)) {
            $db = JFactory::getDBO();
            $db->setQuery('SELECT payment_params FROM ' . hikashop_table('payment')
                . ' WHERE payment_id = ' . (int) $element->payment_id . ' AND payment_type = ' . $db->Quote($this->name));
            $raw = $db->loadResult();
            if (is_string($raw) && $raw !== '') {
                $stored = TeyaHikashopStorage::params($raw);
                if (!$stored instanceof stdClass) { throw new RuntimeException('Invalid stored Teya configuration.'); }
            }
        }
        if (empty($element->payment_params)) { $element->payment_params = new stdClass(); }
        // Browser fields must never replace OAuth credentials or callback keys.
        foreach (TeyaHikashopCredentials::protectedKeys() as $key) {
            unset($element->payment_params->$key);
            if (isset($stored->$key)) { $element->payment_params->$key = $stored->$key; }
        }
        $element->payment_params = TeyaHikashopCredentials::protect($element->payment_params);
        return true;
    }

    private function buildPluginConfig($element)
    {
        $config = array(
            'sandbox' => array('PLG_HIKASHOPPAYMENT_TEYA_SANDBOX', 'boolean', '0'),
            'merchant_success_email' => array('PLG_HIKASHOPPAYMENT_TEYA_MERCHANT_SUCCESS_EMAIL', 'input'),
            'post_success_payment' => array('PLG_HIKASHOPPAYMENT_TEYA_POST_SUCCESS_PAYMENT', 'list', array('redirect' => 'PLG_HIKASHOPPAYMENT_TEYA_REDIRECT', 'show_success_page' => 'PLG_HIKASHOPPAYMENT_TEYA_SHOW_SUCCESS_PAGE')),
            'total_line_item' => array('PLG_HIKASHOPPAYMENT_TEYA_TOTAL_LINE_ITEM', 'boolean', '0'),
            'debug' => array('DEBUG', 'boolean', '0'),
            'teya_connection' => array('PLG_HIKASHOPPAYMENT_TEYA_CONNECTION', 'html', $this->renderOnboardingContent($element)),
            'invalid_status' => array('INVALID_STATUS', 'orderstatus'),
            'refund_status' => array('PLG_HIKASHOPPAYMENT_TEYA_REFUND_STATUS', 'orderstatus'),
            'hikashop_merchant_email' => array('PLG_HIKASHOPPAYMENT_TEYA_HIKASHOP_MERCHANT_EMAIL', 'boolean', '0'),
            'customer_success_email' => array('PLG_HIKASHOPPAYMENT_TEYA_CUSTOMER_SUCCESS_EMAIL', 'boolean', '0'),
            'verified_status' => array('VERIFIED_STATUS', 'orderstatus'),
        );

        return $config;
    }

    public function getPaymentDefaultValues(&$element)
    {
        $element->payment_name = 'Teya';
        $element->payment_description = TeyaHikashopLanguage::text('DEFAULT_DESCRIPTION');
        $element->payment_images = 'MasterCard,VISA,American_Express';
        $element->payment_params->sandbox = 1;
        $element->payment_params->post_success_payment = 'redirect';
        $element->payment_params->total_line_item = 0;
        $element->payment_params->debug = 0;
        $element->payment_params->customer_success_email = 0;
        $element->payment_params->hikashop_merchant_email = 0;
        $element->payment_params->invalid_status = 'cancelled';
        $element->payment_params->refund_status = 'refunded';
        $element->payment_params->verified_status = 'confirmed';
    }

    public function onPaymentDisplay(&$order,&$methods,&$usable_methods)
    {
        foreach ($methods as $method) {
            if (($method->payment_type ?? '')===$this->name) {
                $params=TeyaHikashopStorage::params($method->payment_params ?? null);
                if (!TeyaHikashopCredentials::isConnected($params,TeyaHikashopUtils::environmentFromParams($params))) { $method->enabled=false; }
            }
        }
        return parent::onPaymentDisplay($order,$methods,$usable_methods);
    }

    public function onAfterOrderConfirm(&$order, &$methods, $method_id)
    {
        parent::onAfterOrderConfirm($order,$methods,$method_id);
        try {
            $payload=TeyaHikashopCheckoutBuilder::build($order,$this->payment_params,$this->buildCheckoutUrls($order,$method_id));
            $response=$this->gateway()->checkout((int) $method_id,(int) $order->order_id,$payload);
            $this->app->redirect($response['session_url']);
            return true;
        } catch (Throwable $e) {
            $this->writeLog('Teya checkout failed: '.get_class($e).'; code='.(int)$e->getCode());
            $this->app->enqueueMessage(TeyaHikashopLanguage::text('CHECKOUT_ERROR'),'error');
            return false;
        }
    }
    protected function gateway()
    {
        return new TeyaHikashopGateway(new TeyaHikashopStorage(),function ($params) { return $this->apiClient($params); });
    }


    public function onPaymentNotification(&$statuses)
    {
        $input=hikaInput::get();
        $methodId=$input->getInt('notif_id');
        try {
            $raw=file_get_contents('php://input');
            $signature=$this->requestHeader('X-Teya-Signature');
            $action=$input->getCmd('teya_action','payment');
            if ($action==='registration') {
                $this->gateway()->registration($methodId,$input->getCmd('environment'),$input->getString('nonce'),$raw,$signature);
            } elseif ($action==='payment') {
                $payload=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
                if (!is_array($payload)) { throw new RuntimeException('Invalid notification'); }
                $this->gateway()->notification($methodId,$payload,$raw,$signature);
            } else { $this->respond(403,'Administrator endpoint required'); }
            $this->respond(200,'OK');
        } catch (Throwable $e) {
            $this->writeLog('Teya callback rejected: '.get_class($e).'; code='.(int)$e->getCode());
            $this->respond(400,'Notification could not be processed');
        }
    }


    public function onOrderPaymentRefund(&$order, $total)
    {
        // Use the authenticated Teya refund panel, which supplies a durable request reference.
        return false;
    }


    private function loadAdminAssets()
    {
        $assetBase = rtrim(JUri::root(true), '/') . '/plugins/hikashoppayment/teya/assets/';
        // Package timestamps are fixed, so invalidate cached assets by their contents.
        $assetVersion = '?v=' . substr(hash('sha256',
            hash_file('sha256', __DIR__ . '/assets/teya-admin.js') .
            hash_file('sha256', __DIR__ . '/assets/teya-admin.css')
        ), 0, 16);
        $document = JFactory::getDocument();
        if (method_exists($document, 'addStyleSheet')) {
            $document->addStyleSheet($assetBase . 'teya-admin.css' . $assetVersion);
        }
        if (method_exists($document, 'addScript')) {
            $document->addScript($assetBase . 'teya-admin.js' . $assetVersion);
        }
    }

    private function buildCheckoutUrls($order, $methodId)
    {
        $itemid = empty($this->url_itemid) ? '' : $this->url_itemid;
        $locale = strtolower(substr(JFactory::getLanguage()->get('tag'), 0, 2));
        return array(
            'success_url' => HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=after_end&order_id=' . (int) $order->order_id . $itemid,
            'failure_url' => HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=after_end&order_id=' . (int) $order->order_id . $itemid,
            'cancel_url' => HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=after_end&order_id=' . (int) $order->order_id . $itemid,
            'notification_url' => HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment=teya&notif_id=' . (int) $methodId . '&teya_action=payment&order_id=' . (int) $order->order_id . '&lang=' . $locale . $itemid,
        );
    }

    private function disconnectUrl($methodId,$environment)
    {
        return rtrim(JUri::root(),'/').'/administrator/index.php?option=com_ajax&group=hikashop&plugin=teya&format=raw&teya_action=disconnect&notif_id='.(int) $methodId.'&environment='.TeyaHikashopUtils::normalizeEnvironment($environment);
    }


    private function statusUrl($methodId,$environment)
    {
        return rtrim(JUri::root(),'/').'/administrator/index.php?option=com_ajax&group=hikashop&plugin=teya&format=raw&teya_action=status&notif_id='.(int) $methodId.'&environment='.TeyaHikashopUtils::normalizeEnvironment($environment);
    }


    private function registrationUrl($methodId,$environment)
    {
        return rtrim(JUri::root(),'/').'/administrator/index.php?option=com_ajax&group=hikashop&plugin=teya&format=raw&teya_action=connect&notif_id='.(int) $methodId.'&environment='.TeyaHikashopUtils::normalizeEnvironment($environment);
    }


    private function apiClient($params)
    {
        return new TeyaHikashopApiClient(!empty($params->debug), array($this, 'writeLog'));
    }

    public function writeLog($message)
    {
        if (!empty($this->payment_params->debug) || !empty($this->plugin_params->debug)) {
            if (method_exists($this, 'writeToLog')) {
                $this->writeToLog($message);
            } else {
                error_log('[Teya HikaShop] ' . $message);
            }
        }
    }

    private function requestHeader($name)
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$serverKey]) ? (string) $_SERVER[$serverKey] : '';
    }

    private function respond($status, $message)
    {
        http_response_code((int) $status);
        echo $message;
        exit;
    }

    private function renderOnboardingContent($element)
    {
        $renderer = new TeyaHikashopOnboardingRenderer();
        return $renderer->render($element, function ($id, $environment) {
            return $this->registrationUrl($id, $environment);
        }, function ($id, $environment) {
            return $this->disconnectUrl($id, $environment);
        }, function ($id, $environment) {
            return $this->statusUrl($id, $environment);
        });
    }
}
