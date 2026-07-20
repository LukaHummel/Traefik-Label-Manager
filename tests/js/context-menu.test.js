import {beforeEach, describe, expect, it, vi} from 'vitest';
import fs from 'node:fs';

const source = fs.readFileSync('source/docker.dns/javascript/docker-dns-integration.js', 'utf8');

describe('Docker context menu wrapper', () => {
  beforeEach(() => {
    window.history.replaceState({}, '', '/Docker');
    document.body.innerHTML = '<div id="abc"></div>';
    window.csrf_token = 'token';
    window._ = value => value;
    window.open = vi.fn();
    window.fetch = vi.fn().mockResolvedValue({ok: true, json: () => Promise.resolve({revision: 1, containers: {plex: 'http://plex.home.arpa:32400/web'}})});
  });

  it('preserves native entries and appends a separate link for running containers', async () => {
    let attached;
    const webui = vi.fn();
    const tailscale = vi.fn();
    window.context = {attach: (_selector, options) => { attached = options; }};
    window.addDockerContainerContext = function () {
      window.context.attach('#abc', [{text: 'WebUI', action: webui}, {text: 'Tailscale WebUI', action: tailscale}, {divider: true}, {text: 'Stop'}]);
    };
    window.eval(source);
    document.dispatchEvent(new Event('DOMContentLoaded'));
    await new Promise(resolve => window.setTimeout(resolve, 0));
    window.addDockerContainerContext('plex', '', '', true);
    expect(attached.map(item => item.text || 'divider')).toEqual(['WebUI', 'Tailscale WebUI', 'Docker DNS WebUI', 'divider', 'Stop']);
    expect(attached[0].action).toBe(webui);
    expect(attached[1].action).toBe(tailscale);
    attached[2].action({preventDefault: vi.fn()});
    expect(window.open).toHaveBeenCalledWith('http://plex.home.arpa:32400/web', '_blank', 'noopener');
  });

  it('leaves stopped and unmapped menus unchanged', async () => {
    let attached;
    window.context = {attach: (_selector, options) => { attached = options; }};
    window.addDockerContainerContext = function () { window.context.attach('#abc', [{text: 'Start'}]); };
    window.eval(source);
    document.dispatchEvent(new Event('DOMContentLoaded'));
    await new Promise(resolve => window.setTimeout(resolve, 0));
    window.addDockerContainerContext('plex', '', '', false);
    expect(attached).toEqual([{text: 'Start'}]);
  });

  it('preserves FolderView Plus hook ownership when it rebinds after Docker DNS', async () => {
    const hostAdapterMarker = '__fvplusHostAdapterHook';
    const adapterId = 'fvplus-docker-host';
    let attached;
    let nativeCalls = 0;
    const state = {handler: null, original: null, wrapper: null};

    window.context = {attach: (_selector, options) => { attached = options; }};
    window.addDockerContainerContext = function () {
      nativeCalls += 1;
      window.context.attach('#abc', [{text: 'WebUI'}, {divider: true}, {text: 'Stop'}]);
    };

    function wrapFolderViewHook(handler) {
      const current = window.addDockerContainerContext;
      const metadata = current && current[hostAdapterMarker];
      if (metadata && metadata.adapterId === adapterId) {
        state.handler = handler;
        state.wrapper = current;
        return current;
      }
      state.original = metadata && metadata.adapterId === adapterId ? metadata.original : current;
      state.handler = handler;
      const original = state.original;
      const wrapper = function (...args) {
        const invokeOriginal = (...overrideArgs) => state.original.apply(
          this,
          overrideArgs.length ? overrideArgs : args
        );
        return state.handler({args, invokeOriginal});
      };
      Object.defineProperty(wrapper, hostAdapterMarker, {
        configurable: false,
        enumerable: false,
        writable: false,
        value: Object.freeze({adapterId, original})
      });
      state.wrapper = wrapper;
      window.addDockerContainerContext = wrapper;
      return wrapper;
    }

    wrapFolderViewHook(({invokeOriginal}) => invokeOriginal());
    window.eval(source);
    document.dispatchEvent(new Event('DOMContentLoaded'));
    await new Promise(resolve => window.setTimeout(resolve, 0));

    const dockerDnsWrapper = window.addDockerContainerContext;
    expect(dockerDnsWrapper[hostAdapterMarker]).toBe(state.wrapper[hostAdapterMarker]);

    wrapFolderViewHook(({invokeOriginal}) => invokeOriginal());
    expect(window.addDockerContainerContext).toBe(dockerDnsWrapper);
    expect(() => window.addDockerContainerContext('plex', '', '', true)).not.toThrow();
    expect(nativeCalls).toBe(1);
    expect(attached.map(item => item.text || 'divider')).toEqual([
      'WebUI',
      'Docker DNS WebUI',
      'divider',
      'Stop'
    ]);
  });
});
