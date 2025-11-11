<?php
add_filter('woocommerce_gateway_description', 'versapay_description_fields', 20, 2);

function getVSessionKey()
{
    $payment_gateways = WC_Payment_Gateways::instance();
    // Get the desired WC_Payment_Gateway object
    $payment_gateway = $payment_gateways->payment_gateways()['versapay'];

    $gateway = $payment_gateway->gateway;
    $email = $payment_gateway->email_id;
    $password = $payment_gateway->password;
    $account = $payment_gateway->account;
    $subdomain = versapay_get_subdomain($payment_gateway);
    $host = versapay_get_api_host($payment_gateway);

    $cc_enabled = $payment_gateway->cc_enabled;
    $ach_enabled = $payment_gateway->ach_enabled;
    $gc_enabled = $payment_gateway->gc_enabled;
    $apiKey = $payment_gateway->api_key;
    $apiToken = $payment_gateway->api_token;
    $avsRules = $payment_gateway->avs_rules;
    $savePaymentMethodByDefault = $payment_gateway->save_payment_method_by_default > 0;
    $applePayEnabled = $payment_gateway->apple_pay_merchant_identifier != '';

    $params = [];
    if ($apiToken && $apiKey) {
        $customerId = get_current_user_id();

        $walletId = false;
        if (is_user_logged_in()) {
            $versapay_walletid = get_user_meta($customerId, "versapay_walletid");
            if (!$versapay_walletid[0]) {
                $url = 'https://' . $host . '/api/v2/wallets';
                $wparams = [];
                $wparams['gatewayAuthorization']['apiToken'] = $apiToken;
                $wparams['gatewayAuthorization']['apiKey'] = $apiKey;
                $curl = curl_init($url);
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($wparams, JSON_UNESCAPED_SLASHES));
                curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));

                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                $response = curl_exec($curl);
                curl_close($curl);
                $walletId = json_decode($response, true); 
                $walletId = $walletId['walletId'];
                update_user_meta($customerId, 'versapay_walletid', $walletId);
            } else {
                $walletId = $versapay_walletid[0];  
            }          
        }

        $params['gatewayAuthorization']['apiToken'] = $apiToken;
        $params['gatewayAuthorization']['apiKey'] = $apiKey;

        $cart = WC()->cart;
        $params['options']['orderTotal'] = $cart->get_total();

        if ($walletId) {
            $params['options']['wallet'] = [
            'id' => $walletId,
            'allowAdd' => true,
            'allowEdit' => true,
            'allowDelete' => true,
            'saveByDefault' => $savePaymentMethodByDefault
            ];
        }

        foreach (['rejectAddressMismatch', 'rejectPostCodeMismatch', 'rejectUnknown'] as $avsRule) {
            if ($avsRules && in_array($avsRule, $avsRules)) {
                $params['options']['avsRules'][$avsRule] = true;
            } else {
                $params['options']['avsRules'][$avsRule] = false;
            }
        }

        if ($cc_enabled) {
            $params['options']['paymentTypes'][] = [
            'name' => "creditCard",
            "promoted" => false,
            "label" => "Payment Card",
            "fields" => [
                [
                "name" => "cardholderName",
                "label" => "Cardholder Name",
                "errorLabel" => "Cardholder name"
                ],
                [
                "name" => "accountNo",
                "label" => "Account Number",
                "errorLabel" => "Credit card number"
                ],
                [
                "name" => "expDate",
                "label" => "Expiration Date",
                "errorLabel" => "Expiration date"
                ],
                [
                "name" => "cvv",
                "label" => "Security code",
                "allowLabelUpdate" => false,
                "errorLabel" => "Security code"
                ]
            ]
    
            ];
        }
        
        if ($ach_enabled) {
            $params['options']['paymentTypes'][] = [
            'name' => "ach",
            "promoted" => false,
            "label" => "Bank Account",
            "fields" => [
                [
                "name" => "accountType",
                "label" => "Account Type",
                "errorLabel" => "Account type"
                ],
                [
                "name" => "checkType",
                "label" => "Check Type",
                "errorLabel" => "Check type"
                ],
                [
                "name" => "accountHolder",
                "label" => "Account Holder",
                "errorLabel" => "Account holder name"
                ],
                [
                "name" => "routingNo",
                "label" => "Routing Number",
                "errorLabel" => "Routing number"
                ],
                [
                "name" => "achAccountNo",
                "label" => "Account Number",
                "errorLabel" => "Bank account number"
                ]
            ]
            ];
        }

        if ($gc_enabled) {
            $params['options']['paymentTypes'][] = [
            'name' => "giftCard",
            "promoted" => false,
            "label" => "Gift Card",
            "fields" => [
                [
                "name" => "gcAccountNo",
                "label" => "Account Number",
                "errorLabel" => "Gift card number"
                ],
                [
                "name" => "expDate",
                "label" => "Expiration Date",
                "errorLabel" => "Expiration date"
                ],
                [
                "name" => "pin",
                "label" => "PIN",
                "errorLabel" => "PIN"
                ]
            ]
            ];
        }

        if ($applePayEnabled) {
            $params['options']['paymentTypes'][] = [
                'name' => "applePay",
                "promoted" => true,
                "label" => "ApplePay",
            ];
        }
    } else {          
        $params['gatewayAuthorization']['gateway'] = $gateway;
        $params['gatewayAuthorization']['email'] = $email;
        $params['gatewayAuthorization']['password'] = $password;
        $params['gatewayAuthorization']['accounts'][]= ['type' => "creditCard",'account' => $account];

        $params['options']['fields'][] = ['name' =>  "cardholderName", 
                                        'label' => "Cardholder Name",
                                        'errorLabel' => "Cardholder name" ];

        $params['options']['fields'][] = ['name' =>  "accountNo", 
                                        'label' => "Account Number",
                                        'errorLabel' => "Credit card number"];

        $params['options']['fields'][] = ['name' =>  "expDate", 
                                        'label' => "Expiration Date",
                                        'errorLabel' => "Please check the Expiration Date"];

        $params['options']['fields'][] = ['name' =>  "cvv", 
                                        'label' => "Security code",
                                        'errorLabel' => "Enter Security Code",
                                        'allowLabelUpdate' => false];
    }
            
    $curl = curl_init('https://' . $host . '/api/v2/sessions');
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($params, JSON_UNESCAPED_SLASHES));
    curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));

    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true); 
}

