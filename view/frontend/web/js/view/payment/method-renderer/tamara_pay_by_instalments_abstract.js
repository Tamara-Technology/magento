/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
/*browser:true*/
/*global define*/
define(
    [
        'jquery',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Catalog/js/price-utils',
        'Magento_Checkout/js/action/place-order',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/action/redirect-on-success',
        'mage/url',
        'Magento_Checkout/js/model/full-screen-loader',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/totals'
    ],
    function (
        $,
        Component,
        priceUtils,
        placeOrderAction,
        additionalValidators,
        redirectOnSuccessAction,
        url,
        fullScreenLoader,
        quote,
        totals
    ) {
        'use strict';

        return Component.extend({
            defaults: {
                redirectAfterPlaceOrder: false
            },
            tamaraImageSrc: window.populateTamara.tamaraLogoImageUrl,
            tamaraBadgeSrc: window.populateTamara.tamaraBadgeUrl,
            tamaraLink: window.populateTamara.tamaraAboutLink,
            countryCode: window.populateTamara.tamaraCountryCode,
            currencyCode: window.checkoutConfig.totalsData.quote_currency_code,
            redirectAfterPlaceOrder: false,
            preventPlaceOrderWhenError: false,
            totals: quote.getTotals(),

            /**
             * After place order callback
             */
            afterPlaceOrder: function () {
                this.createTamaraOrder();
            },

            successPayByInstalments: function () {
                if (window.checkoutConfig.payment.tamara.use_magento_checkout_success) {
                    window.location.replace(url.build(window.checkoutConfig.defaultSuccessPageUrl));
                } else {
                    let orderId = window.magentoOrderId;
                    window.location.replace(url.build('tamara/payment/' + orderId + '/success'));
                }
            },

            failedPayByInstalments: function () {
                let orderId = window.magentoOrderId;
                window.location.replace(url.build('tamara/payment/' + orderId + '/failure'));
            },

            cancelPayByInstalments: function () {
                let orderId = window.magentoOrderId;
                window.location.replace(url.build('tamara/payment/' + orderId + '/cancel'));
            },

            getData: function () {
                return {
                    'method': this.item.method
                };
            },

            getGrandTotal: function () {
                let grandTotal = 0;
                if (this.totals()) {
                    grandTotal = totals.getSegment('grand_total').value;
                } else {
                    grandTotal = window.checkoutConfig.totalsData.grand_total;
                }
                return grandTotal;
            },

            isPlaceOrderActive: function () {
                return true;
            },

            /**
             * Place order.
             */
            placeOrder: function (data, event) {
                let self = this;

                if (event) {
                    event.preventDefault();
                }

                if (this.validate() && additionalValidators.validate()) {
                    this.isPlaceOrderActionAllowed(false);

                    this.getPlaceOrderDeferredObject()
                        .done(
                            function (response) {
                                window.magentoOrderId = response;
                                self.afterPlaceOrder();

                                if (self.redirectAfterPlaceOrder) {
                                    redirectOnSuccessAction.execute();
                                }
                            }
                        ).always(
                        function () {
                            self.isPlaceOrderActionAllowed(true);
                        }
                    );

                    return true;
                }

                return false;
            },

            /**
             * @return {*}
             */
            getPlaceOrderDeferredObject: function () {
                return $.when(
                    placeOrderAction(this.getData(), this.messageContainer)
                );
            },

            createTamaraOrder: function () {
                var errorElement = "#error-iframe-pay-by-instalments-" + this.getNumberOfInstalments();
                jQuery(errorElement).addClass('hidden-error-iframe');
                fullScreenLoader.startLoader();
                $.ajax({
                    url: url.build('tamara/payment/placeOrder'),
                    type: 'POST',
                    data: {
                        'orderId' : window.magentoOrderId
                    },
                    success: function (response) {
                        fullScreenLoader.stopLoader(true);
                        if (response.success) {
                            jQuery('#order-id').val(response.orderId);
                            window.magentoOrderId = response.orderId;
                            window.location.replace(response.redirectUrl);
                        } else {
                            jQuery(errorElement).removeClass('hidden-error-iframe').text(response.error);
                            setTimeout(() => jQuery(errorElement).addClass('hidden-error-iframe').text(''), 10000);

                            return false;
                        }
                    },
                    fail: function () {
                        fullScreenLoader.stopLoader(true);
                    }
                });
            },

            isArabicLanguage: function () {
                return (window.checkoutConfig.payment.tamara.locale_code).includes("ar_");
            },

            getPaymentLanguage: function () {
                if (this.isArabicLanguage()) {
                    return 'ar';
                }
                return 'en';
            },

            getPublicKey: function() {
                return window.checkoutConfig.payment.tamara.public_key;
            },

            renderProductWidget: function () {
                var self = this;
                var countExistTamaraProductWidget = 0;
                var existTamaraPaymentProductWidget = setInterval(function() {
                    if ($('.tamara-product-widget').length) {
                        if (window.TamaraProductWidget) {
                            window.TamaraProductWidget.init({ lang: self.getPaymentLanguage(), currency: self.currencyCode, publicKey: self.getPublicKey()});
                            window.TamaraProductWidget.render();
                            clearInterval(existTamaraPaymentProductWidget);
                        }
                    }
                    if (++countExistTamaraProductWidget > 33) {
                        clearInterval(existTamaraPaymentProductWidget);
                    }
                }, 300);
                return false;
            },

            renderInstallmentsPlanWidget: function () {
                var self = this;
                var countExistTamaraInstallmentsPlan = 0;
                var existTamaraInstallmentsPlan = setInterval(function() {
                    if ($('.tamara-installment-plan-widget').length) {
                        $('.tamara-installment-plan-widget').attr('data-price', self.getGrandTotal());
                        if (window.TamaraInstallmentPlan) {
                            $('.tamara-installment-plan-widget').empty();
                            window.TamaraInstallmentPlan.init({ lang: self.getPaymentLanguage(), currency: self.currencyCode, publicKey: self.getPublicKey()});
                            window.TamaraInstallmentPlan.render();
                            clearInterval(existTamaraInstallmentsPlan);
                        }
                    }
                    if (++countExistTamaraInstallmentsPlan > 33) {
                        clearInterval(existTamaraInstallmentsPlan);
                    }
                }, 300);
                return false;
            },

            getMethodName: function () {
                return "tamara_pay_by_instalments_" + this.getNumberOfInstalments();
            },

            getMinLimit: function () {
                return priceUtils.formatPrice(window.checkoutConfig.payment.tamara.payment_types[this.getMethodName()].min_limit);
            },

            getMinLimitAmount: function () {
                return window.checkoutConfig.payment.tamara.payment_types[this.getMethodName()].min_limit;
            },

            getMaxLimit: function () {
                return priceUtils.formatPrice(window.checkoutConfig.payment.tamara.payment_types[this.getMethodName()].max_limit);
            },

            getMaxLimitAmount: function () {
                return window.checkoutConfig.payment.tamara.payment_types[this.getMethodName()].max_limit;
            },

            isTotalAmountInLimit: function () {
                var tamaraConfig = window.checkoutConfig.payment.tamara.payment_types[this.getMethodName()];
                var grandTotal = this.getGrandTotal();

                return !(grandTotal < parseFloat(tamaraConfig.min_limit) || grandTotal > parseFloat(tamaraConfig.max_limit));
            },

            getWidgetVersion: function () {
                return window.checkoutConfig.payment.tamara.widget_version;
            },

            renderWidgetV2: function () {
                window.tamaraWidgetConfig = {
                    "country" : window.checkoutConfig.payment.tamara.country_code,
                    "lang": window.checkoutConfig.payment.tamara.language,
                    "publicKey": window.checkoutConfig.payment.tamara.public_key
                }
                var self = this;
                var countExistTamaraWidgetV2 = 0;
                var existTamaraWidgetV2 = setInterval(function() {
                    if ($('.tamara-promo-widget-wrapper.tamara-checkout-page.tamara-v2').length) {
                        $('.tamara-promo-widget-wrapper.tamara-checkout-page.tamara-v2').empty();

                        //append the widget html
                        let widgetHtml = '<tamara-widget amount="' + self.getGrandTotal() + '" inline-type="6" config=\'{"badgePosition":"","showExtraContent":"full","hidePayInX":false}\'></tamara-widget>';
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
                return true;
            },

            renderWidget: function () {
                //render it in  model/quote-mixin.js
                return false;
            }
        });
    }
);
