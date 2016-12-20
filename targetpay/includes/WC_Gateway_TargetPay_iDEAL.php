<?php
/*TargetPay IDeal Payment Gateway Class */
class WC_Gateway_TargetPay_iDEAL extends WC_Gateway_TargetPay
{
    protected $payMethodId = "IDE";
    protected $payMethodName = "iDEAL";
    protected $maxAmount = 10000;
    public $enabled = true;
    
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
     * @see WC_Gateway_TargetPay::getTagetPayMethodOption()
     * @return string
     */
    protected function getTagetPayMethodOption()
    {
        $html = '';
        $targetPay = new TargetPayCore($this->payMethodId);
        $temp = $targetPay->getBankList();
        if ($this->idealView == 'yes') {
            foreach ($temp as $key => $value) {
                $html .= '<input type="radio" name="bank" id="'. $key . '" value="'. $key .
                '"><label for="'.$key.'">'.$value.'</label><br />';
            }
        } else {
            $html .= '<select name="bank" style="width:170px; padding: 2px; margin-left: 7px">';
            foreach ($temp as $key => $value) {
                $html .= '<option value="'.$key.'">'.$value.'</option>';
            }
            $html .= '</select>';
        }
        return $html;
    }
} // End Class
