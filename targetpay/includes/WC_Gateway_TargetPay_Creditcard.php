<?php
/*TargetPay Creditcard Payment Gateway Class */
class WC_Gateway_TargetPay_Creditcard extends WC_Gateway_TargetPay
{
    protected $payMethodId = "CC";
    protected $payMethodName = "Visa/Mastercard";
    public $enabled = false;
    public $enabledDescription = 'Only possible when creditcard is activated on your targetpay account';
    protected $maxAmount = 10000;
    
    /**
     * return method description
     * {@inheritDoc}
     * @see WC_Gateway_TargetPay::getTagetPayMethodOption()
     * @return string
     */
    protected function getTagetPayMethodOption()
    {
        return 'Visa/Mastercard';
    }
} // End Class