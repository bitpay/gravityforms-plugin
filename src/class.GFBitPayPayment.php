<?php

/**
 * @license Copyright 2011-2014 BitPay Inc., MIT License 
 * see https://github.com/bitpay/gravityforms-plugin/blob/master/LICENSE
 */

/**
 * Class for handling BitPay payment
 *
 * @link https://bitpay.com/bitcoin-payment-gateway-api
 */
class GFBitPayPayment
{
    public $formId;             // Displays form number
    public $posData;            // Displays unique id
    public $invoiceDescription; // Displays product name on invoice
    public $productDescription; // Product's description
    public $total;              // Displays Total
    public $buyerName;
    public $buyerAddress1;
    public $buyerAddress2;
    public $buyerZip;
    public $buyerState;
    public $buyerCity;
    public $buyerCountry;
    public $buyerPhone;
    public $buyerEmail;
    public $currency;
    
    /**
     * Writes $contents to system error logger.
     *
     * @param mixed $contents
     * @throws Exception $e
     */
    public function bpLog($contents)
    {
        if (false === isset($contents) || true === empty($contents)) {
            return;
        }

        if (true === is_array($contents)) {
            $contents = var_export($contents, true);
        } else if (true === is_object($contents)) {
            $contents = json_encode($contents);
        }

        error_log($contents);
    }

