<?php
/**
 * @package    Teya Payments for HikaShop
 * @author     Webensol
 * @license    GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
defined('_JEXEC') or defined('ABSPATH') or die('Restricted access');

final class TeyaHikashopOnboardingRenderer
{
    public function render($element, callable $registrationUrlBuilder, callable $disconnectUrlBuilder = null, callable $statusUrlBuilder = null)
    {
        $id = (int) @$element->payment_id;
        $params = $element->payment_params;
        $activeEnvironment = TeyaHikashopUtils::environmentFromParams($params);
        $assetUrl = rtrim(JUri::root(true), '/') . '/plugins/hikashoppayment/teya/assets/';
        $liveConnected = TeyaHikashopCredentials::isConnected($params, TeyaHikashopUtils::ENV_LIVE);
        $testConnected = TeyaHikashopCredentials::isConnected($params, TeyaHikashopUtils::ENV_TEST);

        $content = '<div class="teya-onboarding-container" data-active-mode="' . htmlspecialchars($activeEnvironment, ENT_QUOTES, 'UTF-8') . '">';
        $content .= '<div class="teya-onboarding-header">';
        $content .= '<div class="teya-onboarding-header-left"><img class="teya-logo" src="' . $assetUrl . 'teya.png" alt="Teya" width="60" height="60"><div><div class="teya-kicker">' . JText::_('PLG_HIKASHOPPAYMENT_TEYA_ALL_IN_ONE') . '</div><h3>' . JText::_('PLG_HIKASHOPPAYMENT_TEYA_CONNECT_ACCOUNT') . '</h3></div></div>';
        $content .= '<div class="teya-onboarding-header-cards"><img src="' . $assetUrl . 'visa-dark.svg" alt="Visa"><img src="' . $assetUrl . 'mastercard-dark.svg" alt="Mastercard"><img src="' . $assetUrl . 'amex.svg" alt="American Express"></div>';
        $content .= '</div>';
        $content .= '<div class="teya-connect-section"><div class="teya-connect-content">';
        $content .= '<h4>' . JText::_('PLG_HIKASHOPPAYMENT_TEYA_CONNECT_ACCOUNT') . '</h4>';
        $content .= '<p class="description">' . JText::_('PLG_HIKASHOPPAYMENT_TEYA_CONNECT_COPY') . '</p>';

        if (!$id) {
            $content .= '<p class="teya-muted">' . JText::_('PLG_HIKASHOPPAYMENT_TEYA_SAVE_FIRST') . '</p>';
        } else {
            $content .= $this->renderConnectButton($id, TeyaHikashopUtils::ENV_LIVE, $liveConnected, $assetUrl, $registrationUrlBuilder, $disconnectUrlBuilder, $statusUrlBuilder);
            $content .= $this->renderConnectButton($id, TeyaHikashopUtils::ENV_TEST, $testConnected, $assetUrl, $registrationUrlBuilder, $disconnectUrlBuilder, $statusUrlBuilder);
        }

        $content .= '<div class="teya-connect-info">';
        $content .= '<p class="teya-terms">' . JText::_('PLG_HIKASHOPPAYMENT_TEYA_TERMS_PREFIX') . ' <a href="https://www.teya.com/legal/" target="_blank" rel="noopener">' . JText::_('PLG_HIKASHOPPAYMENT_TEYA_TERMS') . '</a> ' . JText::_('PLG_HIKASHOPPAYMENT_TEYA_AND') . ' <a href="https://www.teya.com/privacy-policy/" target="_blank" rel="noopener">' . JText::_('PLG_HIKASHOPPAYMENT_TEYA_PRIVACY') . '</a>.</p>';
        $content .= '<p class="teya-help"><strong>' . JText::_('PLG_HIKASHOPPAYMENT_TEYA_NEED_HELP') . '</strong> ' . JText::_('PLG_HIKASHOPPAYMENT_TEYA_VISIT_DOCS') . ' <a href="https://developer.teya.com/" target="_blank" rel="noopener">' . JText::_('PLG_HIKASHOPPAYMENT_TEYA_DOCUMENTATION') . '</a>.</p>';
        $content .= '</div></div></div></div>';

        return $content;
    }

    private function renderConnectButton($id, $environment, $connected, $assetUrl, callable $registrationUrlBuilder, callable $disconnectUrlBuilder = null, callable $statusUrlBuilder = null)
    {
        $connectLabel = $environment === TeyaHikashopUtils::ENV_TEST ? JText::_('PLG_HIKASHOPPAYMENT_TEYA_CONNECT_TEST') : JText::_('PLG_HIKASHOPPAYMENT_TEYA_CONNECT_LIVE');
        $reconnectLabel = $environment === TeyaHikashopUtils::ENV_TEST ? JText::_('PLG_HIKASHOPPAYMENT_TEYA_RECONNECT_TEST') : JText::_('PLG_HIKASHOPPAYMENT_TEYA_RECONNECT_LIVE');
        $label = $connected ? $reconnectLabel : $connectLabel;
        $statusClass = $connected ? 'is-connected' : 'is-disconnected';
        $statusText = $connected ? JText::_('PLG_HIKASHOPPAYMENT_TEYA_CONNECTED') : JText::_('PLG_HIKASHOPPAYMENT_TEYA_NOT_CONNECTED_SHORT');
        $url = call_user_func($registrationUrlBuilder, $id, $environment);
        $statusUrl = $statusUrlBuilder ? call_user_func($statusUrlBuilder, $id, $environment) : '';

        $disconnect = $disconnectUrlBuilder ? '<a ' . ($connected ? '' : 'hidden ') . 'class="teya-disconnect-button" href="' . htmlspecialchars(call_user_func($disconnectUrlBuilder, $id, $environment), ENT_QUOTES, 'UTF-8') . '" data-teya-status-url="' . htmlspecialchars($statusUrl, ENT_QUOTES, 'UTF-8') . '">' . JText::_('PLG_HIKASHOPPAYMENT_TEYA_DISCONNECT') . '</a>' : '';
        $poll = $statusUrl !== '' ? '<p class="teya-poll-status" aria-live="polite"></p>' : '';
        return '<div class="teya-connect-option" data-teya-mode="' . $environment . '" data-connected-label="' . htmlspecialchars(JText::_('PLG_HIKASHOPPAYMENT_TEYA_CONNECTED'), ENT_QUOTES, 'UTF-8') . '" data-disconnected-label="' . htmlspecialchars(JText::_('PLG_HIKASHOPPAYMENT_TEYA_NOT_CONNECTED_SHORT'), ENT_QUOTES, 'UTF-8') . '" data-connect-label="' . htmlspecialchars($connectLabel, ENT_QUOTES, 'UTF-8') . '" data-reconnect-label="' . htmlspecialchars($reconnectLabel, ENT_QUOTES, 'UTF-8') . '"><p class="teya-environment-status ' . $statusClass . '">' . $statusText . '</p><div class="teya-connect-button-container"><a class="teya-connect-button" target="_blank" rel="noopener" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" data-teya-status-url="' . htmlspecialchars($statusUrl, ENT_QUOTES, 'UTF-8') . '" data-teya-environment="' . htmlspecialchars($environment, ENT_QUOTES, 'UTF-8') . '"><img class="teya-connect-logo" src="' . $assetUrl . 'teya.png" alt="Teya" width="32" height="32"><span class="teya-connect-text">' . $label . '</span></a>' . $disconnect . $poll . '</div></div>';
    }
}
