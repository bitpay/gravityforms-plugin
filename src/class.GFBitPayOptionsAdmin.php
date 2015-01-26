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

	public $bitpayTransactionSpeed = '';
	public $bitpayRedirectURL = '';

	/**
	* initialise from form post, if posted
	*/
	public function __construct() {
		if (self::isFormPost()) {
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
		if (strlen($this->bitpayRedirectURL) === 0) {
			$errmsg .= "# Please enter a Redirect URL.<br/>\n";
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
	* process the admin request
	*/
	public function process() {
		$this->frm = new GFBitPayOptionsForm();
		if ($this->frm->isFormPost()) {
			check_admin_referer('save', $this->menuPage . '_wpnonce');

			$errmsg = $this->frm->validate();
			if (empty($errmsg)) {
				update_option('bitpayTransactionSpeed', $this->frm->bitpayTransactionSpeed);
				update_option('bitpayRedirectURL', $this->frm->bitpayRedirectURL);
				$this->plugin->showMessage(__('Options saved.'));
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
