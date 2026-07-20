(function (window, document) {
  'use strict';

  var API = '/plugins/docker.dns/include/Api.php';
  var root = document.getElementById('docker-dns-settings');
  if (!root) return;
  var list = document.getElementById('docker-dns-containers');
  var count = document.getElementById('docker-dns-container-count');
  var status = document.getElementById('docker-dns-status');
  var providerForm = document.getElementById('docker-dns-provider-form');
  var settingsDirty = false;

  function csrf() { return typeof window.csrf_token === 'string' ? window.csrf_token : ''; }
  function escapeHtml(value) {
    var node = document.createElement('div');
    node.textContent = value == null ? '' : String(value);
    return node.innerHTML;
  }
  function escapeAttribute(value) {
    return escapeHtml(value).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function api(payload) {
    var token = csrf();
    var body = new URLSearchParams(Object.assign({}, payload, {
      csrf_token: token,
      docker_dns_csrf_token: token
    }));
    return window.fetch(API, {method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}, body: body.toString()}).then(function (response) {
      return response.json().then(function (result) {
        if (!response.ok || result.ok === false) throw new Error(result.error || 'Request failed.');
        return result;
      });
    });
  }
  function providerPayload(action) {
    return {
      action: action,
      enabled: document.getElementById('docker-dns-enabled').value === 'true',
      provider: document.getElementById('docker-dns-provider').value,
      base_url: document.getElementById('docker-dns-base-url').value,
      username: document.getElementById('docker-dns-username').value,
      password: document.getElementById('docker-dns-password').value,
      verify_tls: document.getElementById('docker-dns-verify-tls').value === 'true',
      host_ipv4_override: document.getElementById('docker-dns-host-ip').value,
      timeout_seconds: document.getElementById('docker-dns-timeout').value
    };
  }
  function setBusy(busy) {
    root.querySelectorAll('button').forEach(function (button) { button.disabled = busy; });
  }
  function message(text, error) {
    status.textContent = text;
    status.classList.toggle('docker-dns-error', !!error);
  }
  function formatPorts(ports) {
    return (ports || []).map(function (p) { return p.public + '→' + p.private + '/' + p.protocol; }).join(', ');
  }
  function dnsStatusClass(value) {
    value = String(value || '').toLowerCase();
    if (value === 'synchronized') return ' is-success';
    if (value === 'pending' || value === 'excluded') return ' is-neutral';
    return ' is-warning';
  }
  function automaticUrl(url) {
    if (!url) return '<span class="docker-dns-muted">No automatic TCP URL</span>';
    var safeUrl = escapeHtml(url);
    var safeAttribute = escapeAttribute(url);
    return '<a href="' + safeAttribute + '" target="_blank" rel="noopener" title="' + safeAttribute + '">' +
      safeUrl + '<span class="docker-dns-external" aria-hidden="true">↗</span></a>';
  }
  function renderSettings(data) {
    var settings = data.settings;
    document.getElementById('docker-dns-enabled').value = String(!!settings.enabled);
    document.getElementById('docker-dns-provider').value = settings.provider;
    document.getElementById('docker-dns-base-url').value = settings.base_url || '';
    document.getElementById('docker-dns-username').value = data.credentials.username || '';
    document.getElementById('docker-dns-password').value = '';
    document.getElementById('docker-dns-password').placeholder = data.credentials.password_set ? 'Stored; leave empty to keep it' : 'Required';
    document.getElementById('docker-dns-verify-tls').value = String(!!settings.verify_tls);
    document.getElementById('docker-dns-host-ip').value = settings.host_ipv4_override || '';
    document.getElementById('docker-dns-timeout').value = settings.timeout_seconds || 10;
    toggleProvider();
  }
  function render(data, includeSettings) {
    if (includeSettings && !settingsDirty) renderSettings(data);
    var state = data.state;
    var summary = 'Last sync: ' + (state.last_sync || 'never') + '\nLast success: ' + (state.last_success || 'never');
    if (state.last_error) summary += '\nError: ' + state.last_error;
    if (state.integration_warning) summary += '\nMenu integration: ' + state.integration_warning;
    message(summary, !!state.last_error);
    var containers = Object.keys(state.containers || {}).map(function (name) { return state.containers[name]; });
    count.textContent = containers.length + (containers.length === 1 ? ' container' : ' containers');
    if (!containers.length) {
      list.innerHTML = '<p class="docker-dns-empty">No containers with explicit published ports were discovered.</p>';
      return;
    }
    list.innerHTML = containers.map(function (container) {
      var url = container.url_override || '';
      var target = container.target_ipv4 || '';
      var targetHint = target ? 'Current target: ' + target + ' · ' + container.target_status : container.target_status;
      var ports = formatPorts(container.ports);
      return '<article class="docker-dns-container' + (container.running ? '' : ' is-stopped') + '" data-container="' + escapeAttribute(container.name) + '">' +
        '<div class="docker-dns-container-summary">' +
          '<div class="docker-dns-container-title">' +
            '<label class="docker-dns-include-label" title="Include this container in DNS synchronization">' +
              '<input class="docker-dns-include" type="checkbox" ' + (container.included ? 'checked' : '') + '>' +
              '<span>' + escapeHtml(container.name) + '</span>' +
            '</label>' +
            '<span class="docker-dns-badge' + dnsStatusClass(container.dns_status) + '">' + escapeHtml(container.dns_status) + '</span>' +
          '</div>' +
          '<div class="docker-dns-container-meta">' +
            '<span title="Hostname">' + escapeHtml(container.hostname) + '</span>' +
            '<span class="docker-dns-ports" title="Published ports: ' + escapeAttribute(ports) + '">' + escapeHtml(ports) + '</span>' +
            (container.running ? '' : '<span class="docker-dns-stopped">Stopped</span>') +
          '</div>' +
        '</div>' +
        '<label class="docker-dns-field docker-dns-ip-field">' +
          '<span>Target IPv4 override</span>' +
          '<input class="docker-dns-ip" type="text" inputmode="decimal" value="' + escapeAttribute(container.target_status === 'override' ? target : '') + '" placeholder="Automatic">' +
          '<small title="' + escapeAttribute(targetHint) + '">' + escapeHtml(targetHint) + '</small>' +
        '</label>' +
        '<label class="docker-dns-field docker-dns-url-field">' +
          '<span>Web UI URL override</span>' +
          '<input class="docker-dns-override" type="url" value="' + escapeAttribute(url) + '" placeholder="Use automatic URL">' +
          '<small>Automatic: ' + automaticUrl(container.automatic_url) + '</small>' +
        '</label>' +
        '<button type="button" class="docker-dns-save-container">Save</button>' +
      '</article>';
    }).join('');
  }
  function load(includeSettings) {
    return window.fetch(API + '?action=status', {credentials: 'same-origin', cache: 'no-store'})
      .then(function (response) { return response.json(); })
      .then(function (data) { if (data.ok === false) throw new Error(data.error); render(data, !!includeSettings); })
      .catch(function (error) { message(error.message, true); });
  }
  function perform(payload, success, savesSettings) {
    setBusy(true);
    message('Working…', false);
    return api(payload).then(function (result) {
      message(success, false);
      document.dispatchEvent(new CustomEvent('docker-dns:url-saved'));
      if (savesSettings) {
        settingsDirty = false;
        if (result.status) renderSettings(result.status);
      }
      return load(savesSettings && !result.status);
    }).catch(function (error) { message(error.message, true); }).finally(function () { setBusy(false); });
  }
  function toggleProvider() {
    document.getElementById('docker-dns-username-row').style.display =
      document.getElementById('docker-dns-provider').value === 'adguard' ? '' : 'none';
  }
  providerForm.addEventListener('input', function () { settingsDirty = true; });
  providerForm.addEventListener('change', function () { settingsDirty = true; });
  document.getElementById('docker-dns-provider').addEventListener('change', toggleProvider);
  document.getElementById('docker-dns-save-settings').addEventListener('click', function () { perform(providerPayload('save-settings'), 'Settings saved.', true); });
  document.getElementById('docker-dns-test').addEventListener('click', function () { perform(providerPayload('test-connection'), 'Connection succeeded.'); });
  document.getElementById('docker-dns-sync').addEventListener('click', function () { perform({action: 'sync-now'}, 'Synchronization completed.'); });
  document.getElementById('docker-dns-cleanup').addEventListener('click', function () {
    if (window.confirm('Remove every DNS hostname currently managed by Docker DNS?')) perform({action: 'cleanup-all'}, 'Managed DNS records removed.');
  });
  list.addEventListener('click', function (event) {
    if (!event.target.classList.contains('docker-dns-save-container')) return;
    var row = event.target.closest('.docker-dns-container');
    perform({action: 'set-container', container_name: row.dataset.container,
      included: row.querySelector('.docker-dns-include').checked,
      target_ipv4_override: row.querySelector('.docker-dns-ip').value,
      url_override: row.querySelector('.docker-dns-override').value}, 'Container settings saved.');
  });
  load(true);
})(window, document);
