**Traefik Label Manager**

View and edit Traefik Docker labels for every Unraid container.

### Requirements

1. Run Traefik v2 or v3 with its standalone Docker provider.
2. Set `providers.docker.exposedByDefault=false`.
3. Set `providers.docker.useBindPortIP=true`.
4. Give Traefik appropriately secured access to the Docker API.
5. Add a DNS rewrite from `*.home.arpa` to Traefik's address.
6. Configure Traefik entrypoints, TLS, certificates, redirects, and middlewares as desired.

### Container form

Open an application's **Add/Update Container** form, enable **Traefik route**,
verify the hostname and backend port, and click **Apply**.

### Network Services page

Open **Settings → Network Services → Traefik Label Manager** to compare the
labels saved in each Unraid template with the labels active on its current
container. Traefik containers are pinned to the top. The editor accepts only
`traefik.*` and Traefik Label Manager ownership labels.

Use **Save Template** for a non-disruptive template update. Use **Apply &
Restart** to save and invoke Unraid's container updater so the labels become
active. A normal Docker restart does not change container labels.

For an unmanaged container, **Enable Route** stages the automatic
`<container>.home.arpa` route and preferred published backend port before save.

Full documentation: https://github.com/LukaHummel/Traefik-Label-Manager#readme
