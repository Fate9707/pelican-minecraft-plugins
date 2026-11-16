<x-filament::section
    :heading="$type === \FateFighter\MinecraftPlugins\Enums\ModrinthProjectType::Plugin ? trans('pelican-minecraft-plugins::strings.page.manager_title') : $type?->getLabel()"
    :description="trans('pelican-minecraft-plugins::strings.page.manager_subtitle')"
    icon="tabler-packages"
    icon-color="primary"
/>
