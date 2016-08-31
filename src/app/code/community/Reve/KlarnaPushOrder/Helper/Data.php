<?php
/**
 * Created by   : Dmitry Shirokovskiy.
 * Email        : info@phpwebstudio.com
 * Date         : 10.07.16
 * Time         : 19:00
 * Description  : helper of Reve_Klarna module
 * Checks if module available & enabled in CMS
 */
class Reve_KlarnaPushOrder_Helper_Data extends Mage_Core_Helper_Abstract
{
  public function getIsEnabled()
  {
    return Mage::getStoreConfigFlag('revetab/general/active');
  }

  public function getKlarnaAttrNames()
  {
    $attrNames = Mage::getStoreConfig('revetab/general/klarna_attr_names');
    return $attrNames;
  }

  function getAttrInfo($label, $value){
    $attrInfo = array();
    $sizeAttrNames = $this->getKlarnaAttrNames();

    if (is_string($sizeAttrNames) && preg_match("/,/",$sizeAttrNames)) {
      $sizeAttrNames = explode(',',$sizeAttrNames);
    }

    if (!is_array($sizeAttrNames)) {
      $sizeAttrNames = [$sizeAttrNames];
    }

    if($label == 'size'){
      foreach ($sizeAttrNames as $attrName) {
        $attribute = Mage::getSingleton('eav/config')->getAttribute('catalog_product', $attrName);
        $attrInfo['labelId'] = $attribute->getId();

        if ($attribute->usesSource()) {
          $attrInfo['valueId'] = $attribute->getSource()->getOptionId($value);
        }

        if($attrInfo['valueId']){
          break;
        }
      }
    }else{
      $attr = Mage::getModel('eav/entity_attribute')->getCollection()->addFieldToFilter('frontend_label', $label);
      $attrInfo['labelId'] = $attr->getData()[0]['attribute_id'];
      // get value code
      $_product = Mage::getModel('catalog/product');
      $labelData = $_product->getResource()->getAttribute($label);
      if ($labelData->usesSource()) {
        $attrInfo['valueId'] = $labelData->getSource()->getOptionId($value);
      }
    }

    return $attrInfo;
  }

  public function _getQuote()
  {
    return Mage::getSingleton("sales/quote");
  }
}
