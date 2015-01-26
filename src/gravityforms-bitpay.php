<?php
/*
Plugin Name: Gravity Forms BitPay Payments
Plugin URI: 
Description: Integrates Gravity Forms with BitPay payment gateway.
Version: 2.0.0
Author: Rich Morgan & Alex Leitner (integrations@bitpay.com)
Author URI: https://www.bitpay.com
*/

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

/*
useful references:
http://www.gravityhelp.com/forums/topic/credit-card-validating#post-44438
http://www.gravityhelp.com/documentation/page/Gform_creditcard_types
http://www.gravityhelp.com/documentation/page/Gform_enable_credit_card_field
http://www.gravityhelp.com/documentation/page/Form_Object
http://www.gravityhelp.com/documentation/page/Entry_Object
*/
register_activation_hook(__FILE__,'gravityforms_bitpay_failed_requirements');

function br_trigger_error($message, $errno) {
 
    if(isset($_GET['action']) && $_GET['action'] == 'error_scrape') {
 
        echo '<strong>' . $message . '</strong>';
 
        exit;
 
    } else {
 
        trigger_error($message, $errno);
 
    }
}

if (!defined('GFBITPAY_PLUGIN_ROOT')) {
	define('GFBITPAY_PLUGIN_ROOT', dirname(__FILE__) . '/');
	define('GFBITPAY_PLUGIN_NAME', basename(dirname(__FILE__)) . '/' . basename(__FILE__));
	define('GFBITPAY_PLUGIN_OPTIONS', 'gfbitpay_plugin');

	// script/style version
	if (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG)
		define('GFBITPAY_PLUGIN_VERSION', time());
	else
		define('GFBITPAY_PLUGIN_VERSION', '2.0.0');

	// custom fields
	define('GFBITPAY_REDIRECT_URL', 'bitpayRedirectURL');
	define('GFBITPAY_TRANSACTION_SPEED', 'bitpayTransactionSpeed');
}

/**
* autoload classes as/when needed
*
* @param string $class_name name of class to attempt to load
*/
function gfbitpay_autoload($class_name) {
	static $classMap = array (
		'GFBitPayAdmin'						=> 'class.GFBitPayAdmin.php',
		'GFBitPayFormData'					=> 'class.GFBitPayFormData.php',
		'GFBitPayOptionsAdmin'				=> 'class.GFBitPayOptionsAdmin.php',
		'GFBitPayPayment'						=> 'class.GFBitPayPayment.php',
		'GFBitPayPlugin'						=> 'class.GFBitPayPlugin.php',
		'GFBitPayStoredPayment'				=> 'class.GFBitPayStoredPayment.php',
	);

	if (isset($classMap[$class_name])) {
		require GFBITPAY_PLUGIN_ROOT . $classMap[$class_name];
	}

	require_once __DIR__ . '/lib/autoload.php';
}
spl_autoload_register('gfbitpay_autoload');

function gravityforms_bitpay_failed_requirements()
{
    global $wp_version;
    $errors = array();
    // PHP 5.4+ required
    if (true === version_compare(PHP_VERSION, '5.4.0', '<')) {
       $errors[] = 'Your PHP version is too old. The BitPay payment plugin requires PHP 5.4 or higher to function. Please contact your web server administrator for assistance.';
    }
    // Wordpress 3.9+ required
    if (true === version_compare($wp_version, '4.0', '<')) {
        $errors[] = 'Your WordPress version is too old. The BitPay payment plugin requires Wordpress 3.9 or higher to function. Please contact your web server administrator for assistance.';
    }
    // GMP or BCMath required
    if (false === extension_loaded('gmp') && false === extension_loaded('bcmath')) {
        $errors[] = 'The BitPay payment plugin requires the GMP or BC Math extension for PHP in order to function. Please contact your web server administrator for assistance.';
    }
    if (false === empty($errors)) {
    	$imploded = implode("<br><br>\n", $errors);
    	br_trigger_error($imploded, E_USER_ERROR);
    } else {
        return false;
    }
}
// instantiate the plug-in
GFBitPayPlugin::getInstance();

add_action('wp_ajax_bitpay_pair_code', 'ajax_bitpay_pair_code');
add_action('wp_ajax_bitpay_revoke_token', 'ajax_bitpay_revoke_token');

function ajax_bitpay_pair_code()
{

    if (true === isset($_POST['pairing_code']) && trim($_POST['pairing_code']) !== '') {
        // Validate the Pairing Code
        $pairing_code = trim($_POST['pairing_code']);
    } else {
        wp_send_json_error("Pairing Code is required");
        return;
    }
    if (!preg_match('/^[a-zA-Z0-9]{7}$/', $pairing_code)) {
        wp_send_json_error("Invalid Pairing Code");
        return;
    }
    // Validate the Network
    $network = ($_POST['network'] == 'Livenet') ? 'Livenet' : 'Testnet';

    try {
        list($private, $public, $sin) = generate_keys();
        $client = create_client($network, $public, $private);
        list($token, $label)  = pairing($pairing_code, $client, $sin);
        save_keys($token, $private, $public);
        update_option('bitpayNetwork', $network);
        wp_send_json(array('sin' => (string) $sin, 'label' => $label, 'network' => $network));
    } catch (\Exception $e) {
        error_log('[Error] In Bitpay plugin, pair_and_get_token() function on line ' . $e->getLine() . ', with the error "' . $e->getMessage() . '" .');
        wp_send_json_error($e->getMessage());
        return;
    }
}

