<?php

/**
 * TargetPay Woocommerce payment module
 *
 * @author Targetpay
 * @license http://opensource.org/licenses/GPL-3.0 GNU General Public License, version 3 (GPL-3.0)
 * @copyright Copyright (C) 2016 Target Media B.V.
 *
 * Plugin Name: TargetPay for Woocommerce
 * Plugin URI: https://www.targetpay.com/
 * Description: Enables iDEAL, Bancontact(Mister Cash), Sofort Banking, Achteraf Betalen, Visa/Mastercard creditcards and Paysafecard in Woocommerce
 * Author: Targetpay
 * Author URI: https://www.targetpay.com
 * Version: 3.0 - 14-12-2016
 * Version: 2.2.1 - bugfixes
 * Version: 2.2 - updated compatibility with WP4.7 and updated bancontact logos
 * Version: 2.1 - Added paybyinvoice, paysafecard and creditcard (1-10-2014)
 * Version: 2.0 - 25-08-2014
 *
 */

define( 'TARGETPAY_OLD_TABLE_NAME', 'woocommerce_targetpay_sales27052016' );
define( 'TARGETPAY_TABLE_NAME', 'woocommerce_targetpay' );

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}

if ( ! class_exists( 'TargetPayInstall' ) ) {
	require_once( realpath( dirname( __FILE__ ) ) . '/includes/install.php' );
}
//create db when active plugin
register_activation_hook( __FILE__, array( 'TargetPayInstall', 'install_db' ) );

add_action( 'plugins_loaded', 'init_targetpay_class', 0 );
add_action( 'admin_enqueue_scripts', 'admin_enqueue_scripts' );
function init_targetpay_class() {
	// If the parent WC_Payment_Gateway class doesn't exist
	// it means WooCommerce is not installed on the site
	// so do nothing
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	if ( ! class_exists( 'WC_Gateway_TargetPay' ) ) {
		require_once( realpath( dirname( __FILE__ ) ) . '/includes/WC_Gateway_TargetPay.php' );
	}
	if ( ! class_exists( 'TargetPayCore' ) ) {
		require_once( realpath( dirname( __FILE__ ) ) . '/includes/targetpay.class.php' );
	}
	// If we made it this far, then include our Gateway Class
	include_once( realpath( dirname( __FILE__ ) ) . '/includes/WC_Gateway_TargetPay_iDEAL.php' );
	include_once( realpath( dirname( __FILE__ ) ) . '/includes/WC_Gateway_TargetPay_MisterCash.php' );
	include_once( realpath( dirname( __FILE__ ) ) . '/includes/WC_Gateway_TargetPay_Sofort.php' );
	include_once( realpath( dirname( __FILE__ ) ) . '/includes/WC_Gateway_TargetPay_Creditcard.php' );
	include_once( realpath( dirname( __FILE__ ) ) . '/includes/WC_Gateway_TargetPay_Paysafecard.php' );

	// Now that we have successfully included our class,
	// Lets add it too WooCommerce
	add_filter( 'woocommerce_payment_gateways', 'add_target_payment_gateway' );
}

function add_target_payment_gateway( $methods ) {
	$methods[] = 'WC_Gateway_TargetPay_iDEAL';
	$methods[] = 'WC_Gateway_TargetPay_MisterCash';
	$methods[] = 'WC_Gateway_TargetPay_Sofort';
	$methods[] = 'WC_Gateway_TargetPay_Creditcard';
	$methods[] = 'WC_Gateway_TargetPay_Paysafecard';

	return $methods;
}

function admin_enqueue_scripts() {
	wp_enqueue_style( 'wc-gateway-target-pay', plugin_dir_url( __FILE__ ) . '/assets/css/targetpay_admin.css' );
}