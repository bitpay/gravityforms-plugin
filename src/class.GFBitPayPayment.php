<?php

/*
©2014 BITPAY, INC.

Permission is hereby granted to any person obtaining a copy of this software
and associated documentation for use and/or modification in association with the
bitpay.com service.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED,
INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR
PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE
FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR
OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
DEALINGS IN THE SOFTWARE.

Bitcoin payment module using the bitpay.com service.
*/

/**
* Class for handling BitPay payment
*
* @link https://bitpay.com/bitcoin-payment-gateway-api
*
*/
class GFBitPayPayment {

	public $formId; // Displays form number
	public $posData; // Displays unique id
	public $invoiceDescription; // Displays product name on invoice
	public $productDescription; // Product's description
	public $total; // Displays Total
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

	///////////////////////////////////////////////////////////////////////////////////////////////////
	///////////////////////////////////////////////////////////////////////////////////////////////////
	
	/**
	 *
	 * Writes $contents to a log file specified in the bp_options file or, if missing,
	 * defaults to a standard filename of 'bplog.txt'.
	 *
	 * @param mixed $contents
	 * @return
	 * @throws Exception $e
	 *
	 */
	public function bpLog($contents) {
		try {
	
			if(isset($bpOptions['logFile']) && $bpOptions['logFile'] != '') {
				$file = dirname(__FILE__).$bpOptions['logFile'];
			} else {
				// Fallback to using a default logfile name in case the variable is
				// missing or not set.
				$file = dirname(__FILE__).'/bplog.txt';
			}
	
			file_put_contents($file, date('m-d H:i:s').": ", FILE_APPEND);
	
			if (is_array($contents))
				$contents = var_export($contents, true);
			else if (is_object($contents))
				$contents = json_encode($contents);
	
			file_put_contents($file, $contents."\n", FILE_APPEND);
	
		} catch (Exception $e) {
			echo 'Error: ' . $e->getMessage();
		}
	}

	/**
	* Process a payment
	*/
	public function processPayment() {
	    try {
	        // Protect your data!
	        $mcrypt_ext  = new \Bitpay\Crypto\McryptExtension();
	        $fingerprint = substr(sha1(sha1(__DIR__)), 0, 24);
	        //Use token that is in_use and with facade = pos for generating invoices
	        if (get_option('bitpayToken') == '' || is_null(get_option('bitpayToken')) == true) {
	            $this->bpLog("No tokens are paired so no transactions can be done!");
	            var_dump("Error Processing Transaction. Please try again later. If the problem persists, please contact us at " .get_option('admin_email'));
	        	$errmsg = "ERROR!";
	        }
	        $token       = unserialize(base64_decode($mcrypt_ext->decrypt(get_option('bitpayToken'), $fingerprint, '00000000')));
	        $public_key  = unserialize(base64_decode($mcrypt_ext->decrypt(get_option('bitpayPublicKey'),  $fingerprint, '00000000')));
	        $private_key = unserialize(base64_decode($mcrypt_ext->decrypt(get_option('bitpayPrivateKey'), $fingerprint, '00000000')));
	        $network = (get_option('bitpayNetwork') === 'Livenet') ? new \Bitpay\Network\Livenet() : new \Bitpay\Network\Testnet();
	        $adapter = new \Bitpay\Client\Adapter\CurlAdapter();

	        /**
	         * Create Buyer object that will be used later.
	         */
	        $buyer = new \Bitpay\Buyer();
	        // name
	        if (true === isset($this->buyerName)) {
	        	$buyer->setFirstName($this->buyerName);
	        } elseif (true === isset($this->buyerName)) {
	        	$buyer->setFirstName($this->buyerFirstName);
	        	if (true === isset($this->buyerName)) {
	            	$buyer->setLastName($this->buyerName);
	        	}
	        }

	        // address -- remove newlines
	        if (true === isset($this->buyerAddress1)) {
	        	$address_line2 = '';
	        	if (true === isset($this->buyerAddress2)) {
	        		$address_line2 = $this->buyerAddress2;
	        	}
	            $buyer->setAddress(
	                array(
	                    $this->buyerAddress1,
	                    $address_line2,
	                )
	            );
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

	        // price
	        $price = number_format($this->total, 2, '.', '');
	        $item->setDescription($this->invoiceDescription)
	             ->setPrice($price);
	        // Create new BitPay invoice
	        $invoice = new \Bitpay\Invoice();
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
	        $currency->setCode(GFCommon::get_currency());
	        $invoice->setCurrency($currency);
	        // Transaction Speed
	        $invoice->setTransactionSpeed(get_option('bitpayTransactionSpeed'));
	        // Redirect URL
	        if (true === is_null(get_option('bitpayRedirectURL'))) {
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
	            error_log($e->getMessage());
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