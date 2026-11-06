<?php
/**
 * Connection form: licence key (default) or login and password (accounts created before the subscriptions).
 *
 * @package Injellik
 *
 * @var array $injellik   Variables of the page.
 * @var bool  $onboarding True in the wizard.
 */

defined( 'ABSPATH' ) || exit;

$injellik_c      = $injellik['connection'];
$injellik_posted = $injellik['posted'];
$injellik_login  = Injellik_Admin::MODE_LOGIN === $injellik_c['mode'];
?>
<form method="post" action="<?php echo esc_url( $injellik['form_url'] ); ?>" class="injellik-connection js-injellik-connection" autocomplete="off">
	<input type="hidden" name="action" value="injellik_save">
	<input type="hidden" name="section" value="connection">
	<input type="hidden" name="auth_mode" value="<?php echo esc_attr( $injellik_c['mode'] ); ?>" class="js-injellik-auth-mode">
	<?php if ( $onboarding ) : ?>
		<input type="hidden" name="onboarding" value="1">
	<?php endif; ?>
	<?php wp_nonce_field( 'injellik_save' ); ?>

	<table class="form-table js-injellik-auth" data-mode="licence" role="presentation"<?php echo $injellik_login ? ' hidden' : ''; ?>>
		<tr>
			<th scope="row"><label for="injellik-licence-key"><?php esc_html_e( 'Licence key', 'injellik-recommendations' ); ?></label></th>
			<td>
				<input type="text" id="injellik-licence-key" name="licence_key" class="regular-text code" spellcheck="false"
					value="<?php echo esc_attr( isset( $injellik_posted['licence_key'] ) ? $injellik_posted['licence_key'] : '' ); ?>"
					placeholder="<?php echo esc_attr( $injellik_c['licence_key_hint'] ? $injellik_c['licence_key_hint'] : 'ijk_lic_…' ); ?>"<?php disabled( $injellik_login ); ?>>
				<p class="description">
					<?php if ( $injellik_c['licence_key_hint'] ) : ?>
						<?php esc_html_e( 'A key is saved. Leave the field empty to keep it.', 'injellik-recommendations' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'The key of this shop, displayed in your web-nest.fr account.', 'injellik-recommendations' ); ?>
					<?php endif; ?>
					<a href="<?php echo esc_url( $injellik['links']['shops'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Find my key', 'injellik-recommendations' ); ?></a> ·
					<a href="<?php echo esc_url( $injellik['links']['pricing'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get a key', 'injellik-recommendations' ); ?></a>
				</p>
			</td>
		</tr>
	</table>

	<table class="form-table js-injellik-auth" data-mode="login" role="presentation"<?php echo $injellik_login ? '' : ' hidden'; ?>>
		<tr>
			<th scope="row"><label for="injellik-api-url"><?php esc_html_e( 'API URL', 'injellik-recommendations' ); ?></label></th>
			<td>
				<input type="url" id="injellik-api-url" name="api_url" class="regular-text code"
					value="<?php echo esc_attr( isset( $injellik_posted['api_url'] ) ? $injellik_posted['api_url'] : $injellik_c['url'] ); ?>"
					placeholder="<?php echo esc_attr( $injellik_c['default_url'] ); ?>"<?php disabled( ! $injellik_login ); ?>>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="injellik-api-login"><?php esc_html_e( 'Login', 'injellik-recommendations' ); ?></label></th>
			<td>
				<input type="text" id="injellik-api-login" name="api_login" class="regular-text"
					value="<?php echo esc_attr( isset( $injellik_posted['api_login'] ) ? $injellik_posted['api_login'] : $injellik_c['login'] ); ?>"<?php disabled( ! $injellik_login ); ?>>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="injellik-api-password"><?php esc_html_e( 'Password', 'injellik-recommendations' ); ?></label></th>
			<td>
				<input type="password" id="injellik-api-password" name="api_password" class="regular-text" autocomplete="new-password"
					placeholder="<?php echo $injellik_c['has_password'] ? esc_attr__( 'Unchanged', 'injellik-recommendations' ) : ''; ?>"<?php disabled( ! $injellik_login ); ?>>
			</td>
		</tr>
	</table>

	<p class="injellik-auth-switch">
		<a href="#" class="js-injellik-auth-switch" data-mode="login"<?php echo $injellik_login ? ' hidden' : ''; ?>><?php esc_html_e( 'Account created before the subscriptions? Use an API login', 'injellik-recommendations' ); ?></a>
		<a href="#" class="js-injellik-auth-switch" data-mode="licence"<?php echo $injellik_login ? '' : ' hidden'; ?>><?php esc_html_e( 'Use a licence key', 'injellik-recommendations' ); ?></a>
	</p>

	<div class="notice inline injellik-test-result js-injellik-test-result" hidden><p></p></div>

	<p class="submit">
		<button type="button" class="button js-injellik-test" data-loading="<?php esc_attr_e( 'Testing…', 'injellik-recommendations' ); ?>"><?php esc_html_e( 'Test the connection', 'injellik-recommendations' ); ?></button>
		<button type="submit" class="button button-primary"><?php echo $onboarding ? esc_html__( 'Save and continue', 'injellik-recommendations' ) : esc_html__( 'Save', 'injellik-recommendations' ); ?></button>
	</p>
</form>
