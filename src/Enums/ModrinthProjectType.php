<?php

namespace FateFighter\MinecraftPlugins\Enums;

use App\Models\Server;
use Filament\Support\Contracts\HasLabel;

enum ModrinthProjectType: string implements HasLabel
{
    case Plugin = 'plugin';

    public function getLabel(): string
    {
        return trans('pelican-minecraft-plugins::strings.minecraft_plugins');
    }

    public function getFolder(?Server $server = null): string
    {
        return 'plugins';
    }

    public function getFileExtension(): string
    {
        return '.jar';
    }

    public function getModrinthLoader(Server $server): ?string
    {
        return MinecraftLoader::fromServer($server)?->value;
    }

    public static function fromServer(Server $server): ?ModrinthProjectType
    {
        $server->loadMissing('egg');

        $features = $server->egg->features ?? [];
        $tags = $server->egg->tags ?? [];

        if (in_array('modrinth_plugins', $features) || (in_array('minecraft', $tags) && in_array('plugins', $features))) {
            return self::Plugin;
        }

        return null;
    }
}
