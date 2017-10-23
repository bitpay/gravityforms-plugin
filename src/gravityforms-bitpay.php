<?php

/*
Plugin Name: Gravity Forms BitPay Payments
Plugin URI:  https://github.com/bitpay/gravityforms-plugin
Description: Integrates Gravity Forms with BitPay payment gateway.
Version:     2.0.3
Author:      Rich Morgan & Alex Leitner (integrations@bitpay.com)
Author URI:  https://www.bitpay.com
*/

/**
 * @license Copyright 2011-2014 BitPay Inc., MIT License
 * see https://github.com/bitpay/gravityforms-plugin/blob/master/LICENSE
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

function br_trigger_error($message, $errno)
{
    if (true === isset($_GET['action']) && $_GET['action'] == 'error_scrape') {
        echo '<strong>' . $message . '</strong>';
        exit();
    } else {
        trigger_error($message, $errno);
    }
}

if (false === defined('GFBITPAY_PLUGIN_ROOT')) {
    define('GFBITPAY_PLUGIN_ROOT', dirname(__FILE__) . '/');
    define('GFBITPAY_PLUGIN_NAME', basename(dirname(__FILE__)) . '/' . basename(__FILE__));
    define('GFBITPAY_PLUGIN_OPTIONS', 'gfbitpay_plugin');

    // script/style version
    if (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) {
        define('GFBITPAY_PLUGIN_VERSION', time());
    } else {
        define('GFBITPAY_PLUGIN_VERSION', '2.0.3');
    }

    // custom fields
    define('GFBITPAY_REDIRECT_URL', 'bitpayRedirectURL');
    define('GFBITPAY_TRANSACTION_SPEED', 'bitpayTransactionSpeed');
}

/**
 * autoload classes as/when needed
 *
 * @param string $class_name name of class to attempt to load
 */
function gfbitpay_autoload($class_name)
{
    static $classMap = array (
        'GFBitPayAdmin'         => 'class.GFBitPayAdmin.php',
        'GFBitPayFormData'      => 'class.GFBitPayFormData.php',
        'GFBitPayOptionsAdmin'  => 'class.GFBitPayOptionsAdmin.php',
        'GFBitPayPayment'       => 'class.GFBitPayPayment.php',
        'GFBitPayPlugin'        => 'class.GFBitPayPlugin.php',
        'GFBitPayStoredPayment' => 'class.GFBitPayStoredPayment.php',
    );

    if (true === isset($classMap[$class_name])) {
        require GFBITPAY_PLUGIN_ROOT . $classMap[$class_name];
    }

    require_once __DIR__ . '/lib/autoload.php';
}

spl_autoload_register('gfbitpay_autoload');

/**
 * Requirements check.
 */
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
add_action( 'gform_loaded', array( 'GF_Bitpay_Bootstrap', 'load' ), 5 );

class GF_Bitpay_Bootstrap {

    public static function load() {

        if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
            return;
        }

        require_once( 'class.GFBitPayPlugin.php' );

        GFAddOn::register( 'GFBitPayPlugin' );
    }

}

function gf_bitpay() {
    return GFBitPayPlugin::get_Instance();
}
// instantiate the plug-in

add_action('wp_ajax_bitpay_pair_code', 'ajax_bitpay_pair_code');
add_action('wp_ajax_bitpay_revoke_token', 'ajax_bitpay_revoke_token');

/**
 * Async pairing process.
 */
function ajax_bitpay_pair_code()
{
    $nonce = $_POST['pairNonce'];
    if ( ! wp_verify_nonce( $nonce, 'bitpay-pair-nonce' ) ) {
        die ( 'Unauthorized!');
    }
    if ( current_user_can( 'manage_options' ) ) {
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
            error_log('[Error] In gravityforms-bitpay.php ajax_bitpay_pair_code() function on line ' . $e->getLine() . ', with the error "' . $e->getMessage() . '" .');
            wp_send_json_error($e->getMessage());
            return;
        }
    }
    exit;
}

/**
 * Async token revocation.
 */
