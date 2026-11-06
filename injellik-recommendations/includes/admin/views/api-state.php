<?php
/**
 * Notice displayed while the API refuses the shop: subscription suspended or licence key invalid.
 * Detailed on the pages of the plugin, compact on the other pages of the back office.
 *
 * @package Injellik
 *
 * @var array $injellik api_state, compact, links.
 */

defined( 'ABSPATH' ) || exit;

$injellik_state = $injellik['api_state'];
?>
<?php if ( Injellik_Config::STATE_SUSPENDED === $injellik_state['reason'] ) : ?>
	<div class="notice notice-error injellik-api-state">
		<p>
			<strong><?php echo $injellik['compact'] ? 'Injellik — ' : ''; ?><?php esc_html_e( 'Your Injellik subscription is suspended.', 'injellik-recommendations' ); ?></strong>
			<?php if ( ! $injellik['compact'] ) : ?>
				<?php esc_html_e( 'The recommendation blocks are hidden and the events are kept in your shop: they will be sent as soon as the subscription is active again.', 'injellik-recommendations' ); ?>
				<?php if ( $injellik_state['since'] ) : ?>
					<?php /* translators: %s: date */ ?>
					<span class="description">(<?php echo esc_html( sprintf( __( 'since %s', 'injellik-recommendations' ), $injellik_state['since'] ) ); ?>)</span>
				<?php endif; ?>
			<?php endif; ?>
			<a href="<?php echo esc_url( $injellik['links']['subscription'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Manage my subscription', 'injellik-recommendations' ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a>
		</p>
	</div>
<?php else : ?>
	<div class="notice notice-warning injellik-api-state">
		<p>
			<strong><?php echo $injellik['compact'] ? 'Injellik — ' : ''; ?><?php esc_html_e( 'Invalid or revoked licence key.', 'injellik-recommendations' ); ?></strong>
			<?php if ( ! $injellik['compact'] ) : ?>
				<?php esc_html_e( 'The recommendation blocks are hidden until a valid key is saved in the Connection tab.', 'injellik-recommendations' ); ?>
			<?php else : ?>
				<a href="<?php echo esc_url( Injellik_Admin::settings_url() . '#connection' ); ?>"><?php esc_html_e( 'Settings', 'injellik-recommendations' ); ?></a> ·
			<?php endif; ?>
			<a href="<?php echo esc_url( $injellik['links']['shops'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Find my key', 'injellik-recommendations' ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a>
		</p>
	</div>
<?php endif; ?>
