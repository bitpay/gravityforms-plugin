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
* Options form input fields
*/
class GFBitPayOptionsForm {

	public $bitpayPairingCode = '';
	public $bitpayTransactionSpeed = '';
	public $bitpayRedirectURL = '';
	public $bitpayNetwork = '';
	public $generate = false;
	public $revoke = false;

	/**
	* initialise from form post, if posted
	*/
	public function __construct() {
		if (self::isFormPost()) {
			// If generate button was pressed
			if (self::getPostValue('generateKeys') == 'Generate') {
				$this->generate = true;
			}
			if (self::getPostValue('revokeKeys') == 'Revoke') {
				$this->revoke = true;
			}
			$this->bitpayPairingCode = self::getPostValue('bitpayPairingCode');
			$this->bitpayNetwork = self::getPostValue('bitpayNetwork');
			$this->bitpayTransactionSpeed = self::getPostValue('bitpayTransactionSpeed');
			$this->bitpayRedirectURL = self::getPostValue('bitpayRedirectURL');
		}
	}

	/**
	* Is this web request a form post?
	*
	* Checks to see whether the HTML input form was posted.
	*
	* @return boolean
	*/
	public static function isFormPost() {
		return ($_SERVER['REQUEST_METHOD'] == 'POST');
	}

	/**
	* Read a field from form post input.
	*
	* Guaranteed to return a string, trimmed of leading and trailing spaces, slashes stripped out.
	*
	* @return string
	* @param string $fieldname name of the field in the form post
	*/
	public static function getPostValue($fieldname) {
		return isset($_POST[$fieldname]) ? stripslashes(trim($_POST[$fieldname])) : '';
	}

	/**
	* Validate the form input, and return error messages.
	*
	* Return a string detailing error messages for validation errors discovered,
	* or an empty string if no errors found.
	* The string should be HTML-clean, ready for putting inside a paragraph tag.
	*
	* @return string
	*/
	public function validate() {
		$errmsg = '';
		if ($this->generate == false) {
			if (strlen($this->bitpayRedirectURL) === 0) {
				$errmsg .= "# Please enter a Redirect URL.<br/>\n";
			}
		} else {
			if (preg_match('/^[a-zA-Z0-9]{7}$/', $this->bitpayPairingCode) != 1) {
				$errmsg .= "# Invalid Pairing Code.<br/>\n";
			}
		}

		return $errmsg;
	}
}

/**
* Options admin
*/
class GFBitPayOptionsAdmin {

	private $plugin;           // handle to the plugin object
	private $menuPage;         // slug for admin menu page
	private $scriptURL = '';
	private $frm;              // handle for the form validator

	/**
	* @param GFBitPayPlugin $plugin handle to the plugin object
	* @param string $menuPage URL slug for this admin menu page
	*/
	public function __construct($plugin, $menuPage, $scriptURL) {
		$this->plugin = $plugin;
		$this->menuPage = $menuPage;
		$this->scriptURL = $scriptURL;

		wp_enqueue_script('jquery');
	}

    /**
     * GENERATING THE KEYS
     */
	public function generate_keys()
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

	public function revoke_keys()
	{
		try {
			delete_option('bitpayToken');
			delete_option('bitpayPrivateKey');
			delete_option('bitpayPublicKey');
			delete_option('bitpayNetwork');
	    } catch (\Exception $e) {
		    error_log('[Error] In Bitpay plugin, revoke_keys() function on line ' . $e->getLine() . ', with the error "' . $e->getMessage() . '" .');
		    throw $e;
		}
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

	public function pairing($pairing_code, $client, $sin)
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
	        return $token;
	    } catch (\Exception $e) {
	        $error = $e->getMessage();
	        $this->plugin->showMessage(__($error));
	        error_log('[Error] In Bitpay plugin, pairing() function on line ' . $e->getLine() . ', with the error "' . $e->getMessage() . '" .');
	        exit();
	    }
	}

	public function save_keys($token, $private, $public)
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

	public function pair_and_get_token($pairing_code, $network)
	{
	    try {
	        list($private, $public, $sin) = $this->generate_keys();
	        $client = $this->create_client($network, $public, $private);
	        $token  = $this->pairing($pairing_code, $client, $sin);
	        $this->save_keys($token, $private, $public);
	    } catch (\Exception $e) {
	        error_log('[Error] In Bitpay plugin, pair_and_get_token() function on line ' . $e->getLine() . ', with the error "' . $e->getMessage() . '" .');
	        throw $e;
	    }
	}
	/**
	* process the admin request
	*/
	public function process() {
		$this->frm = new GFBitPayOptionsForm();
		if ($this->frm->isFormPost()) {
			check_admin_referer('save', $this->menuPage . '_wpnonce');

			$errmsg = $this->frm->validate();
			if (empty($errmsg)) {

				// If generate button was pressed
				if ($this->frm->generate === true) {
					$this->pair_and_get_token($this->frm->bitpayPairingCode, $this->frm->bitpayNetwork);
					update_option('bitpayNetwork', $this->frm->bitpayNetwork);
					$this->plugin->showMessage(__('Pairing Successful.'));
				// If revoke button was pressed
				} elseif ($this->frm->revoke === true) {
					$this->revoke_keys();
					$this->plugin->showMessage(__('Keys Revoked.'));
				// If Save Changes Button was pressed
				} else {
					update_option('bitpayTransactionSpeed', $this->frm->bitpayTransactionSpeed);
					update_option('bitpayRedirectURL', $this->frm->bitpayRedirectURL);
					$this->plugin->showMessage(__('Options saved.'));
				}

			}
			else {
				$this->plugin->showError($errmsg);
			}
		}
		else {
			// initialise form from stored options
			$this->frm->bitpayTransactionSpeed = get_option('bitpayTransactionSpeed');
			$this->frm->bitpayRedirectURL = get_option('bitpayRedirectURL');

		}

		require GFBITPAY_PLUGIN_ROOT . 'views/admin-settings.php';
	}
}
