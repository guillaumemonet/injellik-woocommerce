<?php
/**
 * Dashboard: API health, data synchronisation, event queue, block performance and rankings.
 *
 * @package Injellik
 *
 * @var array $injellik Variables of the page.
 */

defined( 'ABSPATH' ) || exit;

$subtitle        = __( 'Dashboard', 'injellik-recommendations' );
$injellik_api    = $injellik['api'];
$injellik_queue  = $injellik['queue'];
$injellik_chart  = $injellik['chart'];
$injellik_status = array(
	'sent'    => __( 'sent', 'injellik-recommendations' ),
	'pending' => __( 'pending', 'injellik-recommendations' ),
	'error'   => __( 'error', 'injellik-recommendations' ),
);
?>
<div class="wrap injellik injellik-dashboard">
	<?php require __DIR__ . '/header.php'; ?>

	<p class="injellik-page-actions">
		<a href="<?php echo esc_url( $injellik['settings_url'] ); ?>" class="button"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span> <?php esc_html_e( 'Settings', 'injellik-recommendations' ); ?></a>
	</p>

	<?php /* --- Health --- */ ?>
	<div class="injellik-kpis">
		<div class="injellik-kpi <?php echo $injellik_api['ok'] ? 'is-ok' : 'is-error'; ?>">
			<span class="injellik-kpi__label"><?php esc_html_e( 'Injellik API', 'injellik-recommendations' ); ?></span>
			<?php if ( $injellik_api['ok'] ) : ?>
				<span class="injellik-kpi__value"><?php esc_html_e( 'Connected', 'injellik-recommendations' ); ?></span>
				<?php /* translators: %d: milliseconds */ ?>
				<span class="injellik-kpi__help"><?php echo esc_html( sprintf( __( '%d ms', 'injellik-recommendations' ), $injellik_api['latency'] ) ); ?></span>
			<?php else : ?>
				<span class="injellik-kpi__value"><?php esc_html_e( 'Unavailable', 'injellik-recommendations' ); ?></span>
				<span class="injellik-kpi__help"><?php echo esc_html( $injellik_api['message'] ); ?></span>
			<?php endif; ?>
		</div>
		<div class="injellik-kpi">
			<span class="injellik-kpi__label"><?php esc_html_e( 'Events (24 h)', 'injellik-recommendations' ); ?></span>
			<span class="injellik-kpi__value"><?php echo esc_html( number_format_i18n( $injellik_queue['last24h'] ) ); ?></span>
			<span class="injellik-kpi__help">
				<?php if ( $injellik_queue['last_sent'] ) : ?>
					<?php /* translators: %s: date */ ?>
					<?php echo esc_html( sprintf( __( 'last sent: %s', 'injellik-recommendations' ), $injellik_queue['last_sent'] ) ); ?>
				<?php else : ?>
					<?php esc_html_e( 'nothing sent yet', 'injellik-recommendations' ); ?>
				<?php endif; ?>
			</span>
		</div>
		<div class="injellik-kpi <?php echo $injellik['catalog']['synced_at'] ? 'is-ok' : 'is-warning'; ?>">
			<span class="injellik-kpi__label"><?php esc_html_e( 'Catalog', 'injellik-recommendations' ); ?></span>
			<?php /* translators: 1: products sent, 2: products in the shop */ ?>
			<span class="injellik-kpi__value"><?php echo esc_html( sprintf( __( '%1$d / %2$d', 'injellik-recommendations' ), $injellik['catalog']['count'], $injellik['catalog']['products'] ) ); ?></span>
			<span class="injellik-kpi__help"><?php echo $injellik['catalog']['synced_at'] ? esc_html( $injellik['catalog']['synced_at'] ) : esc_html__( 'never synchronised', 'injellik-recommendations' ); ?></span>
		</div>
		<div class="injellik-kpi <?php echo $injellik['last_cron'] || 'cron' !== $injellik['send_mode'] ? '' : 'is-warning'; ?>">
			<span class="injellik-kpi__label"><?php esc_html_e( 'Background tasks', 'injellik-recommendations' ); ?></span>
			<span class="injellik-kpi__value"><?php echo $injellik['last_cron'] ? esc_html( $injellik['last_cron'] ) : esc_html__( 'never', 'injellik-recommendations' ); ?></span>
			<?php /* translators: %d: number of imported orders */ ?>
			<span class="injellik-kpi__help"><?php echo esc_html( sprintf( __( '%d orders imported', 'injellik-recommendations' ), $injellik['orders']['count'] ) ); ?></span>
		</div>
	</div>

	<?php if ( $injellik['last_error'] ) : ?>
		<div class="notice notice-warning inline injellik-last-error">
			<p>
				<?php /* translators: %s: date */ ?>
				<strong><?php echo esc_html( sprintf( __( 'Last error (%s):', 'injellik-recommendations' ), $injellik['last_error']['date'] ) ); ?></strong>
				<?php echo esc_html( $injellik['last_error']['message'] ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( $injellik['form_url'] ); ?>">
				<input type="hidden" name="action" value="injellik_save">
				<input type="hidden" name="section" value="clear_error">
				<?php wp_nonce_field( 'injellik_save' ); ?>
				<p><button type="submit" class="button-link"><?php esc_html_e( 'Dismiss', 'injellik-recommendations' ); ?></button></p>
			</form>
		</div>
	<?php endif; ?>

	<?php /* --- Activity --- */ ?>
	<div class="injellik-card">
		<h2>
			<?php esc_html_e( 'Tracked activity', 'injellik-recommendations' ); ?>
			<?php /* translators: 1: number of events, 2: number of days */ ?>
			<span class="injellik-badge"><?php echo esc_html( sprintf( __( '%1$s events, %2$d days', 'injellik-recommendations' ), number_format_i18n( $injellik_chart['total'] ), $injellik['days'] ) ); ?></span>
		</h2>
		<?php if ( $injellik_chart['total'] ) : ?>
			<div class="injellik-chart" role="img" aria-label="<?php esc_attr_e( 'Events per day', 'injellik-recommendations' ); ?>">
				<?php foreach ( $injellik_chart['bars'] as $injellik_bar ) : ?>
					<?php
					$injellik_title = $injellik_bar['label'] . ' — ' . $injellik_bar['total'];
					foreach ( $injellik_bar['segments'] as $injellik_segment ) {
						$injellik_title .= "\n" . $injellik_segment['type'] . ': ' . $injellik_segment['count'];
					}
					?>
					<div class="injellik-chart__bar" title="<?php echo esc_attr( $injellik_title ); ?>">
						<?php foreach ( array_reverse( $injellik_bar['segments'] ) as $injellik_segment ) : ?>
							<span style="height: <?php echo esc_attr( (string) $injellik_segment['height'] ); ?>%; background: <?php echo esc_attr( $injellik_segment['color'] ); ?>"></span>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			</div>
			<ul class="injellik-legend">
				<?php foreach ( $injellik_chart['by_type'] as $injellik_type => $injellik_count ) : ?>
					<li><span style="background: <?php echo esc_attr( isset( $injellik['colors'][ $injellik_type ] ) ? $injellik['colors'][ $injellik_type ] : '#a7aaad' ); ?>"></span> <?php echo esc_html( $injellik_type ); ?> <strong><?php echo esc_html( number_format_i18n( $injellik_count ) ); ?></strong></li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'No events yet. Visit a product page of your shop to record a first view.', 'injellik-recommendations' ); ?></p>
		<?php endif; ?>
	</div>

	<div class="injellik-columns">
		<?php /* --- Blocks --- */ ?>
		<div class="injellik-card">
			<h2><?php esc_html_e( 'Recommendation blocks', 'injellik-recommendations' ); ?> <span class="injellik-badge"><?php esc_html_e( '30 days', 'injellik-recommendations' ); ?></span></h2>
			<table class="widefat striped injellik-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Block', 'injellik-recommendations' ); ?></th>
						<th><?php esc_html_e( 'Strategy', 'injellik-recommendations' ); ?></th>
						<th class="num"><?php esc_html_e( 'Impressions', 'injellik-recommendations' ); ?></th>
						<th class="num"><?php esc_html_e( 'Clicks', 'injellik-recommendations' ); ?></th>
						<th class="num"><?php esc_html_e( 'CTR', 'injellik-recommendations' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $injellik['blocks'] as $injellik_block ) : ?>
						<tr<?php echo $injellik_block['enabled'] ? '' : ' class="is-disabled"'; ?>>
							<td>
								<?php echo esc_html( $injellik_block['label'] ); ?>
								<?php if ( ! $injellik_block['enabled'] ) : ?>
									<span class="description">(<?php esc_html_e( 'disabled', 'injellik-recommendations' ); ?>)</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $injellik_block['strategy'] ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $injellik_block['impressions'] ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $injellik_block['clicks'] ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $injellik_block['ctr'], 2 ) ); ?> %</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php /* --- Queue --- */ ?>
		<div class="injellik-card">
			<h2><?php esc_html_e( 'Event queue', 'injellik-recommendations' ); ?></h2>
			<ul class="injellik-queue">
				<li class="is-ok"><strong><?php echo esc_html( number_format_i18n( $injellik_queue['sent'] ) ); ?></strong> <?php esc_html_e( 'sent', 'injellik-recommendations' ); ?></li>
				<li class="<?php echo $injellik_queue['pending'] ? 'is-warning' : ''; ?>"><strong><?php echo esc_html( number_format_i18n( $injellik_queue['pending'] ) ); ?></strong> <?php esc_html_e( 'pending', 'injellik-recommendations' ); ?></li>
				<li class="<?php echo $injellik_queue['error'] ? 'is-error' : ''; ?>"><strong><?php echo esc_html( number_format_i18n( $injellik_queue['error'] ) ); ?></strong> <?php esc_html_e( 'in error', 'injellik-recommendations' ); ?></li>
			</ul>

			<div class="injellik-job js-injellik-job" data-action="injellik_flush_queue" data-reload="1">
				<div class="injellik-progress"><div class="injellik-progress__bar js-injellik-job-bar"></div></div>
				<p class="injellik-job__message js-injellik-job-message"></p>
				<button type="button" class="button js-injellik-job-start"<?php disabled( 0 === $injellik_queue['pending'] ); ?>><?php esc_html_e( 'Send now', 'injellik-recommendations' ); ?></button>
				<?php if ( $injellik_queue['error'] ) : ?>
					<form method="post" action="<?php echo esc_url( $injellik['form_url'] ); ?>" class="injellik-inline-form">
						<input type="hidden" name="action" value="injellik_save">
						<input type="hidden" name="section" value="retry_errors">
						<?php wp_nonce_field( 'injellik_save' ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Retry the errors', 'injellik-recommendations' ); ?></button>
					</form>
				<?php endif; ?>
			</div>

			<?php if ( $injellik['problems'] ) : ?>
				<h3><?php esc_html_e( 'Latest errors', 'injellik-recommendations' ); ?></h3>
				<table class="widefat striped injellik-table">
					<tbody>
						<?php foreach ( $injellik['problems'] as $injellik_event ) : ?>
							<tr>
								<td class="injellik-nowrap"><?php echo esc_html( $injellik_event['date_add'] ); ?></td>
								<td><code><?php echo esc_html( $injellik_event['action_type'] ); ?></code> #<?php echo esc_html( $injellik_event['item_sku'] ); ?></td>
								<td><small><?php echo esc_html( (string) $injellik_event['last_error'] ); ?></small></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>

	<?php /* --- Rankings --- */ ?>
	<div class="injellik-columns">
		<?php
		foreach ( array(
			'most_viewed_items' => __( 'Most viewed products', 'injellik-recommendations' ),
			'most_bought_items' => __( 'Most ordered products', 'injellik-recommendations' ),
		) as $injellik_ranking => $injellik_title ) :
			?>
			<div class="injellik-card">
				<h2><?php echo esc_html( $injellik_title ); ?> <span class="injellik-badge"><?php esc_html_e( '30 days, from Injellik', 'injellik-recommendations' ); ?></span></h2>
				<?php if ( $injellik['rankings'][ $injellik_ranking ] ) : ?>
					<table class="widefat striped injellik-table">
						<tbody>
							<?php foreach ( $injellik['rankings'][ $injellik_ranking ] as $injellik_i => $injellik_row ) : ?>
								<tr>
									<td class="injellik-rank"><?php echo (int) $injellik_i + 1; ?></td>
									<td>
										<?php if ( $injellik_row['url'] ) : ?>
											<a href="<?php echo esc_url( $injellik_row['url'] ); ?>"><?php echo esc_html( $injellik_row['label'] ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $injellik_row['label'] ); ?>
										<?php endif; ?>
										<span class="description">#<?php echo esc_html( $injellik_row['sku'] ); ?></span>
									</td>
									<td class="num"><strong><?php echo null === $injellik_row['count'] ? '' : esc_html( number_format_i18n( (float) $injellik_row['count'] ) ); ?></strong></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'No data yet.', 'injellik-recommendations' ); ?></p>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>

	<?php /* --- Recent events --- */ ?>
	<div class="injellik-card">
		<h2><?php esc_html_e( 'Latest events', 'injellik-recommendations' ); ?></h2>
		<?php if ( $injellik['recent'] ) : ?>
			<table class="widefat striped injellik-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'injellik-recommendations' ); ?></th>
						<th><?php esc_html_e( 'Action', 'injellik-recommendations' ); ?></th>
						<th><?php esc_html_e( 'Product', 'injellik-recommendations' ); ?></th>
						<th><?php esc_html_e( 'Visitor', 'injellik-recommendations' ); ?></th>
						<th><?php esc_html_e( 'Status', 'injellik-recommendations' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $injellik['recent'] as $injellik_event ) : ?>
						<tr>
							<td class="injellik-nowrap"><?php echo esc_html( $injellik_event['date_add'] ); ?></td>
							<td><span class="injellik-action" style="border-color: <?php echo esc_attr( isset( $injellik['colors'][ $injellik_event['action_type'] ] ) ? $injellik['colors'][ $injellik_event['action_type'] ] : '#a7aaad' ); ?>"><?php echo esc_html( $injellik_event['action_type'] ); ?></span></td>
							<td>#<?php echo esc_html( $injellik_event['item_sku'] ); ?><?php echo $injellik_event['item_name'] ? ' <span class="description">' . esc_html( $injellik_event['item_name'] ) . '</span>' : ''; ?></td>
							<td><small class="description"><?php echo esc_html( substr( $injellik_event['user_uuid'], 0, 14 ) ); ?>…</small></td>
							<td>
								<span class="injellik-status is-<?php echo esc_attr( $injellik_event['status'] ); ?>"<?php echo $injellik_event['last_error'] ? ' title="' . esc_attr( $injellik_event['last_error'] ) . '"' : ''; ?>>
									<?php echo esc_html( isset( $injellik_status[ $injellik_event['status'] ] ) ? $injellik_status[ $injellik_event['status'] ] : $injellik_event['status'] ); ?>
									<?php echo 'pending' === $injellik_event['status'] && (int) $injellik_event['attempts'] > 0 ? ' (' . (int) $injellik_event['attempts'] . ')' : ''; ?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'No events yet.', 'injellik-recommendations' ); ?></p>
		<?php endif; ?>
	</div>
</div>
