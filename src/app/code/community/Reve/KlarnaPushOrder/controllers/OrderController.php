<?php
/**
 * Created by   : Dmitry Shirokovskiy.
 * Email        : info@phpwebstudio.com
 * Date         : 10.07.16
 * Time         : 19:22
 * Description  : url to call pushing order
 * http[s]://www.your_lovely_shop.tld/reve_klarna/order/?klarna_order=NNNN&storeID=MM
 * or
 * http[s]://www.your_lovely_shop.tld/reve_klarna/order/index/klarna_order/NNNN/storeID/MM
 */
class Reve_KlarnaPushOrder_OrderController extends Mage_Checkout_Controller_Action
{
  /**
   * Retrieve Reve_Klarna Helper
   *
   * @return Reve_Klarna_Helper_Data
   */
  protected function _getHelper()
  {
    return Mage::helper('klarnapushorder');
  }

  protected function _getDefaultStoreId()
  {
    return Mage::app()->getWebsite()->getDefaultGroup()->getDefaultStoreId();
  }

  public function indexAction()
  {
    require_once 'ReveKlarna/Checkout.php';
    $response = ['status' => 'SUCCESS'];

    $isEnabled = $this->_getHelper()->getIsEnabled();

    # get URL parameters
    $storeID = $this->getRequest()->getParam('store');
    $infoOnly = $this->getRequest()->getParam('info') == "1";
    $klarnaOrderId = $this->getRequest()->getParam('klarna_order');

    if (!isset($storeID)) { $storeID = $this->_getDefaultStoreId(); }
    Mage::app()->setCurrentStore($storeID);

    // get Klarna settings

    // Avenla module settings
    $klarnaModule = null;
    $klarnaServer = Mage::getStoreConfig('payment/klarnaCheckout_payment/server');
    $klarnaSecret = Mage::helper('core')->decrypt(Mage::getStoreConfig('payment/klarnaCheckout_payment/sharedsecret'));
    if ($klarnaSecret && !$klarnaModule) $klarnaModule = "Avenla";

    // Klarna Official settings
    if (!$klarnaServer) $klarnaServer = Mage::getStoreConfig('payment/vaimo_klarna_checkout/host');
    if (!$klarnaSecret) $klarnaSecret = Mage::getStoreConfig('payment/vaimo_klarna_checkout/shared_secret');
    if ($klarnaSecret && !$klarnaModule) $klarnaModule = "Klarna Official";

    // Oddny/KL_Klarna_NG settings
    if (!$klarnaServer) $klarnaServer = (Mage::getStoreConfig('payment/klarna/live') == "1" ? "LIVE" : "DEMO");
    if (!$klarnaSecret) $klarnaSecret = Mage::getStoreConfig('payment/klarna/shared_secret');
    if ($klarnaSecret && !$klarnaModule) $klarnaModule = "Oddny/KL_Klarna_NG";

    $klarnaUseTest = in_array(strtolower($klarnaServer), ['demo', 'test', 'testdrive', 'beta']);

    $reveOrder = Mage::getModel("klarnapushorder/order");

    if ($infoOnly) {
      $response['version'] = '0.9.0'; // TODO: autobump when updating version
      $response['testMode'] = $klarnaUseTest;
      $response['klarnaModule'] = $klarnaModule;
      $response['paymentMethodCode'] = $reveOrder->getPaymentMethodCode();
      $response['shippingMethodCode'] = $reveOrder->getShippingMethodCode();
      $response['attrNames'] = $this->_getHelper()->getKlarnaAttrNames();
      $response['availableShippingMethods'] = $reveOrder->getAvailableShippingMethodCodes();
      $this->writeResponse($response);
      return;
    }

    if ($isEnabled) {
      Mage::log("-----------", null, "klarnapushorder-checkout.log");
      Mage::log("Processing Klarna order:". $klarnaOrderId, null, "klarnapushorder-checkout.log");

      // Klarna setup
      $klarnaUrl = Klarna_Checkout_Connector::BASE_URL;
      if ($klarnaUseTest) { $klarnaUrl = Klarna_Checkout_Connector::BASE_TEST_URL; }

      $connector = Klarna_Checkout_Connector::create(
        $klarnaSecret,
        $klarnaUrl
      );

      // fetch klarna order
      $klarnaOrder = new Klarna_Checkout_Order($connector, $klarnaOrderId);
      try {
        $klarnaOrder->fetch();
      } catch (Exception $e) {
        Mage::log("Error on klarna connection (see exception.log)",null,"klarnapushorder-checkout.log");
        Mage::logException($e);

        $response['status'] = 'ERROR';
        $response['message'] = $this->__("Error on klarna connection:".$e->getMessage());
        $this->writeResponse($response, $klarnaOrderId);

        Mage::log("-----------", null, "klarnapushorder-checkout.log");
        return;
      }

      if ($klarnaOrder['status'] == 'created') {
        Mage::log("Klarna Order ($klarnaOrderId) already exist!", null, "klarnapushorder-checkout.log");

        $response['status'] = 'ERROR';
        $response['message'] = $this->__("Error Klarna Order ($klarnaOrderId) already exist!");
        $this->writeResponse($response, $klarnaOrderId);

        Mage::log("-----------", null, "klarnapushorder-checkout.log");
        return;
      } else {
        // match klarna data with magento structure
        $user = $klarnaOrder['shipping_address']; // Klarna user, not Magento structure
        $cart = $klarnaOrder['cart']['items']; // Klarna cart, not Magento structure

        $_customer = Mage::getModel("klarnapushorder/customer");
        try {
          $_customer->assignKlarnaData($user);
        } catch (Exception $e) {
          Mage::log("Error creating customer (see exception.log)",null,"klarnapushorder-checkout.log");
          Mage::logException($e);

          $this->_cancelKlarnaOrder($klarnaOrder);

          $response['status'] = 'ERROR';
          $response['message'] = $this->__("Error creating customer (store:$storeID):".$e->getMessage());
          $this->writeResponse($response, $klarnaOrderId);

          Mage::log("-----------", null, "klarnapushorder-checkout.log");
          return;
        }

        // create sales quote
        $quote = Mage::getSingleton("sales/quote");
        $quote->setStoreId($storeID);
        $quote->assignCustomer($_customer);

        try{
          // add cart to quote
          $reveOrder->setQuote($quote)
            ->pushKlarnaCartToQuote($cart, $storeID)
            ->saveQuote($_customer, ['id'=>$klarnaOrderId, 'reservation'=>$klarnaOrder['reservation']]);

          Mage::log('quote : '. $quote->getId(), null, "klarnapushorder-checkout.log");

          // post quote as an order
          $service = Mage::getModel('sales/service_quote', $reveOrder->getQuote());
          $service->submitAll();
          $newOrder = $service->getOrder();

          if (!is_object($newOrder)) {
            Mage::log("Error pushing order", null, "klarnapushorder-checkout.log");

            $this->_cancelKlarnaOrder($klarnaOrder);

            $response['status'] = 'ERROR';
            $response['message'] = $this->__("Error pushing order (store:$storeID).");
            $this->writeResponse($response, $klarnaOrderId);

            Mage::log("-----------", null, "klarnapushorder-checkout.log");
            return;
          }

          // generate a auth transaction, needed for Klarna Offical Module and Oddny/KL_Klarna_NG
          $payment = $newOrder->getPayment();
          $payment->setTransactionId( $klarnaOrder['reservation'] )
            ->setIsTransactionClosed(0)
            ->setStatus(Mage_Payment_Model_Method_Abstract::STATUS_APPROVED);
          if ($transaction = $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH)) {
            $transaction->save();
          }
          $payment->save();
          $newOrder->setState('processing', 'processing', 'Push order from Klarna via Reve (ID:'. $klarnaOrderId .')')
            ->setStatus('processing'); // TODO: should we have status as a config value?
          $newOrder->save();

          Mage::log("Order created ID: ". $newOrder->getId(), null, "klarnapushorder-checkout.log");

          if (Mage::getStoreConfig('sales_email')['order']['enabled'] == 1) {
            $newOrder->getSendConfirmation(null);
            $newOrder->sendNewOrderEmail();

            Mage::log("Order mail sent", null, "klarnapushorder-checkout.log");

          } else {
            Mage::log("Order mail not sent, it's disabled", null, "klarnapushorder-checkout.log");
          }
        } catch (Exception $e) {
          Mage::log("Error pushing order (see exception.log)",null,"klarnapushorder-checkout.log");
          Mage::logException($e);

          $this->_cancelKlarnaOrder($klarnaOrder);

          $response['status'] = 'ERROR';
          $response['message'] = $this->__("Error pushing order (store:$storeID):".$e->getMessage());
          $this->writeResponse($response, $klarnaOrderId);

          Mage::log("-----------", null, "klarnapushorder-checkout.log");
          return;
        }

        // update klarna order with status created
        try {
          $klarnaOrder->update(array('status' => 'created'));
        } catch (Exception $e) {
          Mage::log("error getting order from klarna. (see exception.log)", null, "klarnapushorder-checkout.log");
          Mage::logException($e);

          $response['status'] = 'ERROR';
          $response['message'] = $this->__("Error getting order from klarna. (see exception.log):".$e->getMessage());
          $this->writeResponse($response, $klarnaOrderId);

          Mage::log("-----------", null, "klarnapushorder-checkout.log");
          return;
        }
        Mage::log("Successfully done!", null, "klarnapushorder-checkout.log");
        $this->_getHelper()->callbackReve($klarnaOrderId, "created");

      }
    } else {
      Mage::log("Module is Disabled!", null, "klarnapushorder-checkout.log");

      $this->_cancelKlarnaOrder($klarnaOrder);

      $response['status'] = 'ERROR';
      $response['message'] = $this->__("Error: Module is Disabled!");
    }

