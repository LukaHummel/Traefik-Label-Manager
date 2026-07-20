import {beforeEach, describe, expect, it, vi} from 'vitest';
import fs from 'node:fs';

const source = fs.readFileSync('source/docker.dns/javascript/docker-dns-settings.js', 'utf8');

const statusResponse = {
  settings: {
    enabled: true,
    provider: 'adguard',
    base_url: 'http://adguard.local',
    verify_tls: true,
    host_ipv4_override: '',
    timeout_seconds: 10,
  },
  credentials: {username: 'admin', password_set: true},
  state: {
    last_sync: '2026-07-20T12:00:00Z',
    last_success: '2026-07-20T12:00:00Z',
    last_error: '',
    integration_warning: '',
    containers: {
      plex: {
        name: 'plex',
        running: true,
        included: true,
        ports: [{public: 8080, private: 80, protocol: 'tcp'}],
        hostname: 'plex.home.arpa',
        target_ipv4: '192.168.1.10',
        target_status: 'Unraid LAN IPv4',
        automatic_url: 'http://plex.home.arpa:8080',
        url_override: '',
        dns_status: 'synchronized',
      },
      worker: {
        name: 'worker',
        running: false,
        included: false,
        ports: [{public: 9000, private: 9000, protocol: 'udp'}],
        hostname: 'worker.home.arpa',
        target_ipv4: '192.168.1.10',
        target_status: 'Unraid LAN IPv4',
        automatic_url: '',
        url_override: '',
        dns_status: 'excluded',
      },
    },
  },
};

describe('responsive container list', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <div id="docker-dns-settings">
        <form id="docker-dns-provider-form">
          <select id="docker-dns-enabled"><option value="true">Yes</option></select>
          <select id="docker-dns-provider"><option value="adguard">AdGuard</option></select>
          <input id="docker-dns-base-url"><input id="docker-dns-username"><input id="docker-dns-password">
          <select id="docker-dns-verify-tls"><option value="true">Yes</option></select>
          <input id="docker-dns-host-ip"><input id="docker-dns-timeout">
          <div id="docker-dns-username-row"></div>
          <button type="button" id="docker-dns-save-settings"></button><button type="button" id="docker-dns-test"></button>
          <button type="button" id="docker-dns-sync"></button><button type="button" id="docker-dns-cleanup"></button>
        </form>
        <div id="docker-dns-status"></div><span id="docker-dns-container-count"></span>
        <div id="docker-dns-containers"></div>
      </div>`;
    window.csrf_token = 'token';
    window.fetch = vi.fn((_url, options) => Promise.resolve({
      ok: true,
      json: () => Promise.resolve(options && options.method === 'POST' ? {ok: true} : statusResponse),
    }));
  });

  it('renders compact cards without repeating global compatibility status', async () => {
    window.eval(source);
    await vi.waitFor(() => expect(document.querySelectorAll('.docker-dns-container')).toHaveLength(2));

    expect(document.getElementById('docker-dns-container-count').textContent).toBe('2 containers');
    expect(document.querySelector('[data-container="plex"] .docker-dns-badge').textContent).toBe('synchronized');
    expect(document.querySelector('[data-container="worker"]').classList.contains('is-stopped')).toBe(true);
    expect(document.getElementById('docker-dns-containers').textContent).not.toContain('No compatibility warning reported');
    expect(document.querySelector('[data-container="plex"] a').href).toBe('http://plex.home.arpa:8080/');
  });

  it('saves values from the selected container card', async () => {
    window.eval(source);
    await vi.waitFor(() => expect(document.querySelector('[data-container="plex"]')).not.toBeNull());

    const card = document.querySelector('[data-container="plex"]');
    card.querySelector('.docker-dns-ip').value = '192.168.1.44';
    card.querySelector('.docker-dns-override').value = 'https://plex.home.arpa/app';
    card.querySelector('.docker-dns-save-container').click();

    await vi.waitFor(() => expect(window.fetch.mock.calls.some(call => call[1] && call[1].method === 'POST')).toBe(true));
    const call = window.fetch.mock.calls.find(entry => entry[1] && entry[1].method === 'POST');
    const request = Object.fromEntries(new URLSearchParams(call[1].body));
    expect(call[1].headers['Content-Type']).toContain('application/x-www-form-urlencoded');
    expect(request).toMatchObject({
      action: 'set-container',
      container_name: 'plex',
      included: 'true',
      target_ipv4_override: '192.168.1.44',
      url_override: 'https://plex.home.arpa/app',
      csrf_token: 'token',
      docker_dns_csrf_token: 'token',
    });
  });

  it('does not reset edited provider settings when an action refreshes status', async () => {
    window.eval(source);
    await vi.waitFor(() => expect(document.getElementById('docker-dns-base-url').value).toBe('http://adguard.local'));

    const baseUrl = document.getElementById('docker-dns-base-url');
    baseUrl.value = 'http://new-adguard.local';
    baseUrl.dispatchEvent(new Event('input', {bubbles: true}));
    document.getElementById('docker-dns-sync').click();

    await vi.waitFor(() => expect(window.fetch.mock.calls.filter(call => call[1] && call[1].method === 'POST')).toHaveLength(1));
    await vi.waitFor(() => expect(window.fetch.mock.calls).toHaveLength(3));
    expect(baseUrl.value).toBe('http://new-adguard.local');
  });

  it('renders the settings returned by a successful save', async () => {
    window.fetch = vi.fn((_url, options) => Promise.resolve({
      ok: true,
      json: () => Promise.resolve(options && options.method === 'POST'
        ? {ok: true, status: {
          ...statusResponse,
          settings: {...statusResponse.settings, base_url: 'http://saved-adguard.local'},
        }}
        : statusResponse),
    }));
    window.eval(source);
    await vi.waitFor(() => expect(document.getElementById('docker-dns-base-url').value).toBe('http://adguard.local'));

    const baseUrl = document.getElementById('docker-dns-base-url');
    baseUrl.value = 'http://saved-adguard.local';
    baseUrl.dispatchEvent(new Event('input', {bubbles: true}));
    document.getElementById('docker-dns-save-settings').click();

    await vi.waitFor(() => expect(window.fetch.mock.calls).toHaveLength(3));
    expect(baseUrl.value).toBe('http://saved-adguard.local');
  });
});
