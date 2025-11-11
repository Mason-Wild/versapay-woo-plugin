<?php
add_filter('woocommerce_gateway_description', 'versapay_description_fields', 20, 2);

/**
 * Retrieve the configured VersaPay gateway instance.
 *
 * @return WC_Gateway_Versapay|null
 */
function vp_get_versapay_gateway_instance() {
    if (!class_exists('WC_Payment_Gateways')) {
        return null;
    }

    $payment_gateways = WC_Payment_Gateways::instance()->payment_gateways();

    return isset($payment_gateways['versapay']) ? $payment_gateways['versapay'] : null;
}

/**
 * Retrieve or create a VersaPay wallet for the current user.
 *
 * @param string $base_url Base URL for the VersaPay e-commerce API.
 * @param string $api_token API token.
 * @param string $api_key API key.
 * @return string|false
 */
function vp_get_or_create_wallet_id($base_url, $api_token, $api_key) {
    $customer_id = get_current_user_id();

    if (!$customer_id) {
        return false;
    }

    $wallet_id = get_user_meta($customer_id, 'versapay_walletid', true);
    if (!empty($wallet_id)) {
        return $wallet_id;
    }

    $url = trailingslashit($base_url) . 'wallets';
    $response = wp_remote_post(
        $url,
        array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'body' => wp_json_encode(
                array(
                    'gatewayAuthorization' => array(
                        'apiToken' => $api_token,
                        'apiKey' => $api_key,
                    ),
                )
            ),
        )
    );

    if (is_wp_error($response)) {
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $wallet_id = isset($body['walletId']) ? $body['walletId'] : '';

    if (!empty($wallet_id)) {
        update_user_meta($customer_id, 'versapay_walletid', sanitize_text_field($wallet_id));
        return $wallet_id;
    }

    return false;
}

/**
 * Build the VersaPay session options payload.
 *
 * @param WC_Gateway_Versapay $gateway Configured gateway instance.
 * @param string              $base_url VersaPay base URL.
 * @param string              $api_token API token.
 * @param string              $api_key API key.
 * @return array
 */
function vp_build_session_options($gateway, $base_url, $api_token, $api_key) {
    $options = array();

    $save_payment_method_by_default = !empty($gateway->save_payment_method_by_default);

    if (!empty($api_token) && !empty($api_key)) {
        $wallet_id = vp_get_or_create_wallet_id($base_url, $api_token, $api_key);

        if ($wallet_id) {
            $options['wallet'] = array(
                'id' => $wallet_id,
                'allowAdd' => true,
                'allowEdit' => true,
                'allowDelete' => true,
                'saveByDefault' => $save_payment_method_by_default,
            );
        }
    }

    $avs_rules = array('rejectAddressMismatch', 'rejectPostCodeMismatch', 'rejectUnknown');
    foreach ($avs_rules as $rule) {
        $options['avsRules'][$rule] = $gateway->avs_rules && in_array($rule, $gateway->avs_rules, true);
    }

    $payment_types = array();

    if (!empty($gateway->cc_enabled)) {
        $payment_types[] = array(
            'name' => 'creditCard',
            'promoted' => false,
            'label' => 'Payment Card',
            'fields' => array(
                array(
                    'name' => 'cardholderName',
                    'label' => 'Cardholder Name',
                    'errorLabel' => 'Cardholder name',
                ),
                array(
                    'name' => 'accountNo',
                    'label' => 'Account Number',
                    'errorLabel' => 'Credit card number',
                ),
                array(
                    'name' => 'expDate',
                    'label' => 'Expiration Date',
                    'errorLabel' => 'Expiration date',
                ),
                array(
                    'name' => 'cvv',
                    'label' => 'Security code',
                    'allowLabelUpdate' => false,
                    'errorLabel' => 'Security code',
                ),
            ),
        );
    }

    if (!empty($gateway->ach_enabled)) {
        $payment_types[] = array(
            'name' => 'ach',
            'promoted' => false,
            'label' => 'Bank Account',
            'fields' => array(
                array(
                    'name' => 'accountType',
                    'label' => 'Account Type',
                    'errorLabel' => 'Account type',
                ),
                array(
                    'name' => 'checkType',
                    'label' => 'Check Type',
                    'errorLabel' => 'Check type',
                ),
                array(
                    'name' => 'accountHolder',
                    'label' => 'Account Holder',
                    'errorLabel' => 'Account holder name',
                ),
                array(
                    'name' => 'routingNo',
                    'label' => 'Routing Number',
                    'errorLabel' => 'Routing number',
                ),
                array(
                    'name' => 'achAccountNo',
                    'label' => 'Account Number',
                    'errorLabel' => 'Bank account number',
                ),
            ),
        );
    }

    if (!empty($gateway->gc_enabled)) {
        $payment_types[] = array(
            'name' => 'giftCard',
            'promoted' => false,
            'label' => 'Gift Card',
            'fields' => array(
                array(
                    'name' => 'gcAccountNo',
                    'label' => 'Account Number',
                    'errorLabel' => 'Gift card number',
                ),
                array(
                    'name' => 'expDate',
                    'label' => 'Expiration Date',
                    'errorLabel' => 'Expiration date',
                ),
                array(
                    'name' => 'pin',
                    'label' => 'PIN',
                    'errorLabel' => 'PIN',
                ),
            ),
        );
    }

    if (!empty($payment_types)) {
        $options['paymentTypes'] = $payment_types;
    }

    return $options;
}

