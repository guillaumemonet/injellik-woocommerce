/**
 * Injellik Recommendations for WooCommerce.
 *
 * - reports the view of the product page (VIEW), so that the tracking also works behind a page cache;
 * - loads the recommendation blocks when they come into view;
 * - reports the clicks on recommended products (CLICK action + block statistics).
 *
 * Event dispatched on document: "injellik:loaded" (detail: {element, placement}) after a block is inserted.
 */
(function () {
  'use strict';

  var config = window.injellik || {};

  function beacon(data) {
    if (!config.trackUrl) {
      return;
    }
    var body = new FormData();
    Object.keys(data).forEach(function (key) { body.append(key, data[key]); });
    if (navigator.sendBeacon && navigator.sendBeacon(config.trackUrl, body)) {
      return;
    }
    fetch(config.trackUrl, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true }).catch(function () {});
  }

  function load(placeholder) {
    if (placeholder.getAttribute('data-injellik-state')) {
      return;
    }
    placeholder.setAttribute('data-injellik-state', 'loading');

    var body = new FormData();
    body.append('payload', placeholder.getAttribute('data-injellik-payload') || '');
    body.append('signature', placeholder.getAttribute('data-injellik-signature') || '');

    function remove() {
      if (placeholder.parentNode) {
        placeholder.parentNode.removeChild(placeholder);
      }
    }

    fetch(config.renderUrl, {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    })
      .then(function (response) { return response.ok ? response.json() : { html: '' }; })
      .then(function (data) {
        if (!data || !data.html) {
          remove();
          return;
        }
        var wrapper = document.createElement('div');
        wrapper.innerHTML = data.html;
        var block = wrapper.firstElementChild;
        if (!block || !placeholder.parentNode) {
          remove();
          return;
        }
        placeholder.parentNode.replaceChild(block, placeholder);
        document.dispatchEvent(new CustomEvent('injellik:loaded', {
          detail: { element: block, placement: block.getAttribute('data-injellik-placement') }
        }));
        // Themes and plugins listening to the WooCommerce product lists (wishlists, quick view...).
        if (window.jQuery) {
          window.jQuery(document.body).trigger('injellik_loaded', [block]);
        }
      })
      .catch(remove);
  }

  function scan(root) {
    var placeholders = (root && root.querySelectorAll ? root : document).querySelectorAll('.js-injellik-reco:not([data-injellik-state])');
    if (!placeholders.length || !config.renderUrl) {
      return;
    }
    if (!('IntersectionObserver' in window)) {
      Array.prototype.forEach.call(placeholders, load);
      return;
    }
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          observer.unobserve(entry.target);
          load(entry.target);
        }
      });
    }, { rootMargin: '300px 0px' });
    Array.prototype.forEach.call(placeholders, function (placeholder) { observer.observe(placeholder); });
  }

  /**
   * Product id of a card of the WooCommerce loop: "post-<id>" class, or the add to cart button.
   */
  function productId(card) {
    var match = /(?:^|\s)post-(\d+)(?:\s|$)/.exec(card.className || '');
    if (match) {
      return match[1];
    }
    var button = card.querySelector('[data-product_id]');
    return button ? button.getAttribute('data-product_id') : '';
  }

  function trackClick(event) {
    var link = event.target.closest ? event.target.closest('a[href]') : null;
    var block = link && link.closest('.injellik-reco[data-injellik-placement]');
    var card = block && link.closest('.product, li');
    var id = card ? productId(card) : '';
    // The add to cart buttons are not clicks on a recommendation: ADDCART is recorded by the shop.
    if (!id || link.classList.contains('add_to_cart_button') || link.classList.contains('ajax_add_to_cart')) {
      return;
    }
    beacon({ type: 'click', product_id: id, placement: block.getAttribute('data-injellik-placement') });
  }

  function init() {
    if (config.view) {
      beacon({ type: 'view', product_id: config.view });
    }
    scan(document);
    document.addEventListener('click', trackClick, true);
    // Content replaced by WooCommerce (cart update...).
    if (window.jQuery) {
      window.jQuery(document.body).on('updated_wc_div updated_cart_totals wc_fragments_refreshed', function () {
        setTimeout(scan, 0);
      });
    }
  }

  window.injellikScan = scan;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
