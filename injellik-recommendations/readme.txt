=== Injellik Recommendations ===
Contributors: webnest
Tags: woocommerce, recommendations, cross-sell, related products, personalization
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Personalized product recommendations computed from the real behaviour of your visitors, powered by the Injellik API.

== Description ==

Injellik Recommendations connects your WooCommerce shop to the Injellik API. It tracks what your visitors do (products viewed, added to cart, ordered, rated), sends it to Injellik, which computes every night the products viewed together, bought together and similar, and displays recommendation blocks on your pages with the product cards of your theme.

* Six-step setup wizard: connection, catalog, order history, blocks.
* Built-in placements: product page, cart (classic and cart block), thank you page, shop and categories; editor block and shortcode anywhere else.
* Seven strategies with a fallback strategy: viewed together, bought together, similar, personalized, customers like you, most viewed, best sellers.
* Compatible with page caches: blocks are loaded after the page and the views are reported by the browser.
* No slowdown: events go through a local queue and are sent after the response, in batches.
* Resilient: an unavailable API or a suspended subscription never breaks the shop.
* Dashboard: API health, activity, click-through rate per block, event queue, rankings.
* Privacy: pseudonymous identifiers only, privacy policy text and personal data eraser.
* WP-CLI commands, HPOS, block themes, multisite.

An Injellik licence key is required: [web-nest.fr](https://web-nest.fr/#tarifs).

== External services ==

This plugin sends data to the Injellik API (https://injellik.web-nest.fr by default), operated by Web-Nest, to compute the recommendations:

* the products of the catalog (id, name, short description, price, GTIN, categories, tags, brand), when they are saved and every night;
* the actions of the visitors (product viewed, added to cart, ordered, rated, recommendation clicked) with pseudonymous identifiers: a random visitor id, a hash of the customer account or of the e-mail of a guest order, and a visit id. Names, e-mail addresses, postal addresses and IP addresses are never sent.

Terms and privacy policy: https://web-nest.fr

== Installation ==

1. Upload the zip in Plugins > Add New > Upload Plugin, and activate it.
2. Open the Injellik menu: the wizard starts.
3. Paste the licence key of the shop, synchronise the catalog, import the order history and choose the blocks.

== Frequently Asked Questions ==

= When do the recommendations appear? =

Tracking starts immediately. Injellik trains its models every night; until then the blocks use their fallback strategy (best sellers, similar products...).

= How do I add a block to a page? =

Insert the "Injellik recommendations" block, or the shortcode `[injellik_recommendations placement="home"]`.

= Can I change the HTML of the blocks? =

Copy `templates/recommendations.php` to `yourtheme/injellik-recommendations/recommendations.php`.

== Changelog ==

= 1.0.0 =
* Initial version.
