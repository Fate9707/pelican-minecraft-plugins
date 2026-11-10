<?php

return [
    'plugin_name' => 'Modrinth',
    'minecraft_plugins' => 'Minecraft Plugins',

    'settings' => [
        'latest_minecraft_version' => 'Latest Minecraft Version',
        'nav_sort' => 'Navigation Order',
        'nav_sort_helper' => 'Sort order in the side menu. Lower numbers appear higher.',
        'settings_saved' => 'Settings saved',
    ],

    'page' => [
        'manager_title' => 'Minecraft Plugin Manager',
        'manager_subtitle' => 'Find, install, update, and manage plugins directly from the Pelican Panel.',
        'sources' => 'Plugin sources',
        'source' => 'Source',
        'version' => 'Version',
        'server_type' => 'Server type',
        'server_information' => 'Server information',
        'quick_actions' => 'Quick actions',
        'total_plugins' => 'Total plugins',
        'available_updates' => 'Available updates',
        'supported_sources' => 'Supported sources',
        'open_plugin_folder' => 'Open plugin folder',
        'modrinth_description' => 'Plugins',
        'hangar_description' => 'Paper / Velocity plugins',
        'spigot_description' => 'Classic plugins',
        'open_folder' => 'Open :folder folder',
        'minecraft_version' => 'Minecraft Version',
        'loader' => 'Loader',
        'installed' => 'Installed :type',
        'unknown' => 'Unknown',
        'view_all' => 'All',
        'view_installed' => 'Installed',
        'updates' => 'Updates',
        'plugin_unavailable' => 'This plugin is no longer available on Modrinth',
    ],

    'table' => [
        'columns' => [
            'title' => 'Title',
            'author' => 'Author',
            'downloads' => 'Downloads',
            'date_modified' => 'Modified',
        ],
    ],

    'version' => [
        'type' => 'Type',
        'downloads' => 'Version downloads',
        'published' => 'Published',
        'changelog' => 'Changelog',
        'no_file_found' => 'No file found',
    ],

    'actions' => [
        'scan' => 'Scan plugins',
        'rescan_plugins_for_updates' => 'Rescan plugins for updates',
        'update_all_plugins' => 'Update all plugins',
        'install_latest' => 'Install latest version',
        'install' => 'Install',
        'installed' => 'Installed',
        'update' => 'Update',
        'uninstall' => 'Uninstall',
        'versions' => 'Version Selection',
    ],

    'badges' => [
        'not_on_modrinth' => 'Not on Modrinth',
        'not_tracked' => 'Not tracked by this source',
        'unknown_source' => 'Source could not be identified',
        'source_hangar' => 'Source: Hangar',
        'source_spigot' => 'Source: Spigot',
    ],

    'modals' => [
        'bulk_update_confirmation' => 'Update all installed items? Their existing files will be replaced with newer versions.',
        'update_heading' => 'Update plugin',
        'update_description' => 'This will replace version :old_version with version :new_version. The old file will be deleted.',
        'uninstall_heading' => 'Uninstall plugin',
        'uninstall_description' => 'Are you sure you want to uninstall :name? This will permanently delete the file from your server.',
    ],

    'notifications' => [
        'install_success' => 'Installation completed',
        'install_success_body' => 'Successfully installed :name version :version',
        'install_failed' => 'Installation failed',
        'install_failed_body' => 'An error occurred during installation. Please try again or contact support if the issue persists.',
        'update_success' => 'Update completed',
        'update_success_body' => 'Successfully updated to version :version',
        'update_failed' => 'Update failed',
        'update_failed_body' => 'An error occurred during the update. Please try again or contact support if the issue persists.',
        'uninstall_success' => 'Uninstall completed',
        'uninstall_success_body' => 'Successfully uninstalled :name',
        'uninstall_failed' => 'Uninstall failed',
        'uninstall_failed_body' => 'An error occurred during uninstallation. Please try again or contact support if the issue persists.',
        'scan_in_progress_plugins' => 'Scanning plugins... please wait a moment.',
        'scan_success' => ':count plugin(s) imported from scan.',
        'scan_failed' => 'Scan failed — Modrinth could not be reached.',
        'bulk_update_success' => ':count plugin(s) updated.',
        'bulk_update_partial' => ':updated plugin(s) updated, :failed failed.',
        'bulk_update_none' => 'All plugins are already up to date.',
    ],
];
