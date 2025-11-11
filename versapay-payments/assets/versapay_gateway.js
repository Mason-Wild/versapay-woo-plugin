const errorMessage = "There was a problem processing your payment. Please check your billing address and payment details or use a different payment method.";
let cartTotalValue;
let paymentMethodSelect = false;
let client = null;
let expressCheckout = false;

const isElementLoaded = async (selector) => {
    while (document.querySelector(selector) === null) {
        // eslint-disable-next-line no-await-in-loop
        await new Promise((resolve) => requestAnimationFrame(resolve));
    }
    return document.querySelector(selector);
};

const getCartTotalValue = () => {
    const cartTotalElement = document.querySelector('.order-total .woocommerce-Price-amount bdi');
    if (!cartTotalElement) {
        return 0;
    }
    const value = parseFloat(cartTotalElement.textContent.trim().replace(/[^0-9.-]/g, ''));
    return Number.isNaN(value) ? 0 : value;
};

const getSelectedPaymentMethod = () => {
    return jQuery('input[name="payment_method"]:checked').val();
};

const displayErrorMessage = () => {
    jQuery('.woocommerce-error').remove();

    let errorContainer = jQuery('.woocommerce-NoticeGroup.woocommerce-NoticeGroup-checkout');
    if (errorContainer.length === 0) {
        const checkoutForm = jQuery('form.checkout.woocommerce-checkout');

        if (checkoutForm.length) {
            errorContainer = jQuery('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"></div>');
            checkoutForm.prepend(errorContainer);
        }
    }

    const woocommerceError = document.createElement('ul');
    woocommerceError.classList.add('woocommerce-error');
    woocommerceError.setAttribute('role', 'alert');

    const li = document.createElement('li');
    li.textContent = errorMessage;
    woocommerceError.appendChild(li);
    errorContainer[0].appendChild(woocommerceError);

    jQuery('html, body').animate(
        {
            scrollTop: jQuery(woocommerceError).offset().top - 100,
        },
        500
    );
};

const waitForSDK = (max = 25) =>
    new Promise((resolve, reject) => {
        let tries = 0;
        (function poll() {
            if (window.versapay && typeof window.versapay.initClient === 'function') {
                resolve();
                return;
            }
            tries += 1;
            if (tries >= max) {
                reject(new Error('VersaPay SDK not available'));
                return;
            }
            setTimeout(poll, 200);
        })();
    });

