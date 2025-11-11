<?php

/**
 * Plugin Name: Versapay Payments
 * Plugin URI: https://versapay.com
 * Author: Versapay
 * Author URI: https://versapay.com
 * Description: Versapay Payments for WooCommerce.
 * Version: 25.4
 * License: GPL2
 * License URL: http://www.gnu.org/licenses/gpl-2.0.txt
 * text-domain: versapay-payments-woo
 * 
 * Class WC_Gateway_Versapay file.
 *
 * @package WooCommerce\Versapay
 */

add_action('wp_enqueue_scripts', 'versapay_enqueue_sdk');
function versapay_enqueue_sdk()
{
    // Only enqueue the SDK when the checkout form is present.
    if (!is_checkout()) {
        return;
    }

    if (!class_exists('WC_Payment_Gateways')) {
        return;
    }

    $payment_gateways = WC_Payment_Gateways::instance();
    $gateways = $payment_gateways->payment_gateways();

    if (!isset($gateways['versapay'])) {
        return;
    }

    $payment_gateway = $gateways['versapay'];

    $subdomain = isset($payment_gateway->subdomain) ? trim($payment_gateway->subdomain) : '';
    // Accept only safe characters in the merchant subdomain to avoid malformed URLs.
    if ($subdomain !== '' && !preg_match('/^[a-z0-9-]+$/i', $subdomain)) {
        $subdomain = '';
    }

    // If no dedicated subdomain is provided, fall back to the primary ecommerce API host.
    $host = $subdomain !== '' ? "{$subdomain}.versapay.com" : 'ecommerce-api.versapay.com';

    // Load the VersaPay SDK so our gateway script can initialize safely.
    wp_enqueue_script(
        'versapay-sdk',
        "https://{$host}/client.js",
        array(),
        null,
        true
    );
}

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (! in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

add_action('plugins_loaded', 'versapay_payment_init', 11);
add_filter('woocommerce_payment_gateways', 'add_to_woo_versapay_payment_gateway');

function versapay_payment_init()
{
    if (class_exists('WC_Payment_Gateway')) {
        require_once plugin_dir_path(__FILE__) . '/includes/class-wc-payment-gateway-versapay.php';
        require_once plugin_dir_path(__FILE__) . '/includes/versapay-order-statuses.php';
        require_once plugin_dir_path(__FILE__) . '/includes/versapay-checkout-description-fields.php';
    }
}

function add_to_woo_versapay_payment_gateway($gateways)
{
    $gateways[] = 'WC_Gateway_Versapay';
    return $gateways;
}

global $wpdb;

add_action("wp_ajax_versapaySaveTransaction", "versapaySaveTransaction");
function versapaySaveTransaction()
{
    if (!wp_verify_nonce($_REQUEST['nonce'], "my_user_vote_nonce")) {
        exit("No naughty business please");
    }

    $vote_count = get_post_meta($_REQUEST["post_id"], "votes", true);
    $vote_count = ($vote_count == ’) ? 0 : $vote_count;
    $new_vote_count = $vote_count + 1;

    $vote = update_post_meta($_REQUEST["post_id"], "votes", $new_vote_count);

    if ($vote === false) {
        $result['type'] = "error";
        $result['vote_count'] = $vote_count;
    } else {
        $result['type'] = "success";
        $result['vote_count'] = $new_vote_count;
    }

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        $result = json_encode($result);
        echo $result;
    } else {
        header("Location: " . $_SERVER["HTTP_REFERER"]);
    }

    die();
}

