# Traefik Label Manager for Unraid

Traefik Label Manager is an Unraid 7 plugin for viewing and editing Traefik
Docker labels. It integrates with the normal **Add/Update Container** form and
adds a management page under **Settings → Network Services**.

## Requirements

- Unraid 7.0 or newer.
- Traefik v2 or v3 using the standalone Docker provider.
- Traefik configured with `exposedByDefault=false` and `useBindPortIP=true`.
- Appropriately secured Docker API access for Traefik.
- A DNS rewrite from `*.home.arpa` to Traefik's address.
- A published TCP port on every container that should be routed.

See the [Traefik Docker provider documentation](https://doc.traefik.io/traefik/reference/install-configuration/providers/docker/)
for provider configuration and Docker API security considerations.

## Traefik setup

Configure the Docker provider in Traefik's static configuration:

```yaml
providers:
  docker:
    exposedByDefault: false
    useBindPortIP: true
```

Mount the Docker socket or configure another secured Docker API endpoint for
Traefik. Configure entrypoints, redirects, certificates, middlewares, and TLS
policy in Traefik.

Add a wildcard rewrite to the DNS server used by local clients:

```text
*.home.arpa -> <Traefik IP address>
```

## Installation

Install the plugin from Community Applications or use the latest manifest:

```text
https://github.com/LukaHummel/Traefik-Label-Manager/releases/latest/download/traefik.label.manager.plg
```

## Container form

For a new route:

1. Open the application's **Add/Update Container** form.
2. Enable **Traefik route**.
3. Verify the generated `<container>.home.arpa` hostname.
4. Select the published backend TCP port.
5. Click **Apply**.

The integration writes the router and service labels plus two ownership labels.
Ownership metadata allows later edits to replace only generated labels while
retaining unrelated Docker and Traefik configuration.

## Network Services page

Open **Settings → Network Services → Traefik Label Manager** to see every Docker
container. Containers using a Traefik image, or with `traefik` in their name,
are pinned above the alphabetically sorted remainder. Each container shows:

- Traefik and ownership labels saved in its Unraid template.
- The corresponding labels active on the current Docker container.
- A pending indicator when template and active values differ.

Only keys beginning with `traefik.` and these ownership keys are editable:

```text
io.github.lukahummel.traefik-label-manager.router
io.github.lukahummel.traefik-label-manager.owns-enable
```

**Save Template** writes changes atomically to the container's Unraid template
without interrupting the container. Docker labels are fixed when a container is
created, so an ordinary Docker restart cannot apply them.

**Apply & Restart** saves the template, then invokes Unraid's container updater.
Unraid stops and recreates the container from the template and restores its
previous running state.

Containers without an Unraid user template are shown read-only.

For an unmanaged container, **Enable Route** stages the same default labels as
the Add/Update Container form: a normalized `<container>.home.arpa` hostname,
deterministic router and service identifier, ownership markers, and the
preferred published backend port. Review the generated values, then use
**Save Template** or **Apply & Restart**.

## Development

```bash
npm ci
./test.sh
./build.sh 2026.07.21.4 1
```

Build output is written to `dist/`. Building requires Docker because the TXZ is
assembled in a Slackware container. A version tag runs the release workflow and
uploads the package with its checksum-bearing plugin manifest.

## Guarantees

- Only `traefik.*` and ownership labels are editable from the management page.
- Unrelated template entries and Docker labels are retained.
- Template writes are locked, validated, and atomically replaced.
- Container recreation uses Unraid's built-in update workflow.
- Exact managed-key conflicts in the container form block Apply.
