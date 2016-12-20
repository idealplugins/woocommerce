<?php
/*TargetPay Paysafecard Payment Gateway Class */
class WC_Gateway_TargetPay_Paysafecard extends WC_Gateway_TargetPay
{
    protected $payMethodId = "WAL";
    protected $maxAmount = 150;
    protected $payMethodName = "Paysafecard";
    public $enabled = true;
    
    /**
     * return method description
     * {@inheritDoc}
     * @see WC_Gateway_TargetPay::getTagetPayMethodOption()
     * @return string
     */
    protected function getTagetPayMethodOption()
    {
        return 'Paysafecard';
    }
} // End Class
