<?php
/**
 * Fontis ANZ eGate Extension
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so you can be sent a copy immediately.
 *
 * @category   Fontis
 * @package    Fontis_Anz
 * @author     Chris Norton
 * @copyright  Copyright (c) 2009 Fontis Pty. Ltd. (http://www.fontis.com.au)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
 
class Fontis_Anz_Model_Egate extends Mage_Payment_Model_Method_Cc
{

    protected $_code  = 'anz_egate';

    protected $_isGateway               = true;
    protected $_canAuthorize            = false;
    protected $_canCapture              = true;
    protected $_canCapturePartial       = false;
    protected $_canRefund               = true;
    protected $_canVoid                 = false;
    protected $_canUseInternal          = true;
    protected $_canUseCheckout          = true;
    protected $_canUseForMultishipping  = true;
    protected $_canSaveCc               = false;

    const STATUS_APPROVED = 'Approved';
    const TRANS_TYPE_CAPTURE    = 1;
    const TRANS_TYPE_REFUND     = 2;
    const TRANS_TYPE_AUTH       = 3;

    const PAYMENT_ACTION_AUTH_CAPTURE = 'authorize_capture';
    const PAYMENT_ACTION_AUTH = 'authorize';

    public function getGatewayUrl()
	{
        return 'https://migs.mastercard.com.au/vpcdps';
    }

    public function getDebug()
	{
        return Mage::getStoreConfig('payment/anz_egate/debug');
    }

    public function getLogPath()
	{
        return Mage::getBaseDir() . '/var/log/anz_egate.log';
    }

    /**
     * Returns the MerchantID as set in the configuration.
     * Note that if test mode is active then "TEST" will be prepended to the ID.
     */
    public function getMerchantId()
	{
        if(Mage::getStoreConfig('payment/anz_egate/test')) {
            return 'TEST' . Mage::getStoreConfig('payment/anz_egate/merchant_id');
        }
        else {
            return Mage::getStoreConfig('payment/anz_egate/merchant_id');
        }
    }

    public function getAccessCode()
	{
        return Mage::getStoreConfig('payment/anz_egate/access_code');
    }

    public function getUser()
	{
        return Mage::getStoreConfig('payment/anz_egate/username');
    }

    public function getPassword()
	{
        return Mage::getStoreConfig('payment/anz_egate/password');
    }

    public function validate()
	{
        parent::validate();
        $paymentInfo = $this->getInfoInstance();
        if ($paymentInfo instanceof Mage_Sales_Model_Order_Payment) {
            $currency_code = $paymentInfo->getOrder()->getBaseCurrencyCode();
        } else {
            $currency_code = $paymentInfo->getQuote()->getBaseCurrencyCode();
        }
        return $this;
    }

    public function authorize(Varien_Object $payment, $amount)
    {

    }

    public function capture(Varien_Object $payment, $amount)
    {
        // Ensure the transaction ID is always unique by including a time-based element
        $payment->setCcTransId($payment->getOrder()->getIncrementId() . '-' . date("His"));
        $this->setAmount($amount)->setPayment($payment);

        $result = $this->_call(self::TRANS_TYPE_CAPTURE, $payment);

        if($result === false) {
            $e = $this->getError();
            if (isset($e['message'])) {
                $message = Mage::helper('anz')->__('There has been an error processing your payment.') . $e['message'];
            } else {
                $message = Mage::helper('anz')->__('There has been an error processing your payment. Please try later or contact us for help.');
            }
            Mage::throwException($message);
        }
        else {
        // Check if there is a gateway error
            switch ($result['vpc_TxnResponseCode']) {
                case 0:
                    $payment->setStatus(self::STATUS_APPROVED)->setLastTransId($result['vpc_TransactionNo']);
                    break;
                case 1:         /* Unspecified failure */
                    Mage::throwException("An error has occurred between our store and our credit card processor.  Please try again. If the error persists, please come back later. Your card has not been charged.");
                    break;
                case 2:         /* Card declined */
                    Mage::throwException("The credit card details you provided have been declined by our credit card processor. Please review the payment details you have entered and try again. If the problem persists, please contact your card issuer.");
                    break;
                case 3:         /* Timeout */
                    Mage::throwException("A timeout has occurred between our store and our credit card processor.  Please try again. If the error persists, please come back later. Your card has not been charged.");
                    break;
                case 4:         /* Card expired */
                    Mage::throwException("The credit card you have entered has expired. Please review the credit card details you have entered and try again. If the problem persists, please contact your card issuer.");
                    break;
                case 5:         /* Insufficient funds */
                    Mage::throwException("The credit card you have entered does not have sufficient funds to cover your order amount. Please check your current credit card balance, review the payment details you have entered and try again. If the problem persists, please contact your card issuer.");
                    break;
                default:
                    Mage::throwException("An error has occurred whilst attempting to process your payment.  Please review your payment details and try again. If the problem persists, please come back later. Your card has not been charged.");
                    //Mage::throwException("Error code " . $result['vpc_TxnResponseCode'] . ": " . urldecode($result['vpc_Message']));
                    break;
            }
        }
        return $this;
    }

    public function refund(Varien_Object $payment, $amount)
    {
        $this->setAmount($amount)->setPayment($payment);

        $result = $this->_call(self::TRANS_TYPE_REFUND, $payment);

        if($result === false) {
            $e = $this->getError();
            if (isset($e['message'])) {
                $message = Mage::helper('anz')->__('There has been an error processing your payment.') . ' ' . $e['message'];
            } else {
                $message = Mage::helper('anz')->__('There has been an error processing your payment. Please try later or contact us for help.');
            }
            Mage::throwException($message);
        }
        else {
            if ($result['vpc_TxnResponseCode'] === '0') {
                $payment->setStatus(self::STATUS_APPROVED)->setLastTransId($result['vpc_TransactionNo']);
            }
            else {
                Mage::throwException("Error code " . $result['vpc_TxnResponseCode'] . ": " . urldecode($result['vpc_Message']));
            }
        }
    }

    protected function _call($type, Varien_Object $payment)
    {
        // Generate any needed values

        // Create expiry dat in format "YYMM"
        $date_expiry = substr($payment->getCcExpYear(), 2, 2) . str_pad($payment->getCcExpMonth(), 2, '0', STR_PAD_LEFT);

        // Most currency have two minor units (e.g. cents) and thus need to be
        // multiplied by 100 to get the correct number to send.
        $amount = $this->getAmount() * 100;

        // Several currencies do not have minor units and thus should not be
        // multiplied.
        if($payment->getOrder()->getBaseCurrencyCode() == 'JPY' ||
            $payment->getOrder()->getBaseCurrencyCode() == 'ITL' ||
            $payment->getOrder()->getBaseCurrencyCode() == 'GRD') {
            $amount = $amount / 100;
        }

        // bug description: http://gondo.webdesigners.sk/fontis-anz-extension-critical-bug/
        $amount = round($amount);
        $request = array();
        $request['vpc_Version'] = '1';
        $request['vpc_MerchTxnRef'] = $payment->getCcTransId();

        $request['vpc_Merchant'] = htmlentities($this->getMerchantId());
        $request['vpc_AccessCode'] = htmlentities($this->getAccessCode());
        $request['vpc_User'] = htmlentities($this->getUser());
        $request['vpc_Password'] = htmlentities($this->getPassword());

        $request['vpc_TxSource'] = 'INTERNET';

        if($type == self::TRANS_TYPE_REFUND) {
            $request['vpc_Command'] = 'refund';
            $request['vpc_TransNo'] = $payment->getLastTransId();
            $request['vpc_Amount'] = htmlentities($amount);
        } else {
            $request['vpc_Command'] = 'pay';
            $request['vpc_OrderInfo'] = $payment->getOrder()->getIncrementId();
            $request['vpc_CardNum'] = htmlentities($payment->getCcNumber());
            $request['vpc_CardExp'] = htmlentities($date_expiry);
            $request['vpc_CardSecurityCode'] = htmlentities($payment->getCcCid());
            $request['vpc_Amount'] = htmlentities($amount);
        }

        $postRequestData = '';
        $amp = '';
        foreach($request as $key => $value) {
            if(!empty($value)) {
                $postRequestData .= $amp . urlencode($key) . '=' . urlencode($value);
                $amp = '&';
            }
        }

        // Send the data via HTTP POST and get the response
        $http = new Varien_Http_Adapter_Curl();
        $http->setConfig(array('timeout' => 30));
        $http->write(Zend_Http_Client::POST, $this->getGatewayUrl(), '1.1', array(), $postRequestData);

        $response = $http->read();

        if ($http->getErrno()) {
            $http->close();
            $this->setError(array(
                'message' => $http->getError()
            ));
            return false;
        }

        // Close the connection
        $http->close();

        // Strip out header tags
        $response = preg_split('/^\r?$/m', $response, 2);
        $response = trim($response[1]);

        // Fill out the results
        $result = array();
        $pieces = explode('&', $response);
        foreach($pieces as $piece) {
            $tokens = explode('=', $piece);
            $result[$tokens[0]] = $tokens[1];
        }

        return $result;
    }
}
