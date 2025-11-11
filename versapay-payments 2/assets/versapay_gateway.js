const errorMessage = "There was a problem processing your payment. Please check your billing address and payment details or use a different payment method.";
let cartTotalValue;
let paymentMethodSelect = false;
let client;

const isElementLoaded = async (selector) => {
    while (document.querySelector(selector) === null) {
		await new Promise((resolve) => requestAnimationFrame(resolve));
	}
	return document.querySelector(selector);
};

const getCartTotalValue = () => {
    const cartTotalElement = document.querySelector(".order-total .woocommerce-Price-amount bdi");
	return parseFloat(cartTotalElement.textContent.trim().replace(/[^0-9.]/g, ""));
};

const getSelectedPaymentMethod = () => {
	return jQuery('input[name="payment_method"]:checked').val();
};

const displayErrorMessage = () => {
	jQuery(".woocommerce-error").remove();

	let errorContainer = jQuery(".woocommerce-NoticeGroup.woocommerce-NoticeGroup-checkout");
	if (errorContainer.length === 0) {
		const checkoutForm = jQuery("form.checkout.woocommerce-checkout");

		if (checkoutForm.length) {
			errorContainer = jQuery('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"></div>');
			checkoutForm.prepend(errorContainer);
		}
	}

	const woocommerceError = document.createElement("ul");
	woocommerceError.classList.add("woocommerce-error");
	woocommerceError.setAttribute("role", "alert");

	const li = document.createElement("li");
	li.textContent = errorMessage;
	woocommerceError.appendChild(li);
	errorContainer[0].appendChild(woocommerceError);

	jQuery("html, body").animate(
		{
			scrollTop: jQuery(woocommerceError).offset().top - 100,
		},
		500
	);
};

const initVersapayPaymentMethod = () => {
    jQuery(".woocommerce-error").remove();
	cartTotalValue = getCartTotalValue();

    const styles = { form: { "margin-left": 0 } };

	const expressCheckoutConfig = JSON.parse(scriptParams.expressCheckoutConfig);

    if (client) {
		versapay.teardownClient(client);
	}
	client = versapay.initClient(scriptParams.sessionKey, styles, [], cartTotalValue, "", expressCheckoutConfig);

    const docWidth = Math.min(500, document.documentElement.clientWidth);
	const docWidthMod = docWidth < 500 ? 0.8 : 1;
	const docHeight = 500;

    const setupVersapayClient = () => {
		isElementLoaded("#versapay-container").then((selector) => {
			if (jQuery("#final_place_order").length === 0) {
				jQuery("#place_order").hide();
				jQuery("#place_order").clone().attr("id", "final_place_order").insertAfter("#place_order");
				jQuery("#final_place_order").attr("type", "button").attr("disabled", "disabled").show();
			}

			if (paymentMethodSelect) {
				paymentMethodSelect = false;
			}

			let clientOnApprovalFirstRun = true;
			let submitOrderRun = false;

			let frameReadyPromise = client.initFrame(selector, `${docHeight}px`, `${docWidth - docWidthMod}px`);
			frameReadyPromise.then(function () {
				let differentPaymentMethodUsed;
				jQuery("#versapay_error").val("");
				jQuery("#final_place_order").removeAttr("disabled");

				jQuery(document).on("click", "#place_order", function (event) {
					submitOrder();
				});
				jQuery(document).on("click", "#final_place_order", function (event) {
					submitOrder();
				});

				const submitOrder = () => {
					if (differentPaymentMethodUsed) {
						return;
					}
					const selectedPaymentMethod = getSelectedPaymentMethod();
					if (selectedPaymentMethod !== "versapay") {
						differentPaymentMethodUsed = true;
						jQuery("#place_order").click();
						return;
					}
					if (submitOrderRun) {
						return;
					}
					submitOrderRun = true;
					if (clientOnApprovalFirstRun) {
						jQuery("#final_place_order").attr("disabled", true);
						event.preventDefault();
						client.submitEvents();
					}
				};

				client.onPartialPayment(
					(result) => {
						submitOrderRun = false;
						jQuery("#final_place_order").removeAttr("disabled");
					},
					(error) => {
						submitOrderRun = false;
						jQuery("#versapay_error").val(errorMessage);
						jQuery("#final_place_order").removeAttr("disabled");
						jQuery("#place_order").click();
					}
				);

				client.onApproval(
					(result) => {
						if (clientOnApprovalFirstRun) {
							clientOnApprovalFirstRun = false;
							jQuery("#versapay_error").val("");

							let payments = [];
							let expressCheckoutPayment = [];

							if (result.payment) {
								expressCheckout = true;
								expressCheckoutPayment.push({
									payment_type: result.paymentTypeName,
									payment: result.payment,
									amount: result.amount,
								});
							} else {
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

							jQuery("#versapay_session_key").val(scriptParams.sessionKey);
							jQuery("#versapay_payments").val(JSON.stringify(payments));
							jQuery("#versapay_express_checkout_payment").val(JSON.stringify(expressCheckoutPayment));
							jQuery("#place_order").click();
							jQuery("#final_place_order").removeAttr("disabled");
						}
					},
					(error) => {
						clientOnApprovalFirstRun = true;
						submitOrderRun = false;
						displayErrorMessage();
						jQuery("#final_place_order").removeAttr("disabled");
					}
				);
			});
		});
	};

    if (paymentMethodSelect) {
		jQuery("body").trigger("update_checkout");
	}

    jQuery("body").on("updated_checkout", () => {
		setupVersapayClient();
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
	if (selectedPaymentMethod === "versapay") {
		paymentMethodSelect = true;
		cartTotalValue = getCartTotalValue();
		initVersapayPaymentMethod();
	}
};

jQuery("body").on("updated_checkout", onCheckoutUpdated);
jQuery("body").on("updated_cart_totals", onCheckoutUpdated);
jQuery("body").on("change", 'input[name="payment_method"]', onPaymentMethodSelect);

jQuery(document).ready(() => {
    cartTotalValue = getCartTotalValue();
	onPaymentMethodSelect();
});

