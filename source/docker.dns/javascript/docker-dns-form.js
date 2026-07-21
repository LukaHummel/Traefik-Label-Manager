(function (window, document) {
  'use strict';

  var MARKER = 'io.github.lukahummel.unraid-docker-dns.router';
  var OWNS_ENABLE = 'io.github.lukahummel.unraid-docker-dns.owns-enable';
  var ENABLE = 'traefik.enable';
  var CONFIG_FIELDS = [
    'confName[]', 'confTarget[]', 'confDefault[]', 'confValue[]', 'confMode[]',
    'confDescription[]', 'confType[]', 'confDisplay[]', 'confRequired[]', 'confMask[]'
  ];

  function reportCompatibility(message) {
    if (window.console && console.warn) console.warn('Docker DNS: ' + message);
  }

  function showError(message, row) {
    var target = row && row.querySelector('#docker-dns-traefik-error');
    if (target) {
      target.textContent = message;
      target.hidden = false;
    }
    if (typeof window.swal === 'function') {
      window.swal({title: 'Docker DNS', text: message, type: 'error'});
    } else if (typeof window.alert === 'function') {
      window.alert('Docker DNS: ' + message);
    }
  }

  function clearError(row) {
    var target = row.querySelector('#docker-dns-traefik-error');
    target.textContent = '';
    target.hidden = true;
  }

  function normalizedLabel(name) {
    return String(name || '').toLowerCase().replace(/[^a-z0-9-]+/g, '-')
      .replace(/^-+|-+$/g, '').slice(0, 63).replace(/-+$/g, '');
  }

  function automaticHostname(name) {
    var label = normalizedLabel(name);
    return label ? label + '.home.arpa' : '';
  }

  function fnv1a(value) {
    var hash = 0x811c9dc5;
    var text = String(value || '').toLowerCase();
    for (var index = 0; index < text.length; index += 1) {
      hash ^= text.charCodeAt(index);
      hash = Math.imul(hash, 0x01000193) >>> 0;
    }
    return ('00000000' + hash.toString(16)).slice(-8);
  }

  function routerId(name) {
    var slug = normalizedLabel(name).slice(0, 40).replace(/-+$/g, '');
    return slug ? 'unraid-dns-' + slug + '-' + fnv1a(name) : '';
  }

  function ownedKeys(id) {
    return [
      'traefik.http.routers.' + id + '.rule',
      'traefik.http.routers.' + id + '.service',
      'traefik.http.services.' + id + '.loadbalancer.server.port'
    ];
  }

  function fields(form, name) {
    return Array.from(form.querySelectorAll('[name="' + name + '"]'));
  }

  function configs(form) {
    var types = fields(form, 'confType[]');
    var targets = fields(form, 'confTarget[]');
    var values = fields(form, 'confValue[]');
    var modes = fields(form, 'confMode[]');
    return types.map(function (type, index) {
      return {
        index: index,
        type: String(type.value || ''),
        target: targets[index] ? String(targets[index].value || '') : '',
        value: values[index] ? String(values[index].value || '') : '',
        mode: modes[index] ? String(modes[index].value || '') : ''
      };
    });
  }

  function labels(form) {
    return configs(form).filter(function (entry) { return entry.type === 'Label'; });
  }

  function labelMap(form) {
    var result = {};
    labels(form).forEach(function (entry) {
      if (!Object.prototype.hasOwnProperty.call(result, entry.target)) result[entry.target] = [];
      result[entry.target].push(entry);
    });
    return result;
  }

  function ports(form) {
    return configs(form).filter(function (entry) {
      if (entry.type !== 'Port' || entry.mode.toLowerCase() === 'udp') return false;
      var privatePort = parseInt(entry.target, 10);
      var publicPort = parseInt(entry.value, 10);
      return privatePort > 0 && privatePort <= 65535 && publicPort > 0 && publicPort <= 65535;
    }).map(function (entry) {
      return {privatePort: parseInt(entry.target, 10), publicPort: parseInt(entry.value, 10)};
    }).sort(function (left, right) {
      return left.publicPort - right.publicPort || left.privatePort - right.privatePort;
    });
  }

  function preferredPort(form, available) {
    var webui = form.querySelector('[name="contWebUI"]');
    var match = webui && String(webui.value || '').match(/\[PORT:(\d+)\]/i);
    if (match) {
      var requested = parseInt(match[1], 10);
      if (available.some(function (port) { return port.privatePort === requested; })) return requested;
    }
    return available.length ? available[0].privatePort : null;
  }

  function parseHostname(rule) {
    var match = String(rule || '').match(/^Host\(`([a-z0-9-]+\.home\.arpa)`\)$/);
    return match ? match[1] : '';
  }

  function validHostname(hostname) {
    return /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.home\.arpa$/.test(hostname) ||
      /^[a-z0-9]\.home\.arpa$/.test(hostname);
  }

  function removeConfigIndexes(form, indexes) {
    var descending = Array.from(new Set(indexes)).sort(function (left, right) { return right - left; });
    CONFIG_FIELDS.forEach(function (name) {
      var entries = fields(form, name);
      descending.forEach(function (index) {
        if (entries[index] && entries[index].parentNode) entries[index].parentNode.removeChild(entries[index]);
      });
    });
  }

  function addHidden(form, name, value) {
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    input.setAttribute('data-docker-dns-label', 'true');
    form.appendChild(input);
  }

  function addLabel(form, key, value) {
    addHidden(form, 'confName[]', 'Docker DNS: ' + key);
    addHidden(form, 'confTarget[]', key);
    addHidden(form, 'confDefault[]', '');
    addHidden(form, 'confValue[]', value);
    addHidden(form, 'confMode[]', '');
    addHidden(form, 'confDescription[]', 'Managed by the Docker DNS Traefik integration.');
    addHidden(form, 'confType[]', 'Label');
    addHidden(form, 'confDisplay[]', 'advanced-hide');
    addHidden(form, 'confRequired[]', 'false');
    addHidden(form, 'confMask[]', 'false');
  }

  function inject() {
    var form = document.querySelector('form[onsubmit*="prepareConfig"]');
    var nameInput = form && form.querySelector('input[name="contName"]');
    if (document.getElementById('docker-dns-traefik-row')) return true;
    if (!form || !nameInput) return false;

    var existing = labelMap(form);
    var oldId = existing[MARKER] && existing[MARKER][0] ? existing[MARKER][0].value : '';
    var oldRuleKey = oldId ? ownedKeys(oldId)[0] : '';
    var initialHost = oldRuleKey && existing[oldRuleKey] ? parseHostname(existing[oldRuleKey][0].value) : '';
    var initialPortKey = oldId ? ownedKeys(oldId)[2] : '';
    var initialPort = initialPortKey && existing[initialPortKey] ? parseInt(existing[initialPortKey][0].value, 10) : null;
    var initialName = String(nameInput.value || '').trim();
    var hostnameWasAutomatic = !initialHost || initialHost === automaticHostname(initialName);

    var nativeWebui = form.querySelector('[name="contWebUI"]');
    var anchor = nativeWebui && nativeWebui.closest('dl');
    var row = document.createElement('dl');
    row.id = 'docker-dns-traefik-row';
    row.innerHTML = '<dt>Traefik route:</dt><dd><div class="docker-dns-traefik-controls">' +
      '<label><input id="docker-dns-traefik-enabled" type="checkbox"> Enable route</label>' +
      '<label><span>Hostname</span><input id="docker-dns-traefik-hostname" type="text" autocomplete="off" spellcheck="false"></label>' +
      '<label><span>Backend port</span><select id="docker-dns-traefik-port"></select></label></div>' +
      '<span class="docker-dns-traefik-help">Creates plugin-owned Traefik Docker labels when you click Apply.</span>' +
      '<span id="docker-dns-traefik-error" class="docker-dns-traefik-error" hidden></span></dd>';
    if (anchor && anchor.parentNode) anchor.parentNode.insertBefore(row, anchor.nextSibling);
    else form.insertBefore(row, form.firstChild);

    var enabled = row.querySelector('#docker-dns-traefik-enabled');
    var hostname = row.querySelector('#docker-dns-traefik-hostname');
    var port = row.querySelector('#docker-dns-traefik-port');
    enabled.checked = !!oldId;
    hostname.value = initialHost || automaticHostname(initialName);

    function refreshPorts(preferred) {
      var available = ports(form);
      var wanted = preferred == null || Number.isNaN(preferred) ? parseInt(port.value, 10) : preferred;
      if (!available.some(function (entry) { return entry.privatePort === wanted; })) wanted = preferredPort(form, available);
      port.innerHTML = '';
      available.forEach(function (entry) {
        var option = document.createElement('option');
        option.value = String(entry.privatePort);
        option.textContent = entry.publicPort + ' \u2192 ' + entry.privatePort + '/tcp';
        option.selected = entry.privatePort === wanted;
        port.appendChild(option);
      });
      if (!available.length) {
        var option = document.createElement('option');
        option.value = '';
        option.textContent = 'No published TCP ports';
        port.appendChild(option);
      }
      enabled.disabled = !available.length && !enabled.checked;
      refreshDisabled();
    }

    function refreshDisabled() {
      hostname.disabled = !enabled.checked;
      port.disabled = !enabled.checked;
      clearError(row);
    }

    enabled.addEventListener('change', refreshDisabled);
    hostname.addEventListener('input', function () { hostnameWasAutomatic = false; clearError(row); });
    nameInput.addEventListener('input', function () {
      if (hostnameWasAutomatic) hostname.value = automaticHostname(nameInput.value);
      clearError(row);
    });
    form.addEventListener('change', function (event) {
      if (event.target === enabled || event.target === hostname || event.target === port) return;
      if (event.target && /^conf(?:Type|Target|Value|Mode)\[\]$/.test(event.target.name || '')) refreshPorts(null);
    });
    refreshPorts(initialPort);

    function reconcile() {
      clearError(row);
      var currentName = String(nameInput.value || '').trim();
      var newId = routerId(currentName);
      var currentLabels = labelMap(form);
      var managedId = currentLabels[MARKER] && currentLabels[MARKER][0] ? currentLabels[MARKER][0].value : '';
      if (managedId && !/^unraid-dns-[a-z0-9-]+-[0-9a-f]{8}$/.test(managedId)) {
        showError('The existing Docker DNS ownership marker is invalid.', row);
        return false;
      }
      if (!enabled.checked) {
        if (!managedId) return true;
        var removeKeys = [MARKER, OWNS_ENABLE].concat(ownedKeys(managedId));
        var ownsEnable = currentLabels[OWNS_ENABLE] && currentLabels[OWNS_ENABLE][0] && currentLabels[OWNS_ENABLE][0].value === 'true';
        if (ownsEnable) removeKeys.push(ENABLE);
        var removeIndexes = [];
        removeKeys.forEach(function (key) {
          (currentLabels[key] || []).forEach(function (entry) { removeIndexes.push(entry.index); });
        });
        removeConfigIndexes(form, removeIndexes);
        return true;
      }

      var chosenHost = String(hostname.value || '').trim();
      if (!newId) {
        showError('Enter a container name before enabling the Traefik route.', row);
        return false;
      }
      if (!validHostname(chosenHost)) {
        showError('Hostname must be one lowercase DNS label followed by .home.arpa.', row);
        return false;
      }
      var available = ports(form);
      var chosenPort = parseInt(port.value, 10);
      if (!available.some(function (entry) { return entry.privatePort === chosenPort; })) {
        showError('Select a published TCP backend port.', row);
        return false;
      }
      if (currentLabels[ENABLE] && currentLabels[ENABLE].some(function (entry) { return entry.value.toLowerCase() === 'false'; })) {
        showError('traefik.enable=false already exists. Remove that manual label before enabling this route.', row);
        return false;
      }

      var previousOwned = managedId ? ownedKeys(managedId) : [];
      var conflicts = ownedKeys(newId).filter(function (key) {
        return currentLabels[key] && previousOwned.indexOf(key) === -1;
      });
      if (conflicts.length) {
        showError('Existing labels conflict with the generated route: ' + conflicts.join(', '), row);
        return false;
      }

      var ownedEnable = !!(currentLabels[OWNS_ENABLE] && currentLabels[OWNS_ENABLE][0] && currentLabels[OWNS_ENABLE][0].value === 'true');
      var cleanupKeys = [MARKER, OWNS_ENABLE].concat(previousOwned);
      if (ownedEnable) cleanupKeys.push(ENABLE);
      var cleanupIndexes = [];
      cleanupKeys.forEach(function (key) {
        (currentLabels[key] || []).forEach(function (entry) { cleanupIndexes.push(entry.index); });
      });
      removeConfigIndexes(form, cleanupIndexes);

      currentLabels = labelMap(form);
      var needsEnable = !currentLabels[ENABLE] || !currentLabels[ENABLE].length;
      if (needsEnable) addLabel(form, ENABLE, 'true');
      addLabel(form, MARKER, newId);
      if (needsEnable) addLabel(form, OWNS_ENABLE, 'true');
      var keys = ownedKeys(newId);
      addLabel(form, keys[0], 'Host(`' + chosenHost + '`)');
      addLabel(form, keys[1], newId);
      addLabel(form, keys[2], String(chosenPort));
      return true;
    }

    var originalSubmit = form.onsubmit;
    form.onsubmit = function () {
      if (!reconcile()) return false;
      return typeof originalSubmit === 'function' ? originalSubmit.apply(this, arguments) : true;
    };
    form.setAttribute('data-docker-dns-traefik', 'true');
    return true;
  }

  function start() {
    if (!/(?:Add|Update)Container/i.test(window.location.pathname)) return;
    var attempts = 0;
    function retry() {
      if (inject()) return;
      attempts += 1;
      if (attempts < 100) window.setTimeout(retry, 100);
      else reportCompatibility('The Docker container form interfaces were not detected.');
    }
    retry();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})(window, document);
