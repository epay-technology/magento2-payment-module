<?php

namespace Epay\Payment\Plugin\Magento\Sales\Block\Adminhtml\Order;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Sales\Block\Adminhtml\Order\View;
use Epay\Payment\Helper\Data;
use Epay\Payment\Model\Method\Epay\Payment as EpayPayment;

class ViewPlugin
{
    /**
     * @var AuthorizationInterface
     */
    protected $authorization;

    /**
     * @var Data
     */
    protected $helper;

    /**
     * @param AuthorizationInterface $authorization
     * @param Data $helper
     */
    public function __construct(
        AuthorizationInterface $authorization,
        Data $helper
    ) {
        $this->authorization = $authorization;
        $this->helper = $helper;
    }

    /**
     * @param View $subject
     * @return void
     */
    public function beforeSetLayout(View $subject)
    {
        $order = $subject->getOrder();

        if ($this->canAddMovePaymentButton($order)) {
            $payment = $order->getPayment();
            if (isset($payment) && ($payment->getAdditionalInformation('currentpaymentincrementid') === null || $payment->getAdditionalInformation('currentpaymentincrementid') != $order->getRealOrderId())) {
                $subject->addButton(
                    'button_movepayment',
                    [
                        'label' => __('Move Payment From Parent'),
                        'onclick' => 'setLocation(\'' . $subject->getUrl('epay/order/move/', array('orderid' => $order->getRealOrderId(), 'parentorderid' => $order->getRelationParentRealId())) . '\')',
                        'class' => 'scalable go'
                    ]
                );
            }
        }
        return null;
    }

    /**
     * Determine if the "Move Payment From Parent" button can be added
     *
     * @param \Magento\Sales\Model\Order $order
     * @return bool
     */
    private function canAddMovePaymentButton($order)
    {
        return $this->validatePaymentMethod($order)
            && !$order->isCanceled()
            && $order->getRelationParentRealId() !== null
            && $this->helper->getEpayConfigData('keep_payment_onedit', $order->getStoreId())
            && $this->authorization->isAllowed('Epay_Payment::move');
    }

    /**
     * Validate the payment method of the order
     *
     * @param \Magento\Sales\Model\Order $order
     * @return bool
     */
    private function validatePaymentMethod($order)
    {
        $payment = $order->getPayment();
        if (!$payment) {
            return false;
        }
        $currentMethod = $payment->getMethod();
        return $currentMethod === EpayPayment::METHOD_CODE;
    }
}
