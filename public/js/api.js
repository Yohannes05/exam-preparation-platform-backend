/**
 * httpFetch — session-authenticated fetch for the Laravel admin UI.
 *
 * Behaves like fetch() but:
 *  - sends the CSRF token header + JSON headers (session cookie auth),
 *  - parses the JSON body once (response.json() still works),
 *  - redirects to /login on 401,
 *  - throws an Error for non-2xx responses; `err.errors` holds Laravel's
 *    field => messages validation map and `err.status` the HTTP status.
 *
 * showToast(kind, message) renders a small transient toast in .toast-holder.
 */
(function () {
  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  function httpFetch(url, options) {
    options = options || {};

    var headers = Object.assign(
      {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken(),
      },
      options.headers || {}
    );

    if (options.body && typeof FormData !== 'undefined' && options.body instanceof FormData) {
      // Let the browser set the multipart boundary for file uploads.
      delete headers['Content-Type'];
    } else if (options.body) {
      if (typeof options.body !== 'string') {
        options.body = JSON.stringify(options.body);
      }
      if (!headers['Content-Type']) headers['Content-Type'] = 'application/json';
    }

    return fetch(
      url,
      Object.assign({ credentials: 'same-origin' }, options, { headers: headers })
    ).then(function (res) {
      if (res.status === 401) {
        window.location.href = '/login';
        throw new Error('Session expired.');
      }

      var contentType = res.headers.get('content-type') || '';
      var parsed =
        contentType.indexOf('application/json') !== -1
          ? res.json().catch(function () { return {}; })
          : Promise.resolve({});

      return parsed.then(function (data) {
        if (!res.ok) {
          var err = new Error(
            (data && (data.message || data.error)) ||
              'Request failed (' + res.status + ').'
          );
          err.status = res.status;
          err.errors = (data && data.errors) || null;
          throw err;
        }

        return {
          ok: res.ok,
          status: res.status,
          headers: res.headers,
          json: function () { return Promise.resolve(data); },
        };
      });
    });
  }

  function showToast(kind, message) {
    var host = document.querySelector('.toast-holder');
    if (!host) {
      host = document.createElement('div');
      host.className = 'toast-holder';
      document.body.appendChild(host);
    }
    var el = document.createElement('div');
    el.className = 'toast toast-' + (kind || 'info');
    el.textContent = message;
    host.appendChild(el);
    setTimeout(function () {
      el.style.transition = 'opacity .3s ease, transform .3s ease';
      el.style.opacity = '0';
      el.style.transform = 'translateY(8px)';
      setTimeout(function () { el.remove(); }, 320);
    }, 3000);
  }

  window.httpFetch = httpFetch;
  window.showToast = showToast;
})();