    /**
     * Process a payment
     */
    public function processPayment()
    {
        try {
            // Protect your data!
            $mcrypt_ext  = new \Bitpay\Crypto\McryptExtension();

            if (false === isset($mcrypt_ext) || true === empty($mcrypt_ext)) {
                $this->bpLog('[ERROR] In GFBitPayPayment::processPayment(): Could not create a new McryptExtension object.');
                throw new \Exception('An error occurred in the BitPay Payment plugin: Could not create a new McryptExtension object.');
            }

            $fingerprint = substr(sha1(sha1(__DIR__)), 0, 24);

            //Use token that is in_use and with facade = pos for generating invoices
            if (get_option('bitpayToken') == '' || is_null(get_option('bitpayToken')) === true) {
                $this->bpLog("No tokens are paired so no transactions can be done!");
                var_dump("Error Processing Transaction. Please try again later. If the problem persists, please contact us at " .get_option('admin_email'));
                $errmsg = "ERROR!";
            }

            $token       = unserialize(base64_decode($mcrypt_ext->decrypt(get_option('bitpayToken'), $fingerprint, '00000000')));
            $public_key  = unserialize(base64_decode($mcrypt_ext->decrypt(get_option('bitpayPublicKey'),  $fingerprint, '00000000')));
            $private_key = unserialize(base64_decode($mcrypt_ext->decrypt(get_option('bitpayPrivateKey'), $fingerprint, '00000000')));
    
            $network = (get_option('bitpayNetwork') === 'Livenet') ? new \Bitpay\Network\Livenet() : new \Bitpay\Network\Testnet();

            $adapter = new \Bitpay\Client\Adapter\CurlAdapter();

            if (false === isset($adapter) || true === empty($adapter)) {
                $this->bpLog('[ERROR] In GFBitPayPayment::processPayment(): Could not create a new CurlAdapter object.');
                throw new \Exception('An error occurred in the BitPay Payment plugin: Could not create a new CurlAdapter object.');
            }

            /**
             * Create Buyer object that will be used later.
             */
            $buyer = new \Bitpay\Buyer();

            if (false === isset($buyer) || true === empty($buyer)) {
                $this->bpLog('[ERROR] In GFBitPayPayment::processPayment(): Could not create a new Buyer object.');
                throw new \Exception('An error occurred in the BitPay Payment plugin: Could not create a new Buyer object.');
            }

            // name
            if (true === isset($this->buyerName)) {
                $buyer->setFirstName($this->buyerName);
            } else if (true === isset($this->buyerFirstName)) {
                $buyer->setFirstName($this->buyerFirstName);

                if (true === isset($this->setLastName)) {
                    $buyer->setLastName($this->setLastName);
                }
            }

            // address -- remove newlines
            if (true === isset($this->buyerAddress1)) {
                $address_line2 = '';

                if (true === isset($this->buyerAddress2)) {
                    $address_line2 = $this->buyerAddress2;
                }

                $buyer->setAddress(array($this->buyerAddress1, $address_line2,));
            }

            // state
            if (true === isset($this->buyerState)) {
                $buyer->setState($this->buyerState);
            }

            // country
            if (true === isset($this->buyerCountry)) {
                $buyer->setCountry($this->buyerCountry);
            }

            // city
            if (true === isset($this->buyerCity)) {
                $buyer->setCity($this->buyerCity);
            }

            // postal code
            if (true === isset($this->buyerZip)) {
                $buyer->setZip($this->buyerZip);
            }

            // email
            if (true === isset($this->buyerEmail)) {
                $buyer->setEmail($this->buyerEmail);
            }

            // phone
            if (true === isset($this->buyerPhone)) {
                $buyer->setPhone($this->buyerPhone);
            }

            /**
             * Create an Item object that will be used later
             */
            $item = new \Bitpay\Item();

            if (false === isset($item) || true === empty($item)) {
                $this->bpLog('[ERROR] In GFBitPayPayment::processPayment(): Could not create a new Item object.');
                throw new \Exception('An error occurred in the BitPay Payment plugin: Could not create a new Item object.');
            }

            // price
            $price = number_format($this->total, 2, '.', '');

            $item->setDescription($this->invoiceDescription)
                 ->setPrice($price);

            // Create new BitPay invoice
            $invoice = new \Bitpay\Invoice();

            if (false === isset($invoice) || true === empty($invoice)) {
                $this->bpLog('[ERROR] In GFBitPayPayment::processPayment(): Could not create a new Invoice object.');
                throw new \Exception('An error occurred in the BitPay Payment plugin: Could not create a new Invoice object.');
            }

            // Add the item to the invoice
            $invoice->setItem($item);

            // Add the buyers info to invoice
            $invoice->setBuyer($buyer);

            // Configure the rest of the invoice
            $invoice->setOrderId($this->posData)
                    ->setNotificationUrl(get_option('siteurl').'/?bitpay_callback=true');

            /**
             * BitPay offers services for many different currencies. You will need to
             * configure the currency in which you are selling products with.
             */
            $currency = new \Bitpay\Currency();

            if (false === isset($currency) || true === empty($currency)) {
                $this->bpLog('[ERROR] In GFBitPayPayment::processPayment(): Could not create a new Currency object.');
                throw new \Exception('An error occurred in the BitPay Payment plugin: Could not create a new Currency object.');
            }

            $currency->setCode(GFCommon::get_currency());

            $invoice->setCurrency($currency);

            // Transaction Speed
            $invoice->setTransactionSpeed(get_option('bitpayTransactionSpeed'));

            // Redirect URL
            if (true === empty(get_option('bitpayRedirectURL'))) {
                update_option('bitpayRedirectURL', get_site_url());
            }

            $redirect_url = get_option('bitpayRedirectURL');
            $invoice->setRedirectUrl($redirect_url);

            // PosData
            $invoice->setPosData($this->posData);

            // Full Notifications
            $invoice->setFullNotifications(true);

            /**
             * Create the client that will be used
             * to send requests to BitPay's API
             */
            $client = new \Bitpay\Client\Client();

            if (false === isset($client) || true === empty($client)) {
                $this->bpLog('[ERROR] In GFBitPayPayment::processPayment(): Could not create a new Client object.');
                throw new \Exception('An error occurred in the BitPay Payment plugin: Could not create a new Client object.');
            }

            $client->setAdapter($adapter);
            $client->setNetwork($network);
            $client->setPrivateKey($private_key);
            $client->setPublicKey($public_key);

            /**
             * You will need to set the token that was
             * returned when you paired your keys.
             */
            $client->setToken($token);

            // Send invoice
            try {
                $client->createInvoice($invoice);
            } catch (\Exception $e) {
                $this->bpLog($e->getMessage());
                var_dump("Error Processing Transaction. Please try again later. If the problem persists, please contact us at " .get_option('admin_email'));
                exit();
            }

            header('Location: '. $invoice->getUrl());
        } catch (\Exception $e) {
            error_log('[Error] In Bitpay plugin, form_bitpay() function on line ' . $e->getLine() . ', with the error "' . $e->getMessage() . '" .');
            throw $e;
        }
    }
}