function ajax_bitpay_revoke_token()
{
    $nonce = $_POST['revokeNonce'];
    if ( ! wp_verify_nonce( $nonce, 'bitpay-revoke-nonce' ) ) {
        die ( 'Unauthorized!');
    }
    if ( current_user_can( 'manage_options' ) ) {
        try {
            delete_option('bitpayToken');
            delete_option('bitpayPrivateKey');
            delete_option('bitpayPublicKey');
            delete_option('bitpaySinKey');
            delete_option('bitpayNetwork');
            wp_send_json(array('success'=>'Token Revoked!'));
        } catch (\Exception $e) {
            error_log('[Error] In gravityforms-bitpay.php ajax_bitpay_revoke_token() function on line ' . $e->getLine() . ', with the error "' . $e->getMessage() . '" .');
            throw $e;
        }
    }
    exit;
}

/**
 * Generating the public/private keypair.
 */
function generate_keys()
{
    $private = new \Bitpay\PrivateKey('/tmp/private.key');

    if (false === isset($private) || true === empty($private)) {
        throw new \Exception('[Error] In gravityforms-bitpay.php generate_keys: Could not create a new PrivateKey object.');
    }

    $public = new \Bitpay\PublicKey('/tmp/public.key');

    if (false === isset($public) || true === empty($public)) {
        throw new \Exception('[Error] In gravityforms-bitpay.php generate_keys: Could not create a new PublicKey object.');
    }

    $sin = new \Bitpay\SinKey('/tmp/sin.key');

    if (false === isset($sin) || true === empty($sin)) {
        throw new \Exception('[Error] In gravityforms-bitpay.php generate_keys: Could not create a new SinKey object.');
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
        error_log('[Error] In gravityforms-bitpay.php generate_keys() function on line ' . $e->getLine() . ', with the error "' . $e->getMessage() . '" .');
        throw $e;
    }

    return array($private, $public, $sin);
}

/**
 * BitPay client object creation.
 */
function create_client($network, $public, $private)
{
    // @var \Bitpay\Client\Client
    $client = new \Bitpay\Client\Client();

    if (false === isset($client) || true === empty($client)) {
        throw new \Exception('[Error] In gravityforms-bitpay.php create_client: Could not create a new Client object.');
    }

    //Set the network being paired with.
    $networkClass = 'Bitpay\\Network\\'. $network;

    if (false === class_exists($networkClass)) {
        throw new \Exception('[Error] In gravityforms-bitpay.php create_client: Could not find the "' . $networkClass . '" network.');
    }

    try {
        $client->setNetwork(new $networkClass());

        //Set Keys
        $client->setPublicKey($public);
        $client->setPrivateKey($private);

        // Initialize our network adapter object for cURL
        $client->setAdapter(new Bitpay\Client\Adapter\CurlAdapter());

    } catch (\Exception $e) {
        error_log('[Error] In gravityforms-bitpay.php create_client() function on line ' . $e->getLine() . ', with the error "' . $e->getMessage() . '" .');
        throw $e;
    }

    return $client;
}

/**
 * Pairing process with BitPay merchant account.
 */
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
  
/**
 * Save the previously generated keys.
 */
function save_keys($token, $private, $public)
{
    try {
        // Protect your data!
        $mcrypt_ext  = new \Bitpay\Crypto\McryptExtension();

        if (false === isset($mcrypt_ext) || true === empty($mcrypt_ext)) {
            throw new \Exception('[Error] In gravityforms-bitpay.php save_keys: Could not create a new McryptExtension object.');
        }

        $fingerprint = sha1(sha1(__DIR__));
        $fingerprint = substr($fingerprint, 0, 24);

        //Setting values for database
        update_option('bitpayPrivateKey', $mcrypt_ext->encrypt(base64_encode(serialize($private)), $fingerprint, '00000000'));
        update_option('bitpayPublicKey', $mcrypt_ext->encrypt(base64_encode(serialize($public)), $fingerprint, '00000000'));
        update_option('bitpayToken', $mcrypt_ext->encrypt(base64_encode(serialize($token)), $fingerprint, '00000000'));

    } catch (\Exception $e) {
        error_log('[Error] In gravityforms-bitpay.php save_keys() function on line ' . $e->getLine() . ', with the error "' . $e->getMessage() . '" .');
        throw $e;
    }
}
