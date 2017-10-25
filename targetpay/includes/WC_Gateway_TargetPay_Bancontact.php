<?php
/*TargetPay Bancontact Payment Gateway Class */
class WC_Gateway_TargetPay_Bancontact extends WC_Gateway_TargetPay
{
    protected $payMethodId = "MRC";
    protected $payMethodName = "Bancontact";
    protected $maxAmount = 10000;
    protected $minAmount = 0.49;
    public $enabled = true;
    
    /**
     * return method description
     * {@inheritDoc}
     * @see WC_Gateway_TargetPay::getTargetPayMethodOption()
     * @return string
     */
    protected function getTargetPayMethodOption()
    {
        return 'Bancontact';
    }


} // End Class