const initVersapayPaymentMethod = () => {
    jQuery('.woocommerce-error').remove();
    cartTotalValue = getCartTotalValue();

    waitForSDK()
        .then(() => {
            if (!window.scriptParams || !window.scriptParams.sessionKey) {
                throw new Error('VersaPay session key is missing');
            }

            expressCheckout = false;

            const styles = { form: { 'margin-left': 0 } };
            let expressCheckoutConfig = {};

            try {
                expressCheckoutConfig = JSON.parse(window.scriptParams.expressCheckoutConfig || '{}');
            } catch (configError) {
                console.error('VersaPay express checkout configuration parse error', configError);
            }

            if (client && typeof window.versapay.teardownClient === 'function') {
                window.versapay.teardownClient(client);
            }

            client = window.versapay.initClient(window.scriptParams.sessionKey, styles, [], cartTotalValue, '', expressCheckoutConfig);

            const docWidth = Math.min(500, document.documentElement.clientWidth);
            const docWidthMod = docWidth < 500 ? 0.8 : 1;
            const docHeight = 500;

            const setupVersapayClient = () => {
                if (!client) {
                    return;
                }

                isElementLoaded('#versapay-container')
                    .then((selector) => {
                        if (jQuery('#final_place_order').length === 0) {
                            jQuery('#place_order').hide();
                            jQuery('#place_order').clone().attr('id', 'final_place_order').insertAfter('#place_order');
                            jQuery('#final_place_order').attr('type', 'button').attr('disabled', 'disabled').show();
                        }

                        if (paymentMethodSelect) {
                            paymentMethodSelect = false;
                        }

                        let clientOnApprovalFirstRun = true;
                        let submitOrderRun = false;

                        const frameReadyPromise = client.initFrame(selector, `${docHeight}px`, `${docWidth - docWidthMod}px`);

                        frameReadyPromise
                            .then(() => {
                                let differentPaymentMethodUsed;
                                const submitOrder = (evt) => {
                                    if (differentPaymentMethodUsed) {
                                        return;
                                    }

                                    const selectedPaymentMethod = getSelectedPaymentMethod();
                                    if (selectedPaymentMethod !== 'versapay') {
                                        differentPaymentMethodUsed = true;
                                        jQuery('#place_order').trigger('click');
                                        return;
                                    }

                                    if (submitOrderRun) {
                                        return;
                                    }
                                    submitOrderRun = true;

                                    if (clientOnApprovalFirstRun) {
                                        jQuery('#final_place_order').attr('disabled', true);
                                        if (evt && typeof evt.preventDefault === 'function') {
                                            evt.preventDefault();
                                        }
                                        client.submitEvents();
                                    }
                                };

                                jQuery('#versapay_error').val('');
                                jQuery('#final_place_order').removeAttr('disabled');

                                jQuery(document)
                                    .off('click.versapayPlaceOrder', '#place_order')
                                    .on('click.versapayPlaceOrder', '#place_order', (event) => {
                                        submitOrder(event);
                                    });

                                jQuery(document)
                                    .off('click.versapayFinalPlaceOrder', '#final_place_order')
                                    .on('click.versapayFinalPlaceOrder', '#final_place_order', (event) => {
                                        submitOrder(event);
                                    });

                                client.onPartialPayment(
                                    () => {
                                        submitOrderRun = false;
                                        jQuery('#final_place_order').removeAttr('disabled');
                                    },
                                    () => {
                                        submitOrderRun = false;
                                        jQuery('#versapay_error').val(errorMessage);
                                        jQuery('#final_place_order').removeAttr('disabled');
                                        jQuery('#place_order').trigger('click');
                                    }
                                );

                                client.onApproval(
                                    (result) => {
                                        if (!clientOnApprovalFirstRun) {
                                            return;
                                        }
                                        clientOnApprovalFirstRun = false;
                                        jQuery('#versapay_error').val('');

                                        let payments = [];
                                        let expressCheckoutPayment = [];

                                        if (result.payment) {
                                            expressCheckout = true;
                                            expressCheckoutPayment.push({
                                                payment_type: result.paymentTypeName,
                                                payment: result.payment,
                                                amount: result.amount,
                                            });
                                        } else if (Array.isArray(result.partialPayments)) {
                                            payments = result.partialPayments.map((partialPayment) => ({
                                                token: partialPayment.token,
                                                payment_type: partialPayment.paymentTypeName,
                                                amount: partialPayment.amount ?? 0.0,
                                            }));
                                            payments.push({
                                                token: result.token,
                                                payment_type: result.paymentTypeName,
                                                amount: result.amount ?? 0.0,
                                            });
                                        }

                                        jQuery('#versapay_session_key').val(window.scriptParams.sessionKey);
                                        jQuery('#versapay_payments').val(JSON.stringify(payments));
                                        jQuery('#versapay_express_checkout_payment').val(JSON.stringify(expressCheckoutPayment));
                                        jQuery('#place_order').trigger('click');
                                        jQuery('#final_place_order').removeAttr('disabled');
                                    },
                                    () => {
                                        clientOnApprovalFirstRun = true;
                                        submitOrderRun = false;
                                        displayErrorMessage();
                                        jQuery('#final_place_order').removeAttr('disabled');
                                    }
                                );
                            })
                            .catch((frameError) => {
                                console.error(frameError);
                                displayErrorMessage();
                            });
                    })
                    .catch((containerError) => {
                        console.error(containerError);
                        displayErrorMessage();
                    });
            };

            setupVersapayClient();
            jQuery('body')
                .off('updated_checkout.versapayFrame')
                .on('updated_checkout.versapayFrame', () => {
                    setupVersapayClient();
                });
        })
        .catch((error) => {
            console.error(error);
            displayErrorMessage();
        });
};

const debounce = (func, wait) => {
    let timeout;
    return (...args) => {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
};

const onCheckoutUpdated = debounce(() => {
    const newCartTotalValue = getCartTotalValue();
    if (cartTotalValue !== newCartTotalValue) {
        initVersapayPaymentMethod();
    }
}, 300);

const onPaymentMethodSelect = () => {
    const selectedPaymentMethod = getSelectedPaymentMethod();
    if (selectedPaymentMethod === 'versapay') {
        paymentMethodSelect = true;
        cartTotalValue = getCartTotalValue();
        initVersapayPaymentMethod();
    }
};

jQuery('body').on('updated_checkout', onCheckoutUpdated);
jQuery('body').on('updated_cart_totals', onCheckoutUpdated);
jQuery('body').on('change', 'input[name="payment_method"]', onPaymentMethodSelect);

jQuery(document).ready(() => {
    cartTotalValue = getCartTotalValue();
    onPaymentMethodSelect();
});