/**
 * Create a VersaPay checkout session.
 *
 * @param float  $amount Order total.
 * @param string $currency Currency code.
 * @param string $token API token.
 * @param string $key API key.
 * @param string $base_url Base URL.
 * @param array  $additional_options Additional options for the payload.
 * @return array|WP_Error
 */
function vp_create_versapay_session($amount, $currency, $token, $key, $base_url, $additional_options = array()) {
    $url = trailingslashit($base_url) . 'sessions';
    $body = array(
        'gatewayAuthorization' => array(
            'apiToken' => $token,
            'apiKey' => $key,
        ),
        'options' => array_merge(
            array(
                'orderTotal' => (float) $amount,
                'currency' => strtoupper($currency),
            ),
            $additional_options
        ),
    );

    $response = wp_remote_post(
        $url,
        array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'body' => wp_json_encode($body),
        )
    );

    if (is_wp_error($response)) {
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $json = json_decode(wp_remote_retrieve_body($response), true);

    if (in_array($status_code, array(200, 201), true) && is_array($json)) {
        $session_id = '';
        if (isset($json['id']) && $json['id']) {
            $session_id = $json['id'];
        } elseif (isset($json['sessionId']) && $json['sessionId']) {
            $session_id = $json['sessionId'];
        } elseif (isset($json['sessionKey']) && $json['sessionKey']) {
            $session_id = $json['sessionKey'];
        }

        if ($session_id) {
            return array(
                'sessionId' => $session_id,
                'raw' => $json,
            );
        }
    }

    return new WP_Error(
        'versapay_session_failed',
        __('VersaPay session creation failed', 'versapay-payments-woo'),
        array(
            'code' => $status_code,
            'body' => $json,
        )
    );
}

/**
 * Build express checkout configuration for localization.
 *
 * @param WC_Gateway_Versapay $gateway Gateway instance.
 * @param string              $base_url VersaPay base URL.
 * @return array
 */
function vp_get_express_checkout_config($gateway, $base_url) {
    $config = array(
        'paymentMethods' => array(),
    );

    if (!empty($gateway->apple_pay_merchant_identifier)) {
        $config['paymentMethods'][] = 'applePay';

        $host = wp_parse_url($base_url, PHP_URL_HOST);
        $config['applePay'] = array(
            'merchantIdentifier' => $gateway->apple_pay_merchant_identifier,
            'displayName' => $gateway->apple_pay_display_name,
            'initiativeContext' => $gateway->apple_pay_initiative_context,
            'merchantCapabilities' => array('supports3DS'),
            'supportedNetworks' => array('amex', 'masterCard', 'visa'),
            'ecommSubdomain' => $host,
            'countryCode' => 'US',
            'currencyCode' => strtoupper(get_woocommerce_currency()),
        );
    }

    return $config;
}

/**
 * Filter the gateway description to inject the VersaPay container and scripts.
 *
 * @param string $description Gateway description.
 * @param string $payment_id Payment method ID.
 * @return string
 */
function versapay_description_fields($description, $payment_id)
{
    if ('versapay' !== $payment_id) {
        return $description;
    }

    $gateway = vp_get_versapay_gateway_instance();
    if (!$gateway) {
        return $description;
    }

    $api_token = trim((string) $gateway->get_option('api_token', ''));
    $api_key = trim((string) $gateway->get_option('api_key', ''));
    $base_url = method_exists($gateway, 'get_ecom_base_url') ? $gateway->get_ecom_base_url() : '';

    $cart_total = WC()->cart ? (float) WC()->cart->get_total('edit') : 0;
    $currency = get_woocommerce_currency();

    $session_key = '';
    $session = null;

    if ($api_token && $api_key && $base_url) {
        $session_options = vp_build_session_options($gateway, $base_url, $api_token, $api_key);
        $session = vp_create_versapay_session($cart_total, $currency, $api_token, $api_key, $base_url, $session_options);
    }

    if ($session instanceof WP_Error) {
        $logger = wc_get_logger();
        $logger->error(
            'VersaPay session creation failed',
            array(
                'source' => 'versapay',
                'data' => $session->get_error_data(),
                'error' => $session->get_error_message(),
            )
        );
    } elseif (is_array($session) && isset($session['sessionId'])) {
        $session_key = $session['sessionId'];
    }

    $express_checkout_config = vp_get_express_checkout_config($gateway, $base_url);

    $versapay_plugin_url = plugins_url('/versapay-payments/assets/versapay_gateway.js');
    if (is_ssl()) {
        $versapay_plugin_url = str_replace('http://', 'https://', $versapay_plugin_url);
    }

    wp_enqueue_script(
        'versapay_gateway',
        $versapay_plugin_url,
        array('jquery', 'wc-checkout', 'versapay-sdk'),
        '1.1.0',
        true
    );

    wp_localize_script(
        'versapay_gateway',
        'scriptParams',
        array(
            'sessionKey' => $session_key,
            'ecomBaseURL' => $base_url,
            'expressCheckoutConfig' => wp_json_encode($express_checkout_config),
        )
    );

    ob_start();
    ?>
    <div id="versapay-container" style="height:360px; width:500px"></div>
    <input type="hidden" name="versapay_session_key" id="versapay_session_key" value="<?php echo esc_attr($session_key); ?>" />
    <input type="hidden" name="versapay_error" id="versapay_error" />
    <input type="hidden" name="versapay_payments" id="versapay_payments" />
    <input type="hidden" name="versapay_express_checkout_payment" id="versapay_express_checkout_payment" />
    <?php

    $description .= ob_get_clean();

    return $description;
}
