<?php

namespace FateFighter\MinecraftPlugins\Enums;

use App\Models\Server;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum MinecraftLoader: string implements HasIcon, HasLabel
{
    case Folia = 'folia';
    case Purpur = 'purpur';
    case Paper = 'paper';
    case Spigot = 'spigot';
    case Bukkit = 'bukkit';
    case Sponge = 'sponge';
    case Velocity = 'velocity';
    case Waterfall = 'waterfall';
    case Bungeecord = 'bungeecord';

    public function getLabel(): string
    {
        return str($this->name)->title();
    }

    public function isProxy(): bool
    {
        return in_array($this, [self::Velocity, self::Waterfall, self::Bungeecord], true);
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Folia => 'mcloader-folia',
            self::Purpur => 'mcloader-purpur',
            self::Paper => 'mcloader-paper',
            self::Spigot => 'mcloader-spigot',
            self::Bukkit => 'mcloader-bukkit',
            self::Sponge => 'mcloader-sponge',
            self::Velocity => 'mcloader-velocity',
            self::Waterfall => 'mcloader-waterfall',
            self::Bungeecord => 'mcloader-bungeecord',
        };
    }

    public static function fromServer(Server $server): ?MinecraftLoader
    {
        $server->loadMissing('egg');

        $tags = $server->egg?->tags ?? [];
        if (is_string($tags)) {
            $tags = json_decode($tags, true) ?: [];
        }

        if (!is_array($tags)) {
            $tags = [];
        }

        $loader = self::fromTags($tags);

        if ($loader) {
            return $loader;
        }

        return self::fromEggName((string) ($server->egg?->name ?? ''));
    }

    /** @param string[] $tags */
    public static function fromTags(array $tags): ?MinecraftLoader
    {
        $tags = array_map(static fn ($tag) => strtolower(trim((string) $tag)), $tags);

        foreach ([
            self::Velocity,
            self::Waterfall,
            self::Bungeecord,
            self::Folia,
            self::Purpur,
            self::Paper,
            self::Bukkit,
            self::Spigot,
            self::Sponge,
        ] as $loader) {
            if (in_array($loader->value, $tags, true)) {
                return $loader;
            }
        }

        return count(array_intersect($tags, ['leaf', 'pufferfish', 'gale'])) > 0 ? self::Paper : null;
    }

    private static function fromEggName(string $name): ?MinecraftLoader
    {
        $name = strtolower($name);

        return match (true) {
            str_contains($name, 'velocity') => self::Velocity,
            str_contains($name, 'waterfall') => self::Waterfall,
            str_contains($name, 'bungeecord'), str_contains($name, 'bungee') => self::Bungeecord,
            str_contains($name, 'folia') => self::Folia,
            str_contains($name, 'purpur') => self::Purpur,
            str_contains($name, 'paper'), str_contains($name, 'leaf'), str_contains($name, 'pufferfish'), str_contains($name, 'gale') => self::Paper,
            str_contains($name, 'spigot') => self::Spigot,
            str_contains($name, 'bukkit') => self::Bukkit,
            str_contains($name, 'sponge') => self::Sponge,
            default => null,
        };
    }
}
