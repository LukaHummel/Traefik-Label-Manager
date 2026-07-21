(function (window, document) {
  'use strict';

  var API = '/plugins/docker.dns/include/Api.php';

  function csrf() {
    return typeof window.csrf_token === 'string' ? window.csrf_token : '';
  }

  function showError(message) {
    if (typeof window.swal === 'function') window.swal({title: 'Docker DNS', text: message, type: 'error'});
    else window.alert('Docker DNS: ' + message);
  }

  function api(payload) {
    var token = csrf();
    var body = new URLSearchParams(Object.assign({}, payload, {
      csrf_token: token,
      docker_dns_csrf_token: token
    }));
    return window.fetch(API, {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body: body.toString()
    }).then(function (response) {
      return response.json().then(function (body) {
        if (!response.ok || body.ok === false) throw new Error(body.error || 'Request failed.');
        return body;
      });
    });
  }

  function localAutomatic(form, name) {
    var label = name.toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 63);
    if (!label) return '';
    var host = label + '.home.arpa';
    var types = Array.from(form.querySelectorAll('[name="confType[]"]'));
    var targets = Array.from(form.querySelectorAll('[name="confTarget[]"]'));
    var values = Array.from(form.querySelectorAll('[name="confValue[]"]'));
    var modes = Array.from(form.querySelectorAll('[name="confMode[]"]'));
    var ports = [];
    types.forEach(function (type, index) {
      if (type.value !== 'Port' || (modes[index] && String(modes[index].value).toLowerCase() === 'udp')) return;
      var privatePort = parseInt(targets[index] && targets[index].value, 10);
      var publicPort = parseInt(values[index] && values[index].value, 10);
      if (publicPort > 0 && publicPort <= 65535) ports.push({privatePort: privatePort, publicPort: publicPort});
    });
    ports.sort(function (a, b) { return a.publicPort - b.publicPort; });
    if (!ports.length) return '';
    var webui = form.querySelector('[name="contWebUI"]');
    var value = webui ? String(webui.value || '').trim() : '';
    if (/^https?:\/\//i.test(value)) {
      value = value.replace(/\[IP\]/g, host).replace(/\[PORT:(\d+)\]/g, function (_all, port) {
        var mapped = ports.find(function (entry) { return entry.privatePort === parseInt(port, 10); });
        return String(mapped ? mapped.publicPort : port);
      });
      try {
        var parsed = new URL(value);
        parsed.hostname = host;
        return parsed.toString();
      } catch (error) {}
    }
    return 'http://' + host + ':' + ports[0].publicPort;
  }

  function reportCompatibility(message) {
    if (window.console && console.warn) console.warn('Docker DNS: ' + message);
  }

  function inject() {
    var form = document.querySelector('form[onsubmit*="prepareConfig"]');
    var nameInput = form && form.querySelector('input[name="contName"]');
    if (document.getElementById('docker-dns-url-row')) return true;
    if (!form || !nameInput) return false;
    var nativeWebui = form.querySelector('input[name="contWebUI"]');
    var anchor = nativeWebui && nativeWebui.closest('dl');
    var row = document.createElement('dl');
    row.id = 'docker-dns-url-row';
    row.innerHTML = '<dt>Docker DNS URL:</dt><dd><span class="docker-dns-url-controls">' +
      '<input id="docker-dns-url" type="url" autocomplete="off" aria-describedby="docker-dns-url-help">' +
      '<button id="docker-dns-url-save" type="button">Save</button></span>' +
      '<span id="docker-dns-url-help" class="docker-dns-url-help">Empty uses the automatic .home.arpa URL. This value is stored only by Docker DNS.</span></dd>';
    if (anchor && anchor.parentNode) anchor.parentNode.insertBefore(row, anchor.nextSibling);
    else form.insertBefore(row, form.firstChild);

    var input = row.querySelector('#docker-dns-url');
    var saveButton = row.querySelector('#docker-dns-url-save');
    var previousName = String(nameInput.value || '').trim();
    var dirty = false;
    var renamePending = false;
    var saving = null;

    function setPlaceholder(serverValue) {
      input.placeholder = serverValue || localAutomatic(form, String(nameInput.value || '').trim()) || 'No published TCP port detected';
    }

    function load() {
      var name = String(nameInput.value || '').trim();
      if (!name || dirty) { setPlaceholder(''); return Promise.resolve(); }
      return window.fetch(API + '?action=container-url&container_name=' + encodeURIComponent(name), {credentials: 'same-origin', cache: 'no-store'})
        .then(function (response) { return response.json(); })
        .then(function (body) {
          input.value = body.url_override || '';
          setPlaceholder(body.proxy_url || body.automatic_url || '');
          previousName = name;
          dirty = false;
          renamePending = false;
        }).catch(function () { setPlaceholder(''); });
    }

    function save() {
      if (saving) return saving;
      var currentName = String(nameInput.value || '').trim();
      saveButton.disabled = true;
      saveButton.textContent = 'Saving…';
      saving = api({action: 'save-container-url', previous_name: previousName,
        container_name: currentName, url_override: input.value}).then(function () {
        dirty = false;
        renamePending = false;
        previousName = currentName;
        saveButton.textContent = 'Saved';
        document.dispatchEvent(new CustomEvent('docker-dns:url-saved'));
        window.setTimeout(function () { saveButton.textContent = 'Save'; }, 1200);
        return true;
      }).catch(function (error) {
        saveButton.textContent = 'Save';
        showError(error.message);
        throw error;
      }).finally(function () {
        saveButton.disabled = false;
        saving = null;
      });
      return saving;
    }

    input.addEventListener('input', function () { dirty = true; });
    saveButton.addEventListener('click', function () { save().catch(function () {}); });
    nameInput.addEventListener('change', function () {
      var currentName = String(nameInput.value || '').trim();
      if (currentName !== previousName) {
        renamePending = true;
        setPlaceholder('');
      } else if (!dirty) {
        load();
      }
    });
    form.addEventListener('change', function (event) { if (event.target !== input) setPlaceholder(''); });
    form.addEventListener('submit', function (event) {
      if (!dirty && !renamePending) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      save().then(function () {
        var allowed = typeof form.onsubmit !== 'function' || form.onsubmit() !== false;
        if (allowed) HTMLFormElement.prototype.submit.call(form);
      }).catch(function () {});
    }, true);
    document.addEventListener('click', function (event) {
      var button = event.target && event.target.closest ? event.target.closest('input[type="button"],button') : null;
      if ((!dirty && !renamePending) || !button || !/\bdone\s*\(/.test(button.getAttribute('onclick') || '')) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      save().then(function () { window.done(); }).catch(function () {});
    }, true);
    load();
    return true;
  }

  function start() {
    if (!/(?:Add|Update)Container/i.test(window.location.pathname)) return;
    var attempts = 0;
    function retry() {
      if (inject()) return;
      attempts += 1;
      if (attempts < 100) window.setTimeout(retry, 100);
      else reportCompatibility('The Docker container form interfaces were not detected; URL editing remains available in Settings.');
    }
    retry();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})(window, document);
