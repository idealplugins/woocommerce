<?php

/* TargetPay IDeal Payment Gateway Class */
abstract class WC_Gateway_TargetPay extends WC_Payment_Gateway
{
    const WOO_ORDER_STATUS_PENDING = 'pending';

    const WOO_ORDER_STATUS_COMPLETED = 'completed';

    const WOO_ORDER_STATUS_PROCESSING = 'processing';

    const WOO_ORDER_STATUS_FAILED = 'failed';

    const WOO_ORDER_STATUS_ON_HOLD = 'on-hold';

    public static $log_enabled = true;

    /** @var WC_Logger Logger instance */
    public static $log = false;

    protected $payMethodId;

    protected $payMethodName;

    protected $maxAmount;

    protected $minAmount;

    public $list_success_status;

    public $enabled = true;

    public $enabledDescription = null;

    public $enabledErrorMessage = null;

    public $language = 'nl';
    public $has_fields = true;

    protected $defaultRtlo = "12345";
    protected $defaultApiKey = "api-key";

    /**
     * Setup our Gateway's id, description and other values.
     */
    public function __construct()
    {
        // The global ID for this Payment method
        $this->id = strtolower("TargetPay_{$this->payMethodId}");
        $this->supports = array(
            'products', 
            'refunds');
        
        $this->setLanguage();
        $this->setListSuccessStatus();
        // This basically defines your settings which are then loaded with init_settings()
        $this->init_form_fields();
        
        // After init_settings() is called, you can get the settings and load them into variables
        $this->init_settings();
        foreach ($this->settings as $setting_key => $value) {
            $this->$setting_key = $value;
        }
        
        // check if method valid to show in FE
        if (! $this->is_valid_for_use()) {
            $this->enabled = false;
        }
        
        // the description show in payment method(Text || payment option)
        $this->description = $this->getTargetPayMethodOption();
        
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
            'do_ssl_check'));
        // check response by method POST - report url
        add_action('woocommerce_api_wc_gateway_targetpay' . strtolower($this->payMethodId) . 'report', array(
            $this, 
            'check_targetpay_report'));
        // check response by method GET - return url
        add_action('woocommerce_api_wc_gateway_targetpay'. strtolower($this->payMethodId) .'return', array(
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
                'process_admin_options'));
        }
    }

    public function get_description(){
        return $this->description;
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
                'default' => $this->enabled ? 'yes' : 'no', 
                'description' => $this->enabledDescription ? __($this->enabledDescription, 'targetpay') : null), 
            'rtlo' => array(
                'title' => __('Digiwallet Outlet Identifier', 'targetpay'),
                'type' => 'text',
                'description' => __('Your Digiwallet Outlet Identifier, You can find this in your organization dashboard under Websites & Outlets on <a href="https://www.digiwallet.nl" target="_blank">https://www.digiwallet.nl</a>', 'targetpay'),
                'default' => $this->defaultRtlo, // Default TargetPay
                'desc_tip' => false,
                'placeholder' => 'Layoutcode'
            ),
            'token' => array(
                'title' => __('Digiwallet token', 'targetpay'), 
                'type' => 'text', 
                'description' => __('Obtain a token from <a href="http://digiwallet.nl" target="_blank">http://digiwallet.nl</a>', 'targetpay'), 
                'default' => $this->defaultApiKey,  // Default TargetPay
                'desc_tip' => false, 
                'placeholder' => 'Token'), 
            'testmode' => array(
                'title' => __('Test mode', 'targetpay'), 
                'type' => 'checkbox', 
                'label' => __('Enable testmode', 'targetpay'), 
                'default' => 'no', 
                'description' => __('Enable testmode, all orders will then be accepted even if unpaid/canceled.', 'targetpay')), 
            'orderStatus' => array(
                'title' => __('Status after payment is received', 'targetpay'), 
                'class' => 'tp-select', 
                'type' => 'select', 
                'description' => __('Choose whether you wish to set payment status after received.', 'targetpay'), 
                'default' => self::WOO_ORDER_STATUS_COMPLETED, 
                'options' => $this->list_success_status));
    }

    /**
     * Submit payment and handle response
     *
     * {@inheritdoc}
     *
     * @see WC_Payment_Gateway::process_payment()
     */
    public function process_payment($order_id, $retry = true)
    {
        global $woocommerce, $wpdb;
        
        $TargetPaySalesTable = $this->getTargetPayTableName();
        
        $order = new WC_Order($order_id);
        $orderID = $order->get_id();
        $amount = $order->order_total;
        if ($amount < $this->minAmount) {
            $message = sprintf(__('The total amount is lower than the minimum of %s euros for %s', 'targetpay'), $this->minAmount, $this->payMethodName);
            wc_add_notice($message, $notice_type = 'error');
            $order->add_order_note($message);
            
            return false;
        }
        
        if ($amount > $this->maxAmount) {
            $message = sprintf(__('The total amount is higher than the maximum of %s euros for %s', 'targetpay'), $this->maxAmount, $this->payMethodName);
            wc_add_notice($message, $notice_type = 'error');
            $order->add_order_note($message);
            
            return false;
        }
        
        $targetPay = new TargetPayCore($this->payMethodId, $this->rtlo, $this->language, ($this->testmode == 'yes') ? "1" : "0");
        $targetPay->setAmount(round($amount * 100));
        $targetPay->setDescription('Order ' . $order->get_order_number()); // $order->id
                                                                           // set return & report & cancel url
        $targetPay->setReturnUrl(add_query_arg(array(
            'wc-api' => 'WC_Gateway_TargetPay'. $this->payMethodId .'Return',
            'od' => $orderID
        ), home_url('/')));
        
        $targetPay->setReportUrl(add_query_arg(array(
            'wc-api' => 'WC_Gateway_TargetPay' . $this->payMethodId . 'Report', 
            'od' => $orderID), home_url('/')));
        // Add additional parameters
        $this->additionalParameters($order, $targetPay);
        
        $url = $targetPay->startPayment();
        
        if (! $url) {
            $message = $targetPay->getErrorMessage();
            wc_add_notice($message, $notice_type = 'error');
            $order->add_order_note("Payment could not be started: {$message}");
            
            return false;
        } else {
            $insert = $wpdb->insert($TargetPaySalesTable, array(
                'cart_id' => $order->get_order_number(), 
                'order_id' => $order->get_id(),
                'rtlo' => $this->rtlo, 
                'paymethod' => $this->payMethodId, 
                'transaction_id' => $targetPay->getTransactionId(), 
                'testmode' => $this->testmode, 
                'more' => $targetPay->getMoreInformation()), array(
                '%s', 
                '%d', 
                '%d', 
                '%s', 
                '%s', 
                '%s', 
                '%s'));
            if (! $insert) {
                $message = "Payment could not be started: can not insert into targetpay table";
                wc_add_notice($message, $notice_type = 'error');
                $order->add_order_note($message);
                
                return false;
            }
            return $this->redirectAfterStart($url, $order, $targetPay);
        }
    }

    /**
     * Update order (if report not working) && show payment result.
     * note: paypalid use to get paypalid in return
     *
     * @return mixed
     */
    public function check_targetpay_return()
    {
        global $woocommerce, $wpdb;
        
        $orderId = ! empty($_REQUEST['od']) ? esc_sql($_REQUEST['od']) : null;
        $trxid = ! empty($_REQUEST['trxid']) ? esc_sql($_REQUEST['trxid']) : (! empty($_REQUEST['paypalid']) ? esc_sql($_REQUEST['paypalid']) : null);
        if (empty($trxid)) {
            // For Afterpay report parameter
            $trxid = ! empty($_REQUEST['invoiceID']) ? esc_sql($_REQUEST['invoiceID']) : "";
        }
        if ($orderId && $trxid) {
            $order = new WC_Order($orderId);
            if ($order->post == null) {
                echo 'Order ' . htmlentities($orderId) . ' not found... ';
                die();
            }
            $extOrder = $this->getExtOrder($orderId, $trxid);
            
            if ($extOrder == null) { // Oeps something wrong... Some extra debug information for Targetpay
                echo 'Sale not found...';
                die();
            }
            if (!in_array($order->status, array_keys($this->list_success_status))) {//check order in return if status != success
                $order = $this->checkOrder($order, $extOrder);
            }
            $this->redirectAfterCheck($order, $trxid);
        }
        echo 'Order ' . htmlentities($orderId) . ' not found... ';
        die();
    }

    /**
     * Process report URL
     * Update order when status = pending.
     * note: acquirerID use to get paypalid in report
     * @return none
     */
    public function check_targetpay_report()
    {
        global $woocommerce, $wpdb;
        $orderId = ! empty($_REQUEST['od']) ? esc_sql($_REQUEST['od']) : null;
        $trxid = ! empty($_REQUEST['trxid']) ? esc_sql($_REQUEST['trxid']) : (! empty($_REQUEST['acquirerID']) ? esc_sql($_REQUEST['acquirerID']) : null);
        if (empty($trxid)) {
            // For Afterpay report parameter
            $trxid = ! empty($_REQUEST['invoiceID']) ? esc_sql($_REQUEST['invoiceID']) : "";
        }
//         if ( substr($_SERVER['REMOTE_ADDR'],0,10) == "89.184.168" ||
//             substr($_SERVER['REMOTE_ADDR'],0,9) == "78.152.58" ) {
            if ($orderId && $trxid) {
                $order = new WC_Order($orderId);
                $extOrder = $this->getExtOrder($orderId, $trxid);
                if (!$order || !$extOrder) {
                    die("order is not found");
                }
                //Ignore updating Woo Order if Order Status is Paid (completed, processing)
                if (in_array($order->status, array_keys($this->list_success_status))) {
                    echo "order $orderId had been done";
                    die;
                }
                $log_msg = 'Prev status= ' . $order->status . PHP_EOL;
                $this->checkOrder($order, $extOrder);
                $log_msg .= 'current status= ' . $order->status . PHP_EOL;
                $log_msg .= 'order number= ' . $orderId . PHP_EOL;
                $log_msg .= 'Version=wc 1.2.1';
                
                if(WP_DEBUG) {
                    error_log($log_msg);
                }
                
                die($log_msg);
             }
             die("orderId || trxid is empty");
//         } else {
//             die("IP address not correct... This call is not from Targetpay");
//         }
    }
    
    public function checkOrder(WC_Order $order, $extOrder)
    {
        $targetPay = new TargetPayCore($extOrder->paymethod, $extOrder->rtlo, $this->language, ($this->testmode == 'yes') ? 1 : 0);
        $result = $targetPay->checkPayment($extOrder->transaction_id, $this->getAdditionParametersReport($extOrder));
        if ($result || $this->testmode == 'yes') {
            if ($extOrder->paymethod == 'BW' && $targetPay->getBankwireAmountPaid() < $targetPay->getBankwireAmountDue()) {
                $order->update_status(self::WOO_ORDER_STATUS_ON_HOLD,
                    "Method $order->payment_method_title(Transaction ID $extOrder->transaction_id): " .
                    "Paid amount (" . number_format($targetPay->getBankwireAmountPaid() / 100, 2) . ") is lower than due amount" .
                    " (" . number_format($targetPay->getBankwireAmountDue() / 100, 2). "), so order is set to On Hold."
                );
                $order->set_transaction_id($extOrder->transaction_id);
                $order->save();
                $this->updateTargetPayTable($order, array('message' => null));
            }
            else {
                $order->update_status($this->orderStatus, "Method $order->payment_method_title(Transaction ID $extOrder->transaction_id): ");
                $order->set_transaction_id($extOrder->transaction_id);
                $order->save();
                $this->updateTargetPayTable($order, array('message' => null));
            }
        } else {
            $this->updateTargetPayTable($order, array('message' => $targetPay->getErrorMessage()));
            $order->update_status(self::WOO_ORDER_STATUS_FAILED, "Method $order->payment_method_title(Transaction ID $extOrder->transaction_id): ");
        }
        return $order;
    }
    
    protected function redirectAfterStart($url, WC_Order $order, TargetPayCore $targetPay)
    {
        return array(
            'result' => 'success', 
            'redirect' => $url);
    }

    /**
     * addition params for report
     *
     * @return array
     */
    protected function getAdditionParametersReport($extOrder)
    {
        return [];
    }

    /**
     * Update woocommerce_TargetPay_Sales table
     *
     * @param Object $order            
     * @param array $data            
     * @return int|false The number of rows updated, or false on error.
     */
    public function updateTargetPayTable($order, $data)
    {
        global $wpdb;
        $TargetPaySalesTable = $this->getTargetPayTableName();
        return $wpdb->update($TargetPaySalesTable, $data, array(
            'order_id' => $order->id));
    }

    /**
     * Check order status and redirect to appropriate page
     *
     * @param Object $order            
     * @param Object $extOrder            
     * @return mixed
     */
    public function redirectAfterCheck($order, $trxid)
    {
        global $woocommerce;
        global $wpdb;
        
        if ($this->testmode == 'yes') {
            return wp_redirect($this->get_return_url($order));
        }
        
        switch ($order->status) {
            case self::WOO_ORDER_STATUS_PENDING:
                return wp_redirect(add_query_arg('wc_error', urlencode(__('The payment is under processing', 'targetpay')), $woocommerce->cart->get_cart_url()));
                break;
            case self::WOO_ORDER_STATUS_FAILED:
                $extOrder = $this->getExtOrder($order->id, $trxid);
                return wp_redirect(add_query_arg('wc_error', urlencode($extOrder->message), $woocommerce->cart->get_cart_url()));
                break;
            case self::WOO_ORDER_STATUS_COMPLETED:
            case self::WOO_ORDER_STATUS_PROCESSING:
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
     *
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
            'EUR')))) {
            $this->enabledErrorMessage = __('TargetPay does not support your store currency.', 'targetpay');
            return false;
        }
        if (! $this->checkSqlTable()) {
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
            $template .= $settings;
            $template .= '</table>';
        } else {
            $template = '<div class="inline error"><p><strong>' . __('Gateway Disabled', 'woocommerce') . '</strong>: ' . $this->enabledErrorMessage . '</p></div>';
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
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$TargetPaySalesTable'") == $TargetPaySalesTable) {
            $dbColums = $wpdb->get_col('DESC ' . $TargetPaySalesTable, 0);
            $requiredColumns = array(
                'id', 
                'cart_id', 
                'order_id', 
                'rtlo', 
                'paymethod', 
                'transaction_id', 
                'testmode', 
                'message', 
                'more');
            $missing = array();
            foreach ($requiredColumns as $col) {
                if (! in_array($col, $dbColums)) {
                    $missing[] = $col;
                }
            }
            
            if (count($missing)) {
                $error = '';
                $error .= '<h1 style="color:red">' . _n('WARNING: One database column is missing', 'WARNING: Multiple database columns are missing', count($missing), 'targetpay') . '</h1>';
                if (count($missing) == 1) {
                    $error .= sprintf(__("<p>We want to inform you that one table column %s is missing in the plugin table. The plugin will <strong>not</strong> work properly.</p>", 'targetpay'), array_shift(array_values($missing)));
                } else {
                    $error .= __('</p>We want to inform you that multiple table columns are missing in the plugin table. The plugin will <strong>not</strong> work properly. Below an overview of the missing columns</p>', 'targetpay');
                    $error .= '<ul>';
                    foreach ($missing as $value) {
                        $error .= '<li>' . $value . '</li>';
                    }
                    $error .= '</ul>';
                }
                $this->enabledErrorMessage = $error;
                return false;
            }
            return true;
        }
        $this->enabledErrorMessage = "<h1 style='color:red'>" . sprintf(__("Table %s doesn't exists!!!", 'targetpay'), $TargetPaySalesTable) . "</h1>";
        return false;
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

    public function setListSuccessStatus()
    {
        $this->list_success_status = array(
            self::WOO_ORDER_STATUS_COMPLETED => __('Completed', 'targetpay'), 
            self::WOO_ORDER_STATUS_PROCESSING => __('Processing', 'targetpay'));
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
    {}

    public function setLanguage()
    {
        $this->language = strtolower(substr(get_locale(), 0, 2));
    }

    /**
     * Logging method.
     *
     * @param string $message
     *            Log message.
     * @param string $level
     *            Optional. Default 'info'.
     *            emergency|alert|critical|error|warning|notice|info|debug
     */
    public static function log($message, $level = 'info')
    {
        if (self::$log_enabled) {
            if (empty(self::$log)) {
                self::$log = wc_get_logger();
            }
            self::$log->log($level, $message, array(
                'source' => 'targetpay'));
        }
    }

    /**
     * Can the order be refunded via Targetpay?
     *
     * @param WC_Order $order            
     * @return bool
     */
    public function can_refund_order($order)
    {
        return $order && $order->get_transaction_id();
    }

    /**
     * Process refund.
     *
     * If the gateway declares 'refunds' support, this will allow it to refund.
     * a passed in amount.
     *
     * @param int $order_id            
     * @param float $amount            
     * @param string $reason            
     * @return boolean True or false based on success, or a WP_Error object.
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        // return true; //TODO remove this once done
        $order = wc_get_order($order_id);
        
        if (! $this->can_refund_order($order)) {
            $this->log('Refund Failed: No transaction ID.', 'error');
            return new WP_Error('error', __('Refund failed: No transaction ID', 'woocommerce'));
        }
        
        $extOrder = $this->getExtOrder($order_id, $order->get_transaction_id());
        
        $dataRefund = array(
            'paymethodID' => $extOrder->paymethod, 
            'transactionID' => $order->get_transaction_id(), 
            'amount' => intval(floatval($amount)), 
            'description' => $reason, 
            'internalNote' => 'Internal note - OrderId: ' . $order_id . ', Amount: ' . $amount . ', Customer Email: ' . $order->get_billing_email(), 
            'consumerName' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        
        $targetPay = new TargetPayCore($extOrder->paymethod, $extOrder->rtlo);
        
        if (! $targetPay->refund($this->token, $dataRefund)) {
            return new WP_Error('error', __($targetPay->getErrorMessage(), 'woocommerce'));
        }
        
        return true;
    }

    abstract protected function getTargetPayMethodOption();

    public function get_title(){
        return __($this->payMethodName, 'targetpay');
    }

    public function payment_fields(){
        echo $this->getTargetPayMethodOption();
    }
} // End class
