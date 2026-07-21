import {beforeEach, describe, expect, it, vi} from 'vitest';
import fs from 'node:fs';

const source = fs.readFileSync('source/docker.dns/javascript/docker-dns-form.js', 'utf8');
const MARKER = 'io.github.lukahummel.unraid-docker-dns.router';
const OWNS_ENABLE = 'io.github.lukahummel.unraid-docker-dns.owns-enable';

function fnv1a(value) {
  let hash = 0x811c9dc5;
  for (const character of value.toLowerCase()) {
    hash ^= character.charCodeAt(0);
    hash = Math.imul(hash, 0x01000193) >>> 0;
  }
  return hash.toString(16).padStart(8, '0');
}

function routeId(name) {
  const slug = name.toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40).replace(/-+$/g, '');
  return `unraid-dns-${slug}-${fnv1a(name)}`;
}

function config(type, target, value, mode = '') {
  const values = {
    'confName[]': `${type}: ${target}`,
    'confTarget[]': target,
    'confDefault[]': '',
    'confValue[]': value,
    'confMode[]': mode,
    'confDescription[]': '',
    'confType[]': type,
    'confDisplay[]': 'always',
    'confRequired[]': 'false',
    'confMask[]': 'false'
  };
  return Object.entries(values).map(([name, fieldValue]) =>
    `<input type="hidden" name="${name}" value="${fieldValue.replaceAll('&', '&amp;').replaceAll('"', '&quot;')}">`).join('');
}

function labels() {
  const form = document.querySelector('form');
  const types = [...form.querySelectorAll('[name="confType[]"]')];
  const targets = [...form.querySelectorAll('[name="confTarget[]"]')];
  const values = [...form.querySelectorAll('[name="confValue[]"]')];
  return types.reduce((result, type, index) => {
    if (type.value === 'Label') result[targets[index].value] = values[index].value;
    return result;
  }, {});
}

async function load({name = 'plex', webui = 'http://[IP]:[PORT:80]/', configs = []} = {}) {
  document.body.innerHTML = `<form onsubmit="return true; /* prepareConfig */"><dl><dt>WebUI</dt><dd>` +
    `<input name="contWebUI" value="${webui}"></dd></dl><input name="contName" value="${name}">` +
    configs.join('') + '<input type="submit" value="Apply"></form>';
  window.eval(source);
  document.dispatchEvent(new Event('DOMContentLoaded'));
  await new Promise(resolve => window.setTimeout(resolve, 0));
  return document.querySelector('form');
}

function enableRoute() {
  const checkbox = document.getElementById('docker-dns-traefik-enabled');
  checkbox.checked = true;
  checkbox.dispatchEvent(new Event('change', {bubbles: true}));
}

