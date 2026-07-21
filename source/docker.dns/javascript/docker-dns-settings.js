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
  var proxyCandidates = [];

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
    var mount = document.getElementById('docker-dns-proxy-mount').value;
    var mountParts = mount ? JSON.parse(mount) : {source: '', destination: ''};
    return {
      action: action,
      enabled: document.getElementById('docker-dns-enabled').value === 'true',
      provider: document.getElementById('docker-dns-provider').value,
      base_url: document.getElementById('docker-dns-base-url').value,
      username: document.getElementById('docker-dns-username').value,
      password: document.getElementById('docker-dns-password').value,
      verify_tls: document.getElementById('docker-dns-verify-tls').value === 'true',
      host_ipv4_override: document.getElementById('docker-dns-host-ip').value,
      timeout_seconds: document.getElementById('docker-dns-timeout').value,
      proxy_enabled: document.getElementById('docker-dns-proxy-enabled').value === 'true',
      proxy_adapter: document.getElementById('docker-dns-proxy-adapter').value,
      proxy_container: document.getElementById('docker-dns-proxy-container').value,
      proxy_network: document.getElementById('docker-dns-proxy-network').value,
      proxy_mount_source: mountParts.source,
      proxy_mount_destination: mountParts.destination,
      caddy_main_config: document.getElementById('docker-dns-caddy-config').value,
      traefik_entrypoint: document.getElementById('docker-dns-traefik-entrypoint').value
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
    proxyCandidates = data.proxy_candidates || [];
    document.getElementById('docker-dns-proxy-enabled').value = String(!!settings.proxy_enabled);
    document.getElementById('docker-dns-proxy-adapter').value = settings.proxy_adapter || 'caddy';
    var containerSelect = document.getElementById('docker-dns-proxy-container');
    containerSelect.innerHTML = '<option value="">Select a container</option>' + proxyCandidates.map(function (candidate) {
      return '<option value="' + escapeAttribute(candidate.name) + '">' + escapeHtml(candidate.name) + (candidate.running ? '' : ' (stopped)') + '</option>';
    }).join('');
    if (settings.proxy_container && !proxyCandidates.some(function (item) { return item.name === settings.proxy_container; })) {
      containerSelect.innerHTML += '<option value="' + escapeAttribute(settings.proxy_container) + '">' + escapeHtml(settings.proxy_container) + ' (missing)</option>';
    }
    containerSelect.value = settings.proxy_container || '';
    updateProxyChoices(settings.proxy_network || '', settings.proxy_mount_source || '', settings.proxy_mount_destination || '');
    document.getElementById('docker-dns-caddy-config').value = settings.caddy_main_config || '/etc/caddy/Caddyfile';
    document.getElementById('docker-dns-traefik-entrypoint').value = settings.traefik_entrypoint || 'web';
    toggleProvider();
    toggleProxyAdapter();
  }
  function selectedProxyCandidate() {
    var name = document.getElementById('docker-dns-proxy-container').value;
    return proxyCandidates.find(function (candidate) { return candidate.name === name; }) || {networks: [], mounts: []};
  }
  function updateProxyChoices(selectedNetwork, selectedSource, selectedDestination) {
    var candidate = selectedProxyCandidate();
    var network = document.getElementById('docker-dns-proxy-network');
    network.innerHTML = '<option value="">Select a static-IP network</option>' + (candidate.networks || []).map(function (item) {
      var label = item.name + (item.ipv4 ? ' · ' + item.ipv4 : '') + (item.static_ipv4 ? '' : ' · dynamic');
      return '<option value="' + escapeAttribute(item.name) + '">' + escapeHtml(label) + '</option>';
    }).join('');
    if (selectedNetwork && !(candidate.networks || []).some(function (item) { return item.name === selectedNetwork; })) {
      network.innerHTML += '<option value="' + escapeAttribute(selectedNetwork) + '">' + escapeHtml(selectedNetwork) + ' (unavailable)</option>';
    }
    network.value = selectedNetwork || '';
    var mount = document.getElementById('docker-dns-proxy-mount');
    mount.innerHTML = '<option value="">Select a writable bind mount</option>' + (candidate.mounts || []).map(function (item) {
      var value = JSON.stringify({source: item.source, destination: item.destination});
      return '<option value="' + escapeAttribute(value) + '" ' + (item.writable ? '' : 'disabled') + '>' + escapeHtml(item.source + ' → ' + item.destination + (item.writable ? '' : ' · read-only')) + '</option>';
    }).join('');
    var selected = selectedSource ? JSON.stringify({source: selectedSource, destination: selectedDestination}) : '';
    if (selected && !(candidate.mounts || []).some(function (item) { return item.source === selectedSource && item.destination === selectedDestination; })) {
      mount.innerHTML += '<option value="' + escapeAttribute(selected) + '">' + escapeHtml(selectedSource + ' → ' + selectedDestination + ' (unavailable)') + '</option>';
    }
    mount.value = selected;
    updateMountHelp();
  }
  function updateMountHelp() {
    var value = document.getElementById('docker-dns-proxy-mount').value;
    var help = document.getElementById('docker-dns-proxy-mount-help');
    if (!value) { help.textContent = ''; return; }
    var mount = JSON.parse(value);
    help.textContent = 'Generated configuration: ' + mount.destination.replace(/\/$/, '') + '/docker-dns';
  }
  function toggleProxyAdapter() {
    var caddy = document.getElementById('docker-dns-proxy-adapter').value === 'caddy';
    document.getElementById('docker-dns-caddy-config-row').style.display = caddy ? '' : 'none';
    document.getElementById('docker-dns-traefik-entrypoint-row').style.display = caddy ? 'none' : '';
  }
  function render(data, includeSettings) {
    if (includeSettings && !settingsDirty) renderSettings(data);
    var state = data.state;
    var summary = 'Last sync: ' + (state.last_sync || 'never') + '\nLast success: ' + (state.last_success || 'never');
    if (state.last_error) summary += '\nError: ' + state.last_error;
    if (state.integration_warning) summary += '\nMenu integration: ' + state.integration_warning;
    if (state.proxy && state.proxy.status !== 'disabled') summary += '\nProxy: ' + state.proxy.status + (state.proxy.ipv4 ? ' · ' + state.proxy.ipv4 : '') + (state.proxy.last_error ? ' · ' + state.proxy.last_error : '');
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
      var tcpPorts = (container.ports || []).filter(function (port) { return port.protocol === 'tcp'; });
      var selectedPrivate = container.proxy_private_port == null ? '' : String(container.proxy_private_port);
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
        '<details class="docker-dns-proxy-details">' +
          '<summary>Reverse proxy · ' + escapeHtml(container.proxy_status || 'direct') + (container.proxy_url ? ' · ' + escapeHtml(container.proxy_url) : '') + '</summary>' +
          '<div class="docker-dns-proxy-controls">' +
            '<label><span>Enabled</span><input class="docker-dns-container-proxy-enabled" type="checkbox" ' + (container.proxy_enabled ? 'checked' : '') + '></label>' +
            '<label><span>Upstream port</span><select class="docker-dns-proxy-port"><option value="">Automatic</option>' + tcpPorts.map(function (port) { return '<option value="' + port.private + '" ' + (selectedPrivate === String(port.private) ? 'selected' : '') + '>' + port.public + '→' + port.private + '/tcp</option>'; }).join('') + '</select></label>' +
            '<label><span>Protocol</span><select class="docker-dns-proxy-scheme"><option value="auto">Automatic</option><option value="http">HTTP</option><option value="https">HTTPS</option></select></label>' +
            '<label><span>Verify upstream TLS</span><input class="docker-dns-proxy-verify" type="checkbox" ' + (container.proxy_verify_tls ? 'checked' : '') + '></label>' +
            '<label><span>TLS server name</span><input class="docker-dns-proxy-tls-name" type="text" value="' + escapeAttribute(container.proxy_tls_server_name || '') + '" placeholder="Optional"></label>' +
          '</div><small>Upstream: ' + escapeHtml(container.proxy_upstream || 'not resolved') + '</small>' +
        '</details>' +
        '<button type="button" class="docker-dns-save-container">Save</button>' +
      '</article>';
    }).join('');
    containers.forEach(function (container) {
      var row = list.querySelector('[data-container="' + CSS.escape(container.name) + '"]');
      if (row) row.querySelector('.docker-dns-proxy-scheme').value = container.proxy_scheme || 'auto';
    });
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
  document.getElementById('docker-dns-proxy-adapter').addEventListener('change', toggleProxyAdapter);
  document.getElementById('docker-dns-proxy-container').addEventListener('change', function () { updateProxyChoices('', '', ''); });
  document.getElementById('docker-dns-proxy-mount').addEventListener('change', updateMountHelp);
  document.getElementById('docker-dns-save-settings').addEventListener('click', function () { perform(providerPayload('save-settings'), 'Settings saved.', true); });
  document.getElementById('docker-dns-test').addEventListener('click', function () { perform(providerPayload('test-connection'), 'Connection succeeded.'); });
  document.getElementById('docker-dns-validate-proxy').addEventListener('click', function () { perform(providerPayload('validate-proxy'), 'Proxy integration loaded successfully.'); });
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
      url_override: row.querySelector('.docker-dns-override').value,
      proxy_enabled: row.querySelector('.docker-dns-container-proxy-enabled').checked,
      proxy_private_port: row.querySelector('.docker-dns-proxy-port').value,
      proxy_scheme: row.querySelector('.docker-dns-proxy-scheme').value,
      proxy_verify_tls: row.querySelector('.docker-dns-proxy-verify').checked,
      proxy_tls_server_name: row.querySelector('.docker-dns-proxy-tls-name').value}, 'Container settings saved.');
  });
  load(true);
})(window, document);
