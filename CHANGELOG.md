# Changelog

## Unreleased

## 2026.07.21.1

- Breaking: reduce the plugin to opt-in Traefik Docker label management.
- Add editable `.home.arpa` hostname and published backend-port controls to
  Unraid Add/Update Container forms.
- Preserve unrelated manual labels and track ownership for safe disable/rename.
- Remove DNS providers, credentials, state, URL overrides, Caddy and Traefik
  file-provider configuration, cron, watchers, and background synchronization.
- Attempt best-effort cleanup of legacy managed records and generated proxy
  files before replacing an older package.

## 2026.07.21

- Add optional, portless HTTP routing through a user-managed Caddy v2 or
  Traefik v2/v3 container.
- Discover the proxy's static LAN IPv4 and appdata mounts without creating or
  modifying its Docker lifecycle.
- Generate marker-protected adapter configuration with validation, load probes,
  atomic updates, and rollback before DNS records switch to the proxy.
- Add automatic and per-container upstream port, protocol, and TLS controls.

## 2026.07.20.3

- Keep provider form values intact while Test Connection, Sync Now, Cleanup All,
  and container actions refresh status data.
- Reload the canonical persisted provider settings after a successful save.

## 2026.07.20.2

- Fixed Unraid CSRF validation for Test Connection, settings, synchronization,
  cleanup, container overrides, and context-menu integration requests.

## 2026.07.20

- Initial beta release.
- AdGuard Home and Pi-hole v6 DNS synchronization.
- Runtime Docker and Dashboard context-menu integration.
- Plugin-owned URL editor on container configuration pages.
- Docker event watcher and five-minute reconciliation.