    Mage::log("-----------", null, "klarnapushorder-checkout.log");
    $this->writeResponse($response, $klarnaOrderId);
  }

  private function writeResponse($response, $klarnaOrderId = null) {
    if ($response['status'] == 'ERROR') {
      $this->_getHelper()->callbackReve($klarnaOrderId, $response['message']);
    }

    $this->getResponse()
      ->clearHeaders()
      ->setHeader('Content-Type', 'application/json')
      ->setBody(Mage::helper('core')
      ->jsonEncode($response));
  }

  private function _cancelKlarnaOrder($klarnaOrder) {
    $klarnaOrderId = $klarnaOrder['id'];
    $reservation = $klarnaOrder['reservation'];

    // TODO: make sure this works with Avenla and Klarna Official modules

    // Oddny/KL_Klarna_NG cancel
    // see https://github.com/Oddny/KL_Klarna_NG/blob/kco/app/code/community/KL/Klarna/Model/Api/Abstract.php
    require_once('Klarna/2.4.3/Klarna.php');
    require_once('Klarna/2.4.3/Country.php');
    require_once('Klarna/2.4.3/Exceptions.php');
    require_once('Klarna/2.4.3/transport/xmlrpc-3.0.0.beta/lib/xmlrpc.inc');
    require_once('Klarna/2.4.3/transport/xmlrpc-3.0.0.beta/lib/xmlrpc_wrappers.inc');
    require_once('Klarna/2.4.3/pclasses/mysqlstorage.class.php');
    $klarnaEID = Mage::getStoreConfig('payment/klarna/merchant_id');
    $klarnaSecret = Mage::getStoreConfig('payment/klarna/shared_secret');
    $klarnaServer = (Mage::getStoreConfig('payment/klarna/live') == "1" ? "LIVE" : "DEMO");

    $klarna = new Klarna();
    $klarna->config(
      $klarnaEID,
      $klarnaSecret,
      KlarnaCountry::SE,
      KlarnaLanguage::SV,
      KlarnaCurrency::SEK,
      ($klarnaServer == "LIVE" ? Klarna::LIVE : Klarna::BETA),
      'json',
      '',
      true,
      true
    );

    try {
      $klarna->cancelReservation($reservation);
    } catch(Exception $e) {
      $this->_getHelper()->callbackReve($klarnaOrderId, "Error: cancel order at klarna ". $e->getMessage());
    }

    $this->_getHelper()->callbackReve($klarnaOrderId, "cancel");
  }
}
