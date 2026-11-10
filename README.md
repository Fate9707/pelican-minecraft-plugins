# Minecraft Plugin Manager for Pelican

Manage Minecraft server plugins from the Pelican Panel. Find a plugin, compare compatible releases, and install or update it without handling the download manually.

## Plugin sources

- **Modrinth** — search plugin projects and choose a release that matches the server.
- **Hangar** — browse Paper ecosystem plugins and their available versions.
- **Spigot** — look up Spigot resources and install supported releases.

## Using the plugin

Install this package in Pelican's Plugin Manager, then open the plugin management page for a Minecraft server. The server egg must identify the server as Minecraft and expose plugin support. For Modrinth, enable the `modrinth_plugins` egg feature (or the Minecraft `plugins` feature).

The manager presents plugins available for that server and provides actions to install, update, and remove supported entries. Version compatibility depends on the information supplied by each source.

This project manages **server plugins**. It is not a client mod or datapack manager.

## License

GNU General Public License v3.0.
