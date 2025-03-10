define([
    'jquery',
    'mage/utils/wrapper'
], function ($, wrapper) {
    'use strict';
    return function (quote) {
        quote.setTotals = wrapper.wrapSuper(quote.setTotals, function (data){
            this._super(data);
            var grandTotal = 0;
            let totalSegments = data.total_segments;
            totalSegments.forEach(function(obj) {
                if (obj.code == 'grand_total') {
                    grandTotal = obj.value;
                }
            });

            if (typeof window.checkoutConfig != "undefined") {

                //re-render widget on cart page after update cart amount
                if ($('.tamara-promo-widget-wrapper.tamara-product-page').length) {
                    if (window.TamaraProductWidget) {
                        $(".tamara-promo-widget-wrapper.tamara-product-page  .tamara-product-widget").attr("data-price", grandTotal);
                        window.TamaraProductWidget.render();
                    }
                    if (window.TamaraWidgetV2) {
                        $(".tamara-promo-widget-wrapper.tamara-product-page  tamara-widget").attr("amount", grandTotal);
                        window.TamaraWidgetV2.refresh();
                    }
                }

                //render installment plan widget on checkout page
                if ($('.tamara-page-type-cart').length == 0) {
                    var countExistTamaraInstallmentsPlan = 0;
                    var existTamaraInstallmentsPlan = setInterval(function() {
                        if ($('.tamara-installment-plan-widget').length) {
                            if (window.TamaraInstallmentPlan) {
                                $('.tamara-installment-plan-widget').empty();
                                $( ".tamara-installment-plan-widget" ).each(function() {
                                    $(this).attr('data-price', grandTotal);
                                });
                                window.TamaraInstallmentPlan.init({ lang: window.checkoutConfig.payment.tamara.language, currency: window.checkoutConfig.payment.tamara.currency_code, publicKey: window.checkoutConfig.payment.tamara.public_key});
                                window.TamaraInstallmentPlan.render();
                                clearInterval(existTamaraInstallmentsPlan);
                            }
                        }

                        if (++countExistTamaraInstallmentsPlan > 33) {
                            clearInterval(existTamaraInstallmentsPlan);
                        }
                    }, 300);

                    //render widget v2
                    window.tamaraWidgetConfig = {
                        "country" : window.checkoutConfig.payment.tamara.country_code,
                        "lang": window.checkoutConfig.payment.tamara.language,
                        "publicKey": window.checkoutConfig.payment.tamara.public_key
                    }
                    var countExistTamaraWidgetV2 = 0;
                    var existTamaraWidgetV2 = setInterval(function() {
                        if ($('.tamara-promo-widget-wrapper.tamara-checkout-page.tamara-v2').length) {
                            $('.tamara-promo-widget-wrapper.tamara-checkout-page.tamara-v2').empty();

                            //append the widget html
                            let widgetHtml = '<tamara-widget amount="' + grandTotal + '" inline-type="6" config=\'{"badgePosition":"","showExtraContent":"full","hidePayInX":false}\'></tamara-widget>';
                            $( ".tamara-promo-widget-wrapper.tamara-checkout-page.tamara-v2" ).each(function() {
                                $(this).append(widgetHtml);
                            });
                            if (window.TamaraWidgetV2) {
                                window.TamaraWidgetV2.refresh();
                            }
                            clearInterval(existTamaraWidgetV2);
                        }
                        if (++countExistTamaraWidgetV2 > 33) {
                            clearInterval(existTamaraWidgetV2);
                        }
                    }, 300);
                }

                //show right widget on the checkout page
                if (window.checkoutConfig.payment.tamara.widget_version == 'mixed') {
                    var paymentTypes = window.checkoutConfig.payment.tamara.payment_types;
                    var numberOfAvailableTypes = 0;
                    for(var type in paymentTypes) {
                        if (paymentTypes[type].min_limit <= data.grand_total && paymentTypes[type].max_limit >= data.grand_total ) {
                            numberOfAvailableTypes++;
                        }
                    }
                    if (numberOfAvailableTypes > 1) {
                        var countExistWidgetWrapperV1 = 0;
                        var existWidgetWrapperV1 = setInterval(function() {
                            if ($('.tamara-promo-widget-wrapper.tamara-checkout-page.tamara-v1').length) {
                                $('.tamara-promo-widget-wrapper.tamara-checkout-page.tamara-v1').show();
                                clearInterval(existWidgetWrapperV1);
                            }
                            if (++countExistWidgetWrapperV1 > 33) {
                                clearInterval(existWidgetWrapperV1);
                            }
                        }, 300);
                    } else {
                        var countExistWidgetWrapperV2 = 0;
                        var existWidgetWrapperV2 = setInterval(function() {
                            if ($('.tamara-promo-widget-wrapper.tamara-checkout-page.tamara-v2').length) {
                                $('.tamara-promo-widget-wrapper.tamara-checkout-page.tamara-v2').show();
                                clearInterval(existWidgetWrapperV2);
                            }
                            if (++countExistWidgetWrapperV2 > 33) {
                                clearInterval(existWidgetWrapperV2);
                            }
                        }, 300);
                    }
                }
            }
        });
        return quote;
    }
});
