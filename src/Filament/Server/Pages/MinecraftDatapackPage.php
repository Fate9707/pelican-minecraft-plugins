<?php

namespace FateFighter\MinecraftPlugins\Filament\Server\Pages;

use App\Models\Server;
use FateFighter\MinecraftPlugins\Enums\ModrinthProjectType;

class MinecraftDatapackPage extends MinecraftModrinthProjectPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-file-zip';

    protected static ?string $slug = 'modrinth-datapacks';

    public static function getNavigationSort(): ?int
    {
        return parent::getNavigationSort() + 1;
    }

    public static function getNavigationLabel(): string
    {
        return trans('pelican-minecraft-plugins::strings.minecraft_datapacks');
    }

    protected static function detectProjectType(Server $server): ?ModrinthProjectType
    {
        if (!ModrinthProjectType::supportsDatapacks($server)) {
            return null;
        }

        return ModrinthProjectType::Datapack;
    }
}