add_action('woocommerce_checkout_process', 'validate_versapay_payment');
function validate_versapay_payment()
{
    $errorMessage = 'There was a problem processing your payment. Please check your billing address and payment details or use a different payment method.';
    $productErrorMessage = 'There is a problem with one of the products in your cart. Please contact the merchant for more information.';

    if ($_REQUEST['payment_method'] != 'versapay') {
        return;
    }

    $logger = wc_get_logger();

    if (isset($_REQUEST['versapay_error']) && $_REQUEST['versapay_error'] != "") {
        $logger->error('Versapay Payments Error: ' . json_encode($_REQUEST['versapay_error'], JSON_UNESCAPED_SLASHES));
        wc_add_notice($errorMessage, 'error');
        return;
    }

    if (isset($_REQUEST['token']) && $_REQUEST['token'] != "") {
        $logger->error('Versapay Payments Error: Missing payment token in the response.');
        wc_add_notice($errorMessage, 'error');
        return;
    }

    $cartItems = WC()->cart->get_cart();
    foreach ($cartItems as $cartItem) {
        if (isset($cartItem['variation_id']) && $cartItem['variation_id'] != 0 && $cartItem['variation_id'] != $cartItem['product_id']) {
            $product = wc_get_product($cartItem['variation_id']);
        } else {
            $product = wc_get_product($cartItem['product_id']);
        }

        if (!$product || empty($product->get_sku())) {
            $logger->error('Versapay Payments Error: One or more items in the cart are missing required product details (SKU).');
            wc_add_notice($productErrorMessage, 'error');
            return;
        }
    }

    $expressCheckout = isset($_POST['versapay_express_checkout_payment']);

    if (isset($_POST['versapay_payments'])) {
        $jsonData = stripslashes($_POST['versapay_payments']);
        $payments = json_decode($jsonData, true);
    }
    if ($expressCheckout) {
        $jsonData = stripslashes($_POST['versapay_express_checkout_payment']);
        $expressCheckoutPayment = json_decode($jsonData, true);
    }

    $authPaymentTotal = 0;
    if ($payments) {
        foreach ($payments as $payment) {
            $authPaymentTotal += (float)$payment['amount'];
        }
    }
    if ($expressCheckoutPayment) {
        $authPaymentTotal += (float)$expressCheckoutPayment[0]['amount'];
    }
    if ((float)WC()->cart->get_total('order') != $authPaymentTotal) {
        $logger->error('Versapay Payments Error: The payment amount does not match the order total amount.');
        wc_add_notice($errorMessage, 'error');
        return;
    }

    $dataPayload = get_data_payload();

    $response = process_versapay_sale_request($dataPayload);
    if (isset($response['message'])) {
        $logger = wc_get_logger();
        $logger->error('Versapay Payments Error: ' . json_encode($response['message'], JSON_UNESCAPED_SLASHES));

        wc_add_notice($errorMessage, 'error');
        return;
    }

    if (is_user_logged_in()) {
        if ($expressCheckout) {
            WC()->session->set('versapay_payment_data', [
                'versapayApprovalCode' => $response['approvalCode'],
                'versapayOrderId' => $response['orderId']
            ]);
        } else {
            WC()->session->set('versapay_payment_data', [
                'versapayApprovalCode' => $response['payments'][0]['payment']['approvalCode'],
                'versapayOrderId' => $response['orderId']
            ]);
        }
    } else {
        if ($expressCheckout) {
            $_POST['versapayOrderId'] = sanitize_text_field($response['orderId']);
            $_POST['versapayApprovalCode'] = sanitize_text_field($response['approvalCode']);
        } else {
            $_POST['versapayOrderId'] = sanitize_text_field($response['orderId']);
            $_POST['versapayApprovalCode'] = sanitize_text_field($response['payments'][0]['payment']['approvalCode']);
        }
    }
}

add_action('woocommerce_checkout_after_order_review', 'inject_versapay_hidden_fields');
function inject_versapay_hidden_fields()
{
    if ($_POST['payment_method'] !== 'versapay') {
        return;
    }

    if (!empty($_POST['versapayOrderId']) && !empty($_POST['versapayApprovalCode'])) {
        echo '<input type="hidden" name="versapayOrderId" value="' . esc_attr($_POST['versapayOrderId']) . '">';
        echo '<input type="hidden" name="versapayApprovalCode" value="' . esc_attr($_POST['versapayApprovalCode']) . '">';
    }
}

add_action('woocommerce_checkout_create_order', 'process_versapay_order', 20, 2);
function process_versapay_order($order, $data)
{
    $errorMessage = 'There was a problem processing your payment. Please check your billing address and payment details or use a different payment method.';

    $posted_data = WC()->checkout()->get_posted_data();
    if (isset($posted_data['payment_method']) && $posted_data['payment_method'] !== 'versapay') {
        return;
    }
    if (!isset($_POST['versapay_payments']) && !isset($_POST['versapay_express_checkout_payment'])) {
        return;
    }

    $paymentData = WC()->session->get('versapay_payment_data', []);
    if (!empty($paymentData['versapayApprovalCode'])) {
        $order->update_meta_data('versapay_approval_code', $paymentData['versapayApprovalCode']);
    } else {
        if (!empty($_POST['versapayApprovalCode'])) {
            $order->update_meta_data('versapay_approval_code', sanitize_text_field($_POST['versapayApprovalCode']));
        }
    }
    if (!empty($paymentData['versapayOrderId'])) {
        $order->update_meta_data('versapay_orderid', $paymentData['versapayOrderId']);
    } else {
        if (!empty($_POST['versapayOrderId'])) {
            $order->update_meta_data('versapay_orderid', sanitize_text_field($_POST['versapayOrderId']));
        }
    }
    WC()->session->__unset('versapay_payment_data');

    $order->save();
}

