<?php
/**
 * Copyright (c) 2019. All rights reserved ePay Payment Solutions.
 *
 * This program is free software. You are allowed to use the software but NOT allowed to modify the software.
 * It is also not legal to do any changes to the software and distribute it in your own name / brand.
 *
 * All use of the payment modules happens at your own risk. We offer a free test account that you can use to test the module.
 *
 * @author    ePay Payment Solutions
 * @copyright ePay Payment Solutions (https://epay.dk)
 * @license   ePay Payment Solutions
 */

namespace Epay\Payment\Model\Method\Epay;

use Epay\Payment\Model\Api\EpayApi;
use Epay\Payment\Model\Api\EpayApiModels;
use Epay\Payment\Helper\EpayConstants;
use Magento\Sales\Model\Order\Payment\Transaction;

class Payment extends \Epay\Payment\Model\Method\AbstractPayment implements
    \Epay\Payment\Model\Method\IPayment
{
    const METHOD_CODE = 'epay';
    const METHOD_REFERENCE = 'epayEPayReference';

    protected $_code = self::METHOD_CODE;

    protected $_infoBlockType = 'Epay\Payment\Block\Info\View';

    /**
     * Payment Method feature
     */
    protected $_isGateway = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = true;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canVoid = true;
    protected $_canDelete = true;


    /**
     * Get ePay Auth object
     *
     * @return \Epay\Payment\Model\Api\Epay\Request\Models\Auth
     */
    public function getAuth($storeId)
    {
        if (!$storeId) {
            $storeId = $this->getStoreManager()->getStore()->getId();
        }
        return $this->_epayHelper->generateEpayAuth($storeId);
    }

    /**
     * Get ePay Checkout payment window
     *
     * @param \Magento\Sales\Model\Order
     * @return \Epay\Payment\Model\Api\Epay\Request\Payment
     */
    public function getPaymentWindow($order)
    {
        if (!isset($order)) {
            return null;
        }
        return $this->createPaymentRequest($order);
    }

    /**
     * Create the ePay payment window Request url
     *
     * @param \Magento\Sales\Model\Order
     * @return \Epay\Payment\Model\Api\Epay\Request\Payment
     */
    public function createPaymentRequest($order)
    {
        $storeId = $order->getStoreId();
        $currency = $order->getBaseCurrencyCode();
        $minorunits = $this->_epayHelper->getCurrencyMinorUnits($currency);
        $roundingMode = $this->getConfigData(
            EpayConstants::ROUNDING_MODE,
            $storeId
        );
        $totalAmountMinorUnits = $this->_epayHelper->convertPriceToMinorunits(
            $order->getBaseTotalDue(),
            $minorunits,
            $roundingMode
        );
        $paymentRequest = $this->_epayHelper->getEpayApiModel(
            EpayApiModels::REQUEST_PAYMENT
        );
        $paymentRequest->encoding = "UTF-8";
        $paymentRequest->cms = $this->_epayHelper->getModuleHeaderInfo();
        $paymentRequest->windowstate = "3";
        $paymentRequest->mobile = $this->getConfigData(
            EpayConstants::ENABLE_MOBILE_PAYMENT_WINDOW,
            $storeId
        );
        $paymentRequest->merchantnumber = $this->getAuth($storeId)->merchantNumber;
        $paymentRequest->windowid = $this->getConfigData(
            EpayConstants::PAYMENT_WINDOW_ID,
            $storeId
        );
        $paymentRequest->amount = $totalAmountMinorUnits;
        $paymentRequest->currency = $currency;
        $paymentRequest->orderid = $order->getIncrementId();
        $paymentRequest->accepturl = $this->_urlBuilder->getUrl(
            'epay/epay/accept',
            ['_secure' => $this->_request->isSecure()]
        );
        $paymentRequest->cancelurl = $this->_urlBuilder->getUrl(
            'epay/epay/cancel',
            ['_secure' => $this->_request->isSecure()]
        );
        $paymentRequest->callbackurl = $this->_urlBuilder->getUrl(
            'epay/epay/callback',
            ['_secure' => $this->_request->isSecure()]
        );
        $paymentRequest->instantcapture = $this->getConfigData(
            EpayConstants::INSTANT_CAPTURE,
            $storeId
        );
        $paymentRequest->language = $this->_epayHelper->calcLanguage();
        $paymentRequest->ownreceipt = $this->getConfigData(
            EpayConstants::OWN_RECEIPT,
            $storeId
        );
        $paymentRequest->timeout = $this->getConfigData(
            EpayConstants::TIMEOUT,
            $storeId
        );
        $paymentRequest->invoice = $this->createInvoice(
            $order,
            $minorunits,
            $roundingMode
        );
        $paymentRequest->hash = $this->_epayHelper->calcEpayMd5Key(
            $order,
            $paymentRequest
        );

        return $paymentRequest;
    }

    /**
     * Create Invoice
     *
     * @param \Magento\Sales\Model\Order $order
     * @param int $minorunits
     * @param string $roundingMode
     * @return string
     */
    public function createInvoice($order, $minorunits, $roundingMode)
    {
        if ($this->getConfigData(EpayConstants::ENABLE_INVOICE_DATA)) {
            $invoice = $this->_epayHelper->getEpayApiModel(
                EpayApiModels::REQUEST_MODEL_INVOICE
            );
            $orderBillingAddress = $order->getBillingAddress();
            if ($orderBillingAddress) {
                $customer = $this->_epayHelper->getEpayApiModel(
                    EpayApiModels::REQUEST_MODEL_CUSTOMER
                );
                $customer->firstname = $orderBillingAddress->getFirstname();
                $customer->lastname = $orderBillingAddress->getLastname();
                $customer->address = $orderBillingAddress->getStreet()[0];
                $customer->zip = $orderBillingAddress->getPostcode();
                $customer->city = $orderBillingAddress->getCity();
                $customer->country = $orderBillingAddress->getCountryId();
                $customer->phone = $orderBillingAddress->getTelephone();
                if ($orderBillingAddress->getEmail()) {
                    $customer->emailaddress = $orderBillingAddress->getEmail();
                } else {
                    $customer->emailaddress = $order->getCustomerEmail();
                }

                $invoice->customer = $customer;
            }

            $orderShippingAddress = $order->getShippingAddress();
            if (!$orderShippingAddress) {
                $orderShippingAddress = $orderBillingAddress;
            }

            if ($orderShippingAddress) {
                $shippingAddress = $this->_epayHelper->getEpayApiModel(
                    EpayApiModels::REQUEST_MODEL_SHIPPINGADDRESS
                );
                $shippingAddress->firstname = $orderShippingAddress->getFirstname();
                $shippingAddress->lastname = $orderShippingAddress->getLastname();
                $shippingAddress->address = $orderShippingAddress->getStreet()[0];
                $shippingAddress->zip = $orderShippingAddress->getPostcode();
                $shippingAddress->city = $orderShippingAddress->getCity();
                $shippingAddress->country = $orderShippingAddress->getCountryId();
                $shippingAddress->phone = $orderShippingAddress->getTelephone();
                $invoice->shippingaddress = $shippingAddress;
            }
            $invoice->lines = [];

            // Add order lines
            $items = $order->getAllVisibleItems();
            foreach ($items as $item) {
                $description = empty($item->getDescription()) ? $item->getName(
                ) : $item->getDescription();
                $invoice->lines[] = [
                    "id" => $item->getSku(),
                    "description" => $this->removeSpecialCharacters($description),
                    "quantity" => intval($item->getQtyOrdered()),
                    "price" => $this->calculateItemPrice(
                        $item,
                        $minorunits,
                        $roundingMode
                    ),
                    "vat" => floatval($item->getTaxPercent())
                ];
            }

            // add shipment as line
            $baseShippingAmount = $order->getBaseShippingAmount();
            if ($baseShippingAmount > 0) {
                $shippingText = __("Shipping");
                $shippingDescription = $order->getShippingDescription();
                $invoice->lines[] = [
                    "id" => $shippingText,
                    "description" => isset($shippingDescription) ? $shippingDescription : $shippingText,
                    "quantity" => 1,
                    "price" => $this->_epayHelper->convertPriceToMinorunits(
                        $baseShippingAmount,
                        $minorunits,
                        $roundingMode
                    ),
                    "vat" => $this->calculateShippingVat($order)
                ];
            }
            // Fix for bug in Magento 2 shipment discont calculation
            $baseShipmentDiscountAmount = $order->getBaseShippingDiscountAmount();
            if ($baseShipmentDiscountAmount > 0) {
                $invoice->lines[] = [
                    "id" => "shipping_discount",
                    "description" => __("Shipping discount"),
                    "quantity" => 1,
                    "price" => $this->_epayHelper->convertPriceToMinorunits(
                        ($baseShipmentDiscountAmount * -1),
                        $minorunits,
                        $roundingMode
                    ),
                ];
            }

            return json_encode($invoice, JSON_UNESCAPED_UNICODE);
        } else {
            return "";
        }
    }

    /**
     * Calculate a single item price and convert into minorunits
     *
     * @param \Magento\Sales\Model\Order\Item $item
     * @param int $minorunits
     * @param string $roundingMode
     * @return integer
     */
    public function calculateItemPrice($item, $minorunits, $roundingMode)
    {
        $itemPrice = $item->getBaseRowTotal() > 0 ? $item->getBaseRowTotal(
            ) / intval($item->getQtyOrdered()) : 0;

        if ($item->getBaseDiscountAmount() > 0) {
            $itemDiscount = $item->getBaseDiscountAmount() / intval(
                    $item->getQtyOrdered()
                );
            $itemPrice = $itemPrice - $itemDiscount;
        }
        $itemPriceMinorUnits = $this->_epayHelper->convertPriceToMinorunits(
            $itemPrice,
            $minorunits,
            $roundingMode
        );
        return $itemPriceMinorUnits;
    }

    /**
     * Calculate the shipment Vat based on shipment tax and base shipment price
     *
     * @param \Magento\Sales\Model\Order $order
     * @return int
     */
    public function calculateShippingVat($order)
    {
        if ($order->getBaseShippingTaxAmount() <= 0 || $order->getBaseShippingAmount(
            ) <= 0) {
            return 0;
        }
        $shippingVat = round(
            ($order->getBaseShippingTaxAmount() / $order->getBaseShippingAmount(
                )) * 100
        );
        return $shippingVat;
    }

    /**
     * Remove special characters
     *
     * @param string $value
     * @return string
     */
    public function removeSpecialCharacters($value)
    {
        return preg_replace('/[^\p{Latin}\d ]/u', '', $value);
    }

    /**
     * Capture payment
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();
        try {
            $transactionId = $payment->getAdditionalInformation(
                $this::METHOD_REFERENCE
            );
            $isInstantCapure = $payment->getAdditionalInformation(
                EpayConstants::INSTANT_CAPTURE
            );
            if ($isInstantCapure === true) {
                $payment->setTransactionId(
                    $transactionId . '-' . EpayConstants::INSTANT_CAPTURE
                )
                    ->setIsTransactionClosed(true)
                    ->setParentTransactionId($transactionId);

                return $this;
            }

            if (!$this->canOnlineAction($payment)) {
                throw new \Exception(
                    __(
                        "The capture action could not, be processed online. Please enable remote payment processing from the module configuration"
                    )
                );
            }

            $currency = $order->getBaseCurrencyCode();
            $minorunits = $this->_epayHelper->getCurrencyMinorunits($currency);
            $roundingMode = $this->getConfigData(
                EpayConstants::ROUNDING_MODE,
                $order->getStoreId()
            );
            $amountMinorunits = $this->_epayHelper->convertPriceToMinorunits(
                $amount,
                $minorunits,
                $roundingMode
            );
            $storeId = $order->getStoreId();
            $auth = $this->getAuth($storeId);
            $actionProvider = $this->_epayHelper->getEPayApi(EpayApi::API_ACTION);
            $captureResponse = $actionProvider->capture(
                $amountMinorunits,
                $transactionId,
                $auth
            );

            $message = "";
            if (!$this->_epayHelper->validateEpayApiResult(
                $captureResponse,
                $transactionId,
                $auth,
                $message
            )) {
                throw new \Exception(
                    __("The capture action failed.") . ' - ' . $message
                );
            }

            $payment->setTransactionId(
                $transactionId . '-' . Transaction::TYPE_CAPTURE
            )
                ->setIsTransactionClosed(true)
                ->setParentTransactionId($transactionId);

            return $this;
        } catch (\Exception $ex) {
            $errorMessage = "({$order->getIncrementId()}) " . $ex->getMessage();
            $this->_messageManager->addError($errorMessage);
            throw $ex;
        }
    }

    /**
     * Refund payment
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();
        $id = $order->getIncrementId();
        try {
            $creditMemo = $payment->getCreditmemo();
            $id = $creditMemo->getInvoice()->getIncrementId();

            if (!$this->canOnlineAction($payment)) {
                throw new \Exception(
                    __(
                        "The refund action could not, be processed online. Please enable remote payment processing from the module configuration"
                    )
                );
            }

            $transactionId = $payment->getAdditionalInformation(
                $this::METHOD_REFERENCE
            );

            $currency = $order->getBaseCurrencyCode();
            $storeId = $order->getStoreId();
            $minorunits = $this->_epayHelper->getCurrencyMinorunits($currency);
            $roundingMode = $this->getConfigData(
                EpayConstants::ROUNDING_MODE,
                $storeId
            );
            $amountMinorunits = $this->_epayHelper->convertPriceToMinorunits(
                $amount,
                $minorunits,
                $roundingMode
            );
            $auth = $this->getAuth($storeId);
            $actionProvider = $this->_epayHelper->getEPayApi(EpayApi::API_ACTION);
            $creditResponse = $actionProvider->credit(
                $amountMinorunits,
                $transactionId,
                $auth
            );

            $message = "";
            if (!$this->_epayHelper->validateEpayApiResult(
                $creditResponse,
                $transactionId,
                $auth,
                $message
            )) {
                throw new \Exception(
                    __("The refund action failed.") . ' - ' . $message
                );
            }

            $payment->setTransactionId(
                $transactionId . '-' . Transaction::TYPE_REFUND
            )
                ->setIsTransactionClosed(true)
                ->setParentTransactionId($transactionId);

            return $this;
        } catch (\Exception $ex) {
            $errorMessage = "({$id}) " . $ex->getMessage();
            $this->_messageManager->addError($errorMessage);
            throw $ex;
        }
    }

    /**
     * Cancel payment
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @return $this
     */
    public function cancel(\Magento\Payment\Model\InfoInterface $payment)
    {
        try {
            if ($this->_request->getActionName() == 'save' && $this->_epayHelper->getEpayConfigData('keep_payment_onedit', $payment->getOrder()->getStoreId())) {
                $this->_messageManager->addSuccess(__("The payment have not been voided for") . ' (' . $payment->getOrder()->getIncrementId() .')');
                return $this;
            }
            $this->void($payment);
            $this->_messageManager->addSuccess(
                __("The payment have been voided") . ' (' . $payment->getOrder(
                )->getIncrementId() . ')'
            );
        } catch (\Exception $ex) {
            $this->_messageManager->addError($ex->getMessage());
        }

        return $this;
    }

    /**
     * Void payment
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @return $this
     */
    public function void(\Magento\Payment\Model\InfoInterface $payment)
    {
        $order = $payment->getOrder();
        try {
            if (!$this->canOnlineAction($payment)) {
                throw new \Exception(
                    __(
                        "The void action could not, be processed online. Please enable remote payment processing from the module configuration"
                    )
                );
            }

            $transactionId = $payment->getAdditionalInformation(
                $this::METHOD_REFERENCE
            );
            $storeId = $order->getStoreId();
            $auth = $this->getAuth($storeId);
            $actionProvider = $this->_epayHelper->getEPayApi(EpayApi::API_ACTION);
            $deleteResponse = $actionProvider->delete($transactionId, $auth);

            $message = "";
            if (!$this->_epayHelper->validateEpayApiResult(
                $deleteResponse,
                $transactionId,
                $auth,
                $message
            )) {
                throw new \Exception(
                    __('The void action failed.') . ' - ' . $message
                );
            }

            $payment->setTransactionId($transactionId . '-' . Transaction::TYPE_VOID)
                ->setIsTransactionClosed(true)
                ->setParentTransactionId($transactionId);

            $this->cancelSurchargeFeeItem($payment);

            return $this;
        } catch (\Exception $ex) {
            $errorMessage = "(OrderId: {$order->getIncrementId()}) " . $ex->getMessage(
                );
            $this->_messageManager->addError($errorMessage);
            throw $ex;
        }
    }

    /**
     * Get ePay Checkout Transaction
     *
     * @param mixed $transactionId
     * @param string $storeId
     * @param string &$message
     * @return \Epay\Payment\Model\Api\Epay\Response\Models\TransactionInformationType|null
     */
    public function getTransaction($transactionId, $storeId, &$message)
    {
        try {
            if (!$this->getConfigData(EpayConstants::REMOTE_INTERFACE)) {
                return null;
            }
            $auth = $this->getAuth($storeId);
            $actionProvider = $this->_epayHelper->getEpayApi(EpayApi::API_ACTION);
            $transactionResponse = $actionProvider->getTransaction(
                $transactionId,
                $auth
            );

            if (!$this->_epayHelper->validateEpayApiResult(
                $transactionResponse,
                $transactionId,
                $auth,
                $message
            )) {
                return null;
            }

            return $transactionResponse->transactionInformation;
        } catch (\Exception $ex) {
            $errorMessage = "(TransactionId: {$transactionId}) " . $ex->getMessage();
            $this->_messageManager->addError($errorMessage);
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function canCapture()
    {
        if ($this->_canCapture && $this->canAction($this::METHOD_REFERENCE)) {
            return true;
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function canRefund()
    {
        if ($this->_canRefund && $this->canAction($this::METHOD_REFERENCE)) {
            return true;
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function canVoid()
    {
        if ($this->_canVoid && $this->canAction($this::METHOD_REFERENCE)) {
            return true;
        }

        return false;
    }

    /**
     * Retrieve an url for merchant payment logoes
     *
     * @return string
     */
    public function getEpayPaymentTypeUrl()
    {
        $actionProvider = $this->_epayHelper->getEpayApi(EpayApi::API_ACTION);
        return $actionProvider->getPaymentLogoUrl(
            $this->getAuth(null)->merchantNumber
        );
    }

    /**
     * Retrieve an url ePay Logo
     *
     * @return string
     */
    public function getEpayLogoUrl()
    {
        $actionProvider = $this->_epayHelper->getEpayApi(EpayApi::API_ACTION);
        return $actionProvider->getEpayLogoUrl();
    }

    /**
     * Retrieve an url for the ePay Checkout action
     *
     * @return string
     */
    public function getCheckoutUrl()
    {
        return $this->_urlBuilder->getUrl(
            'epay/epay/checkout',
            ['_secure' => $this->_request->isSecure()]
        );
    }

    /**
     * Retrieve an url for the ePay Decline action
     *
     * @return string
     */
    public function getCancelUrl()
    {
        return $this->_urlBuilder->getUrl(
            'epay/epay/cancel',
            ['_secure' => $this->_request->isSecure()]
        );
    }

    /**
     * Retrieve an url for the ePay Checkout Paymentwindow Js
     *
     * @return string
     */
    public function getEPayPaymentWindowJsUrl()
    {
        $assetsApi = $this->_epayHelper->getEpayApi(EpayApi::API_ACTION);
        return $assetsApi->getPaymentWindowJSUrl();
    }

    /**
     * Retrieve an url for the ePay Paymentwindow
     *
     * @return string
     */
    public function getEPayPaymentWindowUrl($order)
    {
        $paymentRequest = $this->createPaymentRequest($order);
        
        $assetsApi = $this->_epayHelper->getEpayApi(EpayApi::API_ACTION);

        $url = $assetsApi->getPaymentWindowUrl($paymentRequest);

        if(is_object($url))
        {
            return $url->url;
        }
    }
}
