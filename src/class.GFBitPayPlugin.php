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
* Custom exception classes
*/
class GFBitPayException extends Exception {}
class GFBitPayCurlException extends Exception {}

/**
* Class for managing the plugin
*/
class GFBitPayPlugin {
	public $urlBase;                  // string: base URL path to files in plugin
	public $options;                  // array of plugin options
	protected $txResult = null;       // BitPay transaction results

	/**
	* Static method for getting the instance of this singleton object
	*
	* @return GFBitPayPlugin
	*/
	public static function getInstance() {
		static $instance = NULL;

		if (is_null($instance)) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	* Initialize plugin
	*/
	private function __construct() {
		// record plugin URL base
		$this->urlBase = plugin_dir_url(__FILE__);

		add_action('init', array($this, 'init'));
	}

	/**
	* handle the plugin's init action
	*/
	public function init() {
		// do nothing if Gravity Forms isn't enabled
		if (class_exists('GFCommon')) {
			// hook into Gravity Forms to enable credit cards and trap form submissions
			add_filter('gform_pre_render', array($this, 'gformPreRenderSniff'));
			add_filter('gform_admin_pre_render', array($this, 'gformPreRenderSniff'));
			add_filter('gform_currency', array($this, 'gformCurrency'));
			add_filter('gform_validation', array($this, 'gformValidation'));
			add_action('gform_after_submission', array($this, 'gformAfterSubmission'), 10, 2);
			add_filter('gform_custom_merge_tags', array($this, 'gformCustomMergeTags'), 10, 4);
			add_filter('gform_replace_merge_tags', array($this, 'gformReplaceMergeTags'), 10, 7);
		}

		if (is_admin()) {
			// kick off the admin handling
			new GFBitPayAdmin($this);
		}
	}

	/**
	* check current form for information
	* @param array $form
	* @return array $form
	*/
	public function gformPreRenderSniff($form) {
		// TODO: Should we be checking anything here?
		return $form;
	}

	/**
	* process a form validation filter hook; if last page and has total, attempt to bill it
	* @param array $data an array with elements is_valid (boolean) and form (array of form elements)
	* @return array
	*/
	public function gformValidation($data) {
		// make sure all other validations passed
		if ($data['is_valid']) {
			$formData = new GFBitPayFormData($data['form']);
			// make sure form hasn't already been submitted / processed
			if ($this->hasFormBeenProcessed($data['form'])) {
				$data['is_valid'] = false;
				$formData->buyerName['failed_validation'] = true;
				$formData->buyerName['validation_message'] = $this->getErrMsg(GFBITPAY_ERROR_ALREADY_SUBMITTED);
			}

			// make that this is the last page of the form
			else if ($formData->isLastPage()) {
				if (!$formData) {
					$data['is_valid'] = false;
					$formData->buyerName['failed_validation'] = true;
					$formData->buyerName['validation_message'] = $this->getErrMsg(GFBITPAY_ERROR_NO_AMOUNT);
				} else {
					if ($formData->total > 0) {
						$data = $this->processSinglePayment($data, $formData);
					} else {
						$formData->buyerName['failed_validation'] = true;
						$formData->buyerName['validation_message'] = $this->getErrMsg(GFBITPAY_ERROR_NO_AMOUNT);
					}
				}
			}

			// if errors, send back to the customer information page
			if (!$data['is_valid']) {
				GFFormDisplay::set_current_page($data['form']['id'], $formData->buyerName['pageNumber']);
			}
		}

		return $data;
	}

	/**
	* check whether this form entry's unique ID has already been used; if so, we've already done a payment attempt.
	* @param array $form
	* @return boolean
	*/
	protected function hasFormBeenProcessed($form) {
		global $wpdb;

		$unique_id = RGFormsModel::get_form_unique_id($form['id']);

		$sql = "select lead_id from {$wpdb->prefix}rg_lead_meta where meta_key='gfbitpay_unique_id' and meta_value = %s";
		$lead_id = $wpdb->get_var($wpdb->prepare($sql, $unique_id));

		return !empty($lead_id);
	}

	/**
	* get customer ID
	* @return string
	*/
	protected function getCustomerID() {
		return $this->options['customerID'];
	}

	/**
	* process regular one-off payment
	* @param array $data an array with elements is_valid (boolean) and form (array of form elements)
	* @param GFBitPayFormData $formData pre-parsed data from $data
	* @return array
	*/
	protected function processSinglePayment($data, $formData) {
		// global $wpdb;
		// require_once ABSPATH.'wp-admin/includes/upgrade.php';
		try {
			$bitpay = new GFBitPayPayment();

			$bitpay->formId = $data['form']['id']; // This is the form that the invoice uses

			$bitpay->posData = uniqid();
			$bitpay->invoiceDescription = get_bloginfo('name')." - ".$formData->productName;
			$bitpay->buyerName = $formData->buyerName;
			$bitpay->buyerAddress1 = $formData->buyerAddress1;
			$bitpay->buyerAddress2 = $formData->buyerAddress2;
			$bitpay->buyerZip = $formData->buyerZip;
			$bitpay->buyerState = $formData->buyerState;
			$bitpay->buyerCity = $formData->buyerCity;
			$bitpay->buyerCountry = $formData->buyerCountry;
			$bitpay->buyerPhone = $formData->buyerPhone;
			$bitpay->buyerEmail = $formData->buyerEmail;
			$bitpay->productDescription = $formData->productDescription;
			$bitpay->total = $formData->total;

			$this->txResult = array (
				'payment_gateway'		=> 'gfbitpay',
				'gfbitpay_unique_id'		=> GFFormsModel::get_form_unique_id($data['form']['id']),	// reduces risk of double-submission
			);

			$response = $bitpay->processPayment();

			$this->txResult['payment_status']	= "New";
			$this->txResult['date_created']     = date('Y-m-d H:i:s');
			$this->txResult['payment_date']		= NULL; // Date when payment is received
			$this->txResult['payment_amount']	= $bitpay->total;
			$this->txResult['transaction_id']	= $bitpay->posData;
			$this->txResult['transaction_type']	= 1;
			$this->txResult['currency']         = GFCommon::get_currency();
			$this->txResult['status']           = 'Active';
			$this->txResult['payment_method']   = 'Bitcoin';
			$this->txResult['is_fulfilled']     = '0';

		} catch (GFBitPayException $e) {
			$data['is_valid'] = false;
			$this->txResult = array (
				'payment_status' => 'Failed',
			);
			error_log("Something failed");
		}

		return $data;
	}


	/**
	* save the transaction details to the entry after it has been created
	* @param array $data an array with elements is_valid (boolean) and form (array of form elements)
	* @return array
	*/
	public function gformAfterSubmission($entry, $form) {
		global $wpdb;
		$formData = new GFBitPayFormData($form);
		$leadId = $entry['id'];
		$email = $formData->buyerEmail;
		$wpdb->insert(
			'bitpay_transactions', 
			array( 
				'lead_id' => $leadId, 
				'buyer_email' => $email
			)
		);
		if (!empty($this->txResult)) {
			foreach ($this->txResult as $key => $value) {
				switch ($key) {
					case 'authcode':
						// record bank authorisation code, Beagle score
						gform_update_meta($entry['id'], $key, $value);
						break;
					default:
						$entry[$key] = $value;
						break;
				}
			}
			RGFormsModel::update_lead($entry);
			// record entry's unique ID in database
			$unique_id = RGFormsModel::get_form_unique_id($form['id']);

			gform_update_meta($entry['id'], 'gfbitpay_transaction_id', $unique_id);

			// record payment gateway
			gform_update_meta($entry['id'], 'payment_gateway', 'gfbitpay');
		}
	}

	/**
	* add custom merge tags
	* @param array $merge_tags
	* @param int $form_id
	* @param array $fields
	* @param int $element_id
	* @return array
	*/
	public function gformCustomMergeTags($merge_tags, $form_id, $fields, $element_id) {
		if ($fields && $this->hasFieldType($fields, 'creditcard')) {
			$merge_tags[] = array('label' => 'Transaction ID', 'tag' => '{transaction_id}');
			$merge_tags[] = array('label' => 'Auth Code', 'tag' => '{authcode}');
			$merge_tags[] = array('label' => 'Payment Amount', 'tag' => '{payment_amount}');
			$merge_tags[] = array('label' => 'Payment Status', 'tag' => '{payment_status}');
		}
		return $merge_tags;
	}

	/**
	* replace custom merge tags
	* @param string $text
	* @param array $form
	* @param array $lead
	* @param bool $url_encode
	* @param bool $esc_html
	* @param bool $nl2br
	* @param string $format
	* @return string
	*/
	public function gformReplaceMergeTags($text, $form, $lead, $url_encode, $esc_html, $nl2br, $format) {
		if ($this->hasFieldType($form['fields'], 'buyerName')) {
			if (is_null($this->txResult)) {
				// lead loaded from database, get values from lead meta
				$transaction_id = isset($lead['transaction_id']) ? $lead['transaction_id'] : '';
				$payment_amount = isset($lead['payment_amount']) ? $lead['payment_amount'] : '';
				$payment_status = isset($lead['payment_status']) ? $lead['payment_status'] : '';
				$authcode = (string) gform_get_meta($lead['id'], 'authcode');
			} else {
				// lead not yet saved, get values from transaction results
				$transaction_id = isset($this->txResult['transaction_id']) ? $this->txResult['transaction_id'] : '';
				$payment_amount = isset($this->txResult['payment_amount']) ? $this->txResult['payment_amount'] : '';
				$payment_status = isset($this->txResult['payment_status']) ? $this->txResult['payment_status'] : '';
				$authcode = isset($this->txResult['authcode']) ? $this->txResult['authcode'] : '';
			}

			$tags = array (
				'{transaction_id}',
				'{payment_amount}',
				'{payment_status}',
				'{authcode}'
			);
			$values = array (
				$transaction_id,
				$payment_amount,
				$payment_status,
				$authcode
			);

			$text = str_replace($tags, $values, $text);
		}

		return $text;
	}


	/**
	* tell Gravity Forms what currencies we can process
	* @param string $currency
	* @return string
	*/
	public function gformCurrency($currency) {
		return $currency;
	}

	/**
	* check form to see if it has a field of specified type
	* @param array $fields array of fields
	* @param string $type name of field type
	* @return boolean
	*/
	public static function hasFieldType($fields, $type) {
		if (is_array($fields)) {
			foreach ($fields as $field) {
				if (RGFormsModel::get_input_type($field) == $type)
					return true;
			}
		}
		return false;
	}

	/**
	* get nominated error message, checking for custom error message in WP options
	* @param string $errName the fixed name for the error message (a constant)
	* @param boolean $useDefault whether to return the default, or check for a custom message
	* @return string
	*/
	public function getErrMsg($errName, $useDefault = false) {
		static $messages = array (
			GFBITPAY_ERROR_ALREADY_SUBMITTED  => 'Payment has already been submitted and processed.',
			GFBITPAY_ERROR_NO_AMOUNT		  => 'This form is missing products or totals',
			GFBITPAY_ERROR_FAIL				  => 'Error processing BitPay transaction',
		);

		// default
		$msg = isset($messages[$errName]) ? $messages[$errName] : 'Unknown error';

		// check for custom message
		if (!$useDefault) {
			$msg = get_option($errName, $msg);
		}

		return $msg;
	}

	/**
	* get the customer's IP address dynamically from server variables
	* @return string
	*/
	public static function getCustomerIP() {
		// if test mode and running on localhost, then kludge to an Aussie IP address
		$plugin = self::getInstance();

		// check for remote address, ignore all other headers as they can be spoofed easily
		if (isset($_SERVER['REMOTE_ADDR']) && self::isIpAddress($_SERVER['REMOTE_ADDR'])) {
			return $_SERVER['REMOTE_ADDR'];
		}

		return '';
	}

	/**
	* check whether a given string is an IP address
	* @param string $maybeIP
	* @return bool
	*/
	protected static function isIpAddress($maybeIP) {
		if (function_exists('inet_pton')) {
			// check for IPv4 and IPv6 addresses
			return !!inet_pton($maybeIP);
		}

		// just check for IPv4 addresses
		return !!ip2long($maybeIP);
	}

	/**
	* display a message (already HTML-conformant)
	* @param string $msg HTML-encoded message to display inside a paragraph
	*/
	public static function showMessage($msg) {
		echo "<div class='updated fade'><p><strong>$msg</strong></p></div>\n";
	}

	/**
	* display an error message (already HTML-conformant)
	* @param string $msg HTML-encoded message to display inside a paragraph
	*/
	public static function showError($msg) {
		echo "<div class='error'><p><strong>$msg</strong></p></div>\n";
	}
}

function bitpay_callback() {
	try {
		global $wpdb;
        if (isset($_GET['bitpay_callback'])) {
            $post = file_get_contents("php://input");
            if (true === empty($post)) {
                return array('error' => 'No post data');
            }
            $json = json_decode($post, true);
            if (true === is_string($json)) {
                return array('error' => $json);
            }
            if (false === array_key_exists('posData', $json)) {
                return array('error' => 'no posData');
            }
            if (false === array_key_exists('id', $json)) {
                return 'Cannot find invoice ID';
            }
            // Don't trust parameters from the scary internet.
            // Use invoice ID from the $json in  getInvoice($invoice_id) and get status from that.
            $client  = new \Bitpay\Client\Client();
            $adapter = new \Bitpay\Client\Adapter\CurlAdapter();
            if (strpos($json['url'], 'test') === false) {
                $network = new \Bitpay\Network\Livenet();
            } else {
                $network = new \Bitpay\Network\Testnet();
            }
            $client->setAdapter($adapter);
            $client->setNetwork($network);
            // Checking invoice is valid...
            $response  = $client->getInvoice($json['id']);
            $sessionid = $response->getPosData();
			      // update payment status
            $lead = $wpdb->get_row( "SELECT * FROM `{$wpdb->prefix}rg_lead` WHERE `transaction_id` = '{$sessionid}'", ARRAY_A );
            $leadId = $lead['id'];
            $bitpayTransaction = $wpdb->get_row( "SELECT * FROM `bitpay_transactions` WHERE `lead_id` = '{$leadId}'", ARRAY_A );
            $email = $bitpayTransaction['buyer_email'];

            switch ($response->getStatus()) {
                //For low and medium transaction speeds, the order status is set to "Order Received" . The customer receives
                //an initial email stating that the transaction has been paid.
                case 'paid':
                    $lead['payment_status'] = "Processing";
                    $lead['payment_date'] = date('Y-m-d H:i:s');
                    RGFormsModel::update_lead($lead);
                    $message = 'Thank you! Your payment has been received, but the transaction has not been confirmed on the bitcoin network. You will receive another email when the transaction has been confirmed.';
                    $note = 'The payment has been received, but the transaction has not been confirmed on the bitcoin network. This will be updated when the transaction has been confirmed.';
                    if (!empty($email) == true){
                    	wp_mail($email, 'Transaction Complete', $message);
                    }
					$wpdb->insert(
						$wpdb->prefix.'rg_lead_notes', 
						array( 
							'lead_id' => $leadId, 
							'user_name' => 'BitPay',
							'user_id' => 0,
							'date_created' => date('Y-m-d H:i:s'),
							'value' => $note,
						)
					);
                    break;
                //For low and medium transaction speeds, the order status will not change. For high transaction speed, the order
                //status is set to "Order Received" here. For all speeds, an email will be sent stating that the transaction has
                //been confirmed.
                case 'confirmed':
                    $lead['payment_status'] = "Pending";
                    $lead['payment_date'] = date('Y-m-d H:i:s');
                    RGFormsModel::update_lead($lead);
                    if (get_option('bitpayTransactionSpeed') == 'high') {
                    	$message = 'Thank you! Your payment has been received, and the transaction has been confirmed on the bitcoin network. You will receive another email when the transaction is complete.';
						$note = 'The payment has been received, and the transaction has been confirmed on the bitcoin network. This will be updated when the transaction has been completed.';
						$wpdb->insert(
							$wpdb->prefix.'rg_lead_notes', 
							array( 
								'lead_id' => $leadId, 
								'user_name' => 'BitPay',
								'user_id' => 0,
								'date_created' => date('Y-m-d H:i:s'),
								'value' => $note,
							)
						);
                    } else {
                    	$message = 'Your transaction has now been confirmed on the bitcoin network. You will receive another email when the transaction is complete.';
                    	$note = 'The payment has been received, and the transaction has been confirmed on the bitcoin network. This will be updated when the transaction has been completed.';
						$wpdb->insert(
							$wpdb->prefix.'rg_lead_notes', 
							array( 
								'lead_id' => $leadId, 
								'user_name' => 'BitPay',
								'user_id' => 0,
								'date_created' => date('Y-m-d H:i:s'),
								'value' => $note,
							)
						);
                    }
                    if (!empty($email) == true){
                    	wp_mail($email, 'Transaction Complete', $message);
                    }
                    break;
                //The purchase receipt email is sent upon the invoice status changing to "complete", and the order
                //status is changed to Accepted Payment
                case 'complete':
                    $lead["payment_status"] = 'Approved';
                    $lead["payment_date"] = date('Y-m-d H:i:s');
                    RGFormsModel::update_lead($lead);
                    $message = 'Your transaction is now complete! Thank you for using BitPay!';
                    if (!empty($email) == true){
                    	wp_mail($email, 'Transaction Complete', $message);
                    }
                    $note = 'The transaction is now complete.';
					$wpdb->insert(
						$wpdb->prefix.'rg_lead_notes', 
						array( 
							'lead_id' => $leadId, 
							'user_name' => 'BitPay',
							'user_id' => 0,
							'date_created' => date('Y-m-d H:i:s'),
							'value' => $note,
						)
					);
                    break;
            }
        }
    } catch (\Exception $e) {
        error_log('[Error] In Bitpay plugin, form_bitpay() function on line ' . $e->getLine() . ', with the error "' . $e->getMessage() . '".');
        throw $e;
    }
}

add_action('init', 'bitpay_callback');
