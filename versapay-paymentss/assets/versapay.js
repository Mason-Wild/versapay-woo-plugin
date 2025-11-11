window.addEventListener('load', function () {
    /*setTimeout(function(){ 

    jQuery("#place_order").hide();
    jQuery("#place_order").clone().attr("id","final_place_order").insertAfter("#place_order");
    jQuery("#final_place_order").attr("type","button").attr("disabled","disabled").show();
    }, 2000);*/

    jQuery('input[name="payment_method"]').change(function () {
        console.log("payment method changed");
        usingGateway();
    });
});
function usingGateway() {
    if (jQuery('form[name="checkout"] input[name="payment_method"]:checked').val() == 'versapay') {
        jQuery("#final_place_order").show();
        jQuery("#place_order").hide();
        //Etc etc
    } else {
        jQuery("#final_place_order").hide();
        jQuery("#place_order").show();
    }
}   