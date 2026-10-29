<?php
/**
 * @package Teya Payments for HikaShop
 * @author Webensol
 * @license GNU General Public License version 3; see LICENSE.txt
 */
defined('_JEXEC') or die;

/** Enable the bundled plugins after Joomla has installed both successfully. */
class pkg_teya_hikashopInstallerScript
{
    public function preflight($type, $parent)
    {
        if ($type === 'uninstall') { return true; }
        if (version_compare(PHP_VERSION,'8.2.0','<')) { throw new RuntimeException('Teya Payments requires PHP 8.2 or later.'); }
        if (!defined('JVERSION') || version_compare(JVERSION,'5.4.0','<') || version_compare(JVERSION,'7.0.0','>=')) {
            throw new RuntimeException('Teya Payments requires Joomla 5.4 or Joomla 6.');
        }
        if (!extension_loaded('openssl') || !function_exists('hash_hkdf') || !function_exists('curl_init')) {
            throw new RuntimeException('Teya Payments requires PHP OpenSSL and cURL support.');
        }
        $manifest=JPATH_ADMINISTRATOR.'/components/com_hikashop/hikashop.xml';
        $xml=is_file($manifest) ? simplexml_load_file($manifest) : false;
        if (!$xml || version_compare((string)$xml->version,'6.6.0','<')) {
            throw new RuntimeException('Install HikaShop 6.6.0 or later before installing Teya Payments.');
        }
        return true;
    }

    public function postflight($type, $parent)
    {
        if (!in_array($type, array('install', 'update', 'discover_install'), true)) {
            return;
        }
        $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        foreach (array(array('hikashoppayment', 'teya'), array('hikashop', 'teya')) as $plugin) {
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote($plugin[0]))
                ->where($db->quoteName('element') . ' = ' . $db->quote($plugin[1]));
            $db->setQuery($query)->execute();
        }
        \Joomla\CMS\Factory::getApplication()->enqueueMessage(
            'Teya Payments for HikaShop installed. Both required plugins are enabled. Configure your Teya payment method in HikaShop.',
            'message'
        );
    }
}
