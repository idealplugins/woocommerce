<?php

/**
 * TargetPay Woocommerce payment module
 *
 * @author iDEALplugins.nl <support@idealplugins.nl>
 * @license http://opensource.org/licenses/GPL-3.0 GNU General Public License, version 3 (GPL-3.0)
 * @copyright Copyright (C) 2017 Idealplugins.nl
 *
 * Plugin Name: TargetPay for Woocommerce
 * Plugin URI: http://www.idealplugins.nl
 * Description: Activeert iDEAL, Bancontact, Sofort Banking, Visa/Mastercard creditcards and Paysafecard in Woocommerce
 * Author: Idealplugins.nl
 * Author URI: http://www.idealplugins.nl
 * Version: 3 - 09-03-2017
 */

define('TARGETPAY_OLD_TABLE_NAME', 'woocommerce_targetpay_sales27052016');
define('TARGETPAY_TABLE_NAME', 'woocommerce_targetpay');

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

if (!class_exists('TargetPayInstall')) {
    require_once(realpath(dirname(__FILE__)) . '/includes/install.php');
}
//create db when active plugin
register_activation_hook(__FILE__, array( 'TargetPayInstall', 'install_db' ));

add_action('plugins_loaded', 'init_targetpay_class', 0);
add_action( 'admin_enqueue_scripts', 'admin_enqueue_scripts');
function init_targetpay_class()
{
    // If the parent WC_Payment_Gateway class doesn't exist
    // it means WooCommerce is not installed on the site
    // so do nothing
    if (! class_exists('WC_Payment_Gateway')) {
        return;
    }
    
    if (!class_exists('WC_Gateway_TargetPay')) {
        require_once(realpath(dirname(__FILE__)) . '/includes/WC_Gateway_TargetPay.php');
    }
    if (!class_exists('TargetPayCore')) {
        require_once(realpath(dirname(__FILE__)) . '/includes/targetpay.class.php');
    }
    // If we made it this far, then include our Gateway Class
    include_once(realpath(dirname(__FILE__)) . '/includes/WC_Gateway_TargetPay_iDEAL.php');
    include_once(realpath(dirname(__FILE__)) . '/includes/WC_Gateway_TargetPay_Bancontact.php');
    include_once(realpath(dirname(__FILE__)) . '/includes/WC_Gateway_TargetPay_Sofort.php');
    include_once(realpath(dirname(__FILE__)) . '/includes/WC_Gateway_TargetPay_Creditcard.php');
    include_once(realpath(dirname(__FILE__)) . '/includes/WC_Gateway_TargetPay_Paysafecard.php');
    
    // Now that we have successfully included our class,
    // Lets add it too WooCommerce
    add_filter('woocommerce_payment_gateways', 'add_target_payment_gateway');
}
function add_target_payment_gateway($methods)
{
    $methods[] = 'WC_Gateway_TargetPay_iDEAL';
    $methods[] = 'WC_Gateway_TargetPay_Bancontact';
    $methods[] = 'WC_Gateway_TargetPay_Sofort';
    $methods[] = 'WC_Gateway_TargetPay_Creditcard';
    $methods[] = 'WC_Gateway_TargetPay_Paysafecard';
    return $methods;
}

function admin_enqueue_scripts() {
    wp_enqueue_style( 'wc-gateway-target-pay', plugin_dir_url( __FILE__ ) . '/assets/css/targetpay_admin.css' );
}