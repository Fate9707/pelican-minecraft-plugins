<?php

return [
    'plugin_name' => 'Modrinth',
    'minecraft_plugins' => 'Minecraft Plugins',

    'settings' => [
        'latest_minecraft_version' => 'Neueste Minecraft-Version',
        'nav_sort' => 'Navigationsreihenfolge',
        'nav_sort_helper' => 'Sortierreihenfolge im Seitenmenü. Niedrigere Zahlen erscheinen weiter oben.',
        'settings_saved' => 'Einstellungen gespeichert',
    ],

    'page' => [
        'manager_title' => 'Minecraft Plugin Manager',
        'manager_subtitle' => 'Plugins direkt im Pelican Panel finden, installieren, aktualisieren und verwalten.',
        'sources' => 'Pluginquellen',
        'source' => 'Quelle',
        'version' => 'Version',
        'server_type' => 'Servertyp',
        'server_information' => 'Serverinformationen',
        'quick_actions' => 'Schnellaktionen',
        'total_plugins' => 'Plugins insgesamt',
        'available_updates' => 'Verfügbare Updates',
        'supported_sources' => 'Unterstützte Quellen',
        'open_plugin_folder' => 'Pluginordner öffnen',
        'modrinth_description' => 'Plugins',
        'open_folder' => ':folder-Ordner öffnen',
        'minecraft_version' => 'Minecraft-Version',
        'loader' => 'Loader',
        'installed' => 'Installiert :type',
        'unknown' => 'Unbekannt',
        'view_all' => 'Alle',
        'view_installed' => 'Installiert',
        'updates' => 'Aktualisierungen',
        'plugin_unavailable' => 'Dieses Plugin ist auf Modrinth nicht mehr verfügbar',
    ],

    'table' => [
        'columns' => [
            'title' => 'Titel',
            'author' => 'Autor',
            'downloads' => 'Downloads',
            'date_modified' => 'Geändert',
        ],
    ],

    'version' => [
        'type' => 'Typ',
        'downloads' => 'Versions-Downloads',
        'published' => 'Veröffentlicht',
        'changelog' => 'Änderungsprotokoll',
        'no_file_found' => 'Keine Datei gefunden',
    ],

    'actions' => [
        'scan' => 'Plugins scannen',
        'rescan_plugins_for_updates' => 'Plugins auf Updates erneut scannen',
        'update_all_plugins' => 'Alle Plugins aktualisieren',
        'install_latest' => 'Neueste Version installieren',
        'install' => 'Installieren',
        'installed' => 'Installiert',
        'update' => 'Aktualisieren',
        'uninstall' => 'Deinstallieren',
        'versions' => 'Versionsauswahl',
    ],

    'badges' => [
        'not_on_modrinth' => 'Nicht auf Modrinth',
        'not_tracked' => 'Von dieser Quelle nicht erfasst',
        'unknown_source' => 'Quelle konnte nicht erkannt werden',
        'source_modrinth' => 'Modrinth',
    ],

    'modals' => [
        'bulk_update_confirmation' => 'Alle installierten Elemente aktualisieren? Ihre bisherigen Dateien werden durch neuere Versionen ersetzt.',
        'update_heading' => 'Plugin aktualisieren',
        'update_description' => 'Dies ersetzt Version :old_version durch Version :new_version. Die alte Datei wird gelöscht.',
        'uninstall_heading' => 'Plugin deinstallieren',
        'uninstall_description' => 'Möchtest du :name wirklich deinstallieren? Dies wird die Datei dauerhaft von deinem Server löschen.',
    ],

    'notifications' => [
        'install_success' => 'Installation abgeschlossen',
        'install_success_body' => ':name Version :version erfolgreich installiert',
        'install_failed' => 'Installation fehlgeschlagen',
        'install_failed_body' => 'Bei der Installation ist ein Fehler aufgetreten. Bitte versuche es erneut oder wende dich an den Support, wenn das Problem weiterhin besteht.',
        'update_success' => 'Aktualisierung abgeschlossen',
        'update_success_body' => 'Erfolgreich auf Version :version aktualisiert',
        'update_failed' => 'Aktualisierung fehlgeschlagen',
        'update_failed_body' => 'Bei der Aktualisierung ist ein Fehler aufgetreten. Bitte versuche es erneut oder wende dich an den Support, wenn das Problem weiterhin besteht.',
        'uninstall_success' => 'Deinstallation abgeschlossen',
        'uninstall_success_body' => ':name erfolgreich deinstalliert',
        'uninstall_failed' => 'Deinstallation fehlgeschlagen',
        'uninstall_failed_body' => 'Bei der Deinstallation ist ein Fehler aufgetreten. Bitte versuche es erneut oder wende dich an den Support, wenn das Problem weiterhin besteht.',
        'scan_in_progress_plugins' => 'Plugins werden gescannt... bitte kurz warten.',
        'scan_success' => ':count Plugin(s) beim Scan importiert.',
        'scan_failed' => 'Scan fehlgeschlagen — Modrinth nicht erreichbar.',
        'bulk_update_success' => ':count Plugin(s) aktualisiert.',
        'bulk_update_partial' => ':updated Plugin(s) aktualisiert, :failed fehlgeschlagen.',
        'bulk_update_none' => 'Alle Plugins sind bereits aktuell.',
    ],
];
