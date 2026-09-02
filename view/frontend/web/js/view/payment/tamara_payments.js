define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'tamara_single_checkout',
                component: 'Tamara_Checkout/js/view/payment/method-renderer/tamara_single_checkout'
            },
            {
                type: 'tamara_pay_later',
                component: 'Tamara_Checkout/js/view/payment/method-renderer/tamara_pay_later'
            },
            {
                type: 'tamara_pay_next_month',
                component: 'Tamara_Checkout/js/view/payment/method-renderer/tamara_pay_next_month'
            },
            {
                type: 'tamara_pay_now',
                component: 'Tamara_Checkout/js/view/payment/method-renderer/tamara_pay_now'
            },
            {
                type: 'tamara_pay_by_instalments_2',
                component: 'Tamara_Checkout/js/view/payment/method-renderer/tamara_pay_by_instalments_2'
            },
            {
                type: 'tamara_pay_by_instalments',
                component: 'Tamara_Checkout/js/view/payment/method-renderer/tamara_pay_by_instalments'
            },
            {
                type: 'tamara_pay_by_instalments_4',
                component: 'Tamara_Checkout/js/view/payment/method-renderer/tamara_pay_by_instalments_4'
            },
            {
                type: 'tamara_pay_by_instalments_5',
                component: 'Tamara_Checkout/js/view/payment/method-renderer/tamara_pay_by_instalments_5'
            },
            {
                type: 'tamara_pay_by_instalments_6',
                component: 'Tamara_Checkout/js/view/payment/method-renderer/tamara_pay_by_instalments_6'
            },
            {
                type: 'tamara_pay_by_instalments_7',
                component: 'Tamara_Checkout/js/view/payment/method-renderer/tamara_pay_by_instalments_7'
            },
            {
                type: 'tamara_pay_by_instalments_8',
                component: 'Tamara_Checkout/js/view/payment/method-renderer/tamara_pay_by_instalments_8'
            },
            {
                type: 'tamara_pay_by_instalments_9',
                component: 'Tamara_Checkout/js/view/payment/method-renderer/tamara_pay_by_instalments_9'
            },
            {
                type: 'tamara_pay_by_instalments_10',
                component: 'Tamara_Checkout/js/view/payment/method-renderer/tamara_pay_by_instalments_10'
            },
            {
                type: 'tamara_pay_by_instalments_11',
                component: 'Tamara_Checkout/js/view/payment/method-renderer/tamara_pay_by_instalments_11'
            },
            {
                type: 'tamara_pay_by_instalments_12',
                component: 'Tamara_Checkout/js/view/payment/method-renderer/tamara_pay_by_instalments_12'
            }
        );
        /** Add view logic here if needed */
        return Component.extend({});
    }
);
