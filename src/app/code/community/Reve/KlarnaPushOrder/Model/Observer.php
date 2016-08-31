<?php
/**
 * Created by   : Dmitry Shirokovskiy.
 * Email        : info@phpwebstudio.com
 * Date         : 10.07.16
 * Time         : 19:13
 * Description  :
 */
class Reve_KlarnaPushOrder_Model_Observer extends Varien_Event_Observer
{
  public function cancelOrder(Varien_Event_Observer $observer)
  {
    $helper = Mage::helper('klarnapushorder');
    if (!$helper->getIsEnabled()){ //if module is not enabled
      if (isset($_SERVER['MAGE_IS_DEVELOPER_MODE']))
        Mage::log('Module Reve_KlarnaPushOrder is not Enabled! '.__METHOD__.', line:'.__LINE__);
      return $this;
    }

    # Get Order
    $eventOrder = $observer->getEvent()->getOrder();
    Mage::log("cancelOrder(".$eventOrder->getId().")", null, 'klarnapushorder-checkout.log', true);

    $order = Mage::getModel('sales/order')->load($eventOrder->getId());
    $klarnaOrderId = $order->getPayment()->getAdditionalInformation('klarna_order_id');
    $helper->callbackReve($klarnaOrderId, "cancel");
  }
}