function get_data_payload()
{
    $post = $_REQUEST;

    global $wpdb;

    $lastrowId = $wpdb->get_col("SELECT MAX(ID) FROM " . $wpdb->prefix . "posts");
    $lastOrderId = $lastrowId[0] + 1;

    $payment_gateways = WC_Payment_Gateways::instance();
    $payment_gateway = $payment_gateways->payment_gateways()['versapay'];

    if (isset($_POST['versapay_payments'])) {
        $jsonData = stripslashes($_POST['versapay_payments']);
        $payments = json_decode($jsonData, true);
    }
    if (isset($_POST['versapay_express_checkout_payment'])) {
        $jsonData = stripslashes($_POST['versapay_express_checkout_payment']);
        $expressCheckoutPayment = json_decode($jsonData, true);
    }

    if ($payments) {
        $paymentsArray = [];
        foreach ($payments as $payment) {
            $paymentArray = array(
                "type" => $payment['payment_type'],
                "token" => $payment['token'],
                "amount" => (float)$payment['amount'],
                "capture" => ($payment['payment_type'] != 'creditCard') ? true : false
            );

            switch ($payment['payment_type']) {
                case 'ach':
                    if ($payment_gateway->ach_settlement_token != '') {
                        $paymentArray["settlementToken"] = $payment_gateway->ach_settlement_token;
                    }
                    break;
                case 'creditCard':
                    if ($payment_gateway->cc_settlement_token != '') {
                        $paymentArray["settlementToken"] = $payment_gateway->cc_settlement_token;
                    }
                    break;
            }
            $paymentsArray[] = $paymentArray;
        }
    }

    if ($expressCheckoutPayment) {
        switch ($expressCheckoutPayment[0]['payment_type']) {
            case 'applePay':
                $applePayPayment = [
                    'amount' => (float)$expressCheckoutPayment[0]['amount'],
                    "transactionType" => 'sale',
                    "payment" => $expressCheckoutPayment[0]['payment']
                ];
                break;
        }
    }

    $cart  = WC()->cart;
    $itemsArray = [];
    $totalTax  = 0;

    foreach ($cart->get_cart() as $cartItem_key => $cartItem) {
        if (isset($cartItem['variation_id']) && $cartItem['variation_id'] != 0 && $cartItem['variation_id'] != $cartItem['product_id']) {
            $product = wc_get_product($cartItem['variation_id']);
        } else {
            $product = wc_get_product($cartItem['product_id']);
        }

        $itemsArray[] = [
            'type' => 'Item',
            'number' => $product->get_sku(),
            'description' => wp_strip_all_tags($product->get_name()),
            'price' => round($product->get_price(), 2),
            'quantity' => (int) $cartItem['quantity'],
            'discount' => 0
        ];
    }

    foreach (WC()->cart->get_taxes() as $tax) {
        $totalTax = $totalTax + floatval($tax);
    }

    $dataPayload = [];

    $dataPayload['gatewayAuthorization'] = [
        'apiToken' => $payment_gateway->api_token,
        'apiKey' => $payment_gateway->api_key,
    ];

    $dataPayload['customerNumber'] = (string)get_current_user_id();
    $dataPayload['orderNumber'] = (string)$lastOrderId;
    $dataPayload['purchaseOrderNumber'] = (string)$lastOrderId;

    $shippingMethod = isset($post['shipping_method']) ? $post['shipping_method'][0] : " ";
    $dataPayload['shippingAgentNumber'] = $shippingMethod;
    $dataPayload['shippingAgentServiceNumber'] = $shippingMethod;
    $dataPayload['shippingAgentDescription'] = $shippingMethod;
    $dataPayload['shippingAgentServiceDescription'] = $shippingMethod;

    $dataPayload['currency'] = get_woocommerce_currency();
    $dataPayload['billingAddress'] = [
        'contactFirstName' => isset($post['billing_first_name']) ? $post['billing_first_name'] : '',
        'contactLastName'  => isset($post['billing_last_name']) ? $post['billing_last_name'] : '',
        'companyName'      => isset($post['billing_company']) ? $post['billing_company'] : '',
        'address1'         => isset($post['billing_address_1']) ? $post['billing_address_1'] : '',
        'address2'         => isset($post['billing_address_2']) ? $post['billing_address_2'] : '',
        'city'             => isset($post['billing_city']) ? $post['billing_city'] : '',
        'stateOrProvince'  => isset($post['billing_state']) ? $post['billing_state'] : '',
        'postCode'         => isset($post['billing_postcode']) ? $post['billing_postcode'] : '',
        'country'          => isset($post['billing_country']) ? $post['billing_country'] : '',
        'phone'            => isset($post['billing_phone']) ? $post['billing_phone'] : '',
        'email'            => isset($post['billing_email']) ? $post['billing_email'] : '',
    ];

    $dataPayload['shippingAddress'] = [
        'contactFirstName' => isset($post['shipping_first_name']) && $post['shipping_first_name'] !== '' ? $post['shipping_first_name'] : (isset($post['billing_first_name']) ? $post['billing_first_name'] : ''),
        'contactLastName'  => isset($post['shipping_last_name']) && $post['shipping_last_name'] !== '' ? $post['shipping_last_name'] : (isset($post['billing_last_name']) ? $post['billing_last_name'] : ''),
        'companyName'      => isset($post['shipping_company']) && $post['shipping_company'] !== '' ? $post['shipping_company'] : (isset($post['billing_company']) ? $post['billing_company'] : ''),
        'address1'         => isset($post['shipping_address_1']) && $post['shipping_address_1'] !== '' ? $post['shipping_address_1'] : (isset($post['billing_address_1']) ? $post['billing_address_1'] : ''),
        'address2'         => isset($post['shipping_address_2']) && $post['shipping_address_2'] !== '' ? $post['shipping_address_2'] : (isset($post['billing_address_2']) ? $post['billing_address_2'] : ''),
        'city'             => isset($post['shipping_city']) && $post['shipping_city'] !== '' ? $post['shipping_city'] : (isset($post['billing_city']) ? $post['billing_city'] : ''),
        'stateOrProvince'  => isset($post['shipping_state']) && $post['shipping_state'] !== '' ? $post['shipping_state'] : (isset($post['billing_state']) ? $post['billing_state'] : ''),
        'postCode'         => isset($post['shipping_postcode']) && $post['shipping_postcode'] !== '' ? $post['shipping_postcode'] : (isset($post['billing_postcode']) ? $post['billing_postcode'] : ''),
        'country'          => isset($post['shipping_country']) && $post['shipping_country'] !== '' ? $post['shipping_country'] : (isset($post['billing_country']) ? $post['billing_country'] : ''),
        'phone'            => isset($post['shipping_phone']) && $post['shipping_phone'] !== '' ? $post['shipping_phone'] : (isset($post['billing_phone']) ? $post['billing_phone'] : ''),
        'email'            => isset($post['billing_email']) ? $post['billing_email'] : '',
    ];

    $dataPayload['lines'] = $itemsArray;
    $dataPayload['shippingAmount'] = round($cart->get_shipping_total(), 2);
    $dataPayload['discountAmount'] = round($cart->get_cart_discount_total(), 2);
    $dataPayload['taxAmount'] = round($totalTax, 2);

    if (!empty($paymentsArray)) {
        $dataPayload['payments'] = $paymentsArray;
    }
    if (!empty($applePayPayment)) {
        $dataPayload['applePayPayment'] = $applePayPayment;
    }

    return $dataPayload;
}

