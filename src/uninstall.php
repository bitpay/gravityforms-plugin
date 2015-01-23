<?php
/**
 * BitPay for Gravity Forms Uninstall
 *
 * @author 		bitpay
 */
if( ! defined( 'WP_UNINSTALL_PLUGIN' ) )
    exit();
delete_option('bitpayToken');
delete_option('bitpayPrivateKey');
delete_option('bitpayPublicKey');
delete_option('bitpayNetwork');
delete_option('bitpayRedirectURL');
delete_option('bitpayTransactionSpeed');