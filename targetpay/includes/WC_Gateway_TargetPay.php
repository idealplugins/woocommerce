<?php

/* TargetPay IDeal Payment Gateway Class */
abstract class WC_Gateway_TargetPay extends WC_Payment_Gateway
{
    const WOO_ORDER_STATUS_PENDING = 'pending';

    const WOO_ORDER_STATUS_COMPLETED = 'completed';

    const WOO_ORDER_STATUS_FAILED = 'failed';

    protected $appId = 'ef96dc7014cfff1a73a743e6dd8cb692';

    protected $payMethodId;

    protected $payMethodName;

    protected $maxAmount;

    public $enabled = true;

    /**
     * Setup our Gateway's id, description and other values.
     */
    public function __construct()
    {
        
        // The global ID for this Payment method
        $this->id = strtolower("TargetPay_{$this->payMethodId}");
        
        // This basically defines your settings which are then loaded with init_settings()
        $this->init_form_fields();
        
        // After init_settings() is called, you can get the settings and load them into variables
        $this->init_settings();
        foreach ($this->settings as $setting_key => $value) {
            $this->$setting_key = $value;
        }
        
        // the description show in payment method(Text || payment option)
        $this->description = $this->getTagetPayMethodOption();
        
        // The Title shown on the top of the Payment Gateways Page next to all the other Payment Gateways
        $this->method_title = $this->payMethodName;
        
        // The title to be used for the vertical tabs that can be ordered top to bottom
        $this->title = $this->payMethodName;
        
        // If you want to show an image next to the gateway's name on the frontend, enter a URL to an image.
        $this->icon = plugins_url('../', __FILE__) . '/assets/images/' . $this->payMethodId . '_24.png';
        
        // Bool. Can be set to true if you want payment fields to show on the checkout
        // if doing a direct integration, which we are doing in this case
        $this->has_fields = true;
        
        // Lets check for SSL
        add_action('admin_notices', array(
            $this,
            'do_ssl_check'
        ));
        // check response by method POST - report url
        add_action('woocommerce_api_wc_gateway_targetpayreport', array(
            $this,
            'check_targetpay_report'
        ));
        // check response by method GET - return url
        add_action('woocommerce_api_wc_gateway_targetpayreturn', array(
            $this,
            'check_targetpay_return'
        ));
        // Save settings
        if (is_admin()) {
            // Versions over 2.0
            // Save our administration options. Since we are not going to be doing anything special
            // we have not defined 'process_admin_options' in this class so the method in the parent
            // class will be used instead
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
                $this,
                'process_admin_options'
            ));
        }
    }

    /**
     * Build the administration fields for this specific Gateway.
     *
     * {@inheritdoc}
     *
     * @see WC_Settings_API::init_form_fields()
     */
    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable / Disable', 'targetpay'),
                'label' => __('Enable this payment gateway', 'targetpay'),
                'type' => 'checkbox',
                'default' => 'yes'
            ),
            'rtlo' => array(
                'title' => __('TargetPay layoutcode', 'targetpay'),
                'type' => 'text',
                'description' => __('Your TargetPay layoutcode (rtlo). This was given in the registration process ' . 'or you can find it on TargetPay.com under <a href="https://www.targetpay.com/subaccounts" target="_blank">My account > Subaccounts</a>', 'targetpay'),
                'default' => '93929', // Default TargetPay
                'desc_tip' => false,
                'placeholder' => 'Layoutcode'
            ),
            'testmode' => array(
                'title' => __('Test mode', 'targetpay'),
                'type' => 'checkbox',
                'label' => __('Enable testmode', 'targetpay'),
                'default' => 'no',
                'description' => __('Enable testmode, all orders will then be accepted even if unpaid/canceled.', 'targetpay')
            ),
            'idealView' => array(
                'title' => __('iDEAL bank view', 'targetpay'),
                'type' => 'checkbox',
                'label' => __('With radiobuttons', 'targetpay'),
                'default' => 'no',
                'description' => __('If selected, the banklist will be formed with radiobuttons instead of a dropdownbox.', 'targetpay')
            )
        );
    }

    /**
     * Submit payment and handle response
     *
     * {@inheritdoc}
     *
     * @see WC_Payment_Gateway::process_payment()
     */
    public function process_payment($order_id)
    {
        global $woocommerce, $wpdb;
        
        $TargetPaySalesTable = $this->getTargetPayTableName();
        
        $order = new WC_Order($order_id);
        $orderID = $order->id;
        
        if ($order->order_total > $this->maxAmount) {
            $message = 'Het totaalbedrag is hoger dan het maximum van ' . $this->maxAmount . ' euro voor ' . $this->payMethodName;
            // $woocommerce->add_error($message); -> changed in 2.2
            wc_add_notice($message, $notice_type = 'error');
            $order->add_order_note($message);
            
            return false;
        }
        
        $targetPay = new TargetPayCore($this->payMethodId, $this->rtlo, $this->appId, 'nl', ($this->testmode == 'yes'));
        $targetPay->setAmount(round($order->order_total * 100));
        $targetPay->setDescription('Order ' . $order->get_order_number()); // $order->id
                                                                         // set return & report url
        $targetPay->setReturnUrl(add_query_arg(array(
            'wc-api' => 'WC_Gateway_TargetPayReturn',
            'od' => $orderID
        ), home_url('/')));
        $targetPay->setReportUrl(add_query_arg(array(
            'wc-api' => 'WC_Gateway_TargetPayReport',
            'od' => $orderID
        ), home_url('/')));
        $this->additionalParameters($order, $targetPay);
        $url = $targetPay->startPayment();
        
        $wpdb->insert($TargetPaySalesTable, array(
            'cart_id' => $order->get_order_number(),
            'order_id' => $order->id,
            'rtlo' => $this->rtlo,
            'paymethod' => $this->payMethodId,
            'transaction_id' => $targetPay->getTransactionId(),
            'testmode' => $this->testmode
        ), array(
            '%s',
            '%d',
            '%d',
            '%s',
            '%s',
            '%s'
        ));
        
        if (! $url) {
            $message = $targetPay->getErrorMessage();
            wc_add_notice($message, $notice_type = 'error');
            $order->add_order_note("Payment could not be started: {$message}");
            
            return false;
        } else {
            return array(
                'result' => 'success',
                'redirect' => $url
            );
        }
    }

    /**
     * Update order (if report not working) && show payment result.
     *
     * @return mixed
     */
    public function check_targetpay_return()
    {
        global $woocommerce, $wpdb;
        
        $orderId = ! empty($_REQUEST['od']) ? esc_sql($_REQUEST['od']) : null;
        $trxid = ! empty($_REQUEST['trxid']) ? esc_sql($_REQUEST['trxid']) : null;
        if ($orderId && $trxid) {
            $order = new WC_Order($orderId);
            if ($order->post == null) {
                echo 'Order ' . htmlentities($orderId) . ' not found... ';
                die;
            }
            $extOrder = $this->getExtOrder($orderId, $trxid);
            
            if ($extOrder == null) { // Oeps something wrong... Some extra debug information for Targetpay
                echo 'Sale not found...';
                die;
            }
            $this->redirectAfterCheck($order, $extOrder);
        }
        echo 'Order ' . htmlentities($orderId) . ' not found... ';
        die;
    }

    /**
     * Process report URL
     * Update order when status = pending.
     *
     * @return none
     */
    public function check_targetpay_report()
    {
        global $woocommerce, $wpdb;
        $orderId = ! empty($_REQUEST['od']) ? esc_sql($_REQUEST['od']) : null;
        $trxid = ! empty($_REQUEST['trxid']) ? esc_sql($_REQUEST['trxid']) : null;
        
        if ($orderId && $trxid) {
            $order = new WC_Order($orderId);
            $extOrder = $this->getExtOrder($orderId, $trxid);
            
            if ($order->post && $extOrder && $order->status == self::WOO_ORDER_STATUS_PENDING) {
                $targetPay = new TargetPayCore($extOrder->paymethod, $extOrder->rtlo, $this->appId, 'nl', ($extOrder->testmode == 'yes') ? 1 : 0);
                $result = $targetPay->checkPayment($extOrder->transaction_id);
                if ($result) {
                    $order->update_status(self::WOO_ORDER_STATUS_COMPLETED, "Method $order->payment_method_title(Transaction ID $extOrder->transaction_id): ");
                    return;
                }
                $this->updateTargetPayMessage($order, $targetPay->getErrorMessage());
                $order->update_status(self::WOO_ORDER_STATUS_FAILED, "Method $order->payment_method_title(Transaction ID $extOrder->transaction_id): ");
            }
        }
    }
    
    /**
     * Update message to woocommerce_TargetPay_Sales table
     *
     * @param Object $order
     * @param string $message
     * @return int|false The number of rows updated, or false on error.
     */
    public function updateTargetPayMessage($order, $message)
    {
        global $wpdb;
        $TargetPaySalesTable = $this->getTargetPayTableName();
        return $wpdb->update($TargetPaySalesTable, array('message' => $message), array('order_id' => $order->id, ));
    }
    
    /**
     * Check order status and redirect to appropriate page
     *
     * @param Object $order
     * @param Object $extOrder
     * @return mixed
     */
    public function redirectAfterCheck($order, $extOrder)
    {
        global $woocommerce;
        global $wpdb;
        switch ($order->status) {
            case self::WOO_ORDER_STATUS_PENDING:
                $targetPay = new TargetPayCore($extOrder->paymethod, $extOrder->rtlo, $this->appId, 'nl', ($extOrder->testmode == 'yes') ? 1 : 0);
                $result = $targetPay->checkPayment($extOrder->transaction_id);
                if ($result) {
                    $woocommerce->cart->empty_cart();
                    $order->update_status(self::WOO_ORDER_STATUS_COMPLETED, "Method $order->payment_method_title(Transaction ID $extOrder->transaction_id): ");
                    return wp_redirect($this->get_return_url($order));
                }
                $this->updateTargetPayMessage($order, $targetPay->getErrorMessage());
                $order->update_status(self::WOO_ORDER_STATUS_FAILED, "Method $order->payment_method_title(Transaction ID $extOrder->transaction_id): ");
                wp_redirect(add_query_arg('wc_error', urlencode($targetPay->getErrorMessage()), $woocommerce->cart->get_cart_url()));
                break;
            case self::WOO_ORDER_STATUS_FAILED:
                return wp_redirect(add_query_arg('wc_error', $extOrder->message, $woocommerce->cart->get_cart_url()));
                break;
            case self::WOO_ORDER_STATUS_COMPLETED:
                $woocommerce->cart->empty_cart();
                return wp_redirect($this->get_return_url($order));
                break;
        }
    }
    
    // Validate fields
    public function validate_fields()
    {
        return true;
    }

    /**
     * Return Targetpay table name.
     *
     * @return string
     */
    public function getTargetPayTableName()
    {
        global $wpdb;
        
        return $wpdb->prefix . TARGETPAY_TABLE_NAME;
    }
    
    /**
     * Get order information from wp_woocommerce_TargetPay_Sales table
     * @param int $orderId
     * @param int $trxid
     */
    public function getExtOrder($orderId, $trxid)
    {
        global $wpdb;
        $TargetPaySalesTable = $this->getTargetPayTableName();
        $sql = 'SELECT * FROM ' . $TargetPaySalesTable . " WHERE `order_id` = '" . $orderId . "' AND `transaction_id` = '" . $trxid . "' ORDER BY `id` DESC";
        return $wpdb->get_row($sql, OBJECT);
    }
    
    /**
     * Plugin can only be used for payments in EURO.
     *
     * @return bool Plugin applicable
     */
    public function is_valid_for_use()
    {
        if (! in_array(get_woocommerce_currency(), apply_filters('woocommerce_targetpay_supported_currencies', array(
            'EUR'
        )))) {
            return false;
        }
        
        return true;
    }

    /**
     * Admin options for a payment method.
     */
    public function admin_options()
    {
        ob_start();
        $this->generate_settings_html();
        $settings = ob_get_contents();
        ob_end_clean();
        if ($this->is_valid_for_use()) {
            $template = '<table class="form-table">
                 <div class="tp-method-conf">
                    <div class="tp-icon">
                    <img src="' . plugins_url('../', __FILE__) . '/assets/images/admin_header.png">
                    </div>
                    <div class="tp-icon-method">
                    <img src="' . plugins_url('../', __FILE__) . '/assets/images/' . $this->payMethodId . '_60.png">
                    </div>
                </div>';
            if ($this->checkSqlTable()) {
                $template .= $settings;
            }
            $template .= '</table>';
        } else {
            $template = '<div class="inline error"><p><strong>' . __('Gateway Disabled', 'woocommerce') . '</strong>: ' .
            __('TargetPay does not support your store currency.', 'targetpay') . '</p></div>';
        }
        
        echo $template;
    }

    /**
     * Checks if the mysql table is correct when it exists.
     * If not?
     * create error.
     */
    private function checkSqlTable()
    {
        global $wpdb;
        $TargetPaySalesTable = $this->getTargetPayTableName();
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$TargetPaySalesTable'")) {
            $dbColums = $wpdb->get_col('DESC ' . $TargetPaySalesTable, 0);
            $requiredColumns = array(
                'id',
                'cart_id',
                'order_id',
                'rtlo',
                'paymethod',
                'transaction_id',
                'testmode'
            );
            $missing = array();
            foreach ($requiredColumns as $col) {
                if (! in_array($col, $dbColums)) {
                    $missing[] = $col;
                }
            }
            
            if (count($missing)) {
                echo '<h1 style="color:red">WARNING: ' . ((count($missing) == 1) ? 'One database column is missing' : 'Multiple database columns are missing') . '</h1>';
                if (count($missing) == 1) {
                    echo '<p>We want to inform you that one table column (' . array_shift(array_values($missing)) . ') is missing in the plugin table. The plugin will <strong>not</strong> work properly.</p>';
                } else {
                    echo '</p>We want to inform you that multiple table columns are missing in the plugin table. The plugin will <strong>not</strong> work properly. Below an overview of the missing columns</p>';
                    echo '<ul>';
                    foreach ($missing as $value) {
                        echo '<li>' . $value . '</li>';
                    }
                    echo '</ul>';
                }
                echo "<strong>How to solve this issue?</strong><p>Rename / copy the database table '" . $TargetPaySalesTable . "' into '" . $TargetPaySalesTable . '_' . date_i18n('Y_m_d', time()) . "'.<br /></b>The plugin will create a new table automaticly.</p>";
                
                return false;
            }
        }
        
        return true;
    }

    /**
     * Check if we are forcing SSL on checkout pages.
     */
    public function do_ssl_check()
    {
        if ($this->enabled == 'yes') {
            if (get_option('woocommerce_force_ssl_checkout') == 'no') {
                echo '<div class="error"><p>' . sprintf(__('<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href="%s">forcing the checkout pages to be secured.</a>'), $this->method_title, admin_url('admin.php?page=wc-settings&tab=checkout')) . '</p></div>';
            }
        }
    }

    /**
     * Event handler to attach additional parameters.
     *
     * @param WC_Order $order
     *            Order info
     * @param TargetPayCore $targetPay
     *            Payment class to attach bindings to
     */
    public function additionalParameters(WC_Order $order, TargetPayCore $targetPay)
    {
    }

    abstract protected function getTagetPayMethodOption();
} // End class
