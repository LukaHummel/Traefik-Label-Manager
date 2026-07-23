# Traefik Label Manager for Unraid

Traefik Label Manager is an Unraid 7 plugin for viewing and editing Traefik
Docker labels. It integrates with the normal **Add/Update Container** form and
adds a management page under **Settings → Network Services**.

## Requirements

- Unraid 7.0 or newer.
- Traefik v2 or v3 using the standalone Docker provider.
- Traefik configured with `exposedByDefault=false` and `useBindPortIP=true`.
- Appropriately secured Docker API access for Traefik.
- A DNS rewrite from the configured wildcard suffix (default: `*.home.arpa`) to Traefik's address.
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

Choose the domain suffix under **Settings → Network Services → Traefik Label
Manager**. It defaults to `home.arpa`. Add the matching wildcard rewrite to the
DNS server used by local clients:

```text
*.<configured-domain-suffix> -> <Traefik IP address>
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
3. Verify the generated hostname label beside the configured domain suffix.
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

New labels are selected from the options documented for Traefik's Docker
provider. Hover or focus the `?` beside any label to see what it controls and
an example value. The plugin also displays these internal ownership keys:

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
the Add/Update Container form: a normalized `<container>.<domain-suffix>` hostname,
deterministic router and service identifier, ownership markers, and the
preferred published backend port. Review the generated values, then use
**Save Template** or **Apply & Restart**.

## Development

```bash
pnpm install --frozen-lockfile
./test.sh
./build.sh 2026.07.21.5 1
```

Build output is written to `dist/`. Building requires Docker because the TXZ is
assembled in a Slackware container. A version tag runs the release workflow and
uploads the package with its checksum-bearing plugin manifest.

## Guarantees

- Only labels in Traefik's Docker-provider catalog can be added or saved from
  the management page; internal ownership labels are handled by the plugin.
- Unrecognized pre-existing Traefik labels are left untouched.
- Unrelated template entries and Docker labels are retained.
- Template writes are locked, validated, and atomically replaced.
- Container recreation uses Unraid's built-in update workflow.
- Exact managed-key conflicts in the container form block Apply.
