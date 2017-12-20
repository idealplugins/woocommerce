<?php
/*TargetPay IDeal Payment Gateway Class */
class WC_Gateway_TargetPay_iDEAL extends WC_Gateway_TargetPay
{
    protected $payMethodId = "IDE";
    protected $payMethodName = "iDEAL";
    protected $maxAmount = 10000;
    protected $minAmount = 0.84;
    public $enabled = true;
    
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
                'description' => $this->enabledDescription ? __($this->enabledDescription, 'targetpay') : null
            ),
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
                'default' => $this->defaultApiKey, // Default TargetPay
                'desc_tip' => false,
                'placeholder' => 'Token'
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
            ),
            'orderStatus' => array(
                'title' => __('Status after payment is received', 'targetpay'),
                'class' => 'tp-select',
                'type' => 'select',
                'description' => __('Choose whether you wish to set payment status after received.', 'targetpay'),
                'default' => self::WOO_ORDER_STATUS_COMPLETED,
                'options' => array(
                    self::WOO_ORDER_STATUS_COMPLETED => __('Completed', 'targetpay'),
                    self::WOO_ORDER_STATUS_PROCESSING => __('Processing', 'targetpay')
                )
            )
        );
    }
    
    /**
     *  Bind bank ID
     */
    public function additionalParameters(WC_Order $order, TargetPayCore $targetPay)
    {
        if (isset($_POST["bank"])) {
            $targetPay->setBankId($_POST["bank"]);
        }
    }

    /**
     * return method option for Ideal method
     * {@inheritDoc}
     * @see WC_Gateway_TargetPay::getTargetPayMethodOption()
     * @return string
     */
    protected function getTargetPayMethodOption()
    {
        $html = '';
        $targetPay = new TargetPayCore($this->payMethodId);
        $temp = $targetPay->getBankList();
        if (isset($this->idealView) && $this->idealView == 'yes') {
            foreach ($temp as $key => $value) {
                $html .= '<input type="radio" name="bank" id="'. $key . '" value="'. $key .
                '"><label for="'.$key.'">'.__($value, 'targetpay').'</label><br />';
            }
        } else {
            $html .= '<select name="bank" style="width:170px; padding: 2px; margin-left: 7px">';
            foreach ($temp as $key => $value) {
                $html .= '<option value="'.$key.'">'.__($value, 'targetpay').'</option>';
            }
            $html .= '</select>';
        }
        return $html;
    }
} // End Class
