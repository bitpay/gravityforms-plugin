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