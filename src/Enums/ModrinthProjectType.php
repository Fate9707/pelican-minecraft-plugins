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

        $features = $server->egg?->features ?? [];
        $tags = $server->egg?->tags ?? [];

        $loader = MinecraftLoader::fromServer($server);

        // Egg metadata varies between Pelican installations. Prefer the
        // explicit plugin feature when present, then fall back to the known
        // Minecraft plugin loaders so the page doesn't disappear when an egg
        // omits or renames its feature tags.
        if (is_string($features)) {
            $features = json_decode($features, true) ?: [];
        }

        if (is_string($tags)) {
            $tags = json_decode($tags, true) ?: [];
        }

        if (is_array($features)
            && (in_array('modrinth_plugins', $features, true)
                || (is_array($tags) && in_array('minecraft', $tags, true) && in_array('plugins', $features, true)))) {
            return self::Plugin;
        }

        return $loader !== null ? self::Plugin : null;
    }
}
