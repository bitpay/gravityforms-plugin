<?php

if (is_null(get_option('bitpayToken')) == true || get_option('bitpayToken') == ''){
	$paired = '';
} else {
	$revoke = '<input type="submit" id="revoke_keys" name="revokeKeys" value="Revoke" />';
	$paired = '<div style="
			border: 1px solid;  
		    margin: 10px 0px;
		    width: 295px;
		    padding:5px 5px 5px 30px;  
		    background-repeat: no-repeat;
		    font: normal 100% Helvetica, Arial, sans-serif;
		    color: #4F8A10;  
		    background:#DFF2BF top left no-repeat;
		    background-position: 5px 5px;">You are currently paired with <strong>'.get_option('bitpayNetwork').
		    '</strong> '.$revoke.'</div>';
}
?>
<div class="wrap">
<h3>BitPay Payments</h3>
<p style="text-align: left;">
	This Plugin requires you to set up a BitPay merchant account.
</p>
<ul>
	<li>Navigate to the BitPay <a href="https://bitpay.com/start">Sign-up page.</a></li>
	<li>Get a Pairing Code at the BitPay <a href="https://bitpay.com/api-tokens">Tokens page</a></li>
</ul>
<br/>
<form action="<?php echo $this->scriptURL; ?>" method="post" id="bitpay-settings-form">
	<table class="form-table">
		<ul>
			<li><?php echo $paired ?></li>
		</ul>
		<tr>
			<th>Pairing Code</th>
			<td>
				<input name="bitpayPairingCode" type="text" placeholder="Pairing Code" /><select name="bitpayNetwork"><option value="Livenet">Live</option><option value="Testnet">Test</option></select><input id="generate_keys" type="submit" name="generateKeys" value="Generate" />
				<p><font size='2'>Generate Keys for pairing. This will overwrite your current keys and you will have to pair again.</font></p>
			</td>
		</tr>

		<tr valign="top">
      <th>Transaction Speed</th>
			<td>
				<label><input type="radio" name="bitpayTransactionSpeed" value="slow" <?php echo checked($this->frm->bitpayTransactionSpeed, 'slow'); ?> />Slow</label>
				<label><input type="radio" name="bitpayTransactionSpeed" value="medium" <?php echo checked($this->frm->bitpayTransactionSpeed, 'medium'); ?> />Medium</label>
				<label><input type="radio" name="bitpayTransactionSpeed" value="fast" <?php echo checked($this->frm->bitpayTransactionSpeed, 'fast'); ?> />Fast</label>
				<p><font size='2'>Speed at which the Bitcoin payment registers as "confirmed" to the store: High = Instant, Medium = ~10m, Low = ~1hr (safest).</font></p>
			</td>
		</tr>

      <th>Redirect URL</th>
			<td>
				<label><input type="text" name="bitpayRedirectURL" value="<?php echo $this->frm->bitpayRedirectURL; ?>" /></label>
				<p><font size='2'>Put the URL that you want the buyer to be redirected to after payment. This is usually a "Thanks for your order!" page.</font></p>
			</td>
		</tr>

	</table>
	<p class="submit">
	<input type="submit" name="Submit" class="button-primary" value="Save Changes" />
	<?php wp_nonce_field('save', $this->menuPage . '_wpnonce', false); ?>
	</p>
</form>

</div>

<script>
(function($) {

	function setVisibility() {

		function display(element, visible) {
			if (visible)
				element.css({display: "none"}).show(750);
			else
				element.hide();
		}
		
	}

	$("#bitpay-settings-form").on("change", "input[name='bitpayPairingCode'],input[name='bitpayTransactionSpeed'],input[name='bitpayRedirectURL'],input[name='bitpayNetwork']", setVisibility);

	setVisibility();

})(jQuery);

document.getElementById("generate_keys").addEventListener("click", pairFunction);
document.getElementById("revoke_keys").addEventListener("click", revokeFunction);

function pairFunction() {
    var result = confirm('Are you sure you wish to pair keys?'); return result; 
}
function revokeFunction() {
    var result = confirm('Are you sure you wish to revoke your keys?'); return result; 
}

</script>
