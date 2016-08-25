<?php

/**
 * Created by   : Dmitry Shirokovskiy.
 * Email        : info@phpwebstudio.com
 * Date         : 19.07.16
 * Time         : 12:03
 * Description  :
 */
class Reve_KlarnaPushOrder_Model_Customer extends Mage_Customer_Model_Customer
{
  public function assignKlarnaData($user)
  {
    // create or update customer account
    $customerData = array (
      'account' => array(
        'website_id' => '1',
        'group_id' => '1',
        'prefix' => '',
        'firstname' => $user['given_name'],
        'middlename' => '',
        'lastname' => $user['family_name'],
        'suffix' => '',
        'email' => $user['email'],
        'dob' => '',
        'taxvat' => '',
        'gender' => '',
        'sendemail_store_id' => '1',
        'password' => $this->generatePassword(),
        'default_billing' => '_item1',
        'default_shipping' => '_item1',
      ),
      'address' => array(
        '_item1' => array(
          'prefix' => '',
          'firstname' => $user['given_name'],
          'middlename' => '',
          'lastname' => $user['family_name'],
          'suffix' => '',
          'company' => '',
          'street' => array(
            0 => $user['street_address'],
            1 => '',
          ),
          'city' => $user['city'],
          'country_id' => strtoupper($user['country']),
          'region_id' => '',
          'region' => '',
          'postcode' => $user['postal_code'],
          'telephone' => $user['phone'],
          'fax' => '',
          'vat_id' => '',
        ),
      ),
    );

    try {
      if (!empty($customerData['account']['email']) && Zend_Validate::is($customerData['account']['email'], 'EmailAddress')) {
        $this
          ->setWebsiteId(Mage::app()->getStore()->getWebsiteId())
          ->loadByEmail($customerData['account']['email']);

        if ($this->getId() <= 0) {
          # Create new customer
          $this->createCustomer($customerData);
        }
      } else {
        throw new Exception($this->__("Customer Email is invalid"));
      }

      Mage::log("User " . $this->getId(), null, "klarnapushorder-checkout.log");
    } catch (Exception $e) {
      Mage::log(__METHOD__." exception (see exception.log)", null, "klarnapushorder-checkout.log");
      Mage::logException($e);
    }
  }


  public function createCustomer($data)
  {
    if ($this->getId() > 0) {
      return $this;
    }

    $this->setData($data['account']);

    foreach (array_keys($data['address']) as $index) {
      $address = Mage::getModel('customer/address');

      $addressData = array_merge($data['account'], $data['address'][$index]);

      // Set default billing and shipping flags to address
      // TODO check if current shipping info is the same than current default one, and avoid create a new one.
      $isDefaultBilling = isset($data['account']['default_billing'])
        && $data['account']['default_billing'] == $index;
      $address->setIsDefaultBilling($isDefaultBilling);
      $isDefaultShipping = isset($data['account']['default_shipping'])
        && $data['account']['default_shipping'] == $index;
      $address->setIsDefaultShipping($isDefaultShipping);

      $address->addData($addressData);

      // Set post_index for detect default billing and shipping addresses
      $address->setPostIndex($index);

      $this->addAddress($address);
    }

    // Default billing and shipping
    if (isset($data['account']['default_billing'])) {
      $this->setData('default_billing', $data['account']['default_billing']);
    }
    if (isset($data['account']['default_shipping'])) {
      $this->setData('default_shipping', $data['account']['default_shipping']);
    }
    if (isset($data['account']['confirmation'])) {
      $this->setData('confirmation', $data['account']['confirmation']);
    }
    if (isset($data['account']['sendemail_store_id'])) {
      $this->setSendemailStoreId($data['account']['sendemail_store_id']);
    }

    $this
      ->setPassword($data['account']['password'])
      ->setForceConfirmed(true)
      ->save();
    //$this->cleanAllAddresses();

    return $this;
  }
}
