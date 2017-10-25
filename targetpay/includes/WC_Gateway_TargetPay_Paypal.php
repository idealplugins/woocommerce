<?php
/*TargetPay Paypal Payment Gateway Class */
class WC_Gateway_TargetPay_Paypal extends WC_Gateway_TargetPay
{
    protected $payMethodId = "PYP";
    protected $payMethodName = "Paypal";
    protected $maxAmount = 10000;
    protected $minAmount = 0.84;
    public $enabled = true;
    
    /**
     * return method description
     * {@inheritDoc}
     * @see WC_Gateway_TargetPay::getTargetPayMethodOption()
     * @return string
     */
    protected function getTargetPayMethodOption()
    {
        return 'Paypal';
    }
    /**
     * Check to show/hide this payment method from checkout page
     * {@inheritDoc}
     * @see WC_Payment_Gateway::is_available()
     */
    public function is_available()
    {
        return $this->enabled == "yes" && !empty($this->rtlo);
    }
} // End Class
