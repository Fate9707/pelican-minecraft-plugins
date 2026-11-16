<?php

namespace FateFighter\MinecraftPlugins;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use BladeUI\Icons\Factory as BladeIconsFactory;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Panel;

class MinecraftPlugins implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'pelican-minecraft-plugins';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(
            plugin_path($this->getId(), "src/Filament/$id/Pages"),
            "FateFighter\\MinecraftPlugins\\Filament\\$id\\Pages"
        );

        app(BladeIconsFactory::class)->add('mcloader', [
            'path' => plugin_path($this->getId(), 'resources/icons/loaders'),
            'prefix' => 'mcloader',
        ]);

        app(BladeIconsFactory::class)->add('mcbrand', [
            'path' => plugin_path($this->getId(), 'resources/icons/brands'),
            'prefix' => 'mcbrand',
        ]);
    }

    public function boot(Panel $panel): void
    {
    }

    public function getSettingsFormData(): array
    {
        return [
            'latest_minecraft_version' => config(
                'pelican-minecraft-modrinth.latest_minecraft_version',
                '1.21.11'
            ),
            'nav_sort' => env('MINECRAFT_MODRINTH_NAV_SORT', 11),
        ];
    }

    public function getSettingsForm(): array
    {
        return [
            TextInput::make('latest_minecraft_version')
                ->label(trans('pelican-minecraft-plugins::strings.settings.latest_minecraft_version'))
                ->required()
                ->default(fn () => config(
                    'pelican-minecraft-modrinth.latest_minecraft_version',
                    '1.21.11'
                )),

            TextInput::make('nav_sort')
                ->label(trans('pelican-minecraft-plugins::strings.settings.nav_sort'))
                ->helperText(trans('pelican-minecraft-plugins::strings.settings.nav_sort_helper'))
                ->numeric()
                ->default(env('MINECRAFT_MODRINTH_NAV_SORT', 11)),
        ];
    }

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'LATEST_MINECRAFT_VERSION' => $data['latest_minecraft_version'],
            'MINECRAFT_MODRINTH_NAV_SORT' => $data['nav_sort'],
        ]);

        Notification::make()
            ->title(trans('pelican-minecraft-plugins::strings.settings.settings_saved'))
            ->success()
            ->send();
    }
}
