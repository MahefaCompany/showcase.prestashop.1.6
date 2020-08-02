<?php
/**
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 * We offer the best and most useful modules PrestaShop and modifications for your online store. 
 *
 * @category  PrestaShop Module
 * @author    knowband.com <support@knowband.com>
 * @copyright 2016 Knowband
 * @license   see file: LICENSE.txt
 */

abstract class PaymentModule extends PaymentModuleCore
{
    public function validateOrder(
        $id_cart,
        $id_order_state,
        $amount_paid,
        $payment_method = 'Unknown',
        $message = null,
        $extra_vars = array(),
        $currency_special = null,
        $dont_touch_amount = false,
        $secure_key = false,
        Shop $shop = null
    ) {
        if (!isset($this->context)) {
            $this->context = Context::getContext();
        }
        if (KbPerProductSellerShipping::issellersProductsInCart()
            && Configuration::get('KB_MP_SELLER_ORDER_HANDLING') == 1
            && Configuration::get('KB_MARKETPLACE') == 1
            && Module::isEnabled('kbmarketplace')
        ) {
            if (self::DEBUG_MODE) {
                PrestaShopLogger::addLog(
                    'PaymentModule::validateOrder - Function called',
                    1,
                    null,
                    'Cart',
                    (int) $id_cart,
                    true
                );
            }

            $this->context->cart = new Cart((int) $id_cart);
            $this->context->customer = new Customer((int) $this->context->cart->id_customer);
            // The tax cart is loaded before the customer so re-cache the tax calculation method
            $this->context->cart->setTaxCalculationMethod();

            $this->context->language = new Language((int) $this->context->cart->id_lang);
            $this->context->shop = ($shop ? $shop : new Shop((int) $this->context->cart->id_shop));
            ShopUrl::resetMainDomainCache();
            $id_currency = $currency_special ? (int) $currency_special : (int) $this->context->cart->id_currency;
            $this->context->currency = new Currency((int) $id_currency, null, (int) $this->context->shop->id);
            if (Configuration::get('PS_TAX_ADDRESS_TYPE') == 'id_address_delivery') {
                $context_country = $this->context->country;
            }

            $order_status = new OrderState((int) $id_order_state, (int) $this->context->language->id);
            if (!Validate::isLoadedObject($order_status)) {
                PrestaShopLogger::addLog(
                    'PaymentModule::validateOrder - Order Status cannot be loaded',
                    3,
                    null,
                    'Cart',
                    (int) $id_cart,
                    true
                );
                throw new PrestaShopException('Can\'t load Order status');
            }

            if (!$this->active) {
                PrestaShopLogger::addLog(
                    'PaymentModule::validateOrder - Module is not active',
                    3,
                    null,
                    'Cart',
                    (int) $id_cart,
                    true
                );
                die(Tools::displayError());
            }

            // Does order already exists ?
            if (Validate::isLoadedObject($this->context->cart) && $this->context->cart->OrderExists() == false) {
                if ($secure_key !== false && $secure_key != $this->context->cart->secure_key) {
                    PrestaShopLogger::addLog(
                        'PaymentModule::validateOrder - Secure key does not match',
                        3,
                        null,
                        'Cart',
                        (int) $id_cart,
                        true
                    );
                    die(Tools::displayError());
                }

                // For each package, generate an order
                $package_list = KbPerProductSellerShipping::getSellerPackageList();

                $order_list = array();
                $order_detail_list = array();

                do {
                    $reference = Order::generateReference();
                } while (Order::getByReference($reference)->count());

                $this->currentOrderReference = $reference;

                $order_creation_failed = false;
                $cart_total_paid = (float) Tools::ps_round(
                    (float) $this->context->cart->getOrderTotal(true, Cart::BOTH),
                    2
                );

                // Make sure CartRule caches are empty
                CartRule::cleanCache();
                $cart_rules = $this->context->cart->getCartRules();
                foreach ($cart_rules as $cart_rule) {
                    if (($rule = new CartRule((int) $cart_rule['obj']->id)) && Validate::isLoadedObject($rule)) {
                        if ($error = $rule->checkValidity($this->context, true, true)) {
                            $this->context->cart->removeCartRule((int) $rule->id);
                            if (isset($this->context->cookie)
                                && isset($this->context->cookie->id_customer)
                                && $this->context->cookie->id_customer
                                && !empty($rule->code)
                            ) {
                                $order_page_redirect = 'index.php?controller=order
                                    &submitAddDiscount=1&discount_name=' . urlencode($rule->code);
                                if (Configuration::get('PS_ORDER_PROCESS_TYPE') == 1) {
                                    $order_page_redirect = 'index.php?controller=order-opc
                                        &submitAddDiscount=1&discount_name=' . urlencode($rule->code);
                                }
                                Tools::redirect($order_page_redirect);
                            } else {
                                $rule_name = isset($rule->name[(int) $this->context->cart->id_lang])
                                    ? $rule->name[(int) $this->context->cart->id_lang]
                                    : $rule->code;
                                $error = sprintf(
                                    Tools::displayError(
                                        'CartRule ID %1s (%2s) used in this cart 
                                        is not valid and has been withdrawn from cart'
                                    ),
                                    (int) $rule->id,
                                    $rule_name
                                );
                                PrestaShopLogger::addLog(
                                    $error,
                                    3,
                                    '0000002',
                                    'Cart',
                                    (int) $this->context->cart->id
                                );
                            }
                        }
                    }
                }

                foreach ($package_list as $packageBySeller) {
                    foreach ($packageBySeller as $carrier_id => $package) {
                        /** @var Order $order */
                        $order = new Order();
                        $order->product_list = $package['product_list'];

                        if (Configuration::get('PS_TAX_ADDRESS_TYPE') == 'id_address_delivery') {
                            $address = new Address((int) $this->context->cart->id_address_delivery);
                            $this->context->country = new Country(
                                (int) $address->id_country,
                                (int) $this->context->cart->id_lang
                            );
                            if (!$this->context->country->active) {
                                throw new PrestaShopException('The delivery address country is not active.');
                            }
                        }

                        $carrier = null;
                        $carrier = new Carrier((int) $carrier_id, (int) $this->context->cart->id_lang);
                        $order->id_carrier = (int) $carrier->id;
                        $id_carrier = (int) $carrier->id;

                        $order->id_customer = (int) $this->context->cart->id_customer;
                        $order->id_address_invoice = (int) $this->context->cart->id_address_invoice;
                        $order->id_address_delivery = (int) $this->context->cart->id_address_delivery;
                        $order->id_currency = $this->context->currency->id;
                        $order->id_lang = (int) $this->context->cart->id_lang;
                        $order->id_cart = (int) $this->context->cart->id;
                        $order->reference = $reference;
                        $order->id_shop = (int) $this->context->shop->id;
                        $order->id_shop_group = (int) $this->context->shop->id_shop_group;

                        $order->secure_key = $secure_key
                            ? pSQL($secure_key)
                            : pSQL($this->context->customer->secure_key);
                        $order->payment = $payment_method;
                        if (isset($this->name)) {
                            $order->module = $this->name;
                        }
                        $order->recyclable = $this->context->cart->recyclable;
                        $order->gift = (int) $this->context->cart->gift;
                        $order->gift_message = $this->context->cart->gift_message;
                        $order->mobile_theme = $this->context->cart->mobile_theme;
                        $order->conversion_rate = $this->context->currency->conversion_rate;
                        $amount_paid = !$dont_touch_amount ? Tools::ps_round((float) $amount_paid, 2) : $amount_paid;
                        $order->total_paid_real = 0;

                        $order->total_products = (float) $this->context->cart->getOrderTotal(
                            false,
                            Cart::ONLY_PRODUCTS,
                            $order->product_list,
                            $id_carrier
                        );
                        $order->total_products_wt = (float) $this->context->cart->getOrderTotal(
                            true,
                            Cart::ONLY_PRODUCTS,
                            $order->product_list,
                            $id_carrier
                        );
                        $order->total_discounts_tax_excl = (float) abs($this->context->cart->getOrderTotal(
                            false,
                            Cart::ONLY_DISCOUNTS,
                            $order->product_list,
                            $id_carrier
                        ));
                        $order->total_discounts_tax_incl = (float) abs($this->context->cart->getOrderTotal(
                            true,
                            Cart::ONLY_DISCOUNTS,
                            $order->product_list,
                            $id_carrier
                        ));
                        $order->total_discounts = $order->total_discounts_tax_incl;

                        $order->total_shipping_tax_excl = (float) $this->context->cart->getPackageShippingCost(
                            (int) $id_carrier,
                            false,
                            null,
                            $order->product_list
                        );
                        $order->total_shipping_tax_incl = (float) $this->context->cart->getPackageShippingCost(
                            (int) $id_carrier,
                            true,
                            null,
                            $order->product_list
                        );
                        $order->total_shipping = $order->total_shipping_tax_incl;

                        if (!is_null($carrier) && Validate::isLoadedObject($carrier)) {
                            $order->carrier_tax_rate = $carrier->getTaxesRate(
                                new Address((int) $this->context->cart->{Configuration::get('PS_TAX_ADDRESS_TYPE')})
                            );
                        }

                        $order->total_wrapping_tax_excl = (float) abs($this->context->cart->getOrderTotal(
                            false,
                            Cart::ONLY_WRAPPING,
                            $order->product_list,
                            $id_carrier
                        ));
                        $order->total_wrapping_tax_incl = (float) abs($this->context->cart->getOrderTotal(
                            true,
                            Cart::ONLY_WRAPPING,
                            $order->product_list,
                            $id_carrier
                        ));
                        $order->total_wrapping = $order->total_wrapping_tax_incl;

                        $order->total_paid_tax_excl = (float) Tools::ps_round(
                            (float) $this->context->cart->getOrderTotal(
                                false,
                                Cart::BOTH,
                                $order->product_list,
                                $id_carrier
                            ),
                            _PS_PRICE_COMPUTE_PRECISION_
                        );
                        $order->total_paid_tax_incl = (float) Tools::ps_round(
                            (float) $this->context->cart->getOrderTotal(
                                true,
                                Cart::BOTH,
                                $order->product_list,
                                $id_carrier
                            ),
                            _PS_PRICE_COMPUTE_PRECISION_
                        );
                        $order->total_paid = $order->total_paid_tax_incl;
                        $order->round_mode = Configuration::get('PS_PRICE_ROUND_MODE');
                        $order->round_type = Configuration::get('PS_ROUND_TYPE');

                        $order->invoice_date = '0000-00-00 00:00:00';
                        $order->delivery_date = '0000-00-00 00:00:00';

                        if (self::DEBUG_MODE) {
                            PrestaShopLogger::addLog(
                                'PaymentModule::validateOrder - Order is about to be added',
                                1,
                                null,
                                'Cart',
                                (int) $id_cart,
                                true
                            );
                        }

                        // Creating order
                        $result = $order->add();

                        if (!$result) {
                            PrestaShopLogger::addLog(
                                'PaymentModule::validateOrder - Order cannot be created',
                                3,
                                null,
                                'Cart',
                                (int) $id_cart,
                                true
                            );
                            throw new PrestaShopException('Can\'t save Order');
                        }

                        if ($order_status->logable
                            && number_format($cart_total_paid, _PS_PRICE_COMPUTE_PRECISION_)
                                != number_format($amount_paid, _PS_PRICE_COMPUTE_PRECISION_)
                        ) {
                            $id_order_state = Configuration::get('PS_OS_ERROR');
                        }

                        $order_list[] = $order;

                        if (self::DEBUG_MODE) {
                            PrestaShopLogger::addLog(
                                'PaymentModule::validateOrder - OrderDetail is about to be added',
                                1,
                                null,
                                'Cart',
                                (int) $id_cart,
                                true
                            );
                        }

                        // Insert new Order detail list using cart for the current order
                        $order_detail = new OrderDetail(null, null, $this->context);
                        $order_detail->createList(
                            $order,
                            $this->context->cart,
                            $id_order_state,
                            $order->product_list,
                            0,
                            true
                        );
                        $order_detail_list[] = $order_detail;

                        if (self::DEBUG_MODE) {
                            PrestaShopLogger::addLog(
                                'PaymentModule::validateOrder - OrderCarrier is about to be added',
                                1,
                                null,
                                'Cart',
                                (int) $id_cart,
                                true
                            );
                        }

                        // Adding an entry in order_carrier table
                        if (!is_null($carrier)) {
                            $order_carrier = new OrderCarrier();
                            $order_carrier->id_order = (int) $order->id;
                            $order_carrier->id_carrier = (int) $id_carrier;
                            $order_carrier->weight = (float) $order->getTotalWeight();
                            $order_carrier->shipping_cost_tax_excl = (float) $order->total_shipping_tax_excl;
                            $order_carrier->shipping_cost_tax_incl = (float) $order->total_shipping_tax_incl;
                            $order_carrier->add();
                        }
                    }
                }

                if (Configuration::get('PS_TAX_ADDRESS_TYPE') == 'id_address_delivery') {
                    $this->context->country = $context_country;
                }

                if (!$this->context->country->active) {
                    PrestaShopLogger::addLog(
                        'PaymentModule::validateOrder - Country is not active',
                        3,
                        null,
                        'Cart',
                        (int) $id_cart,
                        true
                    );
                    throw new PrestaShopException('The order address country is not active.');
                }

                if (self::DEBUG_MODE) {
                    PrestaShopLogger::addLog(
                        'PaymentModule::validateOrder - Payment is about to be added',
                        1,
                        null,
                        'Cart',
                        (int) $id_cart,
                        true
                    );
                }

                // Register Payment only if the order status validate the order
                if ($order_status->logable) {
                    if (isset($extra_vars['transaction_id'])) {
                        $transaction_id = $extra_vars['transaction_id'];
                    } else {
                        $transaction_id = null;
                    }

                    if (!$order->addOrderPayment($amount_paid, null, $transaction_id)) {
                        PrestaShopLogger::addLog(
                            'PaymentModule::validateOrder - Cannot save Order Payment',
                            3,
                            null,
                            'Cart',
                            (int) $id_cart,
                            true
                        );
                        throw new PrestaShopException('Can\'t save Order Payment');
                    }
                }

                // Next !
                $cart_rule_used = array();

                // Make sure CartRule caches are empty
                CartRule::cleanCache();
                foreach ($order_detail_list as $key => $order_detail) {
                    /** @var OrderDetail $order_detail */
                    $order = $order_list[$key];
                    if (!$order_creation_failed && isset($order->id)) {
                        if (!$secure_key) {
                            $message .= '<br />' . Tools::displayError('Warning: the secure key is empty, 
                                check your payment account before validation');
                        }
                        // Optional message to attach to this order
                        if (isset($message) & !empty($message)) {
                            $msg = new Message();
                            $message = strip_tags($message, '<br>');
                            if (Validate::isCleanHtml($message)) {
                                if (self::DEBUG_MODE) {
                                    PrestaShopLogger::addLog(
                                        'PaymentModule::validateOrder - Message is about to be added',
                                        1,
                                        null,
                                        'Cart',
                                        (int) $id_cart,
                                        true
                                    );
                                }
                                $msg->message = $message;
                                $msg->id_cart = (int) $id_cart;
                                $msg->id_customer = (int) ($order->id_customer);
                                $msg->id_order = (int) $order->id;
                                $msg->private = 1;
                                $msg->add();
                            }
                        }

                        $virtual_product = true;

                        $product_var_tpl_list = array();
                        foreach ($order->product_list as $product) {
                            $price = Product::getPriceStatic(
                                (int) $product['id_product'],
                                false,
                                ($product['id_product_attribute'] ? (int) $product['id_product_attribute'] : null),
                                6,
                                null,
                                false,
                                true,
                                $product['cart_quantity'],
                                false,
                                (int) $order->id_customer,
                                (int) $order->id_cart,
                                (int) $order->{Configuration::get('PS_TAX_ADDRESS_TYPE')}
                            );
                            $price_wt = Product::getPriceStatic(
                                (int) $product['id_product'],
                                true,
                                ($product['id_product_attribute'] ? (int) $product['id_product_attribute'] : null),
                                2,
                                null,
                                false,
                                true,
                                $product['cart_quantity'],
                                false,
                                (int) $order->id_customer,
                                (int) $order->id_cart,
                                (int) $order->{Configuration::get('PS_TAX_ADDRESS_TYPE')}
                            );

                            $product_price = Product::getTaxCalculationMethod() == PS_TAX_EXC
                                ? Tools::ps_round($price, 2)
                                : $price_wt;

                            $product_var_tpl = array(
                                'reference' => $product['reference'],
                                'name' => $product['name']
                                    . (isset($product['attributes']) ? ' - ' . $product['attributes'] : ''),
                                'unit_price' => Tools::displayPrice($product_price, $this->context->currency, false),
                                'price' => Tools::displayPrice(
                                    $product_price * $product['quantity'],
                                    $this->context->currency,
                                    false
                                ),
                                'quantity' => $product['quantity'],
                                'customization' => array()
                            );

                            $customized_datas = Product::getAllCustomizedDatas((int) $order->id_cart);
                            if (isset($customized_datas[$product['id_product']][$product['id_product_attribute']])) {
                                $product_var_tpl['customization'] = array();
                                $t_id_prod = $product['id_product'];
                                $t_id_p_attr = $product['id_product_attribute'];
                                $t_c_data = $customized_datas[$t_id_prod][$t_id_p_attr][$order->id_address_delivery];
                                foreach ($t_c_data as $customization) {
                                    $customization_text = '';
                                    if (isset($customization['datas'][Product::CUSTOMIZE_TEXTFIELD])) {
                                        foreach ($customization['datas'][Product::CUSTOMIZE_TEXTFIELD] as $text) {
                                            $customization_text .= $text['name'] . ': ' . $text['value'] . '<br />';
                                        }
                                    }

                                    if (isset($customization['datas'][Product::CUSTOMIZE_FILE])) {
                                        $customization_text .= sprintf(
                                            Tools::displayError('%d image(s)'),
                                            count($customization['datas'][Product::CUSTOMIZE_FILE])
                                        ) . '<br />';
                                    }

                                    $customization_quantity = (int) $product['customization_quantity'];

                                    $product_var_tpl['customization'][] = array(
                                        'customization_text' => $customization_text,
                                        'customization_quantity' => $customization_quantity,
                                        'quantity' => Tools::displayPrice(
                                            $customization_quantity * $product_price,
                                            $this->context->currency,
                                            false
                                        )
                                    );
                                }
                            }

                            $product_var_tpl_list[] = $product_var_tpl;
                            // Check if is not a virutal product for the displaying of shipping
                            if (!$product['is_virtual']) {
                                $virtual_product &= false;
                            }
                        } // end foreach ($products)

                        $product_list_txt = '';
                        $product_list_html = '';
                        if (count($product_var_tpl_list) > 0) {
                            $product_list_txt = $this->getEmailTemplateContent(
                                'order_conf_product_list.txt',
                                Mail::TYPE_TEXT,
                                $product_var_tpl_list
                            );
                            $product_list_html = $this->getEmailTemplateContent(
                                'order_conf_product_list.tpl',
                                Mail::TYPE_HTML,
                                $product_var_tpl_list
                            );
                        }

                        $cart_rules_list = array();
                        $total_reduction_value_ti = 0;
                        $total_reduction_value_tex = 0;
                        foreach ($cart_rules as $cart_rule) {
                            $package = array(
                                'id_carrier' => $order->id_carrier,
                                'id_address' => $order->id_address_delivery,
                                'products' => $order->product_list
                            );
                            $values = array(
                                'tax_incl' => $cart_rule['obj']->getContextualValue(
                                    true,
                                    $this->context,
                                    CartRule::FILTER_ACTION_ALL_NOCAP,
                                    $package
                                ),
                                'tax_excl' => $cart_rule['obj']->getContextualValue(
                                    false,
                                    $this->context,
                                    CartRule::FILTER_ACTION_ALL_NOCAP,
                                    $package
                                )
                            );

                            // If the reduction is not applicable to this order, then continue with the next one
                            if (!$values['tax_excl']) {
                                continue;
                            }

                            if (count($order_list) == 1
                                && $values['tax_incl'] > ($order->total_products_wt - $total_reduction_value_ti)
                                && $cart_rule['obj']->partial_use == 1
                                && $cart_rule['obj']->reduction_amount > 0) {
                                // Create a new voucher from the original
                                $voucher = new CartRule((int) $cart_rule['obj']->id);
                                unset($voucher->id);

                                // Set a new voucher code
                                $voucher->code = empty($voucher->code)
                                    ? Tools::substr(
                                        md5($order->id . '-' . $order->id_customer . '-' . $cart_rule['obj']->id),
                                        0,
                                        16
                                    )
                                    : $voucher->code . '-2';
                                if (preg_match(
                                    '/\-([0-9]{1,2})\-([0-9]{1,2})$/',
                                    $voucher->code,
                                    $matches
                                )
                                && $matches[1] == $matches[2]) {
                                    $voucher->code = preg_replace(
                                        '/' . $matches[0] . '$/',
                                        '-' . ((int) $matches[1] + 1),
                                        $voucher->code
                                    );
                                }

                                // Set the new voucher value
                                if ($voucher->reduction_tax) {
                                    $voucher->reduction_amount = ($total_reduction_value_ti + $values['tax_incl'])
                                        - $order->total_products_wt;

                                    // Add total shipping amout only if reduction amount > total shipping
                                    if ($voucher->free_shipping == 1
                                        && $voucher->reduction_amount >= $order->total_shipping_tax_incl
                                    ) {
                                        $voucher->reduction_amount -= $order->total_shipping_tax_incl;
                                    }
                                } else {
                                    $voucher->reduction_amount = ($total_reduction_value_tex + $values['tax_excl'])
                                        - $order->total_products;

                                    // Add total shipping amout only if reduction amount > total shipping
                                    if ($voucher->free_shipping == 1
                                        && $voucher->reduction_amount >= $order->total_shipping_tax_excl) {
                                        $voucher->reduction_amount -= $order->total_shipping_tax_excl;
                                    }
                                }
                                if ($voucher->reduction_amount <= 0) {
                                    continue;
                                }

                                if ($this->context->customer->isGuest()) {
                                    $voucher->id_customer = 0;
                                } else {
                                    $voucher->id_customer = $order->id_customer;
                                }

                                $voucher->quantity = 1;
                                $voucher->reduction_currency = $order->id_currency;
                                $voucher->quantity_per_user = 1;
                                $voucher->free_shipping = 0;
                                if ($voucher->add()) {
                                    // If the voucher has conditions, they are now copied to the new voucher
                                    CartRule::copyConditions($cart_rule['obj']->id, $voucher->id);

                                    $params = array(
                                        '{voucher_amount}' => Tools::displayPrice(
                                            $voucher->reduction_amount,
                                            $this->context->currency,
                                            false
                                        ),
                                        '{voucher_num}' => $voucher->code,
                                        '{firstname}' => $this->context->customer->firstname,
                                        '{lastname}' => $this->context->customer->lastname,
                                        '{id_order}' => $order->reference,
                                        '{order_name}' => $order->getUniqReference()
                                    );
                                    Mail::Send(
                                        (int) $order->id_lang,
                                        'voucher',
                                        sprintf(
                                            Mail::l('New voucher for your order %s', (int) $order->id_lang),
                                            $order->reference
                                        ),
                                        $params,
                                        $this->context->customer->email,
                                        $this->context->customer->firstname . ' ' . $this->context->customer->lastname,
                                        null,
                                        null,
                                        null,
                                        null,
                                        _PS_MAIL_DIR_,
                                        false,
                                        (int) $order->id_shop
                                    );
                                }

                                $values['tax_incl'] = $order->total_products_wt - $total_reduction_value_ti;
                                $values['tax_excl'] = $order->total_products - $total_reduction_value_tex;
                            }
                            $total_reduction_value_ti += $values['tax_incl'];
                            $total_reduction_value_tex += $values['tax_excl'];

                            $order->addCartRule(
                                $cart_rule['obj']->id,
                                $cart_rule['obj']->name,
                                $values,
                                0,
                                $cart_rule['obj']->free_shipping
                            );

                            if ($id_order_state != Configuration::get('PS_OS_ERROR')
                                && $id_order_state != Configuration::get('PS_OS_CANCELED')
                                && !in_array($cart_rule['obj']->id, $cart_rule_used)
                            ) {
                                $cart_rule_used[] = $cart_rule['obj']->id;

                                // Create a new instance of Cart Rule without id_lang, in order to update its quantity
                                $cart_rule_to_update = new CartRule((int) $cart_rule['obj']->id);
                                $cart_rule_to_update->quantity = max(0, $cart_rule_to_update->quantity - 1);
                                $cart_rule_to_update->update();
                            }

                            $cart_rules_list[] = array(
                                'voucher_name' => $cart_rule['obj']->name,
                                'voucher_reduction' => ($values['tax_incl'] != 0.00 ? '-' : '')
                                    . Tools::displayPrice($values['tax_incl'], $this->context->currency, false)
                            );
                        }

                        $cart_rules_list_txt = '';
                        $cart_rules_list_html = '';
                        if (count($cart_rules_list) > 0) {
                            $cart_rules_list_txt = $this->getEmailTemplateContent(
                                'order_conf_cart_rules.txt',
                                Mail::TYPE_TEXT,
                                $cart_rules_list
                            );
                            $cart_rules_list_html = $this->getEmailTemplateContent(
                                'order_conf_cart_rules.tpl',
                                Mail::TYPE_HTML,
                                $cart_rules_list
                            );
                        }

                        // Specify order id for message
                        $old_message = Message::getMessageByCartId((int) $this->context->cart->id);
                        if ($old_message && !$old_message['private']) {
                            $update_message = new Message((int) $old_message['id_message']);
                            $update_message->id_order = (int) $order->id;
                            $update_message->update();

                            // Add this message in the customer thread
                            $customer_thread = new CustomerThread();
                            $customer_thread->id_contact = 0;
                            $customer_thread->id_customer = (int) $order->id_customer;
                            $customer_thread->id_shop = (int) $this->context->shop->id;
                            $customer_thread->id_order = (int) $order->id;
                            $customer_thread->id_lang = (int) $this->context->language->id;
                            $customer_thread->email = $this->context->customer->email;
                            $customer_thread->status = 'open';
                            $customer_thread->token = Tools::passwdGen(12);
                            $customer_thread->add();

                            $customer_message = new CustomerMessage();
                            $customer_message->id_customer_thread = $customer_thread->id;
                            $customer_message->id_employee = 0;
                            $customer_message->message = $update_message->message;
                            $customer_message->private = 0;

                            if (!$customer_message->add()) {
                                $this->errors[] = Tools::displayError('An error occurred while saving message');
                            }
                        }

                        if (self::DEBUG_MODE) {
                            PrestaShopLogger::addLog(
                                'PaymentModule::validateOrder - Hook validateOrder is about to be called',
                                1,
                                null,
                                'Cart',
                                (int) $id_cart,
                                true
                            );
                        }

                        // Hook validate order
                        Hook::exec('actionValidateOrder', array(
                            'cart' => $this->context->cart,
                            'order' => $order,
                            'customer' => $this->context->customer,
                            'currency' => $this->context->currency,
                            'orderStatus' => $order_status
                        ));

                        foreach ($this->context->cart->getProducts() as $product) {
                            if ($order_status->logable) {
                                ProductSale::addProductSale(
                                    (int) $product['id_product'],
                                    (int) $product['cart_quantity']
                                );
                            }
                        }

                        if (self::DEBUG_MODE) {
                            PrestaShopLogger::addLog(
                                'PaymentModule::validateOrder - Order Status is about to be added',
                                1,
                                null,
                                'Cart',
                                (int) $id_cart,
                                true
                            );
                        }

                        // Set the order status
                        $new_history = new OrderHistory();
                        $new_history->id_order = (int) $order->id;
                        $new_history->changeIdOrderState((int) $id_order_state, $order, true);
                        $new_history->addWithemail(true, $extra_vars);

                        // Switch to back order if needed
                        if (Configuration::get('PS_STOCK_MANAGEMENT')
                            && ($order_detail->getStockState() || $order_detail->product_quantity_in_stock <= 0)) {
                            $history = new OrderHistory();
                            $history->id_order = (int) $order->id;
                            $history->changeIdOrderState(
                                Configuration::get($order->valid ? 'PS_OS_OUTOFSTOCK_PAID' : 'PS_OS_OUTOFSTOCK_UNPAID'),
                                $order,
                                true
                            );
                            $history->addWithemail();
                        }

                        unset($order_detail);

                        // Order is reloaded because the status just changed
                        $order = new Order((int) $order->id);

                        // Send an e-mail to customer (one order = one email)
                        if ($id_order_state != Configuration::get('PS_OS_ERROR')
                            && $id_order_state != Configuration::get('PS_OS_CANCELED')
                            && $this->context->customer->id
                        ) {
                            $invoice = new Address((int) $order->id_address_invoice);
                            $delivery = new Address((int) $order->id_address_delivery);
                            $delivery_state = $delivery->id_state ? new State((int) $delivery->id_state) : false;
                            $invoice_state = $invoice->id_state ? new State((int) $invoice->id_state) : false;

                            $data = array(
                                '{firstname}' => $this->context->customer->firstname,
                                '{lastname}' => $this->context->customer->lastname,
                                '{email}' => $this->context->customer->email,
                                '{delivery_block_txt}' => $this->_getFormatedAddress($delivery, "\n"),
                                '{invoice_block_txt}' => $this->_getFormatedAddress($invoice, "\n"),
                                '{delivery_block_html}' => $this->_getFormatedAddress(
                                    $delivery,
                                    '<br />',
                                    array(
                                        'firstname' => '<span style="font-weight:bold;">%s</span>',
                                        'lastname' => '<span style="font-weight:bold;">%s</span>'
                                    )
                                ),
                                '{invoice_block_html}' => $this->_getFormatedAddress(
                                    $invoice,
                                    '<br />',
                                    array(
                                        'firstname' => '<span style="font-weight:bold;">%s</span>',
                                        'lastname' => '<span style="font-weight:bold;">%s</span>'
                                    )
                                ),
                                '{delivery_company}' => $delivery->company,
                                '{delivery_firstname}' => $delivery->firstname,
                                '{delivery_lastname}' => $delivery->lastname,
                                '{delivery_address1}' => $delivery->address1,
                                '{delivery_address2}' => $delivery->address2,
                                '{delivery_city}' => $delivery->city,
                                '{delivery_postal_code}' => $delivery->postcode,
                                '{delivery_country}' => $delivery->country,
                                '{delivery_state}' => $delivery->id_state ? $delivery_state->name : '',
                                '{delivery_phone}' => ($delivery->phone) ? $delivery->phone : $delivery->phone_mobile,
                                '{delivery_other}' => $delivery->other,
                                '{invoice_company}' => $invoice->company,
                                '{invoice_vat_number}' => $invoice->vat_number,
                                '{invoice_firstname}' => $invoice->firstname,
                                '{invoice_lastname}' => $invoice->lastname,
                                '{invoice_address2}' => $invoice->address2,
                                '{invoice_address1}' => $invoice->address1,
                                '{invoice_city}' => $invoice->city,
                                '{invoice_postal_code}' => $invoice->postcode,
                                '{invoice_country}' => $invoice->country,
                                '{invoice_state}' => $invoice->id_state ? $invoice_state->name : '',
                                '{invoice_phone}' => ($invoice->phone) ? $invoice->phone : $invoice->phone_mobile,
                                '{invoice_other}' => $invoice->other,
                                '{order_name}' => $order->getUniqReference(),
                                '{date}' => Tools::displayDate(date('Y-m-d H:i:s'), null, 1),
                                '{carrier}' => ($virtual_product || !isset($carrier->name))
                                    ? Tools::displayError('No carrier')
                                    : $carrier->name,
                                '{payment}' => Tools::substr($order->payment, 0, 32),
                                '{products}' => $product_list_html,
                                '{products_txt}' => $product_list_txt,
                                '{discounts}' => $cart_rules_list_html,
                                '{discounts_txt}' => $cart_rules_list_txt,
                                '{total_paid}' => Tools::displayPrice(
                                    $order->total_paid,
                                    $this->context->currency,
                                    false
                                ),
                                '{total_products}' => Tools::displayPrice(
                                    Product::getTaxCalculationMethod() == PS_TAX_EXC
                                    ? $order->total_products
                                    : $order->total_products_wt,
                                    $this->context->currency,
                                    false
                                ),
                                '{total_discounts}' => Tools::displayPrice(
                                    $order->total_discounts,
                                    $this->context->currency,
                                    false
                                ),
                                '{total_shipping}' => Tools::displayPrice(
                                    $order->total_shipping,
                                    $this->context->currency,
                                    false
                                ),
                                '{total_wrapping}' => Tools::displayPrice(
                                    $order->total_wrapping,
                                    $this->context->currency,
                                    false
                                ),
                                '{total_tax_paid}' => Tools::displayPrice(
                                    ($order->total_products_wt - $order->total_products)
                                    + ($order->total_shipping_tax_incl - $order->total_shipping_tax_excl),
                                    $this->context->currency,
                                    false
                                )
                            );

                            if (is_array($extra_vars)) {
                                $data = array_merge($data, $extra_vars);
                            }

                            // Join PDF invoice
                            $file_attachement = null;
                            if ((int) Configuration::get('PS_INVOICE')
                                && $order_status->invoice && $order->invoice_number
                            ) {
                                $order_invoice_list = $order->getInvoicesCollection();
                                Hook::exec(
                                    'actionPDFInvoiceRender',
                                    array('order_invoice_list' => $order_invoice_list)
                                );
                                $pdf = new PDF($order_invoice_list, PDF::TEMPLATE_INVOICE, $this->context->smarty);
                                $file_attachement['content'] = $pdf->render(false);
                                $file_attachement['name'] = Configuration::get(
                                    'PS_INVOICE_PREFIX',
                                    (int) $order->id_lang,
                                    null,
                                    $order->id_shop
                                ) . sprintf('%06d', $order->invoice_number) . '.pdf';
                                $file_attachement['mime'] = 'application/pdf';
                            }

                            if (self::DEBUG_MODE) {
                                PrestaShopLogger::addLog(
                                    'PaymentModule::validateOrder - Mail is about to be sent',
                                    1,
                                    null,
                                    'Cart',
                                    (int) $id_cart,
                                    true
                                );
                            }

                            if (Validate::isEmail($this->context->customer->email)) {
                                Mail::Send(
                                    (int) $order->id_lang,
                                    'order_conf',
                                    Mail::l('Order confirmation', (int) $order->id_lang),
                                    $data,
                                    $this->context->customer->email,
                                    $this->context->customer->firstname . ' ' . $this->context->customer->lastname,
                                    null,
                                    null,
                                    $file_attachement,
                                    null,
                                    _PS_MAIL_DIR_,
                                    false,
                                    (int) $order->id_shop
                                );
                            }
                        }

                        // updates stock in shops
                        if (Configuration::get('PS_ADVANCED_STOCK_MANAGEMENT')) {
                            $product_list = $order->getProducts();
                            foreach ($product_list as $product) {
                                // if the available quantities depends on the physical stock
                                if (StockAvailable::dependsOnStock($product['product_id'])) {
                                    // synchronizes
                                    StockAvailable::synchronize($product['product_id'], $order->id_shop);
                                }
                            }
                        }

                        $order->updateOrderDetailTax();
                    } else {
                        $error = Tools::displayError('Order creation failed');
                        PrestaShopLogger::addLog($error, 4, '0000002', 'Cart', (int) $order->id_cart);
                        die($error);
                    }
                } // End foreach $order_detail_list
                // Use the last order as currentOrder
                if (isset($order) && $order->id) {
                    $this->currentOrder = (int) $order->id;
                    if ($payment_method == 'Free order' || (isset($context->employee) && Validate::isLoadedObject($context->employee))) {
                        $param = array();
                        $currency = new Currency($order->id_currency);
                        $params['total_to_pay'] = $order->getOrdersTotalPaid();
                        $params['currency'] = $currency->sign;
                        $params['objOrder'] = $order;
                        $params['currencyObj'] = $currency;
                        Hook::exec('displayOrderConfirmation', $params);    
                    }
                }

                if (self::DEBUG_MODE) {
                    PrestaShopLogger::addLog(
                        'PaymentModule::validateOrder - End of validateOrder',
                        1,
                        null,
                        'Cart',
                        (int) $id_cart,
                        true
                    );
                }

                return true;
            } else {
                $error = Tools::displayError('Cart cannot be loaded or an order 
                    has already been placed using this cart');
                PrestaShopLogger::addLog($error, 4, '0000001', 'Cart', (int) $this->context->cart->id);
                die($error);
            }
        } else {
                     
           if(parent::validateOrder(
                $id_cart,
                $id_order_state,
                $amount_paid,
                $payment_method,
                $message,
                $extra_vars,
                $currency_special,
                $dont_touch_amount,
                $secure_key,
                $shop
            )) { if(KbPerProductSellerShipping::issellersProductsInCart()) {
                   $context = Context::getContext();
                   $order_obj = new Order();
                   $order_id = $order_obj->getOrderByCartId(Tools::getValue('id_cart'));
                   $order = new Order($order_id);
                   if (isset($order) && $order->id) {
                    $this->currentOrder = (int) $order->id;
                    if (isset($context->employee) && Validate::isLoadedObject($context->employee)) {
                        $param = array();
                        $currency = new Currency($order->id_currency);
                        $params['total_to_pay'] = $order->getOrdersTotalPaid();
                        $params['currency'] = $currency->sign;
                        $params['objOrder'] = $order;
                        $params['currencyObj'] = $currency;
                        Hook::exec('displayOrderConfirmation', $params);    
                    }
                  }
                }
                 return true;
             }
        }
    }
}	