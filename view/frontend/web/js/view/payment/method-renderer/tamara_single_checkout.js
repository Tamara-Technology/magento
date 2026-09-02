/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
/*browser:true*/
/*global define*/
define(
    [
        'jquery',
        'Tamara_Checkout/js/view/payment/method-renderer/tamara_pay_now',
        'mage/url',
        'Magento_Checkout/js/model/full-screen-loader',
        'Magento_Checkout/js/model/quote'
    ],
    function ($, Component, url, fullScreenLoader, quote) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Tamara_Checkout/payment/tamara_single_checkout'
            },

            getCode: function () {
                return 'tamara_single_checkout';
            },

            getSingleCheckoutConfig: function () {
                return window.checkoutConfig.payment.tamara.single_checkout || {};
            },

            /**
             * The country picked in the checkout form, falling back to the store configuration.
             */
            getCheckoutCountryCode: function () {
                var address = quote.shippingAddress() || quote.billingAddress();

                if (address && address.countryId) {
                    return address.countryId;
                }

                return this.getSingleCheckoutConfig().default_country_code || '';
            },

            getDescription: function () {
                var config = this.getSingleCheckoutConfig(),
                    descriptions = config.descriptions || {};

                if (this.getCheckoutCountryCode() === config.ksa_country_code) {
                    return descriptions.ksa || '';
                }

                return descriptions.default || '';
            },

            createTamaraOrder: function () {
                var errorElement = '#error-iframe-single-checkout';

                $(errorElement).addClass('hidden-error-iframe');
                fullScreenLoader.startLoader();
                $.ajax({
                    url: url.build('tamara/payment/placeOrder'),
                    type: 'POST',
                    data: {
                        'orderId': window.magentoOrderId
                    },
                    success: function (response) {
                        fullScreenLoader.stopLoader(true);
                        if (response.success) {
                            $('#order-id-single-checkout').val(response.orderId);
                            window.magentoOrderId = response.orderId;
                            window.location.replace(response.redirectUrl);
                        } else {
                            $(errorElement).removeClass('hidden-error-iframe').text(response.error);
                            setTimeout(function () {
                                $(errorElement).addClass('hidden-error-iframe').text('');
                            }, 10000);
                        }
                    },
                    error: function () {
                        fullScreenLoader.stopLoader(true);
                    }
                });
            }
        });
    }
);
