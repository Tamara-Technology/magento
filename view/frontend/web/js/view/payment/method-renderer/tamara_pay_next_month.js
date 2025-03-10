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
                template: 'Tamara_Checkout/payment/tamara_pay_next_month',
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

            initObservable: function () {
                this._super()
                    .observe([
                        'tamaraPayNextMonth'
                    ]);

                return this;
            },

            successPayNextMonth: function () {
                if (window.checkoutConfig.payment.tamara.use_magento_checkout_success) {
                    window.location.replace(url.build(window.checkoutConfig.defaultSuccessPageUrl));
                } else {
                    let orderId = window.magentoOrderId;
                    window.location.replace(url.build('tamara/payment/' + orderId + '/success'));
                }
            },

            failedPayNextMonth: function () {
                let orderId = window.magentoOrderId;
                window.location.replace(url.build('tamara/payment/' + orderId + '/failure'));
            },

            cancelPayNextMonth: function () {
                let orderId = window.magentoOrderId;
                window.location.replace(url.build('tamara/payment/' + orderId + '/cancel'));
            },

            getCode: function () {
                return 'tamara_pay_next_month';
            },

            getData: function () {
                return {
                    'method': this.item.method
                };
            },

            getMinLimit: function () {
                return priceUtils.formatPrice(window.checkoutConfig.payment.tamara.payment_types.tamara_pay_next_month.min_limit);
            },

            getMinLimitAmount: function () {
                return window.checkoutConfig.payment.tamara.payment_types.tamara_pay_next_month.min_limit;
            },

            getMaxLimit: function () {
                return priceUtils.formatPrice(window.checkoutConfig.payment.tamara.payment_types.tamara_pay_next_month.max_limit);
            },

            getMaxLimitAmount: function () {
                return window.checkoutConfig.payment.tamara.payment_types.tamara_pay_next_month.max_limit;
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

            isTotalAmountInLimit: function () {
                var tamaraConfig = window.checkoutConfig.payment.tamara_pay_next_month;
                var grandTotal = this.getGrandTotal();
                return !(grandTotal < parseFloat(tamaraConfig.min_limit) || grandTotal > parseFloat(tamaraConfig.max_limit));
            },

            isPlaceOrderActive: function () {
                return true;
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
                jQuery('#error-iframe').addClass('hidden-error-iframe');
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
                            jQuery('#order-id-pay-next-month').val(response.orderId);
                            window.magentoOrderId = response.orderId;
                            window.location.replace(response.redirectUrl);
                        } else {
                            jQuery('#error-iframe').removeClass('hidden-error-iframe').text(response.error);
                            setTimeout(() => jQuery('#error-iframe').addClass('hidden-error-iframe').text(''), 10000);

                            return false;
                        }
                    },
                    fail: function () {
                        fullScreenLoader.stopLoader(true);
                    }
                });
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
                if (this.getWidgetVersion() == 'v1') {
                    this.renderProductWidget();
                } else {
                    if (this.getWidgetVersion() == 'v2') {
                        this.renderWidgetV2();
                    } else {
                        this.renderProductWidget();
                        this.renderWidgetV2();
                    }
                }
                return false;
            }
        });
    }
);
