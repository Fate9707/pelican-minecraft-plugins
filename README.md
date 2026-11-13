# Minecraft Plugin Manager for Pelican

Manage Minecraft server plugins from the Pelican Panel. Find a plugin, compare compatible releases, and install or update it without handling the download manually.

## Plugin source

- **Modrinth** — search Minecraft plugin projects, choose a compatible release, and install or update it from the panel.

## Using the plugin

Install this package in Pelican's Plugin Manager, then open the plugin management page for a Minecraft server. The server egg must identify the server as Minecraft and expose plugin support. For Modrinth, enable the `modrinth_plugins` egg feature (or the Minecraft `plugins` feature).

The manager uses Modrinth for plugin search, version selection, installation, update checks, and installed-plugin identification. Plugins hosted only on other sites are not available through this manager.

This manager lists and handles server plugins only.

## License

GNU General Public License v3.0.