function ajax_bitpay_revoke_token()
{
    try {
        delete_option('bitpayToken');
        delete_option('bitpayPrivateKey');
        delete_option('bitpayPublicKey');
        delete_option('bitpaySinKey');
        delete_option('bitpayNetwork');
        wp_send_json(array('success'=>'Token Revoked!'));
    } catch (\Exception $e) {
        error_log('[Error] In Bitpay plugin, revoke_keys() function on line ' . $e->getLine() . ', with the error "' . $e->getMessage() . '" .');
        throw $e;
    }
}

/**
 * GENERATING THE KEYS
 */
function generate_keys()
{
    $private = new \Bitpay\PrivateKey('/tmp/private.key');
    if (true === empty($private)) {
        throw new \Exception('An error occurred!  The BitPay plugin could not create a new PrivateKey object.');
    }
    $public = new \Bitpay\PublicKey('/tmp/public.key');
    if (true === empty($public)) {
        throw new \Exception('An error occurred!  The BitPay plugin could not create a new PublicKey object.');
    }
    $sin = new \Bitpay\SinKey('/tmp/sin.key');
    if (true === empty($sin)) {
        throw new \Exception('An error occurred!  The BitPay plugin could not create a new SinKey object.');
    }
    try {
        // Generate Private Key values
        $private->generate();
        // Generate Public Key values
        $public->setPrivateKey($private);
        $public->generate();
        // Generate Sin Key values
        $sin->setPublicKey($public);
        $sin->generate();
    } catch (\Exception $e) {
        error_log('[Error] In Bitpay plugin, generate_keys() function on line ' . $e->getLine() . ', with the error "' . $e->getMessage() . '" .');
        throw $e;
    }
    return array($private, $public, $sin);
}

function create_client($network, $public, $private)
{
    // @var \Bitpay\Client\Client
    $client = new \Bitpay\Client\Client();
    if (true === empty($client)) {
        throw new \Exception('An error occurred!  The BitPay plugin could not create a new Client object.');
    }
    //Set the network being paired with.
    $networkClass = 'Bitpay\\Network\\'. $network;
    if (false === class_exists($networkClass)) {
        throw new \Exception('An error occurred!  The BitPay plugin could not find the "' . $networkClass . '" network.');
    }
    try {
        $client->setNetwork(new $networkClass());
        //Set Keys
        $client->setPublicKey($public);
        $client->setPrivateKey($private);
        // Initialize our network adapter object for cURL
        $client->setAdapter(new Bitpay\Client\Adapter\CurlAdapter());
    } catch (\Exception $e) {
        error_log('[Error] In Bitpay plugin, create_client() function on line ' . $e->getLine() . ', with the error "' . $e->getMessage() . '" .');
        throw $e;
    }
    return $client;
}

function pairing($pairing_code, $client, $sin)
{
    //Create Token
    $label = preg_replace('/[^a-zA-Z0-9 \-\_\.]/', '', get_bloginfo());
    $label = substr('Gravity Forms - '.$label, 0, 59);
    try {
        // @var \Bitpay\TokenInterface
        $token = $client->createToken(
            array(
                'id'          => (string) $sin,
                'pairingCode' => $pairing_code,
                'label'       => $label,
            )
        );
        update_option('bitpayLabel', $label);
        update_option('bitpaySinKey', (string) $sin);
        return array($token, $label);
    } catch (\Exception $e) {
        $error = $e->getMessage();
        error_log('[Error] In Bitpay plugin, pairing() function on line ' . $e->getLine() . ', with the error "' . $e->getMessage() . '" .');
        throw $e;
    }
}

function save_keys($token, $private, $public)
{
    try {
        //Protect your data!
        $mcrypt_ext  = new \Bitpay\Crypto\McryptExtension();
        $fingerprint = sha1(sha1(__DIR__));
        $fingerprint = substr($fingerprint, 0, 24);
        //Setting values for database
        update_option('bitpayPrivateKey', $mcrypt_ext->encrypt(base64_encode(serialize($private)), $fingerprint, '00000000'));
        update_option('bitpayPublicKey', $mcrypt_ext->encrypt(base64_encode(serialize($public)), $fingerprint, '00000000'));
        update_option('bitpayToken', $mcrypt_ext->encrypt(base64_encode(serialize($token)), $fingerprint, '00000000'));

    } catch (\Exception $e) {
        error_log('[Error] In Bitpay plugin, save_keys() function on line ' . $e->getLine() . ', with the error "' . $e->getMessage() . '" .');
        throw $e;
    }
}