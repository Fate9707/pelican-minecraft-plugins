# Minecraft Plugins for Pelican

A Pelican Panel server plugin for discovering, installing, updating, and managing Minecraft mods, plugins, and datapacks from Modrinth, Hangar, and Spigot.

## Features

- Search and browse projects from Modrinth, Hangar, and Spigot.
- Filter available releases against the server's Minecraft version and supported platform where the provider supplies that information.
- Install, update, and manage supported Minecraft server add-ons from the server panel.
- Browse Modrinth mods, plugins, and datapacks when the server egg exposes the matching feature.

## Setup

For Modrinth project types, enable the matching egg feature: `modrinth_mods`, `modrinth_plugins`, or optionally `modrinth_datapacks`. The egg should include the `minecraft` tag and a loader tag supported by the server, such as `paper`, `fabric`, `forge`, or `neoforge`.

Install the plugin through Pelican's plugin manager. The repository's `plugin.json` contains the plugin identifier and loader metadata.

## Project history and attribution

This is a derivative project. Its Modrinth implementation is based on [YONN2222/pelican-minecraft-modrinth](https://github.com/YONN2222/pelican-minecraft-modrinth), which credits [H1ghSyst3m/plugins](https://github.com/H1ghSyst3m/plugins/tree/featcomplete-mod-plugin-management) and [pelican-dev/plugins](https://github.com/pelican-dev/plugins) as upstream sources.

This repository retains and adapts that Modrinth code and adds Hangar and Spigot provider integrations, Hungarian translations, and project-specific panel integration. It does not claim the inherited implementation as entirely original. The upstream license is included in [LICENSE](LICENSE), with source details in [NOTICE.md](NOTICE.md).

## License

GNU General Public License v3.0. See [LICENSE](LICENSE).
