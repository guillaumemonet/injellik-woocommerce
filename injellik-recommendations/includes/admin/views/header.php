<?php
/**
 * Header of the pages of the plugin: title, notices and state of the API.
 *
 * @package Injellik
 *
 * @var array  $injellik Variables of the page.
 * @var string $subtitle Subtitle.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="injellik-header">
	<img class="injellik-header__logo" src="<?php echo esc_url( $injellik['logo'] ); ?>" alt="" height="40">
	<div>
		<h1 class="injellik-header__title">Injellik <span><?php echo esc_html( $subtitle ); ?></span></h1>
		<p class="injellik-header__version"><?php echo esc_html( sprintf( /* translators: %s: version */ __( 'Version %s', 'injellik-recommendations' ), $injellik['version'] ) ); ?></p>
	</div>
</div>
<hr class="wp-header-end">

<?php if ( ! empty( $injellik['api_state'] ) ) : ?>
	<?php
	self::view(
		'api-state',
		array(
			'api_state' => $injellik['api_state'],
			'compact'   => false,
		)
	);
	?>
<?php endif; ?>

<?php foreach ( $injellik['errors'] as $injellik_error ) : ?>
	<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $injellik_error ); ?></p></div>
<?php endforeach; ?>

<?php if ( $injellik['updated'] && ! $injellik['errors'] ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'injellik-recommendations' ); ?></p></div>
<?php endif; ?>
