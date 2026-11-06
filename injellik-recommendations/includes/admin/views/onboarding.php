<?php
/**
 * Onboarding wizard: welcome, connection, catalog, order history, blocks, done.
 *
 * @package Injellik
 *
 * @var array $injellik Variables of the page.
 */

defined( 'ABSPATH' ) || exit;

$subtitle   = __( 'Getting started', 'injellik-recommendations' );
$onboarding = true;
$injellik_s = $injellik['step'];
$injellik_u = $injellik['step_urls'];
?>
<div class="wrap injellik injellik-onboarding">
	<?php require __DIR__ . '/header.php'; ?>

	<ol class="injellik-stepper">
		<?php $injellik_i = 0; ?>
		<?php foreach ( $injellik['steps'] as $injellik_key => $injellik_label ) : ?>
			<?php
			$injellik_class = $injellik_i < $injellik['step_index'] ? ' is-done' : ( $injellik_key === $injellik_s ? ' is-current' : '' );
			++$injellik_i;
			?>
			<li class="injellik-stepper__step<?php echo esc_attr( $injellik_class ); ?>">
				<span class="injellik-stepper__number">
					<?php if ( ' is-done' === $injellik_class ) : ?>
						<span class="dashicons dashicons-yes" aria-hidden="true"></span>
					<?php else : ?>
						<?php echo (int) $injellik_i; ?>
					<?php endif; ?>
				</span>
				<span class="injellik-stepper__label"><?php echo esc_html( $injellik_label ); ?></span>
			</li>
		<?php endforeach; ?>
	</ol>

	<div class="injellik-card injellik-step">
		<?php if ( 'welcome' === $injellik_s ) : ?>
			<h2 class="injellik-step__title"><?php esc_html_e( 'Recommend the right products to each visitor', 'injellik-recommendations' ); ?></h2>
			<p class="injellik-step__lead"><?php esc_html_e( 'Injellik learns from the behaviour of your visitors (products viewed, added to cart, ordered) and displays recommendation blocks on your pages. Setup takes about five minutes.', 'injellik-recommendations' ); ?></p>

			<div class="injellik-features">
				<div class="injellik-feature">
					<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
					<h3><?php esc_html_e( '1. Tracking', 'injellik-recommendations' ); ?></h3>
					<p><?php esc_html_e( 'Views, carts and orders are recorded in your shop and sent to Injellik in the background. Your pages are never slowed down, even if the API is unavailable.', 'injellik-recommendations' ); ?></p>
				</div>
				<div class="injellik-feature">
					<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
					<h3><?php esc_html_e( '2. Learning', 'injellik-recommendations' ); ?></h3>
					<p><?php esc_html_e( 'Every night, Injellik computes which products are viewed and bought together and which products are similar.', 'injellik-recommendations' ); ?></p>
				</div>
				<div class="injellik-feature">
					<span class="dashicons dashicons-screenoptions" aria-hidden="true"></span>
					<h3><?php esc_html_e( '3. Recommendations', 'injellik-recommendations' ); ?></h3>
					<p><?php esc_html_e( 'Blocks on the product, cart and confirmation pages, or anywhere with the Injellik block or shortcode, using the product cards of your theme.', 'injellik-recommendations' ); ?></p>
				</div>
			</div>

			<div class="notice notice-info inline">
				<p>
					<?php esc_html_e( 'You need an Injellik licence key, provided with a subscription on web-nest.fr.', 'injellik-recommendations' ); ?>
					<a href="<?php echo esc_url( $injellik['links']['pricing'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get a key', 'injellik-recommendations' ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a>
				</p>
			</div>

			<div class="injellik-actions">
				<a href="<?php echo esc_url( $injellik_u['connection'] ); ?>" class="button button-primary button-hero"><?php esc_html_e( 'Start', 'injellik-recommendations' ); ?> &rarr;</a>
			</div>

		<?php elseif ( 'connection' === $injellik_s ) : ?>
			<h2 class="injellik-step__title"><?php esc_html_e( 'Connect your shop to Injellik', 'injellik-recommendations' ); ?></h2>
			<p class="injellik-step__lead"><?php esc_html_e( 'Paste the licence key of this shop. It is stored in your shop settings and only used by the server.', 'injellik-recommendations' ); ?></p>
			<?php require __DIR__ . '/connection-form.php'; ?>

		<?php elseif ( 'catalog' === $injellik_s ) : ?>
			<h2 class="injellik-step__title"><?php esc_html_e( 'Synchronise your catalog', 'injellik-recommendations' ); ?></h2>
			<p class="injellik-step__lead"><?php esc_html_e( 'Your products (name, price, categories, tags, brand) are sent to Injellik so that it can recommend them and compute similar products. Later changes are synchronised automatically.', 'injellik-recommendations' ); ?></p>

			<div class="injellik-job js-injellik-job" data-action="injellik_sync_catalog"<?php echo $injellik['catalog_synced_at'] ? '' : ' data-autostart="1"'; ?>>
				<p class="injellik-job__summary">
					<?php /* translators: %d: number of products */ ?>
					<strong><?php echo esc_html( sprintf( _n( '%d product in this shop', '%d products in this shop', $injellik['product_count'], 'injellik-recommendations' ), $injellik['product_count'] ) ); ?></strong>
					<?php if ( $injellik['catalog_synced_at'] ) : ?>
						<?php /* translators: %s: date */ ?>
						<span class="description">— <?php echo esc_html( sprintf( __( 'last synchronisation: %s', 'injellik-recommendations' ), $injellik['catalog_synced_at'] ) ); ?></span>
					<?php endif; ?>
				</p>
				<div class="injellik-progress"><div class="injellik-progress__bar js-injellik-job-bar" style="width: <?php echo $injellik['catalog_synced_at'] ? '100' : '0'; ?>%"></div></div>
				<p class="injellik-job__message js-injellik-job-message"></p>
				<button type="button" class="button js-injellik-job-start">
					<?php echo $injellik['catalog_synced_at'] ? esc_html__( 'Synchronise again', 'injellik-recommendations' ) : esc_html__( 'Synchronise', 'injellik-recommendations' ); ?>
				</button>
			</div>

			<div class="injellik-actions">
				<a href="<?php echo esc_url( $injellik_u['connection'] ); ?>" class="button button-link">&larr; <?php esc_html_e( 'Back', 'injellik-recommendations' ); ?></a>
				<a href="<?php echo esc_url( $injellik_u['history'] ); ?>" class="button button-primary js-injellik-next<?php echo $injellik['catalog_synced_at'] ? '' : ' disabled'; ?>"><?php esc_html_e( 'Continue', 'injellik-recommendations' ); ?> &rarr;</a>
			</div>

		<?php elseif ( 'history' === $injellik_s ) : ?>
			<h2 class="injellik-step__title"><?php esc_html_e( 'Import your order history', 'injellik-recommendations' ); ?></h2>
			<p class="injellik-step__lead"><?php esc_html_e( 'Past orders give Injellik a head start: "bought together" recommendations are available after the next training instead of after weeks of tracking. Customers are identified by an anonymous hash, no personal data is sent. Imported orders are not counted in your subscription.', 'injellik-recommendations' ); ?></p>

			<div class="injellik-job js-injellik-job" data-action="injellik_import_orders">
				<p class="injellik-job__options">
					<label for="injellik-since"><?php esc_html_e( 'Orders placed since', 'injellik-recommendations' ); ?></label>
					<input type="date" id="injellik-since" class="js-injellik-job-param" name="since" value="<?php echo esc_attr( $injellik['history_since'] ); ?>">
					<?php /* translators: %d: number of orders */ ?>
					<span class="description">(<?php echo esc_html( sprintf( _n( '%d paid order in total', '%d paid orders in total', $injellik['order_count'], 'injellik-recommendations' ), $injellik['order_count'] ) ); ?>)</span>
				</p>
				<div class="injellik-progress"><div class="injellik-progress__bar js-injellik-job-bar" style="width: <?php echo $injellik['orders_imported_at'] ? '100' : '0'; ?>%"></div></div>
				<p class="injellik-job__message js-injellik-job-message">
					<?php if ( $injellik['orders_imported_at'] ) : ?>
						<?php /* translators: %s: date */ ?>
						<?php echo esc_html( sprintf( __( 'Last import: %s', 'injellik-recommendations' ), $injellik['orders_imported_at'] ) ); ?>
					<?php endif; ?>
				</p>
				<button type="button" class="button js-injellik-job-start"><?php esc_html_e( 'Import the orders', 'injellik-recommendations' ); ?></button>
			</div>

			<div class="injellik-actions">
				<a href="<?php echo esc_url( $injellik_u['catalog'] ); ?>" class="button button-link">&larr; <?php esc_html_e( 'Back', 'injellik-recommendations' ); ?></a>
				<a href="<?php echo esc_url( $injellik_u['placements'] ); ?>" class="button"><?php esc_html_e( 'Skip this step', 'injellik-recommendations' ); ?></a>
				<a href="<?php echo esc_url( $injellik_u['placements'] ); ?>" class="button button-primary js-injellik-next<?php echo $injellik['orders_imported_at'] ? '' : ' disabled'; ?>"><?php esc_html_e( 'Continue', 'injellik-recommendations' ); ?> &rarr;</a>
			</div>

		<?php elseif ( 'placements' === $injellik_s ) : ?>
			<h2 class="injellik-step__title"><?php esc_html_e( 'Choose where to display recommendations', 'injellik-recommendations' ); ?></h2>
			<p class="injellik-step__lead"><?php esc_html_e( 'Enable the blocks you want. Each block uses a strategy, and a fallback strategy while Injellik is still learning. You can change everything later.', 'injellik-recommendations' ); ?></p>
			<?php require __DIR__ . '/placements-form.php'; ?>

		<?php else : ?>
			<div class="injellik-done">
				<div class="injellik-done__icon"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span></div>
				<h2 class="injellik-step__title"><?php esc_html_e( 'Injellik is ready', 'injellik-recommendations' ); ?></h2>
				<p class="injellik-step__lead"><?php esc_html_e( 'Tracking is active. Recommendations based on behaviour appear after the next nightly training; until then the blocks use the fallback strategy.', 'injellik-recommendations' ); ?></p>
			</div>

			<div class="injellik-features">
				<div class="injellik-feature">
					<span class="dashicons dashicons-clock" aria-hidden="true"></span>
					<h3><?php esc_html_e( 'Background tasks', 'injellik-recommendations' ); ?></h3>
					<p><?php esc_html_e( 'WP-Cron retries the events that could not be sent and synchronises the catalog every night. If WP-Cron is disabled on your server, schedule the URL shown in Settings > Data.', 'injellik-recommendations' ); ?></p>
				</div>
				<div class="injellik-feature">
					<span class="dashicons dashicons-shortcode" aria-hidden="true"></span>
					<h3><?php esc_html_e( 'Add blocks anywhere', 'injellik-recommendations' ); ?></h3>
					<p><?php esc_html_e( 'Insert the "Injellik recommendations" block in the editor, or the shortcode:', 'injellik-recommendations' ); ?><br><code>[injellik_recommendations placement="home"]</code></p>
				</div>
			</div>

			<div class="injellik-actions">
				<a href="<?php echo esc_url( $injellik['settings_url'] ); ?>" class="button"><?php esc_html_e( 'Settings', 'injellik-recommendations' ); ?></a>
				<a href="<?php echo esc_url( $injellik['dashboard_url'] ); ?>" class="button button-primary button-hero"><?php esc_html_e( 'Open the dashboard', 'injellik-recommendations' ); ?></a>
			</div>
		<?php endif; ?>
	</div>
</div>
