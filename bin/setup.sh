#!/usr/bin/env bash
# Installs WordPress, WooCommerce, a few sample products and activates the plugin in the development shop.
#   docker compose up -d && ./bin/setup.sh
set -euo pipefail
cd "$(dirname "$0")/.."

PORT=${WP_PORT:-8092}
wp() { docker compose run --rm -T cli wp "$@"; }

echo "Waiting for WordPress..."
for _ in $(seq 1 60); do
    if docker compose exec -T wordpress test -f /var/www/html/wp-config.php; then break; fi
    sleep 2
done

if ! wp core is-installed 2>/dev/null; then
    wp core install --url="http://localhost:$PORT" --title="Injellik Shop" \
        --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
    wp language core install fr_FR --activate || true
    wp rewrite structure '/%postname%/' --hard
fi

wp plugin is-installed woocommerce || wp plugin install woocommerce
wp plugin activate woocommerce
wp language plugin install woocommerce fr_FR || true
wp plugin activate injellik-recommendations

if [ "$(wp post list --post_type=product --format=count)" = "0" ]; then
    wp option update woocommerce_currency EUR
    wp option update woocommerce_default_country FR
    wp option update woocommerce_coming_soon no
    wp eval '
        $cats = array();
        foreach ( array( "Vêtements", "Accessoires", "Chaussures" ) as $name ) {
            $term = wp_insert_term( $name, "product_cat" );
            $cats[] = is_wp_error( $term ) ? $term->get_error_data() : $term["term_id"];
        }
        $names = array( "T-shirt bio", "Sweat à capuche", "Jean slim", "Casquette", "Écharpe en laine", "Sac à dos",
            "Baskets blanches", "Bottines cuir", "Chaussettes rayées", "Ceinture", "Veste en jean", "Bonnet" );
        foreach ( $names as $i => $name ) {
            $product = new WC_Product_Simple();
            $product->set_name( $name );
            $product->set_regular_price( (string) ( 15 + 7 * $i ) );
            $product->set_short_description( "Description de " . $name . "." );
            $product->set_category_ids( array( $cats[ $i % 3 ] ) );
            $product->set_status( "publish" );
            $product->save();
        }
        echo count( $names ) . " products\n";
    '
fi

echo
echo "Shop:        http://localhost:$PORT"
echo "Back office: http://localhost:$PORT/wp-admin (admin / admin)"
