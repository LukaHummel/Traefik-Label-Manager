(function (window, document) {
  'use strict';

  var API = '/plugins/traefik.label.manager/include/Api.php';
  var list = document.getElementById('tlm-containers');
  var message = document.getElementById('tlm-page-message');
  var modal = document.getElementById('tlm-update-modal');
  var frame = document.getElementById('tlm-update-frame');
  var modalTitle = document.getElementById('tlm-update-title');
  var expandedContainers = {};

  function element(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function setMessage(text, error) {
    message.textContent = text || '';
    message.className = error ? 'tlm-message tlm-message-error' : (text ? 'tlm-message tlm-message-ok' : '');
  }

  function request(url, options) {
    return window.fetch(url, Object.assign({credentials: 'same-origin', cache: 'no-store'}, options || {}))
      .then(function (response) {
        return response.json().then(function (body) {
          if (!response.ok || body.ok === false) throw new Error(body.error || 'Request failed.');
          return body;
        });
      });
  }

  function allowedKey(key) {
    return /^traefik\.[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?$/.test(key) ||
      key === 'io.github.lukahummel.traefik-label-manager.router' ||
      key === 'io.github.lukahummel.traefik-label-manager.owns-enable';
  }

  function resizeValue(value) {
    value.style.height = 'auto';
    var height = Math.min(Math.max(value.scrollHeight, 34), 160);
    value.style.height = height + 'px';
    value.style.overflowY = value.scrollHeight > 160 ? 'auto' : 'hidden';
  }

  function resizeValues(scope) {
    scope.querySelectorAll('.tlm-label-value').forEach(resizeValue);
  }

  function addLabelRow(tableBody, label, editable) {
    var row = element('tr', label && label.pending ? 'tlm-label-pending' : '');
    var keyCell = element('td');
    var key = element('input', 'tlm-label-key');
    key.type = 'text';
    key.value = label ? label.key : '';
    key.placeholder = 'traefik.http.routers.example.rule';
    key.disabled = !editable;
    keyCell.appendChild(key);

    var templateCell = element('td');
    var value = element('textarea', 'tlm-label-value');
    value.rows = 1;
    value.value = label ? (label.template_value === null ? (label.active_value || '') : label.template_value) : '';
    value.disabled = !editable;
    value.addEventListener('input', function () { resizeValue(value); });
    templateCell.appendChild(value);

    var activeCell = element('td', 'tlm-active-value');
    activeCell.textContent = label && label.active_value !== null ? label.active_value : '—';

    var stateCell = element('td');
    stateCell.appendChild(element('span', label && label.pending ? 'tlm-badge tlm-badge-pending' : 'tlm-badge tlm-badge-current', label && label.pending ? 'Pending' : 'Current'));

    var actionCell = element('td');
    var remove = element('button', 'tlm-remove-label', 'Remove');
    remove.type = 'button';
    remove.disabled = !editable;
    remove.addEventListener('click', function () { row.remove(); });
    actionCell.appendChild(remove);

    [keyCell, templateCell, activeCell, stateCell, actionCell].forEach(function (cell) { row.appendChild(cell); });
    tableBody.appendChild(row);
    if (!label) key.focus();
  }

  function collectLabels(card) {
    var labels = [];
    var seen = {};
    card.querySelectorAll('tbody tr').forEach(function (row) {
      var key = row.querySelector('.tlm-label-key').value.trim();
      var value = row.querySelector('.tlm-label-value').value;
      if (!allowedKey(key)) throw new Error('Only traefik.* and Traefik Label Manager ownership labels may be saved.');
      if (seen[key]) throw new Error('Duplicate label key: ' + key);
      seen[key] = true;
      labels.push({key: key, value: value});
    });
    return labels;
  }

  function saveTemplate(container, card) {
    var labels;
    try {
      labels = collectLabels(card);
    } catch (error) {
      setMessage(error.message, true);
      return Promise.reject(error);
    }
    card.querySelectorAll('button').forEach(function (button) { button.disabled = true; });
    setMessage('Saving ' + container.name + '…', false);
    return request(API, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        action: 'save',
        container: container.name,
        labels: labels,
        traefik_label_manager_csrf_token: typeof window.csrf_token === 'string' ? window.csrf_token : ''
      })
    }).then(function (body) {
      setMessage('Template saved for ' + container.name + '.', false);
      return body;
    }).catch(function (error) {
      setMessage(error.message, true);
      throw error;
    }).finally(function () {
      card.querySelectorAll('button').forEach(function (button) { button.disabled = false; });
    });
  }

  function confirmApply(name) {
    var text = 'Unraid will stop and recreate ' + name + ' from its saved template. Continue?';
    if (typeof window.swal !== 'function') return Promise.resolve(window.confirm(text));
    return new Promise(function (resolve) {
      window.swal({title: 'Apply & Restart', text: text, type: 'warning', showCancelButton: true,
        confirmButtonText: 'Apply & Restart', cancelButtonText: 'Cancel'}, function (confirmed) { resolve(!!confirmed); });
    });
  }

  function openUpdater(name) {
    modalTitle.textContent = 'Applying template: ' + name;
    frame.src = '/plugins/dynamix.docker.manager/include/CreateDocker.php?updateContainer=true&ct[]=' + encodeURIComponent(name);
    modal.hidden = false;
  }

  function renderContainer(container) {
    var card = element('details', 'tlm-container-card');
    card.dataset.container = container.name;
    card.open = !!expandedContainers[container.name];
    card.addEventListener('toggle', function () {
      expandedContainers[container.name] = card.open;
      if (card.open) resizeValues(card);
    });
    var header = element('summary', 'tlm-container-header');
    var heading = element('div');
    heading.appendChild(element('h3', '', container.name));
    heading.appendChild(element('span', 'tlm-container-state', container.status));
    heading.appendChild(element('span', 'tlm-label-count', container.labels.length + (container.labels.length === 1 ? ' label' : ' labels')));
    if (container.is_traefik) heading.appendChild(element('span', 'tlm-badge tlm-badge-traefik', 'Traefik'));
    if (container.pending) heading.appendChild(element('span', 'tlm-badge tlm-badge-pending', 'Template pending'));
    header.appendChild(heading);
    if (!container.template_found) header.appendChild(element('span', 'tlm-template-missing', 'No Unraid template found'));
    card.appendChild(header);
    var content = element('div', 'tlm-container-content');

    var tableWrap = element('div', 'tlm-table-wrap');
    var table = element('table', 'tlm-label-table');
    var head = element('thead');
    var headRow = element('tr');
    ['Label', 'Template value', 'Active value', 'State', ''].forEach(function (title) { headRow.appendChild(element('th', '', title)); });
    head.appendChild(headRow);
    table.appendChild(head);
    var body = element('tbody');
    container.labels.forEach(function (label) { addLabelRow(body, label, container.template_found); });
    table.appendChild(body);
    tableWrap.appendChild(table);
    content.appendChild(tableWrap);

    var actions = element('div', 'tlm-container-actions');
    var add = element('button', '', 'Add label');
    add.type = 'button';
    add.disabled = !container.template_found;
    add.addEventListener('click', function () { addLabelRow(body, null, true); });
    var save = element('button', '', 'Save Template');
    save.type = 'button';
    save.disabled = !container.template_found;
    save.addEventListener('click', function () { saveTemplate(container, card).then(load).catch(function () {}); });
    var apply = element('button', 'tlm-primary', 'Apply & Restart');
    apply.type = 'button';
    apply.disabled = !container.template_found;
    apply.addEventListener('click', function () {
      confirmApply(container.name).then(function (confirmed) {
        if (!confirmed) return;
        saveTemplate(container, card).then(function () { openUpdater(container.name); }).catch(function () {});
      });
    });
    [add, save, apply].forEach(function (button) { actions.appendChild(button); });
    content.appendChild(actions);
    card.appendChild(content);
    return card;
  }

  function render(containers) {
    list.textContent = '';
    if (!containers.length) {
      list.appendChild(element('div', 'tlm-empty', 'No Docker containers were found.'));
      return;
    }
    containers.forEach(function (container) { list.appendChild(renderContainer(container)); });
  }

  function load() {
    setMessage('', false);
    return request(API + '?action=containers').then(function (body) {
      render(body.containers || []);
    }).catch(function (error) {
      list.textContent = '';
      list.appendChild(element('div', 'tlm-empty', error.message));
      setMessage(error.message, true);
    });
  }

  document.getElementById('tlm-refresh').addEventListener('click', load);
  document.getElementById('tlm-update-close').addEventListener('click', function () {
    frame.src = 'about:blank';
    modal.hidden = true;
    load();
  });
  window.addEventListener('resize', function () { resizeValues(document); });
  load();
})(window, document);
