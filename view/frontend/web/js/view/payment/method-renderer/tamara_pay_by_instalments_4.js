/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
/*browser:true*/
/*global define*/
define(
    [
        'Tamara_Checkout/js/view/payment/method-renderer/tamara_pay_by_instalments_abstract',
        'Magento_Catalog/js/price-utils'
    ],
    function (
        Component,
        priceUtils
    ) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Tamara_Checkout/payment/tamara_pay_by_instalments_4',
                redirectAfterPlaceOrder: false
            },

            initObservable: function () {
                this._super()
                    .observe([
                        'tamaraPayByInstalments4'
                    ]);

                return this;
            },

            getNumberOfInstalments: function() {
                return  4;
            }
        });
    }
);
