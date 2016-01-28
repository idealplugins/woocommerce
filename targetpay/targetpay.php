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
            $this->idealView     = $this->get_option( 'idealView' );

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
                ),
                'idealView' => array(
                    'title' =>          __( 'iDEAL bank view', 'targetpay' ),
                    'type' =>           'checkbox',
                    'label' =>          __( 'With radiobuttons', 'targetpay' ),
                    'default' =>        'no',
                    'description' =>    sprintf( __( 'If selected, the banklist will be formed with radiobuttons instead of a dropdownbox.', 'woocommerce' ), 'targetpay' ),
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
			global $wpdb;
			$table_name = $wpdb->prefix . "woocommerce_TargetPay_Sales"; 
			
			//Will it start with 00? remove those ideal v1 v2
			if(substr($_REQUEST['trxid'],0,2) == 00) {
				$trxid = substr($_REQUEST['trxid'],2,strlen($_REQUEST['trxid']));
			} else {
				$trxid = $_REQUEST['trxid']; //ideal v3
			}
			
			$sql = "SELECT * FROM ".$table_name ." WHERE `order_id` = '".$order_id."' AND `transaction_id` = '".$trxid."'";
			$sale = $wpdb->get_row($sql,OBJECT);

			if($sale == null) { //Oeps something wrong... Some extra debug information for Targetpay
				echo "sale not found | used cart_id: ".$sale->order_id." | Used transaction_id: (POST) '".$_REQUEST['trxid']."' (modified): ".$trxid;
				die();
			}

            $targetPay = new TargetPayCore ($sale->paymethod, $sale->rtlo, "ef96dc7014cfff1a73a743e6dd8cb692", "nl", ($this->settings["testmode"]=="yes") ? 1 : 0);
            $result = $targetPay->checkPayment($sale->transaction_id);
            $order = new WC_Order( $order_id );

            if ($order == null) {
                echo "Order ".htmlentities($order_id)." not found... ";
                return;
            }

            if ($order->status != 'completed') {
                if ($result) {
                    echo "Paid... ";
                    $order->add_order_note( __( 'Payment completed', 'woocommerce' ) );
                    $order->payment_complete();
                } else { 
                    echo "Not paid " . $targetPay->getErrorMessage(). "... ";
                }
            } else {
                echo "Already completed, skipped... ";
            }

            echo "(Woocommerce, 23-04-2015) ";
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
            global $woocommerce, $wpdb;
            
			$TargetPaySalesTable = $wpdb->prefix . "woocommerce_TargetPay_Sales"; 

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


			$table_name = $wpdb->prefix . "woocommerce_TargetPay_Sales"; 
			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE IF NOT EXISTS " . $TargetPaySalesTable . " (
							`id` int(11) NOT NULL AUTO_INCREMENT,
							`cart_id` int(11) NOT NULL DEFAULT '0',
							`order_id` varchar(11) NOT NULL DEFAULT '0',
							`rtlo` int(11) NOT NULL,
							`paymethod` varchar(8) NOT NULL DEFAULT 'IDE',
							`transaction_id` varchar(255) NOT NULL,
							UNIQUE KEY id (id),
							KEY `cart_id` (`cart_id`),
							KEY `transaction_id` (`transaction_id`)
						) ".$charset_collate.";";
			$wpdb->query( $sql );

			$wpdb->insert( 
							$TargetPaySalesTable,
							array(
									'cart_id' => $order->get_order_number(),
									'order_id' => $order->id,
									'rtlo' => $this->parentSettings["rtlo"],
									'paymethod' => $this->payMethodId,
									'transaction_id' => $targetPay->getTransactionId()
									),
							array(
									'%s',
									'%d',
									'%d',
									'%s',
									'%d'
									)
							);

			/*
			$sql = "INSERT INTO ".$TargetPaySalesTable." SET 
						`cart_id` = '".$order->get_order_number()."',
						`rtlo` = '".$this->parentSettings["rtlo"]."',
						`paymethod` = '".$this->payMethodId."',
						`transaction_id` = '".$targetPay->getTransactionId()."'
			
			";
			$wpdb->get_results( $sql );
*/
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
            
            $temp = $targetPay->getBankList();
            
            $this->parentSettings = get_option('woocommerce_targetpay_settings', null);
            if($this->parentSettings["idealView"] == 'yes') {
				$this->description = '';
				foreach ($temp as $key=>$value) {
					$this->description .= '<input type="radio" name="bank" id="'.$key.'" value="'.$key.'"><label for="'.$key.'">'.$value.'</label><br />';
				}
			} else {
				$banks = false;
				foreach ($temp as $key=>$value) {
					$banks .= '<option value="'.$key.'">'.$value.'</option>';
				}
				$this->description = '<select name="bank" style="width:170px; padding: 2px; margin-left: 7px">'.$banks.'</select>';
			}
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
