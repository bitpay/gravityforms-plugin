<div class="wrap">
<h3>BitPay Payments</h3>
<p style="text-align: left;">
	This Plugin requires you to set up a BitPay merchant account.
</p>
<ul>
	<li>Navigate to the BitPay <a href="https://bitpay.com/start">Sign-up page.</a></li>
</ul>
<br/>
<form action="<?php echo $this->scriptURL; ?>" method="post" id="bitpay-settings-form">
	<table class="form-table">
		<tr>
			<th>API Token</th>
			<td id='bitpay_api_token'>
                <div id="bitpay_api_token_form">
                    <?php
                    	wp_enqueue_style('font-awesome', '//netdna.bootstrapcdn.com/font-awesome/4.0.3/css/font-awesome.css');
			            wp_enqueue_style('gravityforms-plugin', plugins_url('../assets/css/style.css', __FILE__));
        			    $pairing_form = file_get_contents(__DIR__.'/../templates/pairing.tpl');
                                    $token_format = file_get_contents(__DIR__.'/../templates/token.tpl');                        if (false === get_option('bitpayToken')) {
                            echo sprintf($pairing_form, 'visible');
                            echo sprintf($token_format, 'hidden', plugins_url('../assets/img/logo.png', __FILE__),'','');
                        } else {
                            echo sprintf($pairing_form, 'hidden');
                            echo sprintf($token_format, get_option('bitpayNetwork'), plugins_url('../assets/img/logo.png', __FILE__), get_option('bitpayLabel'), get_option('bitpaySinKey'));
                        }
                    ?>
                </div>
                   <script type="text/javascript">
                    var ajax_loader_url = '<?php echo plugins_url('../assets/img/ajax-loader.gif', __FILE__); ?>';
                </script>
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

/**
 * @license Copyright 2011-2015 BitPay Inc., MIT License
 * see https://github.com/bitpay/woocommerce-bitpay/blob/master/LICENSE
 */

'use strict';

(function ( $ ) {

  $(function () {

    /**
     * Update the API Token helper link on Network selection
    */

    $('#bitpay_api_token_form').on('change', '.bitpay-pairing__network', function (e) {

      // Helper urls
      var Livenet = 'https://bitpay.com/api-tokens';
      var Testnet = 'https://test.bitpay.com/api-tokens';

      if ($('.bitpay-pairing__network').val() === 'Livenet') {
        $('.bitpay-pairing__link').attr('href', Livenet).html(Livenet);
      } else {
        $('.bitpay-pairing__link').attr('href', Testnet).html(Testnet);
      }

    });

    /**
     * Try to pair with BitPay using an entered pairing code
    */
    $('#bitpay_api_token_form').on('click', '.bitpay-pairing__find', function (e) {

      // Don't submit any forms or follow any links
      e.preventDefault();

      // Hide the pairing code form
      $('.bitpay-pairing').hide();
      $('.bitpay-pairing').after('<div class="bitpay-pairing__loading" style="width: 20em; text-align: center"><img src="'+ajax_loader_url+'"></div>');

      // Attempt the pair with BitPay
      $.post(ajaxurl, {
        'action':       'bitpay_pair_code',
        'pairing_code': $('.bitpay-pairing__code').val(),
        'network':      $('.bitpay-pairing__network').val(),
        'pairNonce':    '<?php echo wp_create_nonce( 'bitpay-pair-nonce' ); ?>'
      })
      .done(function (data) {

        $('.bitpay-pairing__loading').remove();

        // Make sure the data is valid
        if (data && data.sin && data.label) {

          // Set the token values on the template
          $('.bitpay-token').removeClass('bitpay-token--Livenet').removeClass('bitpay-token--Testnet').addClass('bitpay-token--'+data.network);
          $('.bitpay-token__token-label').text(data.label);
          $('.bitpay-token__token-sin').text(data.sin);

          // Display the token and success notification
          $('.bitpay-token').hide().removeClass('bitpay-token--hidden').fadeIn(500);
          $('.bitpay-pairing__code').val('');
          $('.bitpay-pairing__network').val('Livenet');
          $('#message').remove();
          $('#gform_tab_group').before('<div id="message" class="updated fade"><p><strong>You have been paired with your BitPay account!</strong></p></div>');
        }
        // Pairing failed
        else if (data && data.success === false) {
          $('.bitpay-pairing').show();
          $('#message').remove();
          $('#gform_tab_group').before('<div id="message" class="error"><p><strong>Unable to pair with BitPay</strong></p></div>');
        }

      });
    });

    // Revoking Token
    $('#bitpay_api_token_form').on('click', '.bitpay-token__revoke', function (e) {

      // Don't submit any forms or follow any links
      e.preventDefault();

      if (confirm('Are you sure you want to revoke the token?')) {
        $.post(ajaxurl, {
          'action':      'bitpay_revoke_token',
          'revokeNonce': '<?php echo wp_create_nonce( 'bitpay-revoke-nonce' ); ?>'
        })
        .always(function (data) {
          $('.bitpay-token').fadeOut(500, function () {
            $('.bitpay-pairing').removeClass('.bitpay-pairing--hidden').show();
            $('#message').remove();
            $('#gform_tab_group').before('<div id="message" class="updated fade"><p><strong>You have revoked your token!</strong></p></div>');
          });
        });
      }

    });

  });

}( jQuery ));
</script>
