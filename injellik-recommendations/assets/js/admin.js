/**
 * Injellik Recommendations for WooCommerce — back office.
 *
 * - connection test and switch between the licence key and the API login;
 * - batched jobs with a progress bar (catalog synchronisation, order import, queue flush):
 *   the server answers {ok, done, done_count, total, message, after_id?} and the job calls it again until done;
 * - tabs of the settings page, placement cards, copy buttons.
 */
(function () {
  'use strict';

  var settings = window.injellikAdmin || {};

  function post(action, params) {
    var body = new FormData();
    body.append('action', action);
    body.append('_ajax_nonce', settings.nonce || '');
    Object.keys(params || {}).forEach(function (key) { body.append(key, params[key]); });

    return fetch(settings.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (response) {
        return response.json().catch(function () {
          return { ok: false, message: 'HTTP ' + response.status };
        });
      });
  }

  /* Connection form: licence key (default) or login and password, and connection test */
  function initConnection(form) {
    var mode = form.querySelector('.js-injellik-auth-mode');
    var result = form.querySelector('.js-injellik-test-result');

    function showResult(ok, text) {
      result.hidden = false;
      result.className = 'notice inline injellik-test-result js-injellik-test-result ' + (ok ? 'notice-success' : 'notice-error');
      result.querySelector('p').textContent = text;
    }

    form.querySelectorAll('.js-injellik-auth-switch').forEach(function (link) {
      link.addEventListener('click', function (event) {
        event.preventDefault();
        var selected = link.getAttribute('data-mode');
        mode.value = selected;
        // Only the fields of the selected mode are posted.
        form.querySelectorAll('.js-injellik-auth').forEach(function (section) {
          var active = section.getAttribute('data-mode') === selected;
          section.hidden = !active;
          section.querySelectorAll('input').forEach(function (input) { input.disabled = !active; });
        });
        form.querySelectorAll('.js-injellik-auth-switch').forEach(function (other) {
          other.hidden = other.getAttribute('data-mode') === selected;
        });
        result.hidden = true;
        var first = form.querySelector('.js-injellik-auth[data-mode="' + selected + '"] input');
        if (first) {
          first.focus();
        }
      });
    });

    var button = form.querySelector('.js-injellik-test');
    if (!button) {
      return;
    }
    button.addEventListener('click', function () {
      var label = button.textContent;
      var params = { auth_mode: mode.value };
      form.querySelectorAll('.js-injellik-auth input').forEach(function (input) {
        if (!input.disabled) {
          params[input.name] = input.value;
        }
      });
      button.disabled = true;
      button.textContent = button.getAttribute('data-loading');
      post('injellik_test_connection', params).then(function (data) {
        showResult(data.ok, data.message);
      }).catch(function (error) {
        showResult(false, String(error));
      }).then(function () {
        button.disabled = false;
        button.textContent = label;
      });
    });
  }

  /* Batched jobs */
  function initJob(job) {
    var button = job.querySelector('.js-injellik-job-start');
    var bar = job.querySelector('.js-injellik-job-bar');
    var message = job.querySelector('.js-injellik-job-message');
    var action = job.getAttribute('data-action');
    var running = false;

    function setProgress(done, total) {
      if (!bar) {
        return;
      }
      var percent = total > 0 ? Math.min(100, Math.round(100 * done / total)) : 100;
      bar.style.width = percent + '%';
      bar.textContent = percent > 8 ? percent + ' %' : '';
    }

    function finish(ok, text) {
      running = false;
      job.classList.remove('is-running');
      if (button) {
        button.disabled = false;
      }
      if (message) {
        message.textContent = text || '';
        message.className = 'injellik-job__message js-injellik-job-message ' + (ok ? 'is-success' : 'is-error');
      }
      if (bar) {
        bar.classList.toggle('is-error', !ok);
        bar.classList.toggle('is-success', ok);
      }
      if (ok) {
        var next = document.querySelector('.js-injellik-next');
        if (next) {
          next.classList.remove('disabled');
        }
        if (job.getAttribute('data-reload')) {
          window.setTimeout(function () { window.location.reload(); }, 900);
        }
      }
    }

    function step(state) {
      var params = { done_count: state.done_count || 0, after_id: state.after_id || 0 };
      job.querySelectorAll('.js-injellik-job-param').forEach(function (input) { params[input.name] = input.value; });

      post(action, params).then(function (data) {
        if (!data.ok) {
          finish(false, data.message);
          return;
        }
        setProgress(data.done_count || 0, data.total || 0);
        if (message) {
          message.textContent = data.message || '';
        }
        if (data.done) {
          setProgress(1, 1);
          finish(true, data.message);
        } else {
          step(data);
        }
      }).catch(function (error) {
        finish(false, String(error));
      });
    }

    function start() {
      if (running) {
        return;
      }
      running = true;
      job.classList.add('is-running');
      if (button) {
        button.disabled = true;
      }
      if (bar) {
        bar.classList.remove('is-error', 'is-success');
      }
      if (message) {
        message.className = 'injellik-job__message js-injellik-job-message';
      }
      setProgress(0, 1);
      step({});
    }

    if (button) {
      button.addEventListener('click', start);
    }
    if (job.getAttribute('data-autostart')) {
      start();
    }
  }

  /* Disabled "Continue" buttons of the wizard */
  function initNext(link) {
    link.addEventListener('click', function (event) {
      if (link.classList.contains('disabled')) {
        event.preventDefault();
      }
    });
  }

  /* Placement cards: collapse the settings of the disabled blocks */
  function initPlacement(card) {
    var toggle = card.querySelector('.js-injellik-placement-toggle');
    if (toggle) {
      toggle.addEventListener('change', function () {
        card.classList.toggle('is-enabled', toggle.checked);
      });
    }
  }

  function initCopy(button) {
    button.addEventListener('click', function () {
      var input = button.closest('.injellik-copy').querySelector('.js-injellik-copy-source');
      input.select();
      if (navigator.clipboard) {
        navigator.clipboard.writeText(input.value);
      } else {
        document.execCommand('copy');
      }
      var label = button.textContent;
      button.textContent = settings.copied || 'OK';
      window.setTimeout(function () { button.textContent = label; }, 1500);
    });
  }

  /* Tabs of the settings page, kept in the URL hash */
  function initTabs() {
    var links = document.querySelectorAll('.injellik-tabs .nav-tab');
    if (!links.length) {
      return;
    }

    function show(id) {
      var found = false;
      links.forEach(function (link) {
        var active = link.getAttribute('data-tab') === id;
        found = found || active;
        link.classList.toggle('nav-tab-active', active);
        var panel = document.getElementById('injellik-tab-' + link.getAttribute('data-tab'));
        if (panel) {
          panel.hidden = !active;
        }
      });
      return found;
    }

    links.forEach(function (link) {
      link.addEventListener('click', function (event) {
        event.preventDefault();
        show(link.getAttribute('data-tab'));
        if (window.history && window.history.replaceState) {
          window.history.replaceState(null, '', '#' + link.getAttribute('data-tab'));
        }
      });
    });

    function fromHash() {
      if (!show(window.location.hash.replace('#', ''))) {
        show(links[0].getAttribute('data-tab'));
      }
    }
    window.addEventListener('hashchange', fromHash);
    fromHash();
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.js-injellik-connection').forEach(initConnection);
    document.querySelectorAll('.js-injellik-job').forEach(initJob);
    document.querySelectorAll('.js-injellik-next').forEach(initNext);
    document.querySelectorAll('.js-injellik-placement').forEach(initPlacement);
    document.querySelectorAll('.js-injellik-copy').forEach(initCopy);
    initTabs();
  });
})();
