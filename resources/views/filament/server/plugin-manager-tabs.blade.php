<nav aria-label="{{ trans('pelican-minecraft-plugins::strings.page.manager_title') }}">
    <x-filament::tabs>
        <x-filament::tabs.item
            :active="$activeTab === 'all'"
            icon="tabler-download"
            wire:click="showAllPlugins"
        >
            {{ trans('pelican-minecraft-plugins::strings.actions.install') }}
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$activeTab === 'installed' && !$updatesOnly"
            icon="tabler-circle-check"
            wire:click="showInstalledPlugins"
        >
            {{ trans('pelican-minecraft-plugins::strings.page.view_installed') }}
            <x-slot name="badge">{{ $installedCount }}</x-slot>
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$updatesOnly"
            icon="tabler-refresh"
            wire:click="showPluginUpdates"
        >
            {{ trans('pelican-minecraft-plugins::strings.page.updates') }}
            <x-slot name="badge">{{ $availableUpdatesCount ?? 0 }}</x-slot>
        </x-filament::tabs.item>
    </x-filament::tabs>
</nav>
