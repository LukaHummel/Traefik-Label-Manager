# Docker DNS for Unraid

Docker DNS is an Unraid 7 plugin that publishes IPv4 `.home.arpa` records for
Docker containers with published ports. It supports one locally hosted
AdGuard Home or Pi-hole v6 instance.

It can optionally generate host-based HTTP routes for an existing,
user-managed Caddy v2 or Traefik v2/v3 container. The proxy remains fully
visible and user-controlled in Unraid; the plugin never creates, restarts,
updates, or removes it.

The plugin never edits Docker templates, container labels, or Unraid core
files. A runtime integration adds a separate **Docker DNS WebUI** entry to the
normal Docker and Dashboard context menus. A plugin-owned URL field is also
injected into the Add/Update Container page.

## Installation

Install `docker.dns.plg` from Community Applications or paste its raw GitHub
URL into **Plugins → Install Plugin**.

After installation, open **Settings → Docker DNS**, choose a provider, enter
its local API URL and credentials, test the connection, then enable sync.
Clients must use that AdGuard Home or Pi-hole instance as their DNS resolver.

Pi-hole integration requires Pi-hole v6 and an application password. TLS
certificate validation is enabled by default and should only be disabled for
a trusted local service using a self-signed certificate.

## Optional reverse proxy

The first proxy release provides portless HTTP URLs such as
`http://plex.home.arpa`. Browser-facing HTTPS is not configured by the plugin,
although both HTTP and HTTPS upstream WebUIs are supported.

Install Caddy or Traefik as a normal Unraid container and give it a static IPv4
on a macvlan/ipvlan LAN network. Choose one of its existing appdata bind mounts
under **Settings → Docker DNS**; the plugin creates and exclusively owns a
`docker-dns` child directory inside that mount.

The proxy must be able to reach the Unraid LAN address and published host ports
for bridge containers, plus the LAN addresses and internal ports of custom-
network containers. Depending on the Unraid network configuration, this may
require enabling host access to custom networks or attaching the applications
and proxy to a shared routable network.

For Caddy, add one import to the main Caddyfile, adjusted to the selected mount
destination:

```caddyfile
import /config/docker-dns/*.caddy
```

For Traefik, enable its watched file provider:

```yaml
providers:
  file:
    directory: /config/docker-dns
    watch: true
```

Select the proxy container, its static-IP network, and its appdata mount, then
click **Validate Proxy**. Validation writes a reversible configuration and
confirms through a private probe hostname that the proxy loaded it. DNS records
do not switch to the proxy IP until route application succeeds.

Included containers with TCP ports are proxied automatically. Per-container
controls can disable proxying, choose the upstream port and protocol, and set
TLS verification behavior. UDP-only and proxy-disabled containers retain their
direct DNS address.

## How records and links are chosen

A container is included only when Docker reports an explicit, non-empty host
port binding. Bridge containers point at the Unraid LAN IPv4 address;
macvlan/ipvlan containers point at their reachable LAN address. A per-container
IPv4 override is available when a container has more than one such address.

Names are normalized to DNS labels below `.home.arpa`, with deterministic hash
suffixes for collisions. Direct URL selection is, in order: the plugin override, a
read-only derivation of `net.unraid.docker.webui`, then the lowest published TCP
port. UDP-only containers still receive an A record but do not get a WebUI
menu item.

The context-menu and container-form additions are runtime browser integrations
loaded by `DockerDnsIntegration.page`. They clone and extend Unraid's menu data
and keep URL inputs outside Docker's submitted form fields. If an Unraid update
changes either interface, the integration fails closed and records a warning
on the settings page.

## Stored data

Plugin settings and state are stored under
`/boot/config/plugins/docker.dns/`: `config.json`, `secrets.json`,
`overrides.json`, `state.json`, and `docker.dns.cron`. JSON files are written
atomically with mode `0600`; the directory uses mode `0700`.

When proxying is configured, one marker-protected `docker-dns` directory is
also created inside the selected proxy appdata bind mount. No other proxy
configuration is modified.

## Development

```bash
./test.sh
./build.sh 2026.07.21 1
```

Build output is written to `dist/`. Building requires Docker because the TXZ
is assembled in a Slackware container. The build replaces the development MD5
placeholder in both `dist/docker.dns.plg` and the repository's
`docker.dns.plg`. A `vYYYY.MM.DD` tag runs the release workflow and uploads the
TXZ plus its checksum-bearing plugin file.

PHPUnit covers discovery, address and URL selection, CSRF/override migration,
and both provider reconciliation flows. Vitest/jsdom covers context-menu and
container-form integration. CI additionally runs PHP lint, ShellCheck, XML
validation, and the repository template-write guard.

## Safety guarantees

- No writes below `/boot/config/plugins/dockerMan/`.
- No automatic container recreation or restart.
- No proxy-container lifecycle or update operations.
- Proxy writes are confined to the selected, marker-protected appdata subdirectory.
- DNS records remain while containers are stopped.
- Records are removed when a container disappears or is excluded.
- Provider credentials are stored separately with mode `0600`.
