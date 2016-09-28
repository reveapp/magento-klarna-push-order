<?php

/**
 * Created by   : Dmitry Shirokovskiy.
 * Email        : info@phpwebstudio.com
 * Date         : 18.07.16
 * Time         : 23:16
 * Description  :
 */
class Reve_KlarnaPushOrder_Model_Order extends Mage_Sales_Model_Order
{
  const DEFAULT_SHIPPING_METHOD_CODE = 'flatrate_flatrate';

  private $_quote;

  public function setQuote($quote)
  {
    $this->_quote = $quote;
    return $this;
  }

  public function getQuote()
  {
    return $this->_quote;
  }

  public function getShippingMethodCode()
  {
    $shippingCode = Mage::getStoreConfig('revetab/general/shipping_method');
    if ($shippingCode != "")  {
      return $shippingCode;
    }
    return self::DEFAULT_SHIPPING_METHOD_CODE;
  }

  public function getAvailableShippingMethodCodes()
  {
    $methodCodes = array();
    $methods = Mage::getSingleton('shipping/config')->getActiveCarriers();

    foreach($methods as $_ccode => $_carrier){
      if($_methods = $_carrier->getAllowedMethods()){
        foreach($_methods as $_mcode => $_method){
          $_code = $_ccode . '_' . $_mcode;
          array_push($methodCodes, $_code);
        }
      }
    }

    return $methodCodes;
  }

  public function getPaymentMethodCode()
  {
    $payments = Mage::getSingleton('payment/config')->getActiveMethods();

    // Supported Payment methods:
    // vaimo_klarna_checkout = Klarna Official
    // klarnaCheckout_payment = Avenla module
    // klarna_checkout = Oddny/KL_Klarna_NG
    $supportedMethods = ['vaimo_klarna_checkout', 'klarnaCheckout_payment', 'klarna_checkout']; // TODO: maybe allow to add additional in config?

    foreach ($payments as $paymentCode=>$paymentModel) {
      $paymentTitle = Mage::getStoreConfig('payment/'.$paymentCode.'/title');
      if ( in_array($paymentCode, $supportedMethods) ) {
        // return first match, as stores should just have one of the Klarna modules installed
        return $paymentCode;
      }
    }
    // TODO: thow a exception, no supported method
  }

  public function pushKlarnaCartToQuote($cart, $storeId = null)
  {
    // add item to quote
    foreach ($cart as $key => $prod) {
      // load product
      $productId = $prod['reference'];
      $variantAttr = array(
        'qty' => intval($prod['quantity'])
      );

      // get product variant attribute id
      if (isset($prod['merchant_item_data'])) {
        $merchantItemData = explode(';', $prod['merchant_item_data']);

        foreach ($merchantItemData as $key => $attr) {
          if (empty($attr)) continue;

          $attrData = explode(':', $attr);
          $label = $attrData[0];
          $value = $attrData[1];
          $attrInfo = Mage::helper('klarnapushorder')->getAttrInfo($label, $value);

          $variantAttr['super_attribute'][intval($attrInfo['labelId'])] = intval($attrInfo['valueId']);
        }
      }

      // Is it not better if we load product by SKU?
      // that way we might support stores that do not have the feed module installed, if we get SKU from scrape/other feed.
      // $product = Mage::getModel('catalog/product');
      // $product->load($product->getIdBySku($prod['reference']));

      $product = Mage::getModel('catalog/product')->load($productId);
      $this->_quote->addProduct($product, new Varien_Object($variantAttr));
    }
    return $this;
  }

  public function saveQuote($_customer, $klarna_order)
  {
    // set billing and shipping based on klarna details
    $klarnaAddress = $_customer->getKlarnaAddress();
    $addressData = array(
      'firstname' => $klarnaAddress['firstname'],
      'lastname' => $klarnaAddress['lastname'],
      'street' => $klarnaAddress['street'],
      'city' => $klarnaAddress['city'],
      'postcode' => $klarnaAddress['postcode'],
      'telephone' => $klarnaAddress['telephone'],
      'country_id' => $klarnaAddress['country_id']
    );

    $this->_quote->getBillingAddress()->addData($addressData);
    $shippingAddress = $this->_quote->getShippingAddress()->addData($addressData);

    $paymentMethodCode = $this->getPaymentMethodCode();

    // shipping and payments method
    $shippingAddress->setShippingMethod($this->getShippingMethodCode())
      ->setPaymentMethod($paymentMethodCode)
      ->setCollectShippingRates(true)
      ->collectShippingRates();
    $this->_quote->getPayment()->addData(array('method' => $paymentMethodCode));

    $this->_quote->getPayment()->setAdditionalInformation(array(

      // Avenla module support
      'klarna_order_id' => $klarna_order['id'],
      'klarna_order_reservation' => $klarna_order['reservation'],

      // Klarna Official module support (also need to set a AUTH transaction, see order save)
      'klarna_reservation_reference' => $klarna_order['id'],
      'klarna_reservation_id' => $klarna_order['reservation'],

      // Oddny/KL_Klarna_NG module support (also need to set a AUTH transaction, see order save)
      'klarnaCheckoutId' => $klarna_order['id']

    ));

    // calculate totals and save
    $this->_quote->collectTotals();
    $this->_quote->save();

    return $this;
  }
}