function getExpressCheckoutConfig()
{
    $payment_gateways = WC_Payment_Gateways::instance();
    $payment_gateway = $payment_gateways->payment_gateways()['versapay'];
    $subdomain = versapay_get_subdomain($payment_gateway);

    $paymentMethods = [];

    if (!empty($payment_gateway->apple_pay_merchant_identifier)) {
        $paymentMethods[] = "applePay";
    }

    $expressCheckoutConfig = [
        "paymentMethods" => $paymentMethods
    ];
    if (!empty($payment_gateway->apple_pay_merchant_identifier)) {
        $expressCheckoutConfig["applePay"] = [
            "merchantIdentifier" => $payment_gateway->apple_pay_merchant_identifier,
            "displayName" => $payment_gateway->apple_pay_display_name,
            "initiativeContext" => $payment_gateway->apple_pay_initiative_context,
            "merchantCapabilities" => ["supports3DS"],
            "supportedNetworks" => ["amex", "masterCard", "visa"],
            "ecommSubdomain" => $subdomain,
            "countryCode" => "US",
            "currencyCode" => "USD",
        ];
    }

    return $expressCheckoutConfig;
}

function versapay_description_fields($description, $payment_id)
{
    if ('versapay' !== $payment_id) {
        return $description;
    }
    
    $sessionKey = getVSessionKey();
    $sessionKey = (is_array($sessionKey) && isset($sessionKey['id'])) ? $sessionKey['id'] : '';

    ob_start();
    echo '<div id="versapay-container" style="height:360px; width:500px"></div>
    <input type="hidden" name="versapay_session_key" id="versapay_session_key" value="'.$sessionKey. '" />
    <input type="hidden" name="versapay_error" id="versapay_error" />
    <input type="hidden" name="versapay_payments" id="versapay_payments"/>
    <input type="hidden" name="versapay_express_checkout_payment" id="versapay_express_checkout_payment"/>';
        
    $versapayPluginURL = plugins_url('/versapay-payments/assets/versapay_gateway.js');
    if (is_ssl()) {
        $versapayPluginURL = str_replace('http://', 'https://', $versapayPluginURL);
    }

    $cart = WC()->cart;

    // Depend on both WooCommerce checkout assets and the VersaPay SDK to guarantee availability.
    wp_enqueue_script('versapay_gateway', $versapayPluginURL, array('jquery', 'wc-checkout', 'versapay-sdk'), '1.0', true);
    wp_localize_script('versapay_gateway', 'scriptParams', array(
        'sessionKey' => $sessionKey,
        'expressCheckoutConfig' => getExpressCheckoutConfig()
    ));

    $description .= ob_get_clean();

    return $description;    
}
