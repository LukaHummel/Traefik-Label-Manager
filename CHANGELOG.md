# Changelog

## 2026.07.21.3

- Make each container editor expandable and collapsed by default.
- Preserve expanded containers while refreshing saved data.
- Make template value fields compact, full-width, and auto-growing.
- Pin containers using a Traefik image or name above other containers.
- Remove the obsolete identity cleanup and rename repository metadata.

## 2026.07.21.2

- Rename the plugin and package to Traefik Label Manager.
- Add a Network Services page listing every Docker container and its Traefik labels.
- Add validated, atomic editing of Traefik and ownership labels in Unraid templates.
- Show pending differences between saved template labels and active container labels.
- Add Save Template and Apply & Restart actions.

## 2026.07.21.1

- Add opt-in Traefik Docker label management to Unraid container forms.
- Add editable `.home.arpa` hostnames and published backend-port selection.
- Preserve manual labels through plugin-owned routing metadata.
