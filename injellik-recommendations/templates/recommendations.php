<?php
/**
 * Recommendation block.
 *
 * Override it by copying it to yourtheme/injellik-recommendations/recommendations.php.
 * Keep the data-injellik-placement attribute on the root element: it is used to measure the clicks
 * (the product id is read from the "post-<id>" class of the product of the loop).
 *
 * @package Injellik
 *
 * @var string       $placement Placement of the block.
 * @var string       $title     Title.
 * @var WC_Product[] $products  Recommended products.
 * @var int          $columns   Columns.
 */

defined( 'ABSPATH' ) || exit;

wc_set_loop_prop( 'name', 'injellik' );
wc_set_loop_prop( 'columns', $columns );
?>
<?php // The "woocommerce" class applies the product list styles of WooCommerce, as around its shortcodes. ?>
<section class="injellik-reco woocommerce" data-injellik-placement="<?php echo esc_attr( $placement ); ?>">
	<?php if ( '' !== $title ) : ?>
		<h2 class="injellik-reco__title"><?php echo esc_html( $title ); ?></h2>
	<?php endif; ?>

	<?php woocommerce_product_loop_start(); ?>

	<?php foreach ( $products as $injellik_product ) : ?>
		<?php
		$post_object = get_post( $injellik_product->get_id() );
		setup_postdata( $GLOBALS['post'] = $post_object ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, Squiz.PHP.DisallowMultipleAssignments.Found
		wc_get_template_part( 'content', 'product' );
		?>
	<?php endforeach; ?>

	<?php woocommerce_product_loop_end(); ?>
</section>
<?php
wp_reset_postdata();
wc_reset_loop();
