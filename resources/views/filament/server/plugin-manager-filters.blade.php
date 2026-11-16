<x-filament::section>
    <x-filament::badge color="gray">
        {{ trans('pelican-minecraft-plugins::strings.page.version') }}:
        {{ $minecraftVersion ?? trans('pelican-minecraft-plugins::strings.page.unknown') }}
    </x-filament::badge>
    <x-filament::badge color="gray">
        {{ trans('pelican-minecraft-plugins::strings.page.server_type') }}:
        {{ $loader?->getLabel() ?? trans('pelican-minecraft-plugins::strings.page.unknown') }}
    </x-filament::badge>
    @if ($activeTab !== 'installed')
        <x-filament::badge :color="$source === 'hangar' ? 'info' : 'success'">
            {{ trans('pelican-minecraft-plugins::strings.page.source') }}:
            {{ $source === 'hangar' ? 'Hangar' : 'Modrinth' }}
        </x-filament::badge>
    @endif
</x-filament::section>
