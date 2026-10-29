<?php
/** Run from the Joomla root: php plugins/hikashoppayment/teya/cli/refunds.php */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$teyaCliOptions = getopt('', array('check', 'site-url:'));
$siteUrl = $teyaCliOptions['site-url'] ?? (isset($teyaCliOptions['check']) ? 'https://localhost' : '');
if (!filter_var($siteUrl, FILTER_VALIDATE_URL) || parse_url($siteUrl, PHP_URL_SCHEME) !== 'https') {
    fwrite(STDERR, 'Supply --site-url=https://your-shop.example so order emails use the correct shop URL.' . PHP_EOL);
    exit(1);
}
$_SERVER['HTTP_HOST'] = parse_url($siteUrl, PHP_URL_HOST) . (parse_url($siteUrl, PHP_URL_PORT) ? ':' . parse_url($siteUrl, PHP_URL_PORT) : '');
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_URI'] = rtrim((string) parse_url($siteUrl, PHP_URL_PATH), '/') . '/';
$_SERVER['SCRIPT_NAME'] = $_SERVER['REQUEST_URI'] . 'index.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
define('_JEXEC', 1);
define('JDEBUG', false);
define('JPATH_BASE', dirname(__DIR__, 4));
require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/libraries/bootstrap.php';

$container = \Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.web.site')
    ->alias('JSession', 'session.web.site')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.web.site');
$app = $container->get(\Joomla\CMS\Application\SiteApplication::class);
\Joomla\CMS\Factory::$application = $app;
$app->loadLanguage(\Joomla\CMS\Factory::getLanguage());
// VirtueMart versions still use the legacy names.
foreach (array('JFactory' => \Joomla\CMS\Factory::class, 'JPlugin' => \Joomla\CMS\Plugin\CMSPlugin::class,
    'JUri' => \Joomla\CMS\Uri\Uri::class, 'JText' => \Joomla\CMS\Language\Text::class,
    'JSession' => \Joomla\CMS\Session\Session::class) as $legacy => $modern) {
    if (!class_exists($legacy)) { class_alias($modern, $legacy); }
}
try {
    require_once JPATH_ADMINISTRATOR.'/components/com_hikashop/helpers/helper.php';
    foreach (array('Utils','Credentials','ApiClient','WebhookVerifier','CheckoutBuilder','Storage','Gateway') as $part) {
        require_once dirname(__DIR__).'/src/TeyaHikashop'.$part.'.php';
    }
    $store=new TeyaHikashopStorage($container->get(\Joomla\Database\DatabaseInterface::class));
    if (isset($teyaCliOptions['check'])) {
        \Joomla\CMS\Plugin\PluginHelper::importPlugin('hikashoppayment','teya');
        \Joomla\CMS\Plugin\PluginHelper::importPlugin('hikashop','teya');
        if (!class_exists('plgHikashoppaymentTeya') || !class_exists('plgHikashopTeya')) { throw new RuntimeException('Install and enable both Teya plugins first.'); }
        echo 'Teya HikaShop recovery and both plugin classes bootstrap OK; no refunds processed.'.PHP_EOL; exit;
    }
    $gateway=new TeyaHikashopGateway($store,function ($params) { return new TeyaHikashopApiClient(false,function ($message) {}); });
    $processed=0;
    foreach ($store->pending() as $order) {
        if ($processed>=10) { break; }
        $state=TeyaHikashopGateway::ledger($order->order_payment_params);
        if (!empty($state['pending']['blocked'])) { continue; }
        if (($state['pending']['next_attempt'] ?? 0)>time()) { continue; }
        ++$processed;
        try {
            $result=$gateway->refund($order->order_payment_id,$order->order_id,null,$state['pending']['key'],true);
            echo 'Order '.(int) $order->order_id.': '.($result['pending'] ? 'pending' : 'confirmed').PHP_EOL;
        } catch (Throwable $e) { fwrite(STDERR,'Order '.(int) $order->order_id.': recovery failed; review Teya and retry the same reference.'.PHP_EOL); }
    }
} catch (Throwable $e) { fwrite(STDERR,'Teya recovery could not start: '.$e->getMessage().PHP_EOL); exit(1); }
