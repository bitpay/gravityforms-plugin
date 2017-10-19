<?php

/**
 * @license Copyright 2011-2014 BitPay Inc., MIT License 
 * see https://github.com/bitpay/gravityforms-plugin/blob/master/LICENSE
 */

/**
 * Custom exception classes
 */
class GFBitPayException extends Exception {}
class GFBitPayCurlException extends Exception {}
GFForms::include_payment_addon_framework();

/**
 * Class for managing the plugin
 */

class GFBitPayPlugin extends GFPaymentAddOn 
{
    protected $_version = "2.3.7";
    protected $_min_gravityforms_version = '1.9';
    protected $_slug = 'bitpay-gravityforms-plugin';
    protected $_path = 'bitpay-gravityforms-plugin/gravityforms-bitpay.php';
    protected $_full_path = __FILE__;
    protected $_title = 'Gravity Forms BitPay';
    protected $_short_title = 'BitPay';
    public $urlBase;                  // string: base URL path to files in plugin
    public $options;                  // array of plugin options
    private static $instance = null;
    protected $txResult = null;       // BitPay transaction results

    /**
     * Static method for getting the instance of this singleton object
     *
     * @return GFBitPayPlugin
     */
    public static function get_instance()
    {
        if (true === empty(self::$instance)) {
            $instance = new self();
        }
        return $instance;
    }
   
      public function option_choices() {
        return false;
    }

    public function feed_settings_fields() {
        $default_settings = parent::feed_settings_fields();
        //hide default display of setup fee
        $default_settings = $this->remove_field('billingInformation', $default_settings);
        return $default_settings;
    }

    /**
     * Initialize plugin
     */
    public function __construct()
    {
        // record plugin URL base
        $this->urlBase = plugin_dir_url(__FILE__);
        parent::init();
        add_action('init', array($this, 'init'));
    }

    /**
     * handle the plugin's init action
     */
    public function init()
    {
        // do nothing if Gravity Forms isn't enabled
        if (true === class_exists('GFCommon')) {
            // hook into Gravity Forms to trap form submissions
            add_filter('gform_currency', array($this, 'gformCurrency'));
            add_filter('gform_validation', array($this, 'gformValidation'));
            add_action('gform_after_submission', array($this, 'gformAfterSubmission'), 10, 2);
            add_filter('gform_custom_merge_tags', array($this, 'gformCustomMergeTags'), 10, 4);
            add_filter('gform_replace_merge_tags', array($this, 'gformReplaceMergeTags'), 10, 7);
        }
      
        if (is_admin() == true) {
            // kick off the admin handling
            new GFBitPayAdmin($this);
        }
    }

