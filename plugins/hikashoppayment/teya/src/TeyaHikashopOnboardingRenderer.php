<?php
/**
 * @package    Teya Payments for HikaShop
 * @author     Webensol
 * @license    GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
defined('_JEXEC') or defined('ABSPATH') or die('Restricted access');

final class TeyaHikashopOnboardingRenderer
{
    public function render($element, callable $registrationUrlBuilder, ?callable $disconnectUrlBuilder = null, ?callable $statusUrlBuilder = null)
    {
        $id = (int) @$element->payment_id;
        $params = $element->payment_params;
        $activeEnvironment = TeyaHikashopUtils::environmentFromParams($params);
        $assetUrl = rtrim(JUri::root(true), '/') . '/plugins/hikashoppayment/teya/assets/';
        $liveConnected = TeyaHikashopCredentials::isConnected($params, TeyaHikashopUtils::ENV_LIVE);
        $testConnected = TeyaHikashopCredentials::isConnected($params, TeyaHikashopUtils::ENV_TEST);

        $messages = TeyaHikashopLanguage::messages(array('REQUEST_FAILED', 'ENVIRONMENT_MISMATCH', 'WAITING_CONNECTION', 'OPEN_TEYA', 'CONNECTION_SAVED', 'CONNECTION_EXPIRED', 'ENV_TEST', 'ENV_LIVE'));
        $content = '<div class="teya-onboarding-container" data-messages="' . $messages . '" data-active-mode="' . htmlspecialchars($activeEnvironment, ENT_QUOTES, 'UTF-8') . '">';
        $content .= '<div class="teya-onboarding-header">';
        $content .= '<div class="teya-onboarding-header-left"><img class="teya-logo" src="' . $assetUrl . 'teya.png" alt="Teya" width="60" height="60"><div><div class="teya-kicker">' . TeyaHikashopLanguage::text('ALL_IN_ONE') . '</div><h3>' . TeyaHikashopLanguage::text('CONNECT_ACCOUNT') . '</h3></div></div>';
        $content .= '<div class="teya-onboarding-header-cards"><img src="' . $assetUrl . 'visa-dark.svg" alt="Visa"><img src="' . $assetUrl . 'mastercard-dark.svg" alt="Mastercard"><img src="' . $assetUrl . 'amex.svg" alt="American Express"></div>';
        $content .= '</div>';
        $content .= '<div class="teya-connect-section"><div class="teya-connect-content">';
        $content .= '<h4>' . TeyaHikashopLanguage::text('CONNECT_ACCOUNT') . '</h4>';
        $content .= '<p class="description">' . TeyaHikashopLanguage::text('CONNECT_COPY') . '</p>';

        if (!$id) {
            $content .= '<p class="teya-muted">' . TeyaHikashopLanguage::text('SAVE_FIRST') . '</p>';
        } else {
            $content .= $this->renderConnectButton($id, TeyaHikashopUtils::ENV_LIVE, $liveConnected, $assetUrl, $registrationUrlBuilder, $disconnectUrlBuilder, $statusUrlBuilder);
            $content .= $this->renderConnectButton($id, TeyaHikashopUtils::ENV_TEST, $testConnected, $assetUrl, $registrationUrlBuilder, $disconnectUrlBuilder, $statusUrlBuilder);
        }

        $content .= '<div class="teya-connect-info">';
        $content .= '<p class="teya-terms">' . TeyaHikashopLanguage::text('TERMS_PREFIX') . ' <a href="https://www.teya.com/legal/" target="_blank" rel="noopener">' . TeyaHikashopLanguage::text('TERMS') . '</a> ' . TeyaHikashopLanguage::text('AND') . ' <a href="https://www.teya.com/privacy-policy/" target="_blank" rel="noopener">' . TeyaHikashopLanguage::text('PRIVACY') . '</a>.</p>';
        $content .= '<p class="teya-help"><strong>' . TeyaHikashopLanguage::text('NEED_HELP') . '</strong> ' . TeyaHikashopLanguage::text('VISIT_DOCS') . ' <a href="https://developer.teya.com/" target="_blank" rel="noopener">' . TeyaHikashopLanguage::text('DOCUMENTATION') . '</a>.</p>';
        $content .= '</div></div></div></div>';

        return $content;
    }

    private function renderConnectButton($id, $environment, $connected, $assetUrl, callable $registrationUrlBuilder, ?callable $disconnectUrlBuilder = null, ?callable $statusUrlBuilder = null)
    {
        $connectLabel = $environment === TeyaHikashopUtils::ENV_TEST ? TeyaHikashopLanguage::text('CONNECT_TEST') : TeyaHikashopLanguage::text('CONNECT_LIVE');
        $reconnectLabel = $environment === TeyaHikashopUtils::ENV_TEST ? TeyaHikashopLanguage::text('RECONNECT_TEST') : TeyaHikashopLanguage::text('RECONNECT_LIVE');
        $label = $connected ? $reconnectLabel : $connectLabel;
        $statusClass = $connected ? 'is-connected' : 'is-disconnected';
        $statusText = $connected ? TeyaHikashopLanguage::text('CONNECTED') : TeyaHikashopLanguage::text('NOT_CONNECTED_SHORT');
        $url = call_user_func($registrationUrlBuilder, $id, $environment);
        $statusUrl = $statusUrlBuilder ? call_user_func($statusUrlBuilder, $id, $environment) : '';

        $disconnect = $disconnectUrlBuilder ? '<a ' . ($connected ? '' : 'hidden ') . 'class="teya-disconnect-button" href="' . htmlspecialchars(call_user_func($disconnectUrlBuilder, $id, $environment), ENT_QUOTES, 'UTF-8') . '" data-teya-status-url="' . htmlspecialchars($statusUrl, ENT_QUOTES, 'UTF-8') . '">' . TeyaHikashopLanguage::text('DISCONNECT') . '</a>' : '';
        $poll = $statusUrl !== '' ? '<p class="teya-poll-status" aria-live="polite"></p>' : '';
        return '<div class="teya-connect-option" data-teya-mode="' . $environment . '" data-connected-label="' . htmlspecialchars(TeyaHikashopLanguage::text('CONNECTED'), ENT_QUOTES, 'UTF-8') . '" data-disconnected-label="' . htmlspecialchars(TeyaHikashopLanguage::text('NOT_CONNECTED_SHORT'), ENT_QUOTES, 'UTF-8') . '" data-connect-label="' . htmlspecialchars($connectLabel, ENT_QUOTES, 'UTF-8') . '" data-reconnect-label="' . htmlspecialchars($reconnectLabel, ENT_QUOTES, 'UTF-8') . '"><p class="teya-environment-status ' . $statusClass . '">' . $statusText . '</p><div class="teya-connect-button-container"><a class="teya-connect-button" target="_blank" rel="noopener" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" data-teya-status-url="' . htmlspecialchars($statusUrl, ENT_QUOTES, 'UTF-8') . '" data-teya-environment="' . htmlspecialchars($environment, ENT_QUOTES, 'UTF-8') . '"><img class="teya-connect-logo" src="' . $assetUrl . 'teya.png" alt="Teya" width="32" height="32"><span class="teya-connect-text">' . $label . '</span></a>' . $disconnect . $poll . '</div></div>';
    }
}
