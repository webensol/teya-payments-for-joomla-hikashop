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
        $this->loadTeyaLanguage();
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
            'refund_status' => array('Refunded order status', 'orderstatus'),
            'customer_success_email' => array('Send customer payment email', 'boolean', '0'),
            'verified_status' => array('VERIFIED_STATUS', 'orderstatus'),
        );

        return $config;
    }

    private function credentialKeys()
    {
        return TeyaHikashopCredentials::keys();
    }

    private function loadTeyaLanguage()
    {
        $language = JFactory::getLanguage();
        $language->load('plg_hikashoppayment_teya', __DIR__, null, true, true);
        $language->load('plg_hikashoppayment_teya', __DIR__ . '/language', null, true, true);
        $language->load('plg_hikashoppayment_teya', JPATH_SITE, null, true, true);
        $language->load('plg_hikashoppayment_teya', JPATH_ADMINISTRATOR, null, true, true);
    }

    public function getPaymentDefaultValues(&$element)
    {
        $this->loadTeyaLanguage();
        $element->payment_name = 'Teya';
        $element->payment_description = JText::_('PLG_HIKASHOPPAYMENT_TEYA_DEFAULT_DESCRIPTION');
        $element->payment_images = 'MasterCard,VISA,American_Express';
        $element->payment_params->sandbox = 1;
        $element->payment_params->post_success_payment = 'redirect';
        $element->payment_params->total_line_item = 0;
        $element->payment_params->debug = 0;
        $element->payment_params->customer_success_email = 0;
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
        $this->loadTeyaLanguage();
        try {
            $payload=TeyaHikashopCheckoutBuilder::build($order,$this->payment_params,$this->buildCheckoutUrls($order,$method_id));
            $response=$this->gateway()->checkout((int) $method_id,(int) $order->order_id,$payload);
            $this->app->redirect($response['session_url']);
            return true;
        } catch (Throwable $e) {
            $this->writeLog('Teya checkout failed: '.get_class($e).'; code='.(int)$e->getCode());
            $this->app->enqueueMessage('Unable to start payment. Please retry; contact the shop if the problem persists.','error');
            return false;
        }
    }
    protected function gateway()
    {
        return new TeyaHikashopGateway(new TeyaHikashopStorage(),function ($params) { return $this->apiClient($params); });
    }


    public function onBeforeHikaPluginDelete($type, &$elements, &$do)
    {
        // The hikashop companion owns deletion even when the payment group is not loaded.
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

    private function handleDisconnect($methodId, $environment)
    {
        $environment = TeyaHikashopUtils::normalizeEnvironment($environment);
        $params = clone $this->payment_params;
        if (!$this->isConnected($params, $environment)) {
            return;
        }
        $this->disconnectRegistration($methodId, $params, $environment);
        $this->savePaymentParams($methodId, $params, false);
    }

    private function disconnectPaymentMethodRegistrations($method)
    {
        $params = $method->payment_params;
        $changed = false;
        foreach (array(TeyaHikashopUtils::ENV_TEST, TeyaHikashopUtils::ENV_LIVE) as $environment) {
            if (!$this->isConnected($params, $environment)) {
                continue;
            }
            $this->disconnectRegistration((int) $method->payment_id, $params, $environment);
            $changed = true;
        }
        if ($changed) {
            $this->savePaymentParams((int) $method->payment_id, $params, false);
        }
    }

    private function disconnectRegistration($methodId, &$params, $environment)
    {
        $environment = TeyaHikashopUtils::normalizeEnvironment($environment);
        $this->apiClient($params)->deleteCurrentRegistration($this->getAccessToken($methodId, $params, $environment), $environment);
        $prefix = $environment === TeyaHikashopUtils::ENV_TEST ? 'test_' : '';
        foreach (array('client_id', 'client_secret', 'payment_webhook_public_key', 'access_token', 'access_token_expires_at') as $key) {
            $params->{$prefix . $key} = '';
        }
    }

    private function loadTeyaPaymentMethod($methodId)
    {
        if ($methodId <= 0) {
            return null;
        }
        $db = JFactory::getDBO();
        $query = 'SELECT * FROM ' . hikashop_table('payment') . ' WHERE payment_id = ' . (int) $methodId . ' AND payment_type = ' . $db->Quote($this->name);
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

    private function handleRegistrationNotification($methodId, $environment)
    {
        $environment = TeyaHikashopUtils::normalizeEnvironment($environment);
        $raw = file_get_contents('php://input');
        $signature = $this->requestHeader('X-Teya-Signature');
        $client = $this->apiClient($this->payment_params);
        $publicKey = $client->fetchRegistrationPublicKey($environment);
        TeyaHikashopWebhookVerifier::verify($raw, $signature, $publicKey);
        $payload = json_decode($raw, true);
        if (!is_array($payload) || empty($payload['client_id']) || empty($payload['client_secret']) || empty($payload['payment_webhook_public_key'])) {
            throw new RuntimeException('Invalid Teya registration payload.');
        }
        $params = clone $this->payment_params;
        $prefix = $environment === TeyaHikashopUtils::ENV_TEST ? 'test_' : '';
        $params->{$prefix . 'client_id'} = $payload['client_id'];
        $params->{$prefix . 'client_secret'} = $payload['client_secret'];
        $params->{$prefix . 'payment_webhook_public_key'} = $payload['payment_webhook_public_key'];
        $token = $client->requestAccessToken($payload['client_id'], $payload['client_secret'], $environment);
        $params->{$prefix . 'access_token'} = $token['access_token'];
        $params->{$prefix . 'access_token_expires_at'} = time() + max(0, ((int) @$token['expires_in']) - 60);
        $params->sandbox = $environment === TeyaHikashopUtils::ENV_TEST ? 1 : 0;
        $this->savePaymentParams($methodId, $params, true);
        $this->cleanupDuplicatePaymentMethods($methodId);
        $this->writeLog('Teya ' . $environment . ' registration completed for HikaShop payment method ' . $methodId);
    }

    private function handlePaymentNotification(&$statuses, $methodId)
    {
        $raw = file_get_contents('php://input');
        $signature = $this->requestHeader('X-Teya-Signature');
        $environment = TeyaHikashopUtils::environmentFromParams($this->payment_params);
        $verifiedEnvironment = null;
        foreach (array($environment, $environment === TeyaHikashopUtils::ENV_TEST ? TeyaHikashopUtils::ENV_LIVE : TeyaHikashopUtils::ENV_TEST) as $candidate) {
            $publicKey = $this->credential($this->payment_params, 'payment_webhook_public_key', $candidate);
            if ($publicKey === '') {
                continue;
            }
            try {
                TeyaHikashopWebhookVerifier::verify($raw, $signature, $publicKey);
                $verifiedEnvironment = $candidate;
                break;
            } catch (Exception $exception) {
                $lastException = $exception;
            }
        }
        if ($verifiedEnvironment === null) {
            throw isset($lastException) ? $lastException : new RuntimeException('Teya signature verification failed.');
        }
        $payload = json_decode($raw, true);
        $data = is_array($payload) && isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array();
        $sessionId = isset($data['session_id']) ? (string) $data['session_id'] : '';
        if ($sessionId === '') {
            throw new RuntimeException('Invalid webhook data.');
        }
        $orderId = hikaInput::get()->getInt('order_id', 0);
        $order = $orderId ? $this->getOrder($orderId) : $this->findOrderBySessionId($sessionId);
        if (empty($order)) {
            throw new RuntimeException('Order not found.');
        }
        $outcome = $this->webhookOutcome($payload);
        $this->validateWebhookOrder($order, $data, $methodId, $verifiedEnvironment, $outcome);
        if ($outcome === 'unsupported') {
            $this->writeLog('Unsupported Teya webhook received: ' . json_encode(TeyaHikashopUtils::redact($payload)));
            return;
        }
        $transactionId = isset($data['transaction_id']) ? (string) $data['transaction_id'] : '';
        $existingOutcome = $this->webhookOutcome($this->paymentParamArray($order, 'teya_webhook_payload'));
        if ($existingOutcome === 'success' || ($existingOutcome === 'failure' && $outcome === 'failure')) {
            return;
        }
        $status = $outcome === 'success' ? $this->payment_params->verified_status : $this->payment_params->invalid_status;
        $history = new stdClass();
        $history->notified = $transactionId !== '' ? 1 : 0;
        $history->data = 'Teya session ID: ' . $sessionId . "\n" . 'Teya transaction ID: ' . $transactionId . "\n" . 'Teya status: ' . (isset($data['payment_status']) ? $data['payment_status'] : '');
        $email = new stdClass();
        $email->subject = JText::sprintf('PAYMENT_NOTIFICATION_FOR_ORDER', 'Teya', isset($data['payment_status']) ? $data['payment_status'] : $status, $order->order_number);
        $email->body = JText::sprintf('PAYMENT_NOTIFICATION_STATUS', 'Teya', isset($data['payment_status']) ? $data['payment_status'] : $status);
        $paymentData = array(
            'teya_session_id' => $sessionId,
            'teya_transaction_id' => $transactionId,
            'teya_environment' => $verifiedEnvironment,
            'teya_webhook_data' => $data,
            'teya_webhook_payload' => $payload,
        );
        $this->modifyOrder($order->order_id, $status, $history, $email, $paymentData);
    }

    private function validateWebhookOrder($order, array $data, $methodId, $environment, $outcome)
    {
        $params = $order->order_payment_params;
        if (($order->order_payment_method ?? '') !== 'teya' || (int) $methodId !== (int) $order->order_payment_id) {
            throw new RuntimeException('Webhook payment method mismatch.');
        }
        if (empty($params->teya_session_id) || !isset($data['session_id']) || $params->teya_session_id !== $data['session_id']) {
            throw new RuntimeException('Webhook session mismatch.');
        }
        if (empty($params->teya_environment) || $params->teya_environment !== $environment) {
            throw new RuntimeException('Webhook environment mismatch.');
        }
        if ($outcome === 'success') {
            $expected = isset($params->teya_checkout_amount) ? (array) $params->teya_checkout_amount : array(
                'currency' => TeyaHikashopCheckoutBuilder::currencyCode($order),
                'value' => TeyaHikashopUtils::toMinorUnits($order->order_full_price, TeyaHikashopCheckoutBuilder::currencyCode($order)),
            );
            if (!isset($data['amount']['value'], $data['amount']['currency'])
                || (string) $data['amount']['value'] !== (string) $expected['value']
                || $data['amount']['currency'] !== $expected['currency']) {
                throw new RuntimeException('Webhook amount or currency mismatch.');
            }
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


    private function getAccessToken($methodId, $params, $environment)
    {
        $environment = TeyaHikashopUtils::normalizeEnvironment($environment);
        $prefix = $environment === TeyaHikashopUtils::ENV_TEST ? 'test_' : '';
        $tokenKey = $prefix . 'access_token';
        $expiresKey = $prefix . 'access_token_expires_at';
        if (!empty($params->$tokenKey) && !empty($params->$expiresKey) && (int) $params->$expiresKey > time()) {
            return TeyaHikashopCredentials::value($params, 'access_token', $environment);
        }
        $clientId = $this->credential($params, 'client_id', $environment);
        $clientSecret = $this->credential($params, 'client_secret', $environment);
        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('Teya client credentials are not configured.');
        }
        $token = $this->apiClient($params)->requestAccessToken($clientId, $clientSecret, $environment);
        $params->$tokenKey = $token['access_token'];
        $params->$expiresKey = time() + max(0, ((int) @$token['expires_in']) - 60);
        $this->savePaymentParams($methodId, $params, false);
        return $token['access_token'];
    }

    private function connectionStatus($methodId, $environment)
    {
        $environment = TeyaHikashopUtils::normalizeEnvironment($environment);
        $methodId = (int) $methodId;
        $connected = $this->isConnected($this->payment_params, $environment);
        $connectedMethodId = 0;
        $duplicateRemoved = false;
        if (!$connected) {
            $connectedMethodId = $this->findConnectedPaymentMethodId($environment, $methodId);
            $connected = $connectedMethodId > 0;
            if ($connectedMethodId > 0) {
                $duplicateRemoved = $this->cleanupDuplicatePaymentMethod($methodId);
            }
        }
        return array(
            'connected' => $connected,
            'connected_method_id' => $connectedMethodId,
            'duplicate_removed' => $duplicateRemoved,
            'environment' => $environment,
            'active_environment' => TeyaHikashopUtils::environmentFromParams($this->payment_params),
            'csrf_token' => $this->requestTokenName(),
        );
    }

    private function findConnectedPaymentMethodId($environment, $excludeMethodId = 0)
    {
        $db = JFactory::getDBO();
        $query = 'SELECT payment_id, payment_params FROM ' . hikashop_table('payment') . ' WHERE payment_type = ' . $db->Quote($this->name) . ((int) $excludeMethodId > 0 ? ' AND payment_id != ' . (int) $excludeMethodId : '') . ' ORDER BY payment_id DESC';
        $db->setQuery($query);
        $methods = $db->loadObjectList();
        if (empty($methods)) {
            return 0;
        }
        foreach ($methods as $method) {
            $params = TeyaHikashopStorage::params($method->payment_params);
            if (is_object($params) && $this->isConnected($params, $environment)) {
                return (int) $method->payment_id;
            }
        }
        return 0;
    }


    private function cleanupDuplicatePaymentMethods($keepMethodId)
    {
        $db = JFactory::getDBO();
        $query = 'SELECT * FROM ' . hikashop_table('payment') . ' WHERE payment_type = ' . $db->Quote($this->name) . ' AND payment_id != ' . (int) $keepMethodId . ' ORDER BY payment_id DESC';
        $db->setQuery($query);
        $methods = $db->loadObjectList();
        if (empty($methods)) {
            return 0;
        }
        $removed = 0;
        foreach ($methods as $method) {
            if ($this->deleteDuplicatePaymentMethodIfSafe($method)) {
                $removed++;
            }
        }
        return $removed;
    }

    private function cleanupDuplicatePaymentMethod($methodId)
    {
        $method = $this->loadTeyaPaymentMethod((int) $methodId);
        return !empty($method) && $this->deleteDuplicatePaymentMethodIfSafe($method);
    }

    private function deleteDuplicatePaymentMethodIfSafe($method)
    {
        $methodId = (int) @$method->payment_id;
        if ($methodId <= 0) {
            return false;
        }
        $params = isset($method->payment_params) && is_object($method->payment_params) ? $method->payment_params : TeyaHikashopStorage::params((string) @$method->payment_params);
        if (!is_object($params)) {
            $params = new stdClass();
        }
        if (!empty($method->payment_published)) {
            return false;
        }
        if ($this->isConnected($params, TeyaHikashopUtils::ENV_TEST) || $this->isConnected($params, TeyaHikashopUtils::ENV_LIVE)) {
            return false;
        }
        if ($this->paymentMethodHasOrders($methodId)) {
            return false;
        }
        $db = JFactory::getDBO();
        $query = 'DELETE FROM ' . hikashop_table('payment') . ' WHERE payment_id = ' . $methodId . ' AND payment_type = ' . $db->Quote($this->name) . ' AND payment_published = 0';
        $db->setQuery($query);
        $db->execute();
        $this->writeLog('Removed duplicate unconnected HikaShop Teya payment method ' . $methodId);
        return true;
    }

    private function paymentMethodHasOrders($methodId)
    {
        $db = JFactory::getDBO();
        $query = 'SELECT order_id FROM ' . hikashop_table('order') . ' WHERE order_payment_method = ' . $db->Quote($this->name) . ' AND order_payment_id = ' . $db->Quote((string) (int) $methodId);
        $db->setQuery($query, 0, 1);
        return (int) $db->loadResult() > 0;
    }

    private function createCheckoutSessionWithTokenRetry($methodId, $params, $environment, array $payload)
    {
        try {
            return $this->apiClient($params)->createCheckoutSession($payload, $this->getAccessToken($methodId, $params, $environment), $environment);
        } catch (TeyaHikashopApiException $exception) {
            if ((int) $exception->getStatusCode() !== 401) {
                throw $exception;
            }
            $this->clearAccessToken($methodId, $params, $environment);
            return $this->apiClient($params)->createCheckoutSession($payload, $this->getAccessToken($methodId, $params, $environment), $environment);
        }
    }

    private function createRefundWithTokenRetry($methodId, $params, $environment, array $attempt)
    {
        try {
            return $this->apiClient($params)->createRefund($attempt['payload'], $this->getAccessToken($methodId, $params, $environment), $environment, $attempt['key']);
        } catch (TeyaHikashopApiException $exception) {
            if ((int) $exception->getStatusCode() !== 401) {
                throw $exception;
            }
            $this->clearAccessToken($methodId, $params, $environment);
            return $this->apiClient($params)->createRefund($attempt['payload'], $this->getAccessToken($methodId, $params, $environment), $environment, $attempt['key']);
        }
    }

    private function clearAccessToken($methodId, $params, $environment)
    {
        $prefix = TeyaHikashopUtils::normalizeEnvironment($environment) === TeyaHikashopUtils::ENV_TEST ? 'test_' : '';
        $params->{$prefix . 'access_token'} = '';
        $params->{$prefix . 'access_token_expires_at'} = '';
        $this->savePaymentParams($methodId, $params, false);
    }

    private function isConnected($params, $environment)
    {
        return TeyaHikashopCredentials::isConnected($params, $environment);
    }

    private function credential($params, $name, $environment)
    {
        return TeyaHikashopCredentials::value($params, $name, $environment);
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

    private function webhookOutcome(array $payload)
    {
        $event = isset($payload['event']) ? strtolower((string) $payload['event']) : '';
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array();
        $status = isset($data['status']) ? strtoupper((string) $data['status']) : (isset($data['payment_status']) ? strtoupper((string) $data['payment_status']) : '');
        $transactionId = isset($data['transaction_id']) ? (string) $data['transaction_id'] : '';
        if ($event === 'payment.succeeded.v1' && $status === 'SUCCESS' && $transactionId !== '') {
            return 'success';
        }
        if (in_array($event, array('payment.failed.v1', 'payment.cancelled.v1', 'payment.canceled.v1', 'payment.expired.v1'), true) && in_array($status, array('', 'FAILURE', 'FAILED', 'CANCELED', 'CANCELLED', 'EXPIRED'), true)) {
            return 'failure';
        }
        return 'unsupported';
    }

    private function paymentParamArray($order, $key)
    {
        if (empty($order->order_payment_params->$key)) {
            return array();
        }
        $value = $order->order_payment_params->$key;
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array) $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : array();
    }

    private function history($message)
    {
        $history = new stdClass();
        $history->notified = 0;
        $history->data = $message;
        return $history;
    }

    private function savePaymentParams($methodId, $params, $publish)
    {
        $db = JFactory::getDBO();
        $fields = array('payment_params = ' . $db->Quote(serialize(TeyaHikashopCredentials::protect($params))));
        if ($publish) {
            $fields[] = 'payment_published = 1';
        }
        $query = 'UPDATE ' . hikashop_table('payment') . ' SET ' . implode(', ', $fields) . ' WHERE payment_id = ' . (int) $methodId . ' AND payment_type = ' . $db->Quote($this->name);
        $db->setQuery($query);
        $db->execute();
        $this->plugin_params = $params;
        $this->payment_params = $params;
    }

    private function storeOrderPaymentData($orderId, array $data)
    {
        $history = new stdClass();
        $history->notified = 0;
        $history->data = 'Teya checkout session created: ' . $data['teya_session_id'];
        $this->modifyOrder($orderId, null, $history, null, $data);
    }

    private function findOrderBySessionId($sessionId)
    {
        $db = JFactory::getDBO();
        $query = 'SELECT order_id FROM ' . hikashop_table('order') . ' WHERE order_payment_method = ' . $db->Quote($this->name) . ' AND order_payment_params LIKE ' . $db->Quote('%' . $db->escape($sessionId, true) . '%') . ' ORDER BY order_id DESC';
        $db->setQuery($query, 0, 1);
        $orderId = (int) $db->loadResult();
        return $orderId ? $this->getOrder($orderId) : null;
    }

    private function requestHeader($name)
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$serverKey]) ? (string) $_SERVER[$serverKey] : '';
    }

    private function requestTokenName()
    {
        if (class_exists('\Joomla\CMS\Session\Session')) {
            return \Joomla\CMS\Session\Session::getFormToken();
        }
        return class_exists('JSession') ? JSession::getFormToken() : '';
    }

    private function checkRequestToken($method)
    {
        if (class_exists('\Joomla\CMS\Session\Session')) {
            return \Joomla\CMS\Session\Session::checkToken($method);
        }
        return class_exists('JSession') ? JSession::checkToken($method) : false;
    }

    private function respond($status, $message)
    {
        http_response_code((int) $status);
        echo $message;
        exit;
    }

    private function respondJson($status, array $payload)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
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
