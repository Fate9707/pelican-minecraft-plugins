<?php

return [
    'plugin_name' => 'Modrinth',
    'minecraft_plugins' => 'Minecraft pluginok',

    'settings' => [
        'latest_minecraft_version' => 'Legújabb Minecraft-verzió',
        'nav_sort' => 'Menüsorrend',
        'nav_sort_helper' => 'A menüben elfoglalt hely. A kisebb szám feljebb jelenik meg.',
        'settings_saved' => 'Beállítások mentve',
    ],

    'page' => [
        'manager_title' => 'Minecraft Plugin Manager',
        'manager_subtitle' => 'Pluginok keresése, telepítése, frissítése és kezelése közvetlenül a Pelican Panelből.',
        'sources' => 'Pluginforrások',
        'source' => 'Forrás',
        'version' => 'Verzió',
        'server_type' => 'Szervertípus',
        'server_information' => 'Szerverinformáció',
        'quick_actions' => 'Gyors műveletek',
        'total_plugins' => 'Összes plugin',
        'available_updates' => 'Elérhető frissítések',
        'supported_sources' => 'Támogatott források',
        'open_plugin_folder' => 'Pluginmappa megnyitása',
        'modrinth_description' => 'Minecraft pluginok',
        'hangar_description' => 'Paper / Velocity pluginok',
        'spigot_description' => 'Klasszikus pluginok',
        'open_folder' => ':folder mappa megnyitása',
        'minecraft_version' => 'Minecraft-verzió',
        'loader' => 'Betöltő',
        'installed' => 'Telepítve: :type',
        'unknown' => 'Ismeretlen',
        'view_all' => 'Összes',
        'view_installed' => 'Telepítve',
        'updates' => 'Frissítések',
        'plugin_unavailable' => 'Ez a plugin már nem érhető el a Modrinthen.',
    ],

    'table' => [
        'columns' => [
            'title' => 'Név',
            'author' => 'Szerző',
            'downloads' => 'Letöltések',
            'date_modified' => 'Módosítva',
        ],
    ],

    'version' => [
        'type' => 'Típus',
        'downloads' => 'Verzió letöltései',
        'published' => 'Közzétéve',
        'changelog' => 'Változások',
        'no_file_found' => 'Nem található fájl',
    ],

    'actions' => [
        'scan' => 'Pluginok keresése',
        'rescan_plugins_for_updates' => 'Pluginok frissítéseinek újbóli keresése',
        'update_all_plugins' => 'Összes plugin frissítése',
        'install_latest' => 'Legújabb verzió telepítése',
        'install' => 'Telepítés',
        'installed' => 'Telepítve',
        'update' => 'Frissítés',
        'manual_spigot_update' => 'Nyisd meg a SpigotMC oldalt a kézi letöltéshez',
        'uninstall' => 'Eltávolítás',
        'versions' => 'Verzió kiválasztása',
    ],

    'badges' => [
        'not_on_modrinth' => 'Nincs a Modrinthen',
        'not_tracked' => 'Nincs követve ezen a forráson',
        'unknown_source' => 'A forrás nem azonosítható',
        'source_modrinth' => 'Modrinth',
        'source_hangar' => 'Hangar',
        'source_spigot' => 'Spigot',
        'source_builtbybit' => 'BuiltByBit',
    ],

    'modals' => [
        'bulk_update_confirmation' => 'Frissítsem az összes telepített elemet? A régi fájlokat új verziók váltják fel.',
        'update_heading' => 'Plugin frissítése',
        'update_description' => 'A(z) :old_version verziót lecseréli erre: :new_version. A régi fájl törlődik.',
        'uninstall_heading' => 'Plugin eltávolítása',
        'uninstall_description' => 'Biztosan eltávolítod ezt: :name? A fájl végleg törlődik a szerveredről.',
    ],

    'notifications' => [
        'install_success' => 'A telepítés befejeződött',
        'install_success_body' => 'Sikeresen telepítve: :name (:version verzió)',
        'install_failed' => 'A telepítés nem sikerült',
        'install_failed_body' => 'Hiba történt telepítés közben. Próbáld újra, vagy fordulj az ügyfélszolgálathoz, ha a hiba továbbra is fennáll.',
        'update_success' => 'A frissítés befejeződött',
        'update_success_body' => 'Sikeresen frissítve erre a verzióra: :version',
        'update_failed' => 'A frissítés nem sikerült',
        'update_failed_body' => 'Hiba történt frissítés közben. Próbáld újra, vagy fordulj az ügyfélszolgálathoz, ha a hiba továbbra is fennáll.',
        'uninstall_success' => 'Az eltávolítás befejeződött',
        'uninstall_success_body' => 'Sikeresen eltávolítva: :name',
        'uninstall_failed' => 'Az eltávolítás nem sikerült',
        'uninstall_failed_body' => 'Hiba történt eltávolítás közben. Próbáld újra, vagy fordulj az ügyfélszolgálathoz, ha a hiba továbbra is fennáll.',
        'scan_in_progress_plugins' => 'Pluginok keresése folyamatban… várj egy kicsit.',
        'scan_success' => 'A keresés :count plugint importált.',
        'scan_failed' => 'A keresés nem sikerült: a Modrinth nem érhető el.',
        'bulk_update_success' => ':count plugin frissítve.',
        'bulk_update_partial' => ':updated plugin frissítve, :failed sikertelen.',
        'bulk_update_none' => 'Minden plugin naprakész.',
    ],
];
