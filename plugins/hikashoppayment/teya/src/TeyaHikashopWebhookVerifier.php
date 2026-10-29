<?php
/**
 * @package    Teya Payments for HikaShop
 * @author     Webensol
 * @license    GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
defined('_JEXEC') or defined('ABSPATH') or die('Restricted access');

final class TeyaHikashopWebhookVerifier
{
    public static function verify($payload, $signature, $publicKey)
    {
        if ($signature === '') {
            throw new RuntimeException('Missing Teya signature.');
        }
        if ($publicKey === '') {
            throw new RuntimeException('Missing Teya public key.');
        }
        $binarySignature = base64_decode((string) $signature, true);
        if ($binarySignature === false) {
            throw new RuntimeException('Invalid base64 signature format.');
        }
        $pem = TeyaHikashopUtils::derPublicKeyToPem($publicKey);
        $verified = openssl_verify($payload, $binarySignature, $pem, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw new RuntimeException('Teya signature verification failed.');
        }
    }
}
