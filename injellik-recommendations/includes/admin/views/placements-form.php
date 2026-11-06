<?php
/**
 * Settings of the recommendation blocks.
 *
 * @package Injellik
 *
 * @var array $injellik   Variables of the page.
 * @var bool  $onboarding True in the wizard.
 */

defined( 'ABSPATH' ) || exit;
?>
<form method="post" action="<?php echo esc_url( $injellik['form_url'] ); ?>">
	<input type="hidden" name="action" value="injellik_save">
	<input type="hidden" name="section" value="placements">
	<?php if ( $onboarding ) : ?>
		<input type="hidden" name="onboarding" value="1">
	<?php endif; ?>
	<?php wp_nonce_field( 'injellik_save' ); ?>

	<div class="injellik-placements">
		<?php foreach ( $injellik['placements'] as $injellik_key => $injellik_p ) : ?>
			<?php $injellik_name = 'placements[' . $injellik_key . ']'; ?>
			<div class="injellik-placement js-injellik-placement<?php echo $injellik_p['enabled'] ? ' is-enabled' : ''; ?>">
				<div class="injellik-placement__head">
					<?php if ( $injellik_p['automatic'] ) : ?>
						<label class="injellik-switch">
							<input type="checkbox" name="<?php echo esc_attr( $injellik_name ); ?>[enabled]" value="1" class="js-injellik-placement-toggle"<?php checked( $injellik_p['enabled'] ); ?>>
							<strong><?php echo esc_html( $injellik_p['label'] ); ?></strong>
						</label>
					<?php else : ?>
						<strong><?php echo esc_html( $injellik_p['label'] ); ?></strong>
						<span class="injellik-badge"><?php esc_html_e( 'block / shortcode', 'injellik-recommendations' ); ?></span>
					<?php endif; ?>
					<p class="description"><?php echo esc_html( $injellik_p['help'] ); ?></p>
				</div>

				<div class="injellik-placement__body">
					<p>
						<label><?php esc_html_e( 'Strategy', 'injellik-recommendations' ); ?><br>
							<select name="<?php echo esc_attr( $injellik_name ); ?>[strategy]">
								<?php foreach ( $injellik['strategies'] as $injellik_code => $injellik_s ) : ?>
									<?php
									// Strategies based on the current products need products: not on the home page.
									if ( 'home' === $injellik_key && 'items' === $injellik_s['context'] ) {
										continue;
									}
									?>
									<option value="<?php echo esc_attr( $injellik_code ); ?>" title="<?php echo esc_attr( $injellik_s['help'] ); ?>"<?php selected( $injellik_p['strategy'], $injellik_code ); ?>><?php echo esc_html( $injellik_s['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</p>
					<p>
						<label><?php esc_html_e( 'Fallback', 'injellik-recommendations' ); ?><br>
							<select name="<?php echo esc_attr( $injellik_name ); ?>[fallback]">
								<option value=""<?php selected( $injellik_p['fallback'], '' ); ?>><?php esc_html_e( 'None', 'injellik-recommendations' ); ?></option>
								<?php foreach ( $injellik['strategies'] as $injellik_code => $injellik_s ) : ?>
									<option value="<?php echo esc_attr( $injellik_code ); ?>"<?php selected( $injellik_p['fallback'], $injellik_code ); ?>><?php echo esc_html( $injellik_s['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</p>
					<p>
						<label><?php esc_html_e( 'Products', 'injellik-recommendations' ); ?><br>
							<input type="number" name="<?php echo esc_attr( $injellik_name ); ?>[limit]" value="<?php echo esc_attr( (string) $injellik_p['limit'] ); ?>" min="1" max="24" class="small-text">
						</label>
					</p>
					<p class="injellik-placement__title">
						<label><?php esc_html_e( 'Title', 'injellik-recommendations' ); ?><br>
							<input type="text" name="<?php echo esc_attr( $injellik_name ); ?>[title]" value="<?php echo esc_attr( $injellik_p['title'] ); ?>" placeholder="<?php echo esc_attr( $injellik_p['default_title'] ); ?>" class="regular-text" maxlength="128">
						</label>
					</p>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<p class="description"><?php esc_html_e( 'The fallback strategy completes the block while Injellik is still learning, or when the main strategy does not return enough products.', 'injellik-recommendations' ); ?></p>

	<p class="submit">
		<button type="submit" class="button button-primary"><?php echo $onboarding ? esc_html__( 'Save and finish', 'injellik-recommendations' ) : esc_html__( 'Save', 'injellik-recommendations' ); ?></button>
	</p>
</form>
