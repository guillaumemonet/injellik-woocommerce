<?php
/**
 * Settings page: connection, blocks, data, integration, advanced settings.
 *
 * @package Injellik
 *
 * @var array $injellik Variables of the page.
 */

defined( 'ABSPATH' ) || exit;

$subtitle   = __( 'Settings', 'injellik-recommendations' );
$onboarding = false;
$injellik_o = $injellik['settings'];
$injellik_t = array(
	'connection'  => __( 'Connection', 'injellik-recommendations' ),
	'placements'  => __( 'Blocks', 'injellik-recommendations' ),
	'data'        => __( 'Data', 'injellik-recommendations' ),
	'integration' => __( 'Integration', 'injellik-recommendations' ),
	'advanced'    => __( 'Advanced', 'injellik-recommendations' ),
);
?>
<div class="wrap injellik injellik-settings">
	<?php require __DIR__ . '/header.php'; ?>

	<nav class="nav-tab-wrapper injellik-tabs">
		<?php foreach ( $injellik_t as $injellik_id => $injellik_label ) : ?>
			<a href="#<?php echo esc_attr( $injellik_id ); ?>" class="nav-tab<?php echo 'connection' === $injellik_id ? ' nav-tab-active' : ''; ?>" data-tab="<?php echo esc_attr( $injellik_id ); ?>"><?php echo esc_html( $injellik_label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<?php /* --- Connection --- */ ?>
	<div class="injellik-tab" id="injellik-tab-connection">
		<div class="injellik-card">
			<h2><?php esc_html_e( 'Injellik account', 'injellik-recommendations' ); ?></h2>
			<?php require __DIR__ . '/connection-form.php'; ?>
		</div>
		<p class="description">
			<?php esc_html_e( 'Want to see the wizard again?', 'injellik-recommendations' ); ?>
			<a href="<?php echo esc_url( $injellik['onboarding_url'] ); ?>"><?php esc_html_e( 'Restart the onboarding', 'injellik-recommendations' ); ?></a>
		</p>
	</div>

	<?php /* --- Blocks --- */ ?>
	<div class="injellik-tab" id="injellik-tab-placements" hidden>
		<div class="injellik-card">
			<h2><?php esc_html_e( 'Recommendation blocks', 'injellik-recommendations' ); ?></h2>
			<?php require __DIR__ . '/placements-form.php'; ?>
		</div>
	</div>

	<?php /* --- Data --- */ ?>
	<div class="injellik-tab" id="injellik-tab-data" hidden>
		<div class="injellik-card">
			<h2><?php esc_html_e( 'Catalog', 'injellik-recommendations' ); ?></h2>
			<div class="injellik-job js-injellik-job" data-action="injellik_sync_catalog" data-reload="1">
				<p class="injellik-job__summary">
					<?php if ( $injellik['catalog_synced_at'] ) : ?>
						<?php /* translators: 1: number of products, 2: date */ ?>
						<?php echo esc_html( sprintf( __( '%1$d products sent on %2$s.', 'injellik-recommendations' ), $injellik['catalog_count'], $injellik['catalog_synced_at'] ) ); ?>
					<?php else : ?>
						<?php esc_html_e( 'The catalog has never been synchronised.', 'injellik-recommendations' ); ?>
					<?php endif; ?>
					<?php /* translators: %d: number of products */ ?>
					<span class="description"><?php echo esc_html( sprintf( _n( '%d product in this shop', '%d products in this shop', $injellik['product_count'], 'injellik-recommendations' ), $injellik['product_count'] ) ); ?></span>
				</p>
				<div class="injellik-progress"><div class="injellik-progress__bar js-injellik-job-bar"></div></div>
				<p class="injellik-job__message js-injellik-job-message"></p>
				<button type="button" class="button js-injellik-job-start"><?php esc_html_e( 'Synchronise the catalog', 'injellik-recommendations' ); ?></button>
			</div>
		</div>

		<div class="injellik-card">
			<h2><?php esc_html_e( 'Order history', 'injellik-recommendations' ); ?></h2>
			<div class="injellik-job js-injellik-job" data-action="injellik_import_orders" data-reload="1">
				<p class="injellik-job__summary">
					<?php /* translators: 1: number of orders imported, 2: number of orders remaining */ ?>
					<?php echo esc_html( sprintf( __( '%1$d orders imported, %2$d remaining.', 'injellik-recommendations' ), $injellik['orders_imported'], $injellik['orders_remaining'] ) ); ?>
					<?php if ( $injellik['orders_imported_at'] ) : ?>
						<?php /* translators: %s: date */ ?>
						<span class="description"><?php echo esc_html( sprintf( __( 'Last import: %s', 'injellik-recommendations' ), $injellik['orders_imported_at'] ) ); ?></span>
					<?php endif; ?>
				</p>
				<p class="injellik-job__options">
					<label for="injellik-since"><?php esc_html_e( 'Orders placed since', 'injellik-recommendations' ); ?></label>
					<input type="date" id="injellik-since" class="js-injellik-job-param" name="since" value="<?php echo esc_attr( $injellik['history_since'] ); ?>">
				</p>
				<div class="injellik-progress"><div class="injellik-progress__bar js-injellik-job-bar"></div></div>
				<p class="injellik-job__message js-injellik-job-message"></p>
				<button type="button" class="button js-injellik-job-start"><?php esc_html_e( 'Import the orders', 'injellik-recommendations' ); ?></button>
			</div>
			<form method="post" action="<?php echo esc_url( $injellik['form_url'] ); ?>" class="injellik-inline-form">
				<input type="hidden" name="action" value="injellik_save">
				<input type="hidden" name="section" value="reset_history">
				<?php wp_nonce_field( 'injellik_save' ); ?>
				<button type="submit" class="button-link"><?php esc_html_e( 'Restart the import from the first order', 'injellik-recommendations' ); ?></button>
			</form>
			<p class="description"><?php esc_html_e( 'Only the paid orders are imported, with their real date. Orders older than 3 years are ignored by Injellik.', 'injellik-recommendations' ); ?></p>
		</div>

		<div class="injellik-card">
			<h2><?php esc_html_e( 'Background tasks', 'injellik-recommendations' ); ?></h2>
			<p>
				<?php esc_html_e( 'WP-Cron sends the pending events every 10 minutes, purges the old ones and synchronises the catalog every night.', 'injellik-recommendations' ); ?>
				<?php if ( $injellik['last_cron'] ) : ?>
					<?php /* translators: %s: date */ ?>
					<strong><?php echo esc_html( sprintf( __( 'Last run: %s.', 'injellik-recommendations' ), $injellik['last_cron'] ) ); ?></strong>
				<?php endif; ?>
			</p>
			<?php if ( $injellik['wp_cron_disabled'] ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'WP-Cron is disabled on this site (DISABLE_WP_CRON): schedule the URLs below with the cron of your server.', 'injellik-recommendations' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Server cron (optional, recommended when WP-Cron is disabled or the site has little traffic):', 'injellik-recommendations' ); ?></p>
			<div class="injellik-copy">
				<input type="text" readonly class="large-text code js-injellik-copy-source" value="*/10 * * * * curl -s &quot;<?php echo esc_attr( $injellik['cron_url'] ); ?>&quot; &gt; /dev/null">
				<button type="button" class="button js-injellik-copy"><?php esc_html_e( 'Copy', 'injellik-recommendations' ); ?></button>
			</div>
			<div class="injellik-copy">
				<input type="text" readonly class="large-text code js-injellik-copy-source" value="30 2 * * * curl -s &quot;<?php echo esc_attr( $injellik['cron_catalog_url'] ); ?>&quot; &gt; /dev/null">
				<button type="button" class="button js-injellik-copy"><?php esc_html_e( 'Copy', 'injellik-recommendations' ); ?></button>
			</div>
			<p><?php esc_html_e( 'With WP-CLI:', 'injellik-recommendations' ); ?> <code>wp injellik flush</code> · <code>wp injellik sync-catalog</code> · <code>wp injellik import-orders --since=2025-01-01</code> · <code>wp injellik status</code></p>
			<form method="post" action="<?php echo esc_url( $injellik['form_url'] ); ?>" class="injellik-inline-form">
				<input type="hidden" name="action" value="injellik_save">
				<input type="hidden" name="section" value="regenerate_token">
				<?php wp_nonce_field( 'injellik_save' ); ?>
				<button type="submit" class="button-link"><?php esc_html_e( 'Generate a new token', 'injellik-recommendations' ); ?></button>
			</form>
		</div>
	</div>

	<?php /* --- Integration --- */ ?>
	<div class="injellik-tab" id="injellik-tab-integration" hidden>
		<div class="injellik-card">
			<h2><?php esc_html_e( 'Editor block', 'injellik-recommendations' ); ?></h2>
			<p><?php esc_html_e( 'In the block editor or the site editor, insert the "Injellik recommendations" block (WooCommerce category). Choose a placement to reuse its settings, or set the strategy, the number of products and the title of the block.', 'injellik-recommendations' ); ?></p>
		</div>

		<div class="injellik-card">
			<h2><?php esc_html_e( 'Shortcode', 'injellik-recommendations' ); ?></h2>
			<pre class="injellik-code">[injellik_recommendations]
[injellik_recommendations placement="home"]
[injellik_recommendations strategy="bought" fallback="most_bought" limit="4" title="Complete the look"]
[injellik_recommendations strategy="similar" product_id="12,34,56"]</pre>
			<table class="widefat striped injellik-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Parameter', 'injellik-recommendations' ); ?></th>
						<th><?php esc_html_e( 'Values', 'injellik-recommendations' ); ?></th>
						<th><?php esc_html_e( 'Default', 'injellik-recommendations' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr><td><code>placement</code></td><td><code><?php echo implode( '</code>, <code>', array_map( 'esc_html', array_keys( $injellik['placements'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></code></td><td><code>custom</code></td></tr>
					<tr><td><code>strategy</code></td><td><code><?php echo implode( '</code>, <code>', array_map( 'esc_html', array_keys( $injellik['strategies'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></code></td><td><?php esc_html_e( 'that of the placement', 'injellik-recommendations' ); ?></td></tr>
					<tr><td><code>fallback</code></td><td><?php esc_html_e( 'a strategy, or "none"', 'injellik-recommendations' ); ?></td><td><?php esc_html_e( 'that of the placement', 'injellik-recommendations' ); ?></td></tr>
					<tr><td><code>limit</code></td><td>1 – 24</td><td><?php esc_html_e( 'that of the placement', 'injellik-recommendations' ); ?></td></tr>
					<tr><td><code>title</code></td><td><?php esc_html_e( 'text', 'injellik-recommendations' ); ?></td><td><?php esc_html_e( 'that of the placement', 'injellik-recommendations' ); ?></td></tr>
					<tr><td><code>product_id</code></td><td><?php esc_html_e( 'one or several ids separated by commas', 'injellik-recommendations' ); ?></td><td><?php esc_html_e( 'product displayed, products of the order or of the cart', 'injellik-recommendations' ); ?></td></tr>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'A block that names its placement is displayed even when the automatic display of this placement is disabled.', 'injellik-recommendations' ); ?></p>
		</div>

		<div class="injellik-card">
			<h2><?php esc_html_e( 'In a theme', 'injellik-recommendations' ); ?></h2>
			<pre class="injellik-code">&lt;?php if ( function_exists( 'injellik_recommendations' ) ) {
	injellik_recommendations( array( 'placement' =&gt; 'home' ) );
} ?&gt;

&lt;?php do_action( 'injellik_recommendations', array( 'strategy' =&gt; 'most_bought', 'limit' =&gt; 8 ) ); ?&gt;</pre>
			<p><?php esc_html_e( 'To change the HTML of the blocks, copy the template into your theme:', 'injellik-recommendations' ); ?></p>
			<pre class="injellik-code">wp-content/plugins/injellik-recommendations/templates/recommendations.php
  → wp-content/themes/&lt;your-theme&gt;/injellik-recommendations/recommendations.php</pre>
			<p class="description"><?php esc_html_e( 'Keep the data-injellik-placement attribute on the root element: it is used to measure the clicks.', 'injellik-recommendations' ); ?></p>
			<p><?php esc_html_e( 'The blocks are loaded after the page. Each time a block is inserted, an event is dispatched on document:', 'injellik-recommendations' ); ?></p>
			<pre class="injellik-code">document.addEventListener('injellik:loaded', (event) =&gt; {
  const { element, placement } = event.detail; // start a carousel...
});</pre>
		</div>
	</div>

	<?php /* --- Advanced --- */ ?>
	<div class="injellik-tab" id="injellik-tab-advanced" hidden>
		<div class="injellik-card">
			<form method="post" action="<?php echo esc_url( $injellik['form_url'] ); ?>">
				<input type="hidden" name="action" value="injellik_save">
				<input type="hidden" name="section" value="advanced">
				<?php wp_nonce_field( 'injellik_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="injellik-adv-url"><?php esc_html_e( 'API URL', 'injellik-recommendations' ); ?></label></th>
						<td>
							<input type="url" id="injellik-adv-url" name="api_url" class="regular-text code" value="<?php echo esc_attr( $injellik['connection']['url'] ); ?>" placeholder="<?php echo esc_attr( $injellik['connection']['default_url'] ); ?>">
							<p class="description"><?php esc_html_e( 'Only change it when Web-Nest asks you to.', 'injellik-recommendations' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Display of the blocks', 'injellik-recommendations' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="render_mode" value="ajax"<?php checked( $injellik_o['render_mode'], 'ajax' ); ?>> <?php esc_html_e( 'Asynchronous (recommended)', 'injellik-recommendations' ); ?></label>
								<p class="description"><?php esc_html_e( 'The blocks are loaded after the page: no slowdown, compatible with the page caches.', 'injellik-recommendations' ); ?></p>
								<label><input type="radio" name="render_mode" value="server"<?php checked( $injellik_o['render_mode'], 'server' ); ?>> <?php esc_html_e( 'In the page', 'injellik-recommendations' ); ?></label>
								<p class="description"><?php esc_html_e( 'The API is called while the page is generated: visible without JavaScript, but slower and not personalized behind a page cache.', 'injellik-recommendations' ); ?></p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sending of the events', 'injellik-recommendations' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="send_mode" value="realtime"<?php checked( $injellik_o['send_mode'], 'realtime' ); ?>> <?php esc_html_e( 'At the end of the request (recommended)', 'injellik-recommendations' ); ?></label><br>
								<label><input type="radio" name="send_mode" value="cron"<?php checked( $injellik_o['send_mode'], 'cron' ); ?>> <?php esc_html_e( 'Background task only', 'injellik-recommendations' ); ?></label>
								<p class="description"><?php esc_html_e( 'With PHP-FPM, the events are sent after the page has been delivered to the visitor. Choose the background task if PHP runs as an Apache module.', 'injellik-recommendations' ); ?></p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="injellik-adv-timeout"><?php esc_html_e( 'API timeout', 'injellik-recommendations' ); ?></label></th>
						<td>
							<input type="number" id="injellik-adv-timeout" name="timeout" min="1" max="10" class="small-text" value="<?php echo esc_attr( (string) $injellik_o['timeout'] ); ?>"> s
							<p class="description"><?php esc_html_e( 'For the calls made while displaying a block. The back office and the background tasks wait up to 20 s.', 'injellik-recommendations' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="injellik-adv-cache"><?php esc_html_e( 'Recommendation cache', 'injellik-recommendations' ); ?></label></th>
						<td>
							<input type="number" id="injellik-adv-cache" name="cache_ttl" min="0" max="86400" class="small-text" value="<?php echo esc_attr( (string) $injellik_o['cache_ttl'] ); ?>"> s
							<p class="description"><?php esc_html_e( 'Object cache when there is one, transients otherwise. Personalized recommendations are kept 60 s at most, and only with a persistent object cache.', 'injellik-recommendations' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="injellik-adv-columns"><?php esc_html_e( 'Columns', 'injellik-recommendations' ); ?></label></th>
						<td>
							<input type="number" id="injellik-adv-columns" name="columns" min="1" max="6" class="small-text" value="<?php echo esc_attr( (string) $injellik_o['columns'] ); ?>">
							<p class="description"><?php esc_html_e( 'Products per row in the blocks, like the product lists of your theme.', 'injellik-recommendations' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Options', 'injellik-recommendations' ); ?></th>
						<td>
							<fieldset>
								<label><input type="checkbox" name="hide_out_of_stock" value="1"<?php checked( $injellik_o['hide_out_of_stock'] ); ?>> <?php esc_html_e( 'Hide the out-of-stock products (the products on backorder stay)', 'injellik-recommendations' ); ?></label><br>
								<label><input type="checkbox" name="exclude_bots" value="1"<?php checked( $injellik_o['exclude_bots'] ); ?>> <?php esc_html_e( 'Ignore the bots (user agent filter)', 'injellik-recommendations' ); ?></label><br>
								<label><input type="checkbox" name="auto_sync" value="1"<?php checked( $injellik_o['auto_sync'] ); ?>> <?php esc_html_e( 'Send a product to Injellik when it is saved', 'injellik-recommendations' ); ?></label><br>
								<label><input type="checkbox" name="nightly_catalog" value="1"<?php checked( $injellik_o['nightly_catalog'] ); ?>> <?php esc_html_e( 'Synchronise the whole catalog every night', 'injellik-recommendations' ); ?></label>
							</fieldset>
						</td>
					</tr>
				</table>
				<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'injellik-recommendations' ); ?></button></p>
			</form>
		</div>
	</div>
</div>