    /**
     * process a form validation filter hook; if last page and has total, attempt to bill it
     * @param array $data an array with elements is_valid (boolean) and form (array of form elements)
     * @return array
     */
    public function gformValidation($data)
    {
        // make sure all other validations passed
        if ($data['is_valid']) {
            $formData = new GFBitPayFormData($data['form']);

            if (false === isset($formData) || true === empty($formData)) {
                error_log('[ERROR] In GFBitPayPlugin::gformValidation(): Could not create a new McryptExtension object.');
                throw new \Exception('An error occurred in the BitPay Payment plugin: Could not create a new gformValidation object.');
            }

            // make sure form hasn't already been submitted / processed
            if ($this->hasFormBeenProcessed($data['form'])) {
                $data['is_valid'] = false;

                $formData->buyerName['failed_validation']  = true;
                $formData->buyerName['validation_message'] = $this->getErrMsg(GFBITPAY_ERROR_ALREADY_SUBMITTED);
            } else if ($formData->isLastPage()) {
                // make that this is the last page of the form
                if (!$formData) {
                    $data['is_valid'] = false;

                    $formData->buyerName['failed_validation']  = true;
                    $formData->buyerName['validation_message'] = $this->getErrMsg(GFBITPAY_ERROR_NO_AMOUNT);
                } else {
                    if ($formData->total > 0) {
                        $data = $this->processSinglePayment($data, $formData);
                    } else {
                        $formData->buyerName['failed_validation']  = true;
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
    protected function hasFormBeenProcessed($form)
    {
        global $wpdb;

        $unique_id = RGFormsModel::get_form_unique_id($form['id']);
        $sql       = "select lead_id from {$wpdb->prefix}rg_lead_meta where meta_key='gfbitpay_unique_id' and meta_value = %s";
        $lead_id   = $wpdb->get_var($wpdb->prepare($sql, $unique_id));

        return !empty($lead_id);
    }

    /**
     * get customer ID
     * @return string
     */
    protected function getCustomerID()
    {
        return $this->options['customerID'];
    }

    /**
     * process regular one-off payment
     * @param array $data an array with elements is_valid (boolean) and form (array of form elements)
     * @param GFBitPayFormData $formData pre-parsed data from $data
     * @return array
     */
    protected function processSinglePayment($data, $formData)
    {
        try {
            $bitpay = new GFBitPayPayment();

            if (false === isset($bitpay) || true === empty($bitpay)) {
                error_log('[ERROR] In GFBitPayPlugin::processSinglePayment(): Could not create a new GFBitPayPayment object.');
                throw new \Exception('An error occurred in the BitPay Payment plugin: Could not create a new GFBitPayPayment object.');
            }

            $bitpay->formId             = $data['form']['id']; // This is the form that the invoice uses
            $bitpay->posData            = uniqid();
            $bitpay->invoiceDescription = get_bloginfo('name')." - ".$formData->productName;
            $bitpay->buyerName          = $formData->buyerName;
            $bitpay->buyerAddress1      = $formData->buyerAddress1;
            $bitpay->buyerAddress2      = $formData->buyerAddress2;
            $bitpay->buyerZip           = $formData->buyerZip;
            $bitpay->buyerState         = $formData->buyerState;
            $bitpay->buyerCity          = $formData->buyerCity;
            $bitpay->buyerCountry       = $formData->buyerCountry;
            $bitpay->buyerPhone         = $formData->buyerPhone;
            $bitpay->buyerEmail         = $formData->buyerEmail;
            $bitpay->productDescription = $formData->productDescription;
            $bitpay->total              = $formData->total;

            $this->txResult = array (
                'payment_gateway'    => 'gfbitpay',
                'gfbitpay_unique_id' => GFFormsModel::get_form_unique_id($data['form']['id']),
            );

            $response = $bitpay->processPayment();

            $this->txResult['payment_status']   = 'New';
            $this->txResult['date_created']     = date('Y-m-d H:i:s');
            $this->txResult['payment_date']     = null;
            $this->txResult['payment_amount']   = $bitpay->total;
            $this->txResult['transaction_id']   = $bitpay->posData;
            $this->txResult['transaction_type'] = 1;
            $this->txResult['currency']         = GFCommon::get_currency();
            $this->txResult['status']           = 'Active';
            $this->txResult['payment_method']   = 'Bitcoin';
            $this->txResult['is_fulfilled']     = '0';

        } catch (GFBitPayException $e) {
            $data['is_valid'] = false;
            $this->txResult   = array('payment_status' => 'Failed',);

            error_log('[ERROR] In GFBitPayPlugin::processSinglePayment(): ' . $e->getMessage());

            throw $e;
        }

        return $data;
    }


    /**
     * save the transaction details to the entry after it has been created
     * @param array $data an array with elements is_valid (boolean) and form (array of form elements)
     * @return array
     */
    public function gformAfterSubmission($entry, $form)
    {
        global $wpdb;

        $formData = new GFBitPayFormData($form);

        if (false === isset($formData) || true === empty($formData)) {
            error_log('[ERROR] In GFBitPayPlugin::gformAfterSubmission(): Could not create a new GFBitPayFormData object.');
            throw new \Exception('An error occurred in the BitPay Payment plugin: Could not create a new GFBitPayFormData object.');
        }

        $leadId = $entry['id'];
        $email  = $formData->buyerEmail;

        $wpdb->insert(
            'bitpay_transactions', 
            array( 
                'lead_id' => $leadId, 
                'buyer_email' => $email
            )
        );

        if (false === empty($this->txResult)) {
            foreach ($this->txResult as $key => $value) {
                switch ($key) {
                    case 'authcode':
                        gform_update_meta($entry['id'], $key, $value);
                        break;
                    default:
                        $entry[$key] = $value;
                        break;
                }
            }

            if (class_exists('RGFormsModel') == true) {
                RGFormsModel::update_lead($entry);
            } elseif (class_exists('GFAPI') == true) {
                GFAPI::update_entry($entry);
            } else {
                throw new Exception('[ERROR] In GFBitPayPlugin::gformAfterSubmission(): GFAPI or RGFormsModel won\'t update lead.');
            }

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
    public function gformCustomMergeTags($merge_tags, $form_id, $fields, $element_id)
    {
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
    public function gformReplaceMergeTags($text, $form, $lead, $url_encode, $esc_html, $nl2br, $format)
    {
        if ($this->hasFieldType($form['fields'], 'buyerName')) {
            if (true === empty($this->txResult)) {
                // lead loaded from database, get values from lead meta
                $transaction_id = isset($lead['transaction_id']) ? $lead['transaction_id'] : '';
                $payment_amount = isset($lead['payment_amount']) ? $lead['payment_amount'] : '';
                $payment_status = isset($lead['payment_status']) ? $lead['payment_status'] : '';
                $authcode       = (string) gform_get_meta($lead['id'], 'authcode');
            } else {
                // lead not yet saved, get values from transaction results
                $transaction_id = isset($this->txResult['transaction_id']) ? $this->txResult['transaction_id'] : '';
                $payment_amount = isset($this->txResult['payment_amount']) ? $this->txResult['payment_amount'] : '';
                $payment_status = isset($this->txResult['payment_status']) ? $this->txResult['payment_status'] : '';
                $authcode       = isset($this->txResult['authcode']) ? $this->txResult['authcode'] : '';
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
    public function gformCurrency($currency)
    {
        return $currency;
    }

    /**
     * check form to see if it has a field of specified type
     * @param array $fields array of fields
     * @param string $type name of field type
     * @return boolean
     */
    public static function hasFieldType($fields, $type)
    {
        if (true === is_array($fields)) {
            foreach ($fields as $field) {
                if (RGFormsModel::get_input_type($field) == $type) {
                    return true;
                }
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
    public function getErrMsg($errName, $useDefault = false)
    {
        static $messages = array (
            GFBITPAY_ERROR_ALREADY_SUBMITTED => 'Payment has already been submitted and processed.',
            GFBITPAY_ERROR_NO_AMOUNT         => 'This form is missing products or totals',
            GFBITPAY_ERROR_FAIL              => 'Error processing BitPay transaction',
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
    public static function getCustomerIP()
    {
        $plugin = self::get_instance();

        // check for remote address, ignore all other headers as they can be spoofed easily
        if (true === isset($_SERVER['REMOTE_ADDR']) && self::isIpAddress($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }

        return '';
    }

    /**
     * check whether a given string is an IP address
     * @param string $maybeIP
     * @return bool
     */
    protected static function isIpAddress($maybeIP)
    {
        if (true === function_exists('inet_pton')) {
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
    public static function showMessage($msg)
    {
        echo "<div class='updated fade'><p><strong>$msg</strong></p></div>\n";
    }

    /**
     * display an error message (already HTML-conformant)
     * @param string $msg HTML-encoded message to display inside a paragraph
     */
    public static function showError($msg)
    {
        echo "<div class='error'><p><strong>$msg</strong></p></div>\n";
    }
}

function bitpay_callback()
{
    try {
        global $wpdb;

        if (true === isset($_GET['bitpay_callback'])) {
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

            if (false === isset($client) || true === empty($client)) {
                error_log('[ERROR] In GFBitPayPlugin::bitpay_callback(): Could not create a new Client object.');
                throw new \Exception('An error occurred in the BitPay Payment plugin: Could not create a new Client object.');
            }

            $adapter = new \Bitpay\Client\Adapter\CurlAdapter();

            if (false === isset($adapter) || true === empty($adapter)) {
                error_log('[ERROR] In GFBitPayPlugin::bitpay_callback(): Could not create a new CurlAdapter object.');
                throw new \Exception('An error occurred in the BitPay Payment plugin: Could not create a new CurlAdapter object.');
            }

            if (strpos($json['url'], 'test') === false) {
                $network = new \Bitpay\Network\Livenet();
            } else {
                $network = new \Bitpay\Network\Testnet();
            }

            if (false === isset($network) || true === empty($network)) {
                error_log('[ERROR] In GFBitPayPlugin::bitpay_callback(): Could not create a new network object.');
                throw new \Exception('An error occurred in the BitPay Payment plugin: Could not create a new network object.');
            }

            $client->setAdapter($adapter);
            $client->setNetwork($network);

            // Checking invoice is valid...
            $response  = $client->getInvoice($json['id']);
            $sessionid = $response->getPosData();

            // update payment status
            $lead              = $wpdb->get_row( "SELECT * FROM `{$wpdb->prefix}rg_lead` WHERE `transaction_id` = '{$sessionid}'", ARRAY_A );
            $leadId            = $lead['id'];
            $bitpayTransaction = $wpdb->get_row( "SELECT * FROM `bitpay_transactions` WHERE `lead_id` = '{$leadId}'", ARRAY_A );
            $email             = $bitpayTransaction['buyer_email'];

            switch ($response->getStatus()) {
                // For low and medium transaction speeds, the order status is set to "Order Received" . The customer receives
                // an initial email stating that the transaction has been paid.
                case 'paid':
                    $lead['payment_status'] = "Processing";
                    $lead['payment_date']   = date('Y-m-d H:i:s');

                    if (class_exists('RGFormsModel') == true) {
                        RGFormsModel::update_lead($lead);
                    } else if (class_exists('GFAPI') == true) {
                        GFAPI::update_entry($lead);
                    } else {
                        throw new \Exception('[ERROR] In GFBitPayPlugin::bitpay_callback(): GFAPI or RGFormsModel won\'t update lead.');
                    }

                    $message = 'Thank you! Your payment has been received, but the transaction has not been confirmed on the bitcoin network. You will receive another email when the transaction has been confirmed.';
                    $note    = 'The payment has been received, but the transaction has not been confirmed on the bitcoin network. This will be updated when the transaction has been confirmed.';

                    if (false === empty($email)) {
                        wp_mail($email, 'Transaction Complete', $message);
                    }

                    $wpdb->insert(
                        $wpdb->prefix.'rg_lead_notes', 
                        array( 
                            'lead_id'      => $leadId, 
                            'user_name'    => 'BitPay',
                            'user_id'      => 0,
                            'date_created' => date('Y-m-d H:i:s'),
                            'value'        => $note,
                        )
                    );

                    break;

                // For low and medium transaction speeds, the order status will not change. For high transaction speed, the order
                // status is set to "Order Received" here. For all speeds, an email will be sent stating that the transaction has
                // been confirmed.
                case 'confirmed':
                    $lead['payment_status'] = "Pending";
                    $lead['payment_date']   = date('Y-m-d H:i:s');

                    if (class_exists('RGFormsModel') == true) {
                        RGFormsModel::update_lead($lead);
                    } else if (class_exists('GFAPI') == true) {
                        GFAPI::update_entry($lead);
                    } else {
                        throw new \Exception('[ERROR] In GFBitPayPlugin::bitpay_callback(): GFAPI or RGFormsModel won\'t update lead.');
                    }

                    if (get_option('bitpayTransactionSpeed') == 'high') {
                        $message = 'Thank you! Your payment has been received, and the transaction has been confirmed on the bitcoin network. You will receive another email when the transaction is complete.';
                        $note    = 'The payment has been received, and the transaction has been confirmed on the bitcoin network. This will be updated when the transaction has been completed.';

                        $wpdb->insert(
                            $wpdb->prefix.'rg_lead_notes', 
                            array( 
                                'lead_id'      => $leadId, 
                                'user_name'    => 'BitPay',
                                'user_id'      => 0,
                                'date_created' => date('Y-m-d H:i:s'),
                                'value'        => $note,
                            )
                        );
                    } else {
                        $message = 'Your transaction has now been confirmed on the bitcoin network. You will receive another email when the transaction is complete.';
                        $note    = 'The payment has been received, and the transaction has been confirmed on the bitcoin network. This will be updated when the transaction has been completed.';

                        $wpdb->insert(
                            $wpdb->prefix.'rg_lead_notes', 
                            array( 
                                'lead_id'      => $leadId, 
                                'user_name'    => 'BitPay',
                                'user_id'      => 0,
                                'date_created' => date('Y-m-d H:i:s'),
                                'value'        => $note,
                            )
                        );
                    }

                    if (false === empty($email)) {
                        wp_mail($email, 'Transaction Complete', $message);
                    }

                    break;

                // The purchase receipt email is sent upon the invoice status changing to "complete", and the order
                // status is changed to Accepted Payment
                case 'complete':
                    $lead["payment_status"] = 'Approved';
                    $lead["payment_date"]   = date('Y-m-d H:i:s');

                    if (class_exists('RGFormsModel') == true) {
                        RGFormsModel::update_lead($lead);
                    } elseif (class_exists('GFAPI') == true) {
                        GFAPI::update_entry($lead);
                    } else {
                        throw new \Exception('[ERROR] In GFBitPayPlugin::bitpay_callback(): GFAPI or RGFormsModel won\'t update lead.');
                    }

                    $message = 'Your transaction is now complete! Thank you for using BitPay!';
                    $note = 'The transaction is now complete.';

                    $wpdb->insert(
                        $wpdb->prefix.'rg_lead_notes', 
                        array( 
                            'lead_id'      => $leadId, 
                            'user_name'    => 'BitPay',
                            'user_id'      => 0,
                            'date_created' => date('Y-m-d H:i:s'),
                            'value'        => $note,
                        )
                    );

                    if (false === empty($email)){
                        wp_mail($email, 'Transaction Complete', $message);
                    }

                    break;
            }
        }
    } catch (\Exception $e) {
        error_log('[Error] In GFBitPayPlugin::bitpay_callback() function on line ' . $e->getLine() . ', with the error "' . $e->getMessage() . '".');
        throw $e;
    }
}

add_action('init', 'bitpay_callback');
