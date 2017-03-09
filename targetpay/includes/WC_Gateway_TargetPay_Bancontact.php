<?php
/*TargetPay Bancontact Payment Gateway Class */
class WC_Gateway_TargetPay_Bancontact extends WC_Gateway_TargetPay
{
    protected $payMethodId = "MRC";
    protected $payMethodName = "Bancontact";
    protected $maxAmount = 10000;
    public $enabled = true;
    
    /**
     * return method description
     * {@inheritDoc}
     * @see WC_Gateway_TargetPay::getTagetPayMethodOption()
     * @return string
     */
    protected function getTagetPayMethodOption()
    {
        return 'Bancontact';
    }
} // End Class
