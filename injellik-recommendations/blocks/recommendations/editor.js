/**
 * Injellik recommendations block: settings in the sidebar, placeholder in the editor.
 * The recommendations depend on the visitor: they are only rendered on the site.
 */
(function (blocks, element, blockEditor, components, i18n) {
  'use strict';

  var el = element.createElement;
  var __ = i18n.__;
  var InspectorControls = blockEditor.InspectorControls;
  var useBlockProps = blockEditor.useBlockProps;

  var placements = [
    { value: 'custom', label: __('Block and shortcode settings', 'injellik-recommendations') },
    { value: 'home', label: __('Home page', 'injellik-recommendations') },
    { value: 'product', label: __('Product page', 'injellik-recommendations') },
    { value: 'cart', label: __('Cart', 'injellik-recommendations') },
    { value: 'order_confirmation', label: __('Order confirmation', 'injellik-recommendations') },
    { value: 'category', label: __('Shop and category pages', 'injellik-recommendations') }
  ];

  var strategies = [
    { value: 'viewed', label: __('Viewed together', 'injellik-recommendations') },
    { value: 'bought', label: __('Bought together', 'injellik-recommendations') },
    { value: 'similar', label: __('Similar products', 'injellik-recommendations') },
    { value: 'personalized', label: __('Personalized', 'injellik-recommendations') },
    { value: 'predictive', label: __('Customers like you', 'injellik-recommendations') },
    { value: 'most_viewed', label: __('Most viewed', 'injellik-recommendations') },
    { value: 'most_bought', label: __('Best sellers', 'injellik-recommendations') }
  ];

  var fromPlacement = __('Placement setting', 'injellik-recommendations');

  blocks.registerBlockType('injellik/recommendations', {
    edit: function (props) {
      var a = props.attributes;
      var set = props.setAttributes;
      var placement = placements.filter(function (p) { return p.value === a.placement; })[0];
      var strategy = strategies.filter(function (s) { return s.value === a.strategy; })[0];

      return el('div', useBlockProps(),
        el(InspectorControls, null,
          el(components.PanelBody, { title: __('Recommendations', 'injellik-recommendations') },
            el(components.SelectControl, {
              label: __('Placement', 'injellik-recommendations'),
              help: __('The block uses the settings of this placement (Injellik > Settings > Blocks), unless you change them below.', 'injellik-recommendations'),
              value: a.placement,
              options: placements,
              onChange: function (value) { set({ placement: value }); }
            }),
            el(components.SelectControl, {
              label: __('Strategy', 'injellik-recommendations'),
              value: a.strategy,
              options: [{ value: '', label: fromPlacement }].concat(strategies),
              onChange: function (value) { set({ strategy: value }); }
            }),
            el(components.SelectControl, {
              label: __('Fallback', 'injellik-recommendations'),
              value: a.fallback,
              options: [{ value: 'default', label: fromPlacement }, { value: 'none', label: __('None', 'injellik-recommendations') }].concat(strategies),
              onChange: function (value) { set({ fallback: value }); }
            }),
            el(components.RangeControl, {
              label: __('Products', 'injellik-recommendations'),
              help: __('0: setting of the placement.', 'injellik-recommendations'),
              value: a.limit,
              min: 0,
              max: 24,
              onChange: function (value) { set({ limit: value || 0 }); }
            }),
            el(components.TextControl, {
              label: __('Title', 'injellik-recommendations'),
              value: a.title,
              placeholder: fromPlacement,
              onChange: function (value) { set({ title: value }); }
            }),
            el(components.TextControl, {
              label: __('Source products (ids)', 'injellik-recommendations'),
              help: __('Optional, separated by commas. By default: the product displayed, or the products of the cart or of the order.', 'injellik-recommendations'),
              value: a.productIds,
              onChange: function (value) { set({ productIds: value.replace(/[^0-9,]/g, '') }); }
            })
          )
        ),
        el(components.Placeholder, {
          icon: 'chart-area',
          label: a.title || __('Injellik recommendations', 'injellik-recommendations'),
          instructions: [
            placement ? placement.label : a.placement,
            strategy ? strategy.label : null,
            a.limit ? a.limit + ' ' + __('products', 'injellik-recommendations') : null
          ].filter(Boolean).join(' · ')
        },
          el('p', { className: 'components-placeholder__instructions' },
            __('The recommended products depend on each visitor: they are displayed on the site.', 'injellik-recommendations'))
        )
      );
    },
    save: function () {
      return null;
    }
  });
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n);