describe('Traefik container form integration', () => {
  beforeEach(() => {
    window.history.replaceState({}, '', '/Docker/UpdateContainer');
    window.prepareConfig = vi.fn(() => true);
    window.swal = vi.fn();
    window.alert = vi.fn();
    window.fetch = vi.fn();
  });

  it('loads only on Add/Update Container pages', async () => {
    window.history.replaceState({}, '', '/Docker');
    await load({configs: [config('Port', '80', '8080', 'tcp')]});
    expect(document.getElementById('docker-dns-traefik-row')).toBeNull();
  });

  it('starts disabled and derives the hostname and WebUI private port', async () => {
    await load({configs: [config('Port', '443', '8443', 'tcp'), config('Port', '80', '8080', 'tcp')]});
    expect(document.getElementById('docker-dns-traefik-enabled').checked).toBe(false);
    expect(document.getElementById('docker-dns-traefik-hostname').value).toBe('plex.home.arpa');
    expect(document.getElementById('docker-dns-traefik-port').value).toBe('80');
    expect([...document.querySelectorAll('#docker-dns-traefik-port option')].map(option => option.textContent))
      .toEqual(['8080 → 80/tcp', '8443 → 443/tcp']);
  });

  it('falls back to the private port with the lowest published host port', async () => {
    await load({webui: '', configs: [config('Port', '9000', '19000', 'tcp'), config('Port', '8080', '18080', 'tcp')]});
    expect(document.getElementById('docker-dns-traefik-port').value).toBe('8080');
  });

  it('creates the exact managed label set without network requests', async () => {
    const form = await load({configs: [config('Port', '80', '8080', 'tcp'), config('Label', 'manual.label', 'kept')]});
    enableRoute();
    expect(form.onsubmit()).toBe(true);
    const id = routeId('plex');
    expect(labels()).toEqual({
      'manual.label': 'kept',
      'traefik.enable': 'true',
      [MARKER]: id,
      [OWNS_ENABLE]: 'true',
      [`traefik.http.routers.${id}.rule`]: 'Host(`plex.home.arpa`)',
      [`traefik.http.routers.${id}.service`]: id,
      [`traefik.http.services.${id}.loadbalancer.server.port`]: '80'
    });
    expect(window.fetch).not.toHaveBeenCalled();
  });

  it('reuses an existing true enable label without claiming it', async () => {
    const form = await load({configs: [config('Port', '80', '8080', 'tcp'), config('Label', 'traefik.enable', 'true')]});
    enableRoute();
    expect(form.onsubmit()).toBe(true);
    expect(labels()['traefik.enable']).toBe('true');
    expect(labels()[OWNS_ENABLE]).toBeUndefined();
    expect(document.querySelectorAll('[name="confTarget[]"]')).toSatisfy(targets =>
      [...targets].filter(target => target.value === 'traefik.enable').length === 1);
  });

  it('blocks a manual false enable label', async () => {
    const form = await load({configs: [config('Port', '80', '8080', 'tcp'), config('Label', 'traefik.enable', 'false')]});
    enableRoute();
    expect(form.onsubmit()).toBe(false);
    expect(window.swal).toHaveBeenCalledWith(expect.objectContaining({text: expect.stringContaining('traefik.enable=false')}));
    expect(labels()[MARKER]).toBeUndefined();
  });

  it('cannot enable a route without a published TCP port', async () => {
    const form = await load({configs: [config('Port', '53', '53', 'udp')]});
    const checkbox = document.getElementById('docker-dns-traefik-enabled');
    expect(checkbox.disabled).toBe(true);
    checkbox.disabled = false;
    enableRoute();
    expect(form.onsubmit()).toBe(false);
    expect(window.swal).toHaveBeenCalledWith(expect.objectContaining({text: 'Select a published TCP backend port.'}));
  });

  it('rejects hostnames outside one lowercase home.arpa label', async () => {
    const form = await load({configs: [config('Port', '80', '8080', 'tcp')]});
    enableRoute();
    document.getElementById('docker-dns-traefik-hostname').value = 'Plex.internal.home.arpa';
    expect(form.onsubmit()).toBe(false);
    expect(labels()[MARKER]).toBeUndefined();
  });

  it('renames owned keys and follows an automatic hostname', async () => {
    const oldId = routeId('plex');
    const form = await load({configs: [
      config('Port', '80', '8080', 'tcp'), config('Label', 'traefik.enable', 'true'),
      config('Label', MARKER, oldId), config('Label', OWNS_ENABLE, 'true'),
      config('Label', `traefik.http.routers.${oldId}.rule`, 'Host(`plex.home.arpa`)'),
      config('Label', `traefik.http.routers.${oldId}.service`, oldId),
      config('Label', `traefik.http.services.${oldId}.loadbalancer.server.port`, '80')
    ]});
    const name = document.querySelector('[name="contName"]');
    name.value = 'Plex New';
    name.dispatchEvent(new Event('input', {bubbles: true}));
    expect(document.getElementById('docker-dns-traefik-hostname').value).toBe('plex-new.home.arpa');
    expect(form.onsubmit()).toBe(true);
    const current = labels();
    const newId = routeId('Plex New');
    expect(current[MARKER]).toBe(newId);
    expect(current[`traefik.http.routers.${newId}.rule`]).toBe('Host(`plex-new.home.arpa`)');
    expect(current[`traefik.http.routers.${oldId}.rule`]).toBeUndefined();
  });

  it('preserves a custom hostname across a rename', async () => {
    const oldId = routeId('plex');
    const form = await load({configs: [
      config('Port', '80', '8080', 'tcp'), config('Label', 'traefik.enable', 'true'), config('Label', MARKER, oldId),
      config('Label', `traefik.http.routers.${oldId}.rule`, 'Host(`media.home.arpa`)'),
      config('Label', `traefik.http.routers.${oldId}.service`, oldId),
      config('Label', `traefik.http.services.${oldId}.loadbalancer.server.port`, '80')
    ]});
    const name = document.querySelector('[name="contName"]');
    name.value = 'plex-new';
    name.dispatchEvent(new Event('input', {bubbles: true}));
    expect(document.getElementById('docker-dns-traefik-hostname').value).toBe('media.home.arpa');
    expect(form.onsubmit()).toBe(true);
  });

  it('disabling removes only owned labels', async () => {
    const id = routeId('plex');
    const form = await load({configs: [
      config('Port', '80', '8080', 'tcp'), config('Label', 'manual.label', 'kept'),
      config('Label', 'traefik.http.routers.manual.rule', 'Host(`manual.example`)'),
      config('Label', 'traefik.enable', 'true'), config('Label', MARKER, id), config('Label', OWNS_ENABLE, 'true'),
      config('Label', `traefik.http.routers.${id}.rule`, 'Host(`plex.home.arpa`)'),
      config('Label', `traefik.http.routers.${id}.service`, id),
      config('Label', `traefik.http.services.${id}.loadbalancer.server.port`, '80')
    ]});
    const checkbox = document.getElementById('docker-dns-traefik-enabled');
    checkbox.checked = false;
    checkbox.dispatchEvent(new Event('change', {bubbles: true}));
    expect(form.onsubmit()).toBe(true);
    expect(labels()).toEqual({
      'manual.label': 'kept',
      'traefik.http.routers.manual.rule': 'Host(`manual.example`)'
    });
  });

  it('preserves a pre-existing true enable label when disabling', async () => {
    const id = routeId('plex');
    const form = await load({configs: [
      config('Port', '80', '8080', 'tcp'), config('Label', 'traefik.enable', 'true'),
      config('Label', MARKER, id),
      config('Label', `traefik.http.routers.${id}.rule`, 'Host(`plex.home.arpa`)'),
      config('Label', `traefik.http.routers.${id}.service`, id),
      config('Label', `traefik.http.services.${id}.loadbalancer.server.port`, '80')
    ]});
    const checkbox = document.getElementById('docker-dns-traefik-enabled');
    checkbox.checked = false;
    checkbox.dispatchEvent(new Event('change', {bubbles: true}));
    expect(form.onsubmit()).toBe(true);
    expect(labels()).toEqual({'traefik.enable': 'true'});
  });

  it('blocks collisions in the generated namespace', async () => {
    const id = routeId('plex');
    const form = await load({configs: [
      config('Port', '80', '8080', 'tcp'),
      config('Label', `traefik.http.routers.${id}.rule`, 'Host(`manual.home.arpa`)')
    ]});
    enableRoute();
    expect(form.onsubmit()).toBe(false);
    expect(window.swal).toHaveBeenCalledWith(expect.objectContaining({text: expect.stringContaining('conflict')}));
  });

  it('is idempotent across repeated Apply preparation', async () => {
    const form = await load({configs: [config('Port', '80', '8080', 'tcp')]});
    enableRoute();
    expect(form.onsubmit()).toBe(true);
    expect(form.onsubmit()).toBe(true);
    const targets = [...form.querySelectorAll('[name="confTarget[]"]')].map(input => input.value);
    expect(new Set(targets).size).toBe(targets.length);
  });
});
