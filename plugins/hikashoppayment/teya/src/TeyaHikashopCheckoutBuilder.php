<?php
/**
 * @package    Teya Payments for HikaShop
 * @author     Webensol
 * @license    GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
defined('_JEXEC') or defined('ABSPATH') or die('Restricted access');

require_once __DIR__ . '/TeyaHikashopLanguage.php';

final class TeyaHikashopCheckoutBuilder
{
    public static function build($order, $params, array $urls)
    {
        $currency = self::currencyCode($order);
        $amount = isset($order->order_full_price) ? $order->order_full_price : (isset($order->cart->full_total->prices[0]->price_value_with_tax) ? $order->cart->full_total->prices[0]->price_value_with_tax : 0);
        if ($currency === 'HUF' && abs((float) $amount - round((float) $amount)) > 0.000001) {
            throw new RuntimeException('Teya requires whole HUF order totals. Configure HikaShop order rounding before payment.');
        }
        if (TeyaHikashopUtils::toMinorUnits($amount, $currency) <= 0) {
            throw new RuntimeException('Teya requires a positive payment amount.');
        }
        $payload = array(
            'amount' => array(
                'currency' => $currency,
                'value' => TeyaHikashopUtils::toMinorUnits($amount, $currency),
            ),
            'type' => 'SALE',
            'merchant_reference' => 'joomla_hikashop#' . (isset($order->order_number) ? $order->order_number : $order->order_id),
            'success_url' => $urls['success_url'],
            'failure_url' => $urls['failure_url'],
            'cancel_url' => $urls['cancel_url'],
            'line_items' => self::lineItems($order, $amount, $currency, !empty($params->total_line_item)),
            'language' => TeyaHikashopUtils::checkoutLanguage(),
            'post_success_payment' => isset($params->post_success_payment) && $params->post_success_payment === 'show_success_page' ? 'SHOW_SUCCESS_PAGE' : 'REDIRECT',
        );
        $customer = self::customer($order);
        if (!empty($customer)) {
            $payload['customer'] = (object) $customer;
            if (!empty($params->customer_success_email) && !empty($customer['email'])) { $payload['customer_success_email'] = $customer['email']; }
        }
        $billingAddress = self::billingAddress($order);
        if (!empty($billingAddress)) {
            $payload['billing_address'] = (object) $billingAddress;
        }
        if (!empty($params->merchant_success_email)) {
            $payload['merchant_success_email'] = $params->merchant_success_email;
        }
        return $payload;
    }

    public static function currencyCode($order)
    {
        $currencyId = null;
        if (isset($order->order_currency_id)) {
            $currencyId = $order->order_currency_id;
        } elseif (isset($order->cart->full_total->prices[0]->price_currency_id)) {
            $currencyId = $order->cart->full_total->prices[0]->price_currency_id;
        }
        if ($currencyId) {
            $currencyClass = hikashop_get('class.currency');
            $currency = $currencyClass->get((int) $currencyId);
            if (!empty($currency->currency_code)) {
                return $currency->currency_code;
            }
        }
        throw new RuntimeException('Cannot determine the HikaShop order currency.');
    }

    private static function customer($order)
    {
        $billing = isset($order->cart->billing_address) ? $order->cart->billing_address : (isset($order->billing_address) ? $order->billing_address : null);
        $name = trim(self::scalar(@$billing->address_firstname) . ' ' . self::scalar(@$billing->address_lastname));
        $email = isset($order->customer->user_email) ? $order->customer->user_email : (isset($order->cart->user->user_email) ? $order->cart->user->user_email : '');
        return array_filter(array('name' => $name, 'email' => self::scalar($email)));
    }

    private static function scalar($value)
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        if (is_object($value)) {
            if (isset($value->value)) {
                $value = $value->value;
            } elseif (method_exists($value, '__toString')) {
                $value = (string) $value;
            } else {
                return '';
            }
        }
        return trim((string) $value);
    }

    private static function billingAddress($order)
    {
        $billing = isset($order->cart->billing_address) ? $order->cart->billing_address : (isset($order->billing_address) ? $order->billing_address : null);
        return array_filter(array(
            'street_address_line1' => self::scalar(@$billing->address_street),
            'street_address_line2' => self::scalar(@$billing->address_street2),
            'city' => self::scalar(@$billing->address_city),
            'state' => self::scalar(@$billing->address_state->zone_name),
            'zipcode' => self::scalar(@$billing->address_post_code),
            'country' => self::scalar(@$billing->address_country->zone_code_2),
        ));
    }

    private static function lineItems($order, $amount, $currency, $singleLine)
    {
        $products = !empty($order->cart->products) ? $order->cart->products : ($order->products ?? array());
        if ($singleLine || empty($products)) {
            return array(array(
                'description' => TeyaHikashopUtils::truncateDescription(TeyaHikashopLanguage::text('ORDER_LINE', isset($order->order_number) ? $order->order_number : $order->order_id)),
                'quantity' => 1,
                'unit_price' => TeyaHikashopUtils::toMinorUnits($amount, $currency),
            ));
        }
        $items = array();
        foreach ($products as $product) {
            $quantity = isset($product->order_product_quantity) ? (int) $product->order_product_quantity : (isset($product->cart_product_quantity) ? (int) $product->cart_product_quantity : 1);
            if ($quantity < 1) {
                continue;
            }
            $unitPrice = isset($product->order_product_price) ? $product->order_product_price : (isset($product->prices[0]->unit_price->price_value_with_tax) ? $product->prices[0]->unit_price->price_value_with_tax : 0);
            if (!$unitPrice && isset($product->prices[0]->price_value_with_tax)) {
                $unitPrice = $product->prices[0]->price_value_with_tax;
            }
            if (isset($product->order_product_tax)) { $unitPrice += $product->order_product_tax; }
            $items[] = array(
                'description' => TeyaHikashopUtils::truncateDescription(isset($product->order_product_name) ? $product->order_product_name : @$product->product_name),
                'quantity' => $quantity,
                'unit_price' => TeyaHikashopUtils::toMinorUnits($unitPrice, $currency),
            );
        }
        if (empty($items)) {
            $items[] = array(
                'description' => TeyaHikashopUtils::truncateDescription(TeyaHikashopLanguage::text('ORDER_LINE', isset($order->order_number) ? $order->order_number : $order->order_id)),
                'quantity' => 1,
                'unit_price' => TeyaHikashopUtils::toMinorUnits($amount, $currency),
            );
        }
        $difference = TeyaHikashopUtils::toMinorUnits($amount, $currency) - array_sum(array_map(function ($item) {
            return $item['quantity'] * $item['unit_price'];
        }, $items));
        if ($difference !== 0) {
            $items[] = array('description' => TeyaHikashopLanguage::text('ORDER_ADJUSTMENTS'), 'quantity' => 1, 'unit_price' => $difference);
        }
        return $items;
    }
}
