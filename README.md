# Docker DNS for Unraid

Docker DNS is an Unraid 7 plugin that adds opt-in Traefik Docker labels through
the normal **Add/Update Container** form. A route such as
`http://plex.home.arpa` is generated from the container name and its published
TCP port, while both values remain editable before Apply.

The plugin is deliberately stateless. It does not host Traefik, configure DNS,
store credentials, run a background service, write proxy configuration files,
or recreate containers itself. Unraid persists the labels and performs its
normal container update when the user clicks Apply.

## Requirements

- Unraid 7.0 or newer.
- Traefik v2 or v3 using the standalone Docker provider.
- Traefik configured with `exposedByDefault=false` and `useBindPortIP=true`.
- Appropriately secured Docker API access for Traefik.
- A user-managed DNS rewrite from `*.home.arpa` to Traefik's address.
- A published TCP port on every container that should be routed.

See the [Traefik Docker provider documentation](https://doc.traefik.io/traefik/reference/install-configuration/providers/docker/)
for static provider configuration and Docker API security considerations.

## Installation and use

Install `docker.dns.plg` from Community Applications or paste its raw GitHub
URL into **Plugins → Install Plugin**. Open **Settings → Docker DNS** for the
one-time setup checklist.

For each application:

1. Open its Add/Update Container form.
2. Enable **Traefik route**.
3. Verify the generated `<container>.home.arpa` hostname and backend port.
4. Click **Apply**.

The plugin adds these labels:

```text
traefik.enable=true
traefik.http.routers.<managed-id>.rule=Host(`<hostname>.home.arpa`)
traefik.http.routers.<managed-id>.service=<managed-id>
traefik.http.services.<managed-id>.loadbalancer.server.port=<private-port>
```

Two additional ownership labels ensure that later edits or disabling remove
only plugin-managed values. Existing unrelated Docker and Traefik labels are
preserved. The plugin does not add entrypoint, TLS, certificate resolver,
middleware, scheme, or network labels.

Traefik entrypoints determine whether the route is available over HTTP, HTTPS,
or both. That policy remains entirely user-managed.

## Upgrading from 2026.07.21

Version 2026.07.21.1 is a breaking scope reduction. Before package replacement,
the installer stops the legacy watcher and attempts to remove DNS records and
marker-protected proxy files managed by the previous version. Cleanup is best
effort and does not block installation. Legacy credentials, settings, state,
URL overrides, DNS providers, and generated Caddy/Traefik file configuration
are then removed.

## Uninstallation

Docker labels are immutable on a running container. Before uninstalling, disable
the route and click Apply on every managed container if its labels and route
should be removed. Uninstalling the plugin alone never recreates containers and
therefore leaves already-applied labels intact.

## Development

```bash
npm ci
./test.sh
./build.sh 2026.07.21.1 1
```

Build output is written to `dist/`. Building requires Docker because the TXZ is
assembled in a Slackware container. A `vYYYY.MM.DD.N` tag runs the release
workflow and uploads the package plus its checksum-bearing plugin file.

## Safety guarantees

- No reads or writes below `/boot/config/plugins/dockerMan/`.
- No automatic container recreation, restart, or lifecycle operations.
- No DNS, proxy, credential, override, or synchronization state.
- No background daemon, cron job, or Docker event watcher.
- Only plugin-owned label keys are replaced or removed.
- Manual Traefik labels are preserved and exact-key conflicts block Apply.
