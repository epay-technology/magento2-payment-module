<?php

namespace Epay\Payment\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface as HttpGetActionInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Payment\Transaction;
use Epay\Payment\Helper\Data;
use Epay\Payment\Helper\EpayConstants;
use Epay\Payment\Model\Method\Epay\Payment as EpayPayment;

class Move extends Action implements HttpGetActionInterface
{
    /**
     * @var Data
     */
    protected $helper;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    protected $searchCriteriaBuilder;

    /**
     * @param Action\Context $context
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param Data $helper
     */
    public function __construct(
        Action\Context $context,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        Data $helper
    ) {
        parent::__construct($context);
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->helper = $helper;
    }

    /**
     * @return \Magento\Framework\Controller\ResultInterface
     * @throws \Exception
     */
    public function execute()
    {
        $childOrderId = $this->getRequest()->getParam('orderid');
        $parentOrderId = $this->getRequest()->getParam('parentorderid');

        if (!empty($childOrderId) && !empty($parentOrderId)) {
            try {
                $childOrder = $this->getOrderByIncrementId($childOrderId);
                $parentOrder = $this->getOrderByIncrementId($parentOrderId);
                $childOrder = $this->transfereSurcharge($parentOrder, $childOrder);

                if ($childOrder->getBaseGrandTotal() <= $parentOrder->getBaseGrandTotal()) {

                    $childOrder = $this->movePayment($parentOrder, $childOrder);
                    $message = __("Payment moved from parent order #%1", $parentOrderId);
                    $status = $this->helper->getEpayConfigData('order_status', $parentOrder->getStoreId());
                    $childOrder->setState(\Magento\Sales\Model\Order::STATE_PROCESSING)->setStatus($status);
                    $childOrder->addCommentToStatusHistory($message);
                    $this->orderRepository->save($childOrder);
                    $this->messageManager->addSuccess($message);
                } else {
                    throw new \Exception(__("The transaction could not be moved because the amount of the edited order exceeds the transaction amount - Go to the ePay administration to handle the payment manually."));
                }
            } catch (\Exception $e) {
                $this->messageManager->addError($e->getMessage());
            }
        } else {
            $this->messageManager->addInfo(__("Nothing changed"));
        }
        return $this->resultRedirectFactory->create()->setPath('sales/order/view', array('order_id'=> $this->getRequest()->getParam('order_id')));
    }

    /**
     * @param \Magento\Sales\Model\Order $parentOrder
     * @param \Magento\Sales\Model\Order $childOrder
     * @return \Magento\Sales\Model\Order
     */
    protected function movePayment($parentOrder, $childOrder)
    {
        $childPayment = $childOrder->getPayment();
        $parentPayment = $parentOrder->getPayment();
        $transactionId = $parentPayment->getAdditionalInformation(EpayPayment::METHOD_REFERENCE);
        $isInstantCapture = $parentPayment->getAdditionalInformation(EpayConstants::INSTANT_CAPTURE);
        $ccType = $parentPayment->getData('cc_type');
        $ccNumberEnc = $parentPayment->getData('cc_number_enc');

        $childPayment->setAdditionalInformation(EpayPayment::METHOD_REFERENCE, $transactionId);
        $childPayment->setAdditionalInformation(EpayConstants::INSTANT_CAPTURE, $isInstantCapture);
        $childPayment->setAdditionalInformation('currentpaymentincrementid', $childOrder->getRealOrderId());
        $childPayment->setTransactionId($transactionId)->setIsTransactionClosed(0);
        $childPayment->setData('cc_type', $ccType);
        $childPayment->setData('cc_number_enc', $ccNumberEnc);

        $transaction = $childPayment->addTransaction(Transaction::TYPE_AUTH);
        $transaction->setAdditionalInformation("Transaction ID", $transactionId);
        $transaction->save();
        $childPayment->save();

        return $childOrder;
    }

    /**
     * @param \Magento\Sales\Model\Order $parentOrder
     * @param \Magento\Sales\Model\Order $childOrder
     * @return \Magento\Sales\Model\Order
     */
    protected function transfereSurcharge($parentOrder, $childOrder)
    {
        foreach ($parentOrder->getAllItems() as $item) {
            if ($item->getSku() === 'surcharge_fee') {
                foreach ($childOrder->getAllItems() as $childItem) {
                    if ($childItem->getSku() === EpayConstants::EPAY_SURCHARGE) {
                        return $childOrder;
                    }
                }
                $feeItem = $this->helper->createSurchargeItem(
                    $item->getBaseRowTotal(),
                    $item->getRowTotal(),
                    $childOrder->getStoreId(),
                    $childOrder->getId(),
                    $item->getName()
                );
                $childOrder->addItem($feeItem);
                $this->updateOrderTotals($childOrder, $item->getBaseRowTotal(), $item->getRowTotal());

                $feeMessage = $item->getName() . ' ' . __("added to order");
                $childOrder->addCommentToStatusHistory($feeMessage);
                break;
            }
        }
        return $childOrder;
    }

    /**
     * Update order totals
     *
     * @param \Magento\Sales\Model\Order $order
     * @param float $baseAmount
     * @param float $amount
     */
    protected function updateOrderTotals($order, $baseAmount, $amount)
    {
        $order->setBaseGrandTotal($order->getBaseGrandTotal() + $baseAmount);
        $order->setBaseSubtotal($order->getBaseSubtotal() + $baseAmount);
        $order->setGrandTotal($order->getGrandTotal() + $amount);
        $order->setSubtotal($order->getSubtotal() + $amount);
    }

    /**
     * @param int $incrementId
     * @return \Magento\Sales\Model\Order
     */
    protected function getOrderByIncrementId($incrementId)
    {
        $this->searchCriteriaBuilder->addFilter('increment_id', $incrementId, 'eq');
        $searchCriteria = $this->searchCriteriaBuilder->create();
        $order = $this->orderRepository->getList($searchCriteria)->getItems();
        return reset($order);
    }

    /**
     * @return boolean
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Epay_Payment::move');
    }
}