function process_versapay_sale_request($dataPayload)
{
    $subdomain = WC_Payment_Gateways::instance()->payment_gateways()['versapay']->subdomain;
    $sessionId = isset($_POST['versapay_session_key']) ? sanitize_text_field($_POST['versapay_session_key']) : '';
    $url = "https://" . $subdomain . ".versapay.com/api/v2/sessions/" . $sessionId . "/sales";

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($dataPayload, JSON_UNESCAPED_SLASHES));
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($curl);
    curl_close($curl);

    $response = json_decode($response, true);

    return $response;
}

add_action('woocommerce_thankyou', 'versapay_woocommerce_auto_complete_paid_order', 10, 1);
function versapay_woocommerce_auto_complete_paid_order($order_id)
{
    if (!$order_id) {
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    if ($order->get_payment_method() !== 'versapay') {
        return;
    }

    $versapayApprovalCode = $order->get_meta('versapay_approval_code', true);
    $versapayOrderId = $order->get_meta('versapay_orderid', true);

    $order->update_status('processing', __('Order processed via VersaPay.'));

    if ($versapayApprovalCode) {
        $note = __("Versapay Payments Approval Code: " . $versapayApprovalCode);
        $order->add_order_note($note);
        $order->set_transaction_id($versapayApprovalCode);
    }

    if ($versapayOrderId) {
        $note = __("Versapay Order Id: " . $versapayOrderId);
        $order->add_order_note($note);
    }

    $order->save();
}

//HPOS
add_filter('manage_woocommerce_page_wc-orders_columns', 'add_versapay_orderid_column', 20);
function add_versapay_orderid_column($columns)
{
    if (!isHPOS()) {
        return;
    }

    $new_columns = [];
    foreach ($columns as $column_name => $column_info) {
        $new_columns[$column_name] = $column_info;

        if ('order_total' === $column_name) {
            $new_columns['versapay_orderid'] = __('Versapay Order Id', 'my-textdomain');
        }
    }

    return $new_columns;
}
add_action('manage_woocommerce_page_wc-orders_custom_column', 'add_versapay_orderid_column_content', 10, 2);
function add_versapay_orderid_column_content($column, $post_id)
{
    if (!isHPOS()) {
        return;
    }

    if ('versapay_orderid' === $column) {
        $order = wc_get_order($post_id);
        $versapay_orderid = $order->get_meta('versapay_orderid', true);

        echo !empty($versapay_orderid) ? esc_html($versapay_orderid) : __('', 'my-textdomain');
    }
}

// WordPress posts storage (legacy)
add_filter('manage_edit-shop_order_columns', 'wc_versapay_orderid_column_header', 20);
function wc_versapay_orderid_column_header($columns)
{
    if (isHPOS()) {
        return;
    }

    $new_columns = array();
    foreach ($columns as $column_name => $column_info) {
        $new_columns[$column_name] = $column_info;

        if ('order_total' === $column_name) {
            $new_columns['versapay_orderid'] = __('Versapay Order Id', 'my-textdomain');
        }
    }

    return $new_columns;
}
add_action('manage_shop_order_posts_custom_column', 'wc_add_order_versapay_orderid_column_content');
function wc_add_order_versapay_orderid_column_content($column)
{
    if (isHPOS()) {
        return;
    }

    global $post;

    if ('versapay_orderid' === $column) {
        $order    = wc_get_order($post->ID);
        echo $order->get_meta('versapay_orderid');
    }
}

if (!function_exists('sv_helper_get_order_meta')) {
    /**
     * Helper function to get meta for an order.
     *
     * @param \WC_Order $order the order object
     * @param string $key the meta key
     * @param bool $single whether to get the meta as a single item. Defaults to `true`
     * @param string $context if 'view' then the value will be filtered
     * @return mixed the order property
     */
    function sv_helper_get_order_meta($order, $key = '', $single = true, $context = 'edit')
    {
        // WooCommerce > 3.0
        if (defined('WC_VERSION') && WC_VERSION && version_compare(WC_VERSION, '3.0', '>=')) {
            $value = $order->get_meta($key, $single, $context);
        } else {
            // have the $order->get_id() check here just in case the WC_VERSION isn't defined correctly
            $order_id = is_callable(array($order, 'get_id')) ? $order->get_id() : $order->id;
            $value = get_post_meta($order_id, $key, $single);
        }

        return $value;
    }
}

//show versapay wallet id
add_action('show_user_profile', 'versapay_walletid_user_profile_fields');
add_action('edit_user_profile', 'versapay_walletid_user_profile_fields');
function versapay_walletid_user_profile_fields($user)
{
?>
    <h3><?php _e("Versapay Wallet Id information", "blank"); ?></h3>

    <table class="form-table">
        <tr>
            <th><label for="versapay_walletid"><?php _e("Versapay Wallet Id"); ?></label></th>
            <td>
                <input type="text" name="versapay_walletid" id="versapay_walletid" value="<?php echo esc_attr(get_the_author_meta('versapay_walletid', $user->ID)); ?>" class="regular-text" /><br />
                <span class="description"><?php _e("Versapay Wallet Id."); ?></span>
            </td>
        </tr>
    </table>
<?php
}

add_action('personal_options_update', 'save_versapay_walletid_user_profile_fields');
add_action('edit_user_profile_update', 'save_versapay_walletid_user_profile_fields');
function save_versapay_walletid_user_profile_fields($user_id)
{
    if (empty($_POST['_wpnonce']) || ! wp_verify_nonce($_POST['_wpnonce'], 'update-user_' . $user_id)) {
        return;
    }

    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }
    update_user_meta($user_id, 'versapay_walletid', $_POST['versapay_walletid']);
}

function isHPOS()
{
    if (
        class_exists(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class) &&
        wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
    ) {
        return true;
    }
    return false;
}
