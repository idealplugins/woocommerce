<?php

/**
 * TargetPay Woocommerce payment module
 *
 * @author iDEALplugins.nl <info@yellowmelon.nl>
 * @license http://opensource.org/licenses/GPL-3.0 GNU General Public License, version 3 (GPL-3.0)
 * @copyright Copyright (C) 2014 Yellow Melon B.V.
 *
 * Plugin Name: TargetPay for Woocommerce
 * Plugin URI: https://www.idealplugins.nl
 * Description: Enables iDEAL, Mister Cash, Sofort Banking, Achteraf Betalen, Visa/Mastercard creditcards and Paysafecard in Woocommerce
 * Author: Yellow Melon B.V.
 * Author URI: http://www.yellowmelon.nl
 * Version: 2.0 - 25-08-2014
 * Version: 2.1 - Added paybyinvoice, paysafecard and creditcard (1-10-2014)
 */

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) return;

// Only load if TargetPayCore class is not yet defined => avoid concurrency problems

if (!class_exists('TargetPayCore')) {
    require_once ( realpath(dirname(__FILE__)) . '/classes/targetpay.class.php');
    }

add_action( 'plugins_loaded', 'init_targetpay_class', 0);

function init_targetpay_class() 
{

    /**
     *  General settings for TargetPay
     */

    class WC_Gateway_TargetPay extends WC_Payment_Gateway 
    {

        public $notify_url;

        /**
         *  Base constructor
         */

        public function __construct() 
        {
            global $woocommerce;

            $this->id           = 'targetpay';
            $this->has_fields   = false;
            $this->method_title = "TargetPay";
            $this->title        = "TargetPay";
            $this->notify_url   = str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'WC_Gateway_TargetPay', home_url( '/' ) ) );

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            $this->rtlo         = $this->get_option( 'rtlo' );
            $this->testmode     = $this->get_option( 'testmode' );

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action('woocommerce_api_wc_gateway_targetpay', array( $this, 'check_ipn_response' ) );
            add_filter('woocommerce_payment_gateways', array($this, 'addGateway'));

            if ( !$this->is_valid_for_use() ) $this->enabled = false;
        }

        /**
         *  Plugin can only be used for payments in EURO
         *  @return bool Plugin applicable
         */

        public function is_valid_for_use() 
        {
            if ( ! in_array( get_woocommerce_currency(), apply_filters( 'woocommerce_targetpay_supported_currencies', array( 'EUR' ) ) ) ) return false;
            return true;
        }

        /**
         *  Display header (admin)
         */

        public function admin_options() 
        {
            if ($this->is_valid_for_use()) {
                echo "<table class=\"form-table\">
                        <div style=\"margin: 3px 0 3px 0; padding: 7px 0 12px 0; width: 100%; border-style: none none solid none; border-width: 1px; border-color: #ccc\">
                            <div style=\"margin: 0 25px 0 0\"><img src=\"".plugins_url('', __FILE__)."/images/admin_header.png\"></div>
                        </div>";
                $this->generate_settings_html();
                echo "</table>";
            } else {
                echo "<div class=\"inline error\"><p><strong>". __( 'Gateway Disabled', 'woocommerce' ) . "</strong>: ". __( 'TargetPay does not support your store currency.', 'woocommerce' ) . "</p></div>";
            }
        }

        /**
         *  Form fields (admin)
         */

        public function init_form_fields() 
        {
            $this->form_fields = array(
                'rtlo' => array(
                    'title' =>          __( 'TargetPay layoutcode', 'targetpay' ),
                    'type' =>           'text',
                    'description' =>    __( 'Your TargetPay layoutcode (rtlo). This was given in the registration process '.
                                            'or you can find it on TargetPay.com under <a href="https://www.targetpay.com/subaccounts" target="_blank">My account > Subaccounts</a>', 'targetpay' ),
                    'default' =>        '93929', // Default TargetPay
                    'desc_tip' =>       false,
                    'placeholder' =>    'Layoutcode'
                ),
                'testmode' => array(
                    'title' =>          __( 'Test mode', 'targetpay' ),
                    'type' =>           'checkbox',
                    'label' =>          __( 'Enable testmode', 'targetpay' ),
                    'default' =>        'no',
                    'description' =>    sprintf( __( 'Enable testmode, all orders will then be accepted even if unpaid/canceled.', 'woocommerce' ), 'targetpay' ),
                )
            );
        }

        /**
         *  Form fields (admin)
         */

        public function addGateway($methods) 
        {
            $methods[] = 'WC_Gateway_TargetPay';
            $methods[] = 'WC_Gateway_TargetPay_PaymentMethod_iDEAL';
            $methods[] = 'WC_Gateway_TargetPay_PaymentMethod_MisterCash';
            $methods[] = 'WC_Gateway_TargetPay_PaymentMethod_Sofort';
            $methods[] = 'WC_Gateway_TargetPay_PaymentMethod_PayByInvoice';
            $methods[] = 'WC_Gateway_TargetPay_PaymentMethod_Paysafecard';
            $methods[] = 'WC_Gateway_TargetPay_PaymentMethod_Creditcard';
            return $methods;
        }

        /**
         *  Process report URL
         */

        public function check_ipn_response() 
        {
            global $woocommerce;
            if (defined("TARGETPAY_IPN_DONE")) return;
            define ("TARGETPAY_IPN_DONE", true);

            @ob_clean();

            list ($payMethod, $order_id) = explode("-", $_REQUEST["tx"], 2);

            $targetPay = new TargetPayCore ($payMethod, $_REQUEST["rtlo"], "ef96dc7014cfff1a73a743e6dd8cb692", "nl", ($this->settings["testmode"]=="yes") ? 1 : 0);
            $result = $targetPay->checkPayment ($_REQUEST["trxid"]);

            $order = new WC_Order( $order_id );

            if ($order == null) {
                echo "Order ".htmlentities($order_id)." not found - ";
                return;
            }

            if ($order->status != 'completed') {
                if ($result) {
                    echo "Was not completed, is now completed - ";
                    $order->add_order_note( __( 'Payment completed', 'woocommerce' ) );
                    $order->payment_complete();
                } else { 
                    /* 
                        Changed [14-01-2015]:
                        Do not update cart for failure to prevent conflicting multiple payment attempts (failed vs. completed)

                    $order->add_order_note(__('Payment error:', 'woocommerce'));
                    $order->cancel_order();
                    */
                    echo "Not paid, cart not updated (version 14/1/2015): " . $targetPay->getErrorMessage(). " - ";
                }
            } else {
                echo "Already completed - ";
            }

            @ob_flush();
        }
    }

    /**
     *  Base class, will be extended by the individual payment methods
     */

    class WC_Gateway_TargetPay_Base extends WC_Payment_Gateway 
    {

        /**
         *  Set up class with payment method details
         */

        public function __construct() 
        {
            $this->id                 = "TargetPay_{$this->payMethodId}";
            $this->icon               = plugins_url('', __FILE__) . '/images/'. $this->payMethodId.'_24.png';
            $this->method_title       = "{$this->payMethodName}";
            $this->paymentMethodCode  = $this->payMethodId;
            add_action ("woocommerce_update_options_payment_gateways_{$this->id}", array($this, 'process_admin_options'));
            $this->form_fields = array(
                    'stepone' => array(
                        'title' => $this->method_title,
                        'type' => 'title'
                        ),
                    'enabled' => array(
                        'title' => __('Active', 'targetpay'),
                        'type' => 'checkbox',
                        'label' => ' '
                        ),
                    );
            $this->init_settings();
            $this->title = $this->payMethodName;
        }

        /**
         *  Admin options for a payment method
         */

        public function admin_options() 
        {
            ob_start();
            $this->generate_settings_html();
            $settings = ob_get_contents();
            ob_end_clean();
            $template =
                '<table class="form-table">
                 <div style="margin: 3px 0 3px 0; padding: 7px 0 12px 0; width: 100%; border-style: none none solid none; border-width: 1px; border-color: #ccc">
                    <div style="margin: 0 25px 0 0; float: left">
                    <img src="'.plugins_url('', __FILE__).'/images/admin_header.png">
                    </div>
                    <div style="margin: 0">
                    <img src="'.plugins_url('', __FILE__).'/images/'.$this->payMethodId.'_60.png">
                    </div>
                </div>'.
                $settings.
                '</table>';
            echo $template;
            }

        /**
         *  Event handler to attach additional parameters 
         *  @param WC_Order $order Order info
         *  @param TargetPayCore $targetPay Payment class to attach bindings to
         */

        public function additionalParameters (WC_Order $order, TargetPayCore $targetPay) 
        {
        }

        /**
         *  Start payment with this method
         */

        public function process_payment($order_id) 
        {
            global $woocommerce;

            $order = new WC_Order($order_id);
            $orderID = $order->id;
            $this->parentSettings = get_option('woocommerce_targetpay_settings', null);

            if ($order->order_total > $this->maxAmount) {
                $message = "Het totaalbedrag is hoger dan het maximum van ".$this->maxAmount . " euro voor ".$this->payMethodName;
                // $woocommerce->add_error($message); -> changed in 2.2
                wc_add_notice($message, $notice_type ='error');
                $order->add_order_note($message);
                return false;
            } 

            $targetPay = new TargetPayCore ($this->payMethodId, $this->parentSettings["rtlo"],  "ef96dc7014cfff1a73a743e6dd8cb692", "nl", ($this->parentSettings["testmode"]=="yes"));
            $targetPay->setAmount (round ($order->order_total*100));
            $targetPay->setDescription ('Order '.$order->get_order_number()); // $order->id
            $targetPay->setReturnUrl ($this->get_return_url($order));
            $targetPay->setCancelUrl ($order->get_cancel_order_url());

            $tx = $this->payMethodId."-".$order->id;
            $targetPay->setReportUrl (add_query_arg( 'wc-api', 'WC_Gateway_TargetPay', add_query_arg( 'tx', $tx, home_url( '/' ) ) ) );

            $this->additionalParameters ($order, $targetPay);
            $url = $targetPay->startPayment();

            if (!$url) {
                $message = $targetPay->getErrorMessage();
                // $woocommerce->add_error(__('Payment error:', 'woothemes') . ' ' . $message); -> changed in 2.2
                wc_add_notice($message, $notice_type ='error');
                $order->add_order_note("Payment could not be started: {$message}");
                return false;
            } else {
                return array(
                    'result' => 'success',
                    'redirect' => $url
                    );
            }
        }
    }

    /**
     *  Backwards incompatibility fix
     */

    class WC_TargetPay extends WC_Gateway_TargetPay 
    {
        public function __construct() 
        {
            _deprecated_function( 'WC_TargetPay', '1.4', 'WC_Gateway_TargetPay' );
            parent::__construct();
        }
    }

    /**
     *  Specifics for iDEAL
     */

    class WC_Gateway_TargetPay_PaymentMethod_iDEAL extends WC_Gateway_TargetPay_Base 
    {
        protected $payMethodId = "IDE";
        protected $payMethodName = "iDEAL";
        protected $maxAmount = 10000;
        public $enabled = true;

        public function __construct() 
        {
            if( !class_exists( 'WP_Http' ) )
                include_once( ABSPATH . WPINC. '/class-http.php' );

            $targetPay = new TargetPayCore ("IDE");
            $banks = false;
            $temp = $targetPay->getBankList();
            foreach ($temp as $key=>$value) 
                $banks .= '<option value="'.$key.'">'.$value.'</option>';

            $this->description = '<select name="bank" style="width:170px; padding: 2px; margin-left: 7px">'.$banks.'</select>';
            parent::__construct();
        }

        /**
         *  Bind bank ID
         */ 

        public function additionalParameters (WC_Order $order, TargetPayCore $targetPay) 
        {   
            if (isset($_POST["bank"])) $targetPay->setBankId ($_POST["bank"]);
        }
    }

    /**
     *  Specifics for Mister Cash
     */

    class WC_Gateway_TargetPay_PaymentMethod_MisterCash extends WC_Gateway_TargetPay_Base 
    {
        protected $payMethodId = "MRC";
        protected $payMethodName = "Mister Cash";
        public    $enabled = true;
        protected $maxAmount = 10000;
        public    $description = 'Bancontact/Mister Cash';
    }

    /**
     *  Specifics for Sofort
     */

    class WC_Gateway_TargetPay_PaymentMethod_Sofort extends WC_Gateway_TargetPay_Base 
    {
        protected $payMethodId = "DEB";
        protected $payMethodName = "Sofort Banking";
        protected $maxAmount = 10000;
        public    $enabled = true;
        public    $description = '<select name="country" style="width:220px; padding: 2px; margin-left: 7px">
                                  <option selected>Choose country of your bank...</option>
                                  <option value="49">Deutschland</option>
                                  <option value="43">&Ouml;sterreich</option>
                                  <option value="41">Schweiz</option>
                                  </select>';

        /**
         *  Bind country ID
         */ 

        public function additionalParameters (WC_Order $order, TargetPayCore $targetPay) 
        {   
            if (isset($_POST["country"])) $targetPay->setCountryId ($_POST["country"]);         
        }

    }

    /**
     *  Specifics for Pay by Invoice (Achteraf betalen)
     */

    class WC_Gateway_TargetPay_PaymentMethod_PayByInvoice extends WC_Gateway_TargetPay_Base 
    {
        protected $payMethodId = "AFT";
        protected $payMethodName = "Achteraf betalen";
        protected $maxAmount = 1000;
        public    $enabled = false; // true: disabled per 1-10-2014
        public    $description = 'Achteraf Betalen';

        /**
         *      Parse cart to order contents array
         */

        public function parseOrderContents ($order, $amountToPay) 
        {
            $return = array();

            // Cart items

            $products = $order->get_items();

            foreach ($products as $id => $product) {

                $tax_rate = ($product["line_subtotal"]) ? $product["line_subtotal_tax"] / $product["line_subtotal"] * 100 : 0;
                $qty = ($product["qty"]) ? $product["qty"] : 1;

                $return[] = array(
                    'type' => 1,
                    'product' => $product["product_id"],
                    'description' => $product["name"],
                    'amount' => round((($product["line_subtotal"] + $product["line_subtotal"])/ $qty) * 100), 
                    'quantity' => $product["qty"],
                    'amountvat' => $tax_rate,
                    'discount' => 0, // Not available
                    'discountvat' => 0 // Not available
                );
            }

            // Calculate shipping etc.

            $return[] = array(
                'type' => 2,
                'amount' => round(($order->get_total_shipping() + $order->get_shipping_tax())*100), 
                'amountvat' => round($order->get_shipping_tax()*100),
                'discount' => 0, // Not available
                'discountvat' => 0 // Not available
            );

            // Rest?

            $rest = $amountToPay - $order->get_total_shipping() - $order->get_shipping_tax();

            if ($rest > 0.01) {
                $return[] = array(
                    'type' => 4, // Actually we don't know...
                    'amount' => round($rest*100),
                    'amountvat' => 0, 
                    'discount' => 0, // Not available
                    'discountvat' => 0 // Not available
                );            
            }

            return $return;
        }   

        /**
         *  Bind additional details from the order
         */ 

        public function additionalParameters (WC_Order $order, TargetPayCore $targetPay) 
        {   
            $targetPay->setCurrency ($order->get_order_currency()); 
            $targetPay->bindParam ("cgender", "") // Resume
                    ->bindParam ("cinitials", ucfirst(substr($order->billing_first_name,0,1)).".") // Experimental
                    ->bindParam ("clastname", $order->billing_last_name) 
                    ->bindParam ("cbirthdate", "") // Resume
                    ->bindParam ("cbank", "") // Resume
                    ->bindParam ("cphone", $order->billing_phone) 
                    ->bindParam ("cmobilephone", $order->billing_phone) 
                    ->bindParam ("cemail", $order->billing_email) 

                    ->bindParam ("order", $order->id) 
                    ->bindParam ("ordercontents", json_encode($this->parseOrderContents($order, $order->order_total ))) // todo

                    ->bindParam ("invoiceaddress", $order->billing_address_1 . (($order->billing_address_2) ? " ".$order->billing_address_2 : "")) 
                    ->bindParam ("invoicezip", $order->billing_postcode) 
                    ->bindParam ("invoicecity", $order->billing_city) 
                    ->bindParam ("invoicecountry", $order->billing_city)

                    ->bindParam ("deliveryaddress", $order->shipping_address_1 . (($order->shipping_address_2) ? " ".$order->shipping_address_2 : "")) 
                    ->bindParam ("deliveryzip", $order->shipping_postcode) 
                    ->bindParam ("deliverycity", $order->shipping_city) 
                    ->bindParam ("deliverycountry", $order->shipping_city);
        }     
    }

    /**
     *  Specifics for Paysafecard
     */

    class WC_Gateway_TargetPay_PaymentMethod_Paysafecard extends WC_Gateway_TargetPay_Base 
    {
        protected $payMethodId = "WAL";
        protected $maxAmount = 150;
        protected $payMethodName = "Paysafecard";
        public    $enabled = true;
        public    $description = 'Paysafecard';
    }

    /**
     *  Specifics for Creditcard
     */

    class WC_Gateway_TargetPay_PaymentMethod_Creditcard extends WC_Gateway_TargetPay_Base 
    {
        protected $payMethodId = "CC";
        protected $payMethodName = "Visa/Mastercard";
        public    $enabled = true;
        protected $maxAmount = 10000;
        public    $description = 'Visa/Mastercard';
    }

    new WC_Gateway_TargetPay();
}
