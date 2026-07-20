(function (window, document) {
  'use strict';

  var API = '/plugins/docker.dns/include/Api.php';
  var MARKER = '__dockerDnsWrapped';
  var HOST_ADAPTER_MARKER = '__fvplusHostAdapterHook';
  var urlMap = Object.create(null);
  var warningSent = false;
  var startedAt = Date.now();

  function validUrl(value) {
    try {
      var parsed = new URL(value);
      return (parsed.protocol === 'http:' || parsed.protocol === 'https:') &&
        /(?:^|\.)home\.arpa$/i.test(parsed.hostname) && !parsed.username && !parsed.password;
    } catch (error) {
      return false;
    }
  }

  function csrf() {
    return typeof window.csrf_token === 'string' ? window.csrf_token : '';
  }

  function api(payload) {
    var token = csrf();
    var body = new URLSearchParams(Object.assign({}, payload, {
      csrf_token: token,
      docker_dns_csrf_token: token
    }));
    return window.fetch(API, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body: body.toString()
    }).then(function (response) {
      return response.json().then(function (body) {
        if (!response.ok || body.ok === false) throw new Error(body.error || 'Docker DNS API request failed.');
        return body;
      });
    });
  }

  function warn(message) {
    if (warningSent) return;
    warningSent = true;
    if (window.console && console.warn) console.warn('Docker DNS: ' + message);
    api({action: 'integration-warning', message: message}).catch(function () {});
  }

  function refreshUrls() {
    return window.fetch(API + '?action=context-urls', {credentials: 'same-origin', cache: 'no-store'})
      .then(function (response) {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
      })
      .then(function (body) {
        urlMap = body && body.containers && typeof body.containers === 'object'
          ? body.containers : Object.create(null);
        return urlMap;
      })
      .catch(function (error) {
        if (window.console && console.debug) console.debug('Docker DNS URL refresh failed:', error);
        return urlMap;
      });
  }

  function addOption(options, container, started) {
    var url = urlMap[container];
    if (!started || !validUrl(url)) return options.slice();
    var clone = options.slice();
    var option = {
      text: typeof window._ === 'function' ? window._('Docker DNS WebUI') : 'Docker DNS WebUI',
      icon: 'fa-globe',
      action: function (event) {
        if (event && event.preventDefault) event.preventDefault();
        var opened = window.open(url, '_blank', 'noopener');
        if (opened) opened.opener = null;
      }
    };
    var firstDivider = clone.findIndex(function (item) { return item && item.divider; });
    clone.splice(firstDivider < 0 ? clone.length : firstDivider, 0, option);
    return clone;
  }

  function preserveHostAdapterMetadata(original, wrapped) {
    var descriptor;
    try {
      descriptor = Object.getOwnPropertyDescriptor(original, HOST_ADAPTER_MARKER);
    } catch (error) {
      return;
    }
    if (!descriptor) return;
    try {
      Object.defineProperty(wrapped, HOST_ADAPTER_MARKER, descriptor);
    } catch (error) {}
  }

  function installWrapper() {
    if (typeof window.addDockerContainerContext !== 'function' ||
        !window.context || typeof window.context.attach !== 'function') return false;
    if (window.addDockerContainerContext[MARKER]) return true;

    var original = window.addDockerContainerContext;
    function wrapped() {
      var args = Array.prototype.slice.call(arguments);
      var container = String(args[0] || '');
      var started = !!args[3];
      var attach = window.context && window.context.attach;
      if (typeof attach !== 'function') {
        warn('context.attach is no longer available; the standard Docker menu was left unchanged.');
        return original.apply(this, args);
      }
      window.context.attach = function (selector, options) {
        try {
          if (!Array.isArray(options)) return attach.apply(this, arguments);
          return attach.call(this, selector, addOption(options, container, started));
        } catch (error) {
          warn('Menu decoration failed: ' + error.message);
          return attach.call(this, selector, options);
        }
      };
      try {
        return original.apply(this, args);
      } finally {
        if (window.context) window.context.attach = attach;
      }
    }
    wrapped[MARKER] = true;
    wrapped.__dockerDnsOriginal = original;
    // FolderView Plus may inspect and rebind this hook after Docker DNS loads.
    // Preserve its ownership metadata so it does not capture this wrapper as a
    // new original and create a recursive FolderView -> Docker DNS -> FolderView
    // call chain.
    preserveHostAdapterMetadata(original, wrapped);
    window.addDockerContainerContext = wrapped;
    try {
      if (window.sessionStorage.getItem('dockerDnsCompatibilityCleared') !== '2026.07.20') {
        api({action: 'integration-warning', message: ''}).catch(function () {});
        window.sessionStorage.setItem('dockerDnsCompatibilityCleared', '2026.07.20');
      }
    } catch (error) {}
    return true;
  }

  function installWhenReady() {
    if (installWrapper()) return;
    if (Date.now() - startedAt > 15000) {
      warn('Unraid Docker menu interfaces were not detected; plugin URLs remain available in Settings.');
      return;
    }
    window.setTimeout(installWhenReady, 100);
  }

  function relevantPage() {
    var path = window.location.pathname;
    return /(?:Docker|Dashboard)/i.test(path) && !/(?:Add|Update)Container/i.test(path);
  }

  document.addEventListener('docker-dns:url-saved', refreshUrls);
  document.addEventListener('DOMContentLoaded', function () {
    refreshUrls();
    if (relevantPage()) installWhenReady();
    if (window.jQuery) {
      window.jQuery(document).ajaxComplete(function (_event, _xhr, settings) {
        if (settings && /DockerContainers\.php|dashboard/i.test(String(settings.url || ''))) refreshUrls();
      });
    }
    window.setInterval(function () {
      if (relevantPage() && !document.hidden) refreshUrls();
    }, 30000);
  });
})(window, document);
