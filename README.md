**MAGENTO MODULE IS NO LONGER MAINTAINED AND NOT NEEDED TO SUPPORT PURCHASES FROM REVEAPP.COM**

Klarna Push Order for reve
==========================

Magento module to enable Klarna Checkout Push Orders from [reve](https://www.reveapp.com).

## Installation

### Requirements

Any of following Magento modules must be installed and configured for Klarna Checkout

 - [Klarna Payment extension (Official)](https://www.magentocommerce.com/magento-connect/klarna-payment-extension-1.html)
 - [Klarna Checkout module for Magento](https://www.magentocommerce.com/magento-connect/klarna-checkout-module-for-magento.html)
 - Klarna NG from Oddny

### Manually

Copy content from `src` folder to Magento root ex `/var/www/htdocs'

Open `System > Configuration > Reve Klarna PushOrder` in Magento Admin and set `Shipping method` to use for push orders and your `Product attributes names` for product variants if used.

Enable the module.

To view available shipping method codes used `//yoursite.com/reve_klarna/order/?info=1`

### Magento Connect

.. comming soon...

## License

Open Software License ([OSL 3.0](https://opensource.org/licenses/osl-3.0.php))


