<?php
/*TargetPay Sofort Payment Gateway Class */
class WC_Gateway_TargetPay_Sofort extends WC_Gateway_TargetPay
{
    protected $payMethodId = "DEB";
    protected $payMethodName = "Sofort Banking";
    protected $maxAmount = 10000;
    public $enabled = true;
    
    /**
     *  Bind country ID
     */
    public function additionalParameters(WC_Order $order, TargetPayCore $targetPay)
    {
        if (isset($_POST["country"])) {
            $targetPay->setCountryId($_POST["country"]);
        }
    }
    
    /**
     * build method option for Sofort Banking method
     * {@inheritDoc}
     * @see WC_Gateway_TargetPay::getTagetPayMethodOption()
     * @return string
     */
    protected function getTagetPayMethodOption()
    {
        $html = '';
        $targetPay = new TargetPayCore($this->payMethodId);
        $temp = $targetPay->getBankList();
        $html .= '<select name="country" style="width:220px; padding: 2px; margin-left: 7px">';
        foreach ($temp as $key => $value) {
            $countryId = str_replace('DEB', '', $key);
            $countryName = str_replace('Sofort Banking: ', '', $value);
            $html .= '<option value="'.$countryId.'">'.$countryName.'</option>';
        }
        $html .= '</select>';
        return $html;
    }
} // End Class
