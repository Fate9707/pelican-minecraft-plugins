<?php

namespace FateFighter\MinecraftPlugins\Filament\Server\Pages;

use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Traits\Filament\BlockAccessInConflict;
use FateFighter\MinecraftPlugins\Enums\MinecraftLoader;
use FateFighter\MinecraftPlugins\Enums\ModrinthProjectType;
use FateFighter\MinecraftPlugins\Facades\MinecraftModrinth;
use FateFighter\MinecraftPlugins\Services\HangarService;
use FateFighter\MinecraftPlugins\Services\SpigetService;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MinecraftModrinthProjectPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use HasTabs;
    use InteractsWithTable;

    /** @var array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}>|null */
    protected ?array $installedPluginsMetadata = null;

    /** @var array<string, array<int, mixed>> Cache for version data by project_id */
    protected array $versionsCache = [];

    /** @var array<string> */
    public array $unknownFiles = [];

    public string $source = 'modrinth';

    public bool $updatesOnly = false;

    protected $queryString = [
        'source' => ['except' => 'modrinth'],
    ];

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-packages';

    protected static ?string $slug = 'modrinth';

    public static function getNavigationSort(): ?int
    {
        return (int) env('MINECRAFT_MODRINTH_NAV_SORT', 11);
    }

    protected static function detectProjectType(Server $server): ?ModrinthProjectType
    {
        return ModrinthProjectType::fromServer($server);
    }

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return parent::canAccess() && static::detectProjectType($server);
    }

    public static function getNavigationLabel(): string
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $type = static::detectProjectType($server);

        if ($type === ModrinthProjectType::Plugin) {
            return trans('pelican-minecraft-plugins::strings.page.manager_title');
        }

        return $type?->getLabel() ?? 'Modrinth';
    }

    public static function getModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public static function getPluralModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        if (!in_array($this->source, ['modrinth', 'hangar', 'spigot'], true)
            || (static::detectProjectType(Filament::getTenant()) !== ModrinthProjectType::Plugin && in_array($this->source, ['hangar', 'spigot'], true))) {
            $this->source = 'modrinth';
        }

        $this->loadDefaultActiveTab();
    }

    public function switchSource(string $source): void
    {
        if (!in_array($source, ['modrinth', 'hangar', 'spigot'], true)) {
            return;
        }

        if ($source !== 'modrinth' && static::detectProjectType(Filament::getTenant()) !== ModrinthProjectType::Plugin) {
            return;
        }

        $this->source = $source;
        $this->updatesOnly = false;
        $this->installedPluginsMetadata = null;
        $this->versionsCache = [];
        $this->resetPage();
    }

    public function showAllPlugins(): void
    {
        $this->updatesOnly = false;
        $this->activeTab = 'all';
        $this->resetPage();
    }

    public function showInstalledPlugins(): void
    {
        $this->updatesOnly = false;
        $this->activeTab = 'installed';
        $this->resetPage();
    }

    public function showPluginUpdates(): void
    {
        $this->updatesOnly = true;
        $this->activeTab = 'installed';
        $this->resetPage();
    }

    public function updatedActiveTab(?string $activeTab): void
    {
        if ($activeTab !== 'installed' || $this->usesExternalProvider()) {
            return;
        }

        /** @var Server $server */
        $server = Filament::getTenant();

        $projectType = static::detectProjectType($server);
        $scanCacheKey = MinecraftModrinth::getHashScanCacheKey($server, $projectType);
        if (Cache::has($scanCacheKey)) {
            return;
        }

        /** @var DaemonFileRepository $fileRepository */
        $fileRepository = app(DaemonFileRepository::class);
        if (!$this->hasScanCandidates($server, $fileRepository)) {
            return;
        }

        Notification::make()
            ->title(trans('pelican-minecraft-plugins::strings.notifications.scan_in_progress_plugins'))
            ->info()
            ->send();
    }

    protected function hasScanCandidates(Server $server, DaemonFileRepository $fileRepository): bool
    {
        $type = static::detectProjectType($server);
        if (!$type) {
            return false;
        }

        try {
            $directoryContents = $fileRepository->setServer($server)->getDirectory(MinecraftModrinth::getProjectFolder($server, $fileRepository, $type));
        } catch (Exception $exception) {
            report($exception);

            return false;
        }

        $extension = $type->getFileExtension();

        $diskFiles = collect($directoryContents)
            ->filter(fn ($item) => isset($item['name']) && str_ends_with(strtolower($item['name']), $extension))
            ->pluck('name')
            ->map(fn ($name) => strtolower($name))
            ->values()
            ->toArray();

        $knownFilenames = array_map('strtolower', MinecraftModrinth::getInstalledPlugins($server, $fileRepository, $type));

        foreach ($diskFiles as $filename) {
            if (!in_array($filename, $knownFilenames, true)) {
                return true;
            }
        }

        foreach ($knownFilenames as $filename) {
            if (!in_array($filename, $diskFiles, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(trans('pelican-minecraft-plugins::strings.page.view_all')),
            'installed' => Tab::make(trans('pelican-minecraft-plugins::strings.page.view_installed')),
        ];
    }

    /** @return array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string}> */
    protected function getInstalledPluginsMetadata(): array
    {
        if ($this->installedPluginsMetadata === null) {
            /** @var Server $server */
            $server = Filament::getTenant();
            /** @var DaemonFileRepository $fileRepository */
            $fileRepository = app(DaemonFileRepository::class);

            $this->installedPluginsMetadata = MinecraftModrinth::getInstalledPluginsMetadata(
                $server,
                $fileRepository,
                static::detectProjectType($server)
            );
        }

        return $this->installedPluginsMetadata;
    }

    /** @return array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}|null */
    protected function getInstalledMod(string $projectId): ?array
    {
        $installedPlugins = $this->getInstalledPluginsMetadata();

        foreach ($installedPlugins as $plugin) {
            if ($plugin['project_id'] === $projectId) {
                return $plugin;
            }
        }

        return null;
    }

    /** @return array<int, mixed> */
    protected function getCachedVersions(string $projectId): array
    {
        if (!isset($this->versionsCache[$projectId])) {
            /** @var Server $server */
            $server = Filament::getTenant();
            $this->versionsCache[$projectId] = $this->getProviderVersions($projectId, $server);
        }

        return $this->versionsCache[$projectId];
    }

    private function usesHangar(): bool
    {
        return $this->source === 'hangar';
    }

    private function usesSpiget(): bool
    {
        return $this->source === 'spigot';
    }

    private function usesExternalProvider(): bool
    {
        return $this->usesHangar() || $this->usesSpiget();
    }

    /** @return array<int, array<string, mixed>> */
    private function getProviderVersions(string $projectId, Server $server): array
    {
        if (str_starts_with($projectId, 'builtbybit:')) {
            return [];
        }

        if (str_starts_with($projectId, 'hangar:')) {
            $slug = substr($projectId, 7);

            return app(HangarService::class)->getVersions($slug, $server);
        }

        if (str_starts_with($projectId, 'spigot:')) {
            $resourceId = substr($projectId, 7);

            return app(SpigetService::class)->getVersions($resourceId, $server);
        }

        return MinecraftModrinth::getModrinthVersions($projectId, $server, static::detectProjectType($server));
    }

    /** @param array<string, mixed> $record */
    private function canSelectPluginVersion(array $record): bool
    {
        $projectId = (string) ($record['project_id'] ?? '');

        if ($projectId === '' || str_starts_with($projectId, 'builtbybit:')) {
            return false;
        }

        // Provider-tagged installed records remain selectable even if an old
        // scan left the legacy not_on_modrinth flag on the record.
        if (in_array($record['provider'] ?? null, ['modrinth', 'hangar', 'spigot'], true)
            || str_starts_with($projectId, 'hangar:')
            || str_starts_with($projectId, 'spigot:')) {
            return true;
        }

        return !($record['not_on_modrinth'] ?? false);
    }

    private function getAvailableUpdatesCount(): int
    {
        $updates = 0;

        foreach ($this->getInstalledPluginsMetadata() as $installedPlugin) {
            $projectId = (string) ($installedPlugin['project_id'] ?? '');
            if ($projectId === '') {
                continue;
            }

            $versions = $this->getCachedVersions($projectId);
            if (isset($versions[0]['id']) && ($installedPlugin['version_id'] ?? null) !== $versions[0]['id']) {
                $updates++;
            }
        }

        return $updates;
    }

    /** @param array<string, mixed> $record */
    private function recordHasAvailableUpdate(array $record): bool
    {
        $projectId = (string) ($record['project_id'] ?? '');
        if ($projectId === '') {
            return false;
        }

        $installedPlugin = $this->getInstalledMod($projectId);
        $versions = $this->getCachedVersions($projectId);

        return $installedPlugin !== null
            && isset($versions[0]['id'])
            && $installedPlugin['version_id'] !== $versions[0]['id'];
    }

    /** @return array<string, mixed>|null */
    private function discoverInstalledPluginSource(Server $server, DaemonFileRepository $fileRepository, string $filename): ?array
    {
        $cacheKey = 'plugin_source_detection:v5:'.$server->id.':'.sha1(strtolower($filename));
        $cachedCandidate = Cache::get($cacheKey);
        if (is_array($cachedCandidate) && !empty($cachedCandidate['unknown_source'])) {
            return null;
        }

        $jarIdentity = is_array($cachedCandidate)
            ? []
            : $this->readPluginIdentityFromJar($server, $fileRepository, $filename);
        $projectName = trim((string) ($jarIdentity['name'] ?? ''));
        $website = (string) ($jarIdentity['website'] ?? '');
        if ($projectName === '' && str_contains(strtolower($website), 'hangar.papermc.io/')) {
            $pathParts = array_values(array_filter(explode('/', trim((string) parse_url($website, PHP_URL_PATH), '/'))));
            $projectName = (string) (end($pathParts) ?: '');
        }
        $nameKey = preg_replace('/[^a-z0-9]+/', '', strtolower($projectName)) ?? '';
        $filenameBase = pathinfo($filename, PATHINFO_FILENAME);
        $filenameProjectName = trim((string) preg_replace('/(?:\s*\(\d+\)|[-_ ]v?\d+(?:[._-]\d+)*(?:[-+][A-Za-z0-9._-]+)?)$/i', '', $filenameBase));
        $filenameNameKey = preg_replace('/[^a-z0-9]+/', '', strtolower($filenameProjectName)) ?? '';

        $candidate = is_array($cachedCandidate) ? $cachedCandidate : null;

        if ($candidate === null) {
            $matches = [];
            $spigotResourceId = $this->extractSpigotResourceId($website);
            $knownSpigotResources = [
                'protocollib' => '1997',
                'vulcan' => '83626',
                'vault' => '34315',
            ];

            if ($spigotResourceId === null) {
                foreach ($knownSpigotResources as $knownName => $resourceId) {
                    if ($nameKey === $knownName || $filenameNameKey === $knownName) {
                        $spigotResourceId = $resourceId;
                        break;
                    }
                }
            }

            if ($spigotResourceId !== null) {
                $spigotProject = app(SpigetService::class)->findProjectById($spigotResourceId);
                if ($spigotProject) {
                    $candidate = $spigotProject;
                }
            }

            $marketplaceUrl = strtolower($website);
            $isSpigotUrl = str_contains($marketplaceUrl, 'spigotmc.org/resources/');
            $isHangarUrl = str_contains($marketplaceUrl, 'hangar.papermc.io/');
            $isModrinthUrl = str_contains($marketplaceUrl, 'modrinth.com/');
            $builtByBitUrl = str_contains($marketplaceUrl, 'builtbybit.com')
                || str_contains($marketplaceUrl, 'exploitfixer.org');

            if ($candidate === null && $builtByBitUrl) {
                $builtByBitName = $projectName !== '' ? $projectName : pathinfo($filename, PATHINFO_FILENAME);
                $builtByBitSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower($builtByBitName)) ?? 'plugin';
                $candidate = [
                    'project_id' => 'builtbybit:'.trim($builtByBitSlug, '-'),
                    'slug' => trim($builtByBitSlug, '-'),
                    'title' => $builtByBitName,
                    'provider' => 'builtbybit',
                    'project_type' => 'plugin',
                ];
            }

            if ($candidate === null && $isModrinthUrl) {
                $pathParts = array_values(array_filter(explode('/', trim((string) parse_url($website, PHP_URL_PATH), '/'))));
                $modrinthSlug = end($pathParts);
                if (is_string($modrinthSlug) && $modrinthSlug !== '') {
                    $candidate = MinecraftModrinth::findProjectByName(
                        $server,
                        $modrinthSlug,
                        static::detectProjectType($server)
                    );
                    if ($candidate) {
                        $candidate['project_id'] = $candidate['id'] ?? $candidate['project_id'] ?? '';
                    }
                }
            }

            // A project name alone is not sufficient to claim Modrinth or a
            // fuzzy marketplace match. For Hangar/Spigot, only accept an
            // exact plugin.yml name, and only when one provider matches.
            if ($candidate === null && $nameKey !== '' && !$isSpigotUrl && !$isModrinthUrl && !$builtByBitUrl) {
                try {
                    $hangarProject = app(HangarService::class)->findProjectByName($server, $projectName);
                    if ($hangarProject && (!$isHangarUrl || $hangarProject['provider'] === 'hangar')) {
                        $matches[$hangarProject['project_id']] = $hangarProject;
                    }
                } catch (\Throwable $exception) {
                    report($exception);
                }

                if (!$isHangarUrl) {
                    try {
                        $spigotProject = app(SpigetService::class)->findProjectByName($projectName);
                        if ($spigotProject) {
                            $matches[$spigotProject['project_id']] = $spigotProject;
                        }
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                }

                if (count($matches) === 1) {
                    $candidate = reset($matches);
                }
            }

            if ($candidate !== null) {
                $candidate['detected_name_key'] = $nameKey;
                Cache::put($cacheKey, $candidate, now()->addHours(6));
            } else {
                // Unknowns are retried on the next explicit rescan.
                Cache::put($cacheKey, ['unknown_source' => true], now()->addMinute());
            }
        }

        if (!is_array($candidate) || !empty($candidate['unknown_source'])) {
            return null;
        }

        if (($candidate['provider'] ?? null) === 'modrinth') {
            $candidate['project_id'] = $candidate['project_id'] ?? $candidate['id'] ?? '';
        }

        $versionNumber = preg_match('/(?:[-_ ]v?)(\d+(?:\.\d+){1,3}(?:[-+][A-Za-z0-9._-]+)?)\.jar$/i', $filename, $versionMatch) === 1
            ? $versionMatch[1]
            : 'Detected';
        $candidateTitle = $candidate['title'] ?? ($projectName !== '' ? $projectName : $filename);

        $saved = MinecraftModrinth::savePluginMetadata(
            $server,
            $fileRepository,
            (string) $candidate['project_id'],
            (string) ($candidate['slug'] ?? $candidate['project_id']),
            (string) $candidateTitle,
            'detected:'.sha1(strtolower($filename)),
            $versionNumber,
            $filename,
            isset($candidate['author']) ? (string) $candidate['author'] : null,
            static::detectProjectType($server)
        );

        return $saved ? $candidate : null;
    }

    /** @return array{name?: string, website?: string} */
    private function readPluginIdentityFromJar(Server $server, DaemonFileRepository $fileRepository, string $filename): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return [];
        }

        $type = static::detectProjectType($server);
        if (!$type) {
            return [];
        }

        $temporaryFile = null;

        try {
            $folder = MinecraftModrinth::getProjectFolder($server, $fileRepository, $type);
            $contents = $fileRepository->setServer($server)->getContent("{$folder}/{$filename}");
            $temporaryFile = tempnam(sys_get_temp_dir(), 'pelican-plugin-');
            if (!is_string($temporaryFile) || file_put_contents($temporaryFile, $contents) === false) {
                return [];
            }

            $archive = new \ZipArchive();
            if ($archive->open($temporaryFile) !== true) {
                return [];
            }

            try {
                $identity = [];
                foreach (['paper-plugin.yml', 'plugin.yml'] as $descriptorName) {
                    $descriptor = $archive->getFromName($descriptorName);
                    if (!is_string($descriptor)) {
                        continue;
                    }

                    foreach (['name', 'website'] as $key) {
                        if (isset($identity[$key])) {
                            continue;
                        }

                        $value = $this->readYamlScalar($descriptor, $key);
                        if ($value !== null) {
                            $identity[$key] = $value;
                        }
                    }
                }

                if (!isset($identity['name'])) {
                    $manifest = $archive->getFromName('META-INF/MANIFEST.MF');
                    if (is_string($manifest)
                        && preg_match('/^(?:Implementation|Specification)-Title:\s*(.+)$/mi', $manifest, $matches) === 1) {
                        $identity['name'] = trim($matches[1]);
                    }
                }

                if (!isset($identity['website'])) {
                    $manifest = $archive->getFromName('META-INF/MANIFEST.MF');
                    if (is_string($manifest)
                        && preg_match('/^(?:Implementation|Specification)-URL:\s*(https?:\/\/\S+)$/mi', $manifest, $matches) === 1) {
                        $identity['website'] = trim($matches[1]);
                    }
                }

                return $identity;
            } finally {
                $archive->close();
            }
        } catch (\Throwable $exception) {
            report($exception);

            return [];
        } finally {
            if (is_string($temporaryFile) && is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    }

    private function readYamlScalar(string $yaml, string $key): ?string
    {
        $pattern = '/^\s*'.preg_quote($key, '/').'\s*:\s*(?:"([^"]+)"|\'([^\']+)\'|([^#\r\n]+))/mi';
        if (preg_match($pattern, $yaml, $matches) !== 1) {
            return null;
        }

        $value = trim((string) ($matches[1] ?? $matches[2] ?? $matches[3] ?? ''));

        return $value !== '' ? $value : null;
    }

    private function extractSpigotResourceId(string $url): ?string
    {
        if (preg_match('~spigotmc\.org/resources/(?:[^/?#]*\.)?(\d+)(?:[/?#]|$)~i', $url, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @param array<int, array{primary: bool, filename: string, url: string}> $files
     * @return array{primary: bool, filename: string, url: string}|null
     */
    protected function getPrimaryFile(array $files): ?array
    {
        foreach ($files as $file) {
            if (!empty($file['primary'])) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @throws Exception
     */
    protected function validateFilename(string $filename): string
    {
        if ($filename === '' || $filename === '.' || str_contains($filename, "\0") || str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            throw new Exception('Invalid filename: potential path traversal detected');
        }

        return basename($filename);
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $versionData
     * @param array<string, mixed> $primaryFile
     * @param array<string, mixed>|null $installedPlugin
     *
     * @throws Exception
     */
    private function performInstallOrUpdate(
        Server $server,
        DaemonFileRepository $fileRepository,
        array $record,
        array $versionData,
        array $primaryFile,
        ?array $installedPlugin = null
    ): void {
        $safeNewFilename = $this->validateFilename($primaryFile['filename']);
        $oldFilename = $installedPlugin ? $this->validateFilename($installedPlugin['filename']) : null;

        $type = static::detectProjectType($server);
        if (!$type) {
            throw new Exception('Server does not support Minecraft plugins');
        }

        $folder = MinecraftModrinth::getProjectFolder($server, $fileRepository, $type);

        if (!empty($primaryFile['download_via_panel'])) {
            // Spiget's older-version proxy can return a file through the API
            // server, while Wings follows the redirect and gets a 403 from
            // SpigotMC. Fetch it from the panel and write the bytes through
            // Wings instead of asking Wings to fetch the external URL.
            $response = Http::withUserAgent('pelican-minecraft-plugins')
                ->timeout(120)
                ->connectTimeout(10)
                ->get($primaryFile['url'])
                ->throw();
            $contents = $response->body();

            if (!str_starts_with($contents, "PK\x03\x04")) {
                throw new Exception('Spiget did not return a valid plugin archive for this version');
            }

            $writeResponse = $fileRepository->setServer($server)->putContent(
                $folder.'/'.$safeNewFilename,
                $contents
            );
            if ($writeResponse->failed()) {
                throw new Exception('Wings could not write the downloaded plugin file');
            }
        } else {
            try {
                $fileRepository->setServer($server)->pull($primaryFile['url'], $folder, [
                    'filename' => $safeNewFilename,
                    'use_header' => false,
                    'foreground' => true,
                ]);
            } catch (\Throwable $downloadException) {
                $fallbackUrl = $primaryFile['fallback_url'] ?? null;
                if (!is_string($fallbackUrl) || $fallbackUrl === '') {
                    throw $downloadException;
                }

                $fileRepository->setServer($server)->pull($fallbackUrl, $folder, [
                    'filename' => $safeNewFilename,
                    'use_header' => false,
                    'foreground' => true,
                ]);
            }
        }

        $downloadedFilenames = collect($fileRepository->setServer($server)->getDirectory($folder))
            ->filter(fn ($file) => is_array($file) && isset($file['name']))
            ->pluck('name')
            ->map(fn (string $filename) => strtolower($filename));
        if (!$downloadedFilenames->contains(strtolower($safeNewFilename))) {
            throw new Exception('Downloaded plugin file was not found in the target directory');
        }

        $saved = MinecraftModrinth::savePluginMetadata(
            $server,
            $fileRepository,
            $record['project_id'],
            $record['slug'],
            $record['title'],
            $versionData['id'],
            $versionData['version_number'],
            $safeNewFilename,
            $record['author'] ?? null,
            $type
        );

        if (!$saved) {
            try {
                Http::daemon($server->node)
                    ->post("/api/servers/{$server->uuid}/files/delete", [
                        'root' => '/',
                        'files' => [$folder.'/'.$safeNewFilename],
                    ])
                    ->throw();
            } catch (Exception $rollbackException) {
                report($rollbackException);
            }

            throw new Exception('Failed to save mod metadata');
        }

        if ($oldFilename && $oldFilename !== $safeNewFilename) {
            try {
                Http::daemon($server->node)
                    ->post("/api/servers/{$server->uuid}/files/delete", [
                        'root' => '/',
                        'files' => [$folder.'/'.$oldFilename],
                    ])
                    ->throw();
            } catch (Exception $deleteException) {
                try {
                    Http::daemon($server->node)
                        ->post("/api/servers/{$server->uuid}/files/delete", [
                            'root' => '/',
                            'files' => [$folder.'/'.$safeNewFilename],
                        ])
                        ->throw();
                } catch (Exception $rollbackException) {
                    report($rollbackException);
                }

                if ($installedPlugin && !MinecraftModrinth::savePluginMetadata(
                    $server,
                    $fileRepository,
                    $record['project_id'],
                    $installedPlugin['project_slug'],
                    $installedPlugin['project_title'],
                    $installedPlugin['version_id'],
                    $installedPlugin['version_number'],
                    $oldFilename,
                    $installedPlugin['author'] ?? null,
                    $type
                )) {
                    report(new Exception('Failed to restore old mod metadata during rollback'));
                }

                throw $deleteException;
            }
        }

        Cache::forget(MinecraftModrinth::getHashScanCacheKey($server, $type));
        $this->unknownFiles = array_values(
            array_filter($this->unknownFiles, fn (string $filename) => strtolower($filename) !== strtolower($safeNewFilename))
        );
    }

    /**
     * @return array{updated: int, failed: int}
     */
    private function performBulkUpdate(Server $server, DaemonFileRepository $fileRepository): array
    {
        $updatedCount = 0;
        $failedCount = 0;
        $installedPlugins = $this->getInstalledPluginsMetadata();

        foreach ($installedPlugins as $installedPlugin) {
            try {
                if (!isset($installedPlugin['project_id'], $installedPlugin['project_slug'], $installedPlugin['project_title'], $installedPlugin['version_id'])) {
                    continue;
                }

                $versions = $this->getProviderVersions($installedPlugin['project_id'], $server);
                if (empty($versions) || !isset($versions[0]['id'], $versions[0]['version_number'], $versions[0]['files'])) {
                    continue;
                }

                $latestVersion = $versions[0];
                if ($installedPlugin['version_id'] === $latestVersion['id']) {
                    continue;
                }

                $primaryFile = $this->getPrimaryFile($latestVersion['files']);
                if (!$primaryFile) {
                    if (str_starts_with((string) $installedPlugin['project_id'], 'spigot:')) {
                        $spigotResourceId = substr((string) $installedPlugin['project_id'], 7);
                        $spigotProject = app(SpigetService::class)->findProjectById($spigotResourceId);
                        if (!empty($spigotProject['premium'])) {
                            // Premium Spigot downloads must be obtained manually by the owner.
                            continue;
                        }
                    }

                    throw new Exception('No downloadable file found for bulk update');
                }

                $record = [
                    'project_id' => $installedPlugin['project_id'],
                    'slug' => $installedPlugin['project_slug'],
                    'title' => $installedPlugin['project_title'],
                    'author' => $installedPlugin['author'] ?? null,
                ];

                $this->performInstallOrUpdate($server, $fileRepository, $record, $latestVersion, $primaryFile, $installedPlugin);
                $updatedCount++;
            } catch (Exception $exception) {
                report($exception);
                $failedCount++;
            }
        }

        if ($updatedCount > 0 || $failedCount > 0) {
            $this->installedPluginsMetadata = null;
            $this->versionsCache = [];
        }

        return [
            'updated' => $updatedCount,
            'failed' => $failedCount,
        ];
    }

    public function updateAllPlugins(): void
    {
        /** @var Server $server */
        $server = Filament::getTenant();
        /** @var DaemonFileRepository $fileRepository */
        $fileRepository = app(DaemonFileRepository::class);
        $result = $this->performBulkUpdate($server, $fileRepository);

        if ($result['updated'] === 0 && $result['failed'] === 0) {
            Notification::make()
                ->title(trans('pelican-minecraft-plugins::strings.notifications.bulk_update_none'))
                ->info()
                ->send();

            return;
        }

        if ($result['failed'] > 0) {
            Notification::make()
                ->title(trans('pelican-minecraft-plugins::strings.notifications.bulk_update_partial', [
                    'updated' => $result['updated'],
                    'failed' => $result['failed'],
                ]))
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title(trans('pelican-minecraft-plugins::strings.notifications.bulk_update_success', [
                'count' => $result['updated'],
            ]))
            ->success()
            ->send();
    }

    public function scanPlugins(): void
    {
        /** @var Server $server */
        $server = Filament::getTenant();
        $type = static::detectProjectType($server);

        if ($type === null) {
            return;
        }

        $this->clearPluginSourceDetectionCache($server);
        Cache::forget(MinecraftModrinth::getHashScanCacheKey($server, $type));
        $this->redirect(static::getUrl());
    }

    private function clearPluginSourceDetectionCache(Server $server): void
    {
        $type = static::detectProjectType($server);
        if (!$type) {
            return;
        }

        try {
            /** @var DaemonFileRepository $fileRepository */
            $fileRepository = app(DaemonFileRepository::class);
            $folder = MinecraftModrinth::getProjectFolder($server, $fileRepository, $type);
            $files = $fileRepository->setServer($server)->getDirectory($folder);

            foreach ($files as $file) {
                if (!is_array($file) || !isset($file['name']) || !str($file['name'])->lower()->endsWith('.jar')) {
                    continue;
                }

                Cache::forget('plugin_source_detection:v5:'.$server->id.':'.sha1(strtolower($file['name'])));
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param array<string, mixed> $record
     *
     * @throws Exception
     */
    private function getUninstallFilename(array $record): string
    {
        if (($record['not_on_modrinth'] ?? false) === true) {
            return $this->validateFilename((string) ($record['title'] ?? ''));
        }

        if (empty($record['project_id'])) {
            throw new Exception('Missing project ID for uninstall');
        }

        $installedPlugin = $this->getInstalledMod($record['project_id']);

        if (!$installedPlugin) {
            throw new Exception('Mod not found in metadata');
        }

        return $this->validateFilename($installedPlugin['filename']);
    }

    /**
     * @throws Exception
     */
    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, int $page) {
                /** @var Server $server */
                $server = Filament::getTenant();

                if ($this->activeTab === 'installed') {
                    $perPage = 20;
                    $installedPlugins = $this->getInstalledPluginsMetadata();
                    $unknownFiles = [];

                    /** @var DaemonFileRepository $fileRepository */
                    $fileRepository = app(DaemonFileRepository::class);

                    $staleMetadataRemoved = false;
                    foreach ($installedPlugins as $plugin) {
                        if (!str_starts_with((string) ($plugin['version_id'] ?? ''), 'detected:')) {
                            continue;
                        }

                        if ($this->discoverInstalledPluginSource($server, $fileRepository, $plugin['filename'])) {
                            continue;
                        }

                        if (MinecraftModrinth::removePluginMetadataByFilename(
                            $server,
                            $fileRepository,
                            $plugin['filename'],
                            static::detectProjectType($server)
                        )) {
                            $staleMetadataRemoved = true;
                        }
                    }

                    if ($staleMetadataRemoved) {
                        Cache::forget(MinecraftModrinth::getHashScanCacheKey($server, static::detectProjectType($server)));
                        $this->installedPluginsMetadata = null;
                        $installedPlugins = $this->getInstalledPluginsMetadata();
                    }

                    $metadataBefore = count($installedPlugins);

                    try {
                        // First import exact Modrinth hash matches, then
                        // identify other sources from their JAR metadata.
                        $unknownFiles = MinecraftModrinth::scanAndImportPlugins($server, $fileRepository, static::detectProjectType($server));
                        $this->installedPluginsMetadata = null;
                        $installedPlugins = $this->getInstalledPluginsMetadata();

                        $importedCount = max(0, count($installedPlugins) - $metadataBefore);
                        if ($importedCount > 0) {
                            Notification::make()
                                ->title(trans('pelican-minecraft-plugins::strings.notifications.scan_success', ['count' => $importedCount]))
                                ->success()
                                ->send();
                        }

                        $type = static::detectProjectType($server);
                        if ($type) {
                            $folder = MinecraftModrinth::getProjectFolder($server, $fileRepository, $type);
                            $contents = $fileRepository->setServer($server)->getDirectory($folder);
                            $knownFilenames = array_fill_keys(array_map(
                                fn (array $plugin) => strtolower($plugin['filename']),
                                MinecraftModrinth::getInstalledPluginsMetadata($server, $fileRepository, $type)
                            ), true);
                            $unknownFiles = collect($contents)
                                ->filter(fn ($item) => is_array($item) && isset($item['name']) && str($item['name'])->lower()->endsWith('.jar'))
                                ->pluck('name')
                                ->filter(fn (string $filename) => !isset($knownFilenames[strtolower($filename)]))
                                ->values()
                                ->all();

                            $identifiedAny = false;
                            foreach ($unknownFiles as $filename) {
                                if ($this->discoverInstalledPluginSource($server, $fileRepository, $filename)) {
                                    $identifiedAny = true;
                                }
                            }

                            if ($identifiedAny) {
                                $this->installedPluginsMetadata = null;
                                $installedPlugins = $this->getInstalledPluginsMetadata();
                                $knownFilenames = array_fill_keys(array_map(
                                    fn (array $plugin) => strtolower($plugin['filename']),
                                    MinecraftModrinth::getInstalledPluginsMetadata($server, $fileRepository, $type)
                                ), true);
                                $unknownFiles = array_values(array_filter(
                                    $unknownFiles,
                                    fn (string $filename) => !isset($knownFilenames[strtolower($filename)])
                                ));
                            }

                            $this->unknownFiles = $unknownFiles;
                        }
                    } catch (Exception $exception) {
                        report($exception);

                        if (str_contains(strtolower($exception->getMessage()), 'modrinth')) {
                            Notification::make()
                                ->title(trans('pelican-minecraft-plugins::strings.notifications.scan_failed'))
                                ->danger()
                                ->send();
                        }

                        $unknownFiles = [];
                        $this->unknownFiles = [];
                    }

                    if ($search) {
                        $searchLower = strtolower($search);
                        $installedPlugins = array_values(array_filter($installedPlugins, function (array $plugin) use ($searchLower) {
                            return str_contains(strtolower($plugin['project_title']), $searchLower)
                                || str_contains(strtolower($plugin['project_slug']), $searchLower);
                        }));

                        $unknownFiles = array_values(array_filter($unknownFiles, fn (string $filename) => str_contains(strtolower($filename), $searchLower)));
                    }

                    $projects = [];
                    $modrinthPlugins = [];
                    foreach ($installedPlugins as $plugin) {
                        $projectId = (string) ($plugin['project_id'] ?? '');
                        if (str_starts_with($projectId, 'hangar:')) {
                            $project = app(HangarService::class)->findProjectByName($server, $plugin['project_title']) ?? [];
                            $projects[] = array_merge($project, [
                                'project_id' => $projectId,
                                'slug' => $project['slug'] ?? $plugin['project_slug'],
                                'hangar_path' => $project['hangar_path'] ?? trim(($plugin['author'] ?? '').'/'.$plugin['project_slug'], '/'),
                                'title' => $project['title'] ?? $plugin['project_title'],
                                'description' => $project['description'] ?? '',
                                'icon_url' => $project['icon_url'] ?? null,
                                'author' => $project['author'] ?? ($plugin['author'] ?? ''),
                                'downloads' => $project['downloads'] ?? null,
                                'date_modified' => $project['date_modified'] ?? ($plugin['installed_at'] ?? null),
                                'project_type' => 'plugin',
                                'provider' => 'hangar',
                            ]);
                        } elseif (str_starts_with($projectId, 'builtbybit:')) {
                            $projects[] = [
                                'project_id' => $projectId,
                                'slug' => $plugin['project_slug'],
                                'title' => $plugin['project_title'],
                                'description' => null,
                                'icon_url' => null,
                                'author' => $plugin['author'] ?? '',
                                'downloads' => null,
                                'date_modified' => $plugin['installed_at'] ?? null,
                                'project_type' => 'plugin',
                                'provider' => 'builtbybit',
                                'unavailable' => true,
                            ];
                        } elseif (str_starts_with($projectId, 'spigot:')) {
                            $resourceId = substr($projectId, 7);
                            $project = app(SpigetService::class)->findProjectById($resourceId) ?? [];
                            $projects[] = array_merge($project, [
                                'project_id' => $projectId,
                                'slug' => $project['slug'] ?? $plugin['project_slug'],
                                'spigot_resource_id' => $resourceId,
                                'title' => $project['title'] ?? $plugin['project_title'],
                                'description' => $project['description'] ?? '',
                                'icon_url' => $project['icon_url'] ?? null,
                                'author' => $project['author'] ?? ($plugin['author'] ?? ''),
                                'downloads' => $project['downloads'] ?? null,
                                'date_modified' => $project['date_modified'] ?? ($plugin['installed_at'] ?? null),
                                'project_type' => 'plugin',
                                'provider' => 'spigot',
                            ]);
                        } else {
                            $modrinthPlugins[] = $plugin;
                        }
                    }

                    if (!empty($modrinthPlugins)) {
                        $installedPages = (int) ceil(count($modrinthPlugins) / $perPage);
                        for ($installedPage = 1; $installedPage <= $installedPages; $installedPage++) {
                            $projects = array_merge($projects, MinecraftModrinth::getInstalledPluginsFromModrinth($modrinthPlugins, $installedPage));
                        }
                    }

                    foreach ($unknownFiles as $filename) {
                        $projects[] = [
                            'project_id' => null,
                            'slug' => null,
                            'title' => $filename,
                            'description' => null,
                            'icon_url' => null,
                            'author' => null,
                            'downloads' => null,
                            'date_modified' => null,
                            'not_on_modrinth' => true,
                            'unknown_source' => true,
                            'provider' => 'unknown',
                        ];
                    }

                    usort($projects, fn (array $left, array $right) => strcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? '')));

                    if ($this->updatesOnly) {
                        $projects = array_values(array_filter($projects, function (array $project): bool {
                            if (empty($project['project_id'])) {
                                return false;
                            }

                            $installedPlugin = $this->getInstalledMod($project['project_id']);
                            $versions = $this->getCachedVersions($project['project_id']);

                            return $installedPlugin !== null
                                && isset($versions[0]['id'])
                                && $installedPlugin['version_id'] !== $versions[0]['id'];
                        }));
                    }

                    $totalCount = count($projects);
                    $offset = ($page - 1) * $perPage;
                    $pagedProjects = array_slice($projects, $offset, $perPage);

                    return new LengthAwarePaginator($pagedProjects, $totalCount, $perPage, $page);
                } else {
                    if ($this->usesHangar()) {
                        $response = app(HangarService::class)->getProjects($server, $page, $search);
                    } elseif ($this->usesSpiget()) {
                        $response = app(SpigetService::class)->getProjects($server, $page, $search);
                    } else {
                        $response = MinecraftModrinth::getModrinthProjects($server, $page, $search, static::detectProjectType($server));
                    }

                    return new LengthAwarePaginator($response['hits'], $response['total_hits'], 20, $page);
                }
            })
            ->paginated([20])
            ->columns([
                ImageColumn::make('icon_url')
                    ->label(''),
                TextColumn::make('title')
                    ->searchable()
                    ->description(function (array $record): ?string {
                        if ($record['not_on_modrinth'] ?? false) {
                            return trans($record['unknown_source'] ?? false
                                ? 'pelican-minecraft-plugins::strings.badges.unknown_source'
                                : 'pelican-minecraft-plugins::strings.badges.not_tracked');
                        }

                        $description = $record['description'] ?? null;
                        if (!is_string($description)) {
                            return null;
                        }

                        return (strlen($description) > 120) ? substr($description, 0, 120).'...' : $description;
                    }),
                TextColumn::make('provider')
                    ->label(trans('pelican-minecraft-plugins::strings.page.source'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => trans(match ($state) {
                        'hangar' => 'pelican-minecraft-plugins::strings.badges.source_hangar',
                        'spigot' => 'pelican-minecraft-plugins::strings.badges.source_spigot',
                        'builtbybit' => 'pelican-minecraft-plugins::strings.badges.source_builtbybit',
                        'modrinth' => 'pelican-minecraft-plugins::strings.badges.source_modrinth',
                        default => 'pelican-minecraft-plugins::strings.badges.unknown_source',
                    }))
                    ->color(fn (?string $state) => match ($state) {
                        'hangar' => 'info',
                        'spigot' => 'warning',
                        'builtbybit' => 'primary',
                        'modrinth' => 'success',
                        default => 'gray',
                    })
                    ->visible(fn (): bool => $this->activeTab === 'installed'),
                TextColumn::make('author')
                    ->url(fn ($state, array $record) => match ($record['provider'] ?? $this->source) {
                        'hangar' => $state ? "https://hangar.papermc.io/{$state}" : null,
                        'spigot' => 'https://www.spigotmc.org/resources/'.($record['spigot_resource_id'] ?? '').'/',
                        default => $state ? "https://modrinth.com/user/{$state}" : null,
                    }, true)
                    ->toggleable(),
                TextColumn::make('downloads')
                    ->icon('tabler-download')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('date_modified')
                    ->icon('tabler-calendar')
                    ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state, 'UTC')->diffForHumans() : '')
                    ->tooltip(fn ($state) => $state ? Carbon::parse($state, 'UTC')->timezone(user()->timezone ?? 'UTC')->format($table->getDefaultDateTimeDisplayFormat()) : '')
                    ->toggleable(),
            ])
            ->recordUrl(function (array $record) {
                if (!empty($record['unavailable']) || ($record['not_on_modrinth'] ?? false)) {
                    return null;
                }

                /** @var Server $server */
                $server = Filament::getTenant();
                $projectType = static::detectProjectType($server)?->value ?? $record['project_type'];

                if (($record['provider'] ?? null) === 'hangar') {
                    $path = $record['hangar_path'] ?? trim(($record['author'] ?? '').'/'.$record['slug'], '/');

                    return 'https://hangar.papermc.io/'.$path;
                }

                if (($record['provider'] ?? null) === 'spigot') {
                    $resourceId = $record['spigot_resource_id'] ?? substr((string) ($record['project_id'] ?? ''), 7);

                    return 'https://www.spigotmc.org/resources/'.$resourceId.'/';
                }

                return "https://modrinth.com/{$projectType}/{$record['slug']}";
            }, true)
            ->recordActions([
                Action::make('versions')
                    ->iconButton()
                    ->extraAttributes(['class' => 'mx-0.5'])
                    ->icon('tabler-list')
                    ->color('info')
                    ->tooltip(trans('pelican-minecraft-plugins::strings.actions.versions'))
                    ->visible(fn (array $record): bool => $this->canSelectPluginVersion($record))
                    ->modalSubmitAction(false)
                    ->schema(function (array $record) {
                        $versions = $this->getCachedVersions($record['project_id']);

                        $installedPlugin = $this->getInstalledMod($record['project_id']);
                        $installedVersionId = $installedPlugin['version_id'] ?? null;

                        $sections = [];
                        foreach ($versions as $versionIndex => $versionData) {
                            $primaryFile = $this->getPrimaryFile($versionData['files'] ?? []);

                            $sectionComponents = [
                                TextEntry::make('type_' . $versionIndex)
                                    ->label(trans('pelican-minecraft-plugins::strings.version.type'))
                                    ->state($versionData['version_type'] ?? '')
                                    ->badge()
                                    ->color(match ($versionData['version_type'] ?? '') {
                                        'release' => 'success',
                                        'beta' => 'warning',
                                        'alpha' => 'danger',
                                        default => 'gray',
                                    }),
                                TextEntry::make('downloads_' . $versionIndex)
                                    ->label(trans('pelican-minecraft-plugins::strings.version.downloads'))
                                    ->state($versionData['downloads'] ?? 0)
                                    ->icon('tabler-download')
                                    ->numeric(),
                                TextEntry::make('published_' . $versionIndex)
                                    ->label(trans('pelican-minecraft-plugins::strings.version.published'))
                                    ->state(fn () => isset($versionData['date_published']) ? Carbon::parse($versionData['date_published'], 'UTC')->diffForHumans() : ''),
                            ];

                            if (!empty($versionData['changelog'])) {
                                $sectionComponents[] = TextEntry::make('changelog_' . $versionIndex)
                                    ->label(trans('pelican-minecraft-plugins::strings.version.changelog'))
                                    ->state($versionData['changelog'])
                                    ->markdown();
                            }

                            if (($versionData['id'] ?? null) === $installedVersionId) {
                                $headerAction = Action::make('installed_' . $versionIndex)
                                    ->label(trans('pelican-minecraft-plugins::strings.actions.installed'))
                                    ->icon('tabler-check')
                                    ->color('success')
                                    ->disabled();
                                $sectionIcon = 'tabler-check';
                                $sectionIconColor = 'success';
                            } else {
                                $headerAction = Action::make('install_version_' . $versionIndex)
                                    ->label(trans('pelican-minecraft-plugins::strings.actions.install'))
                                    ->icon('tabler-download')
                                    ->disabled(!$primaryFile)
                                    ->action(function (DaemonFileRepository $fileRepository) use ($record, $versionData, $primaryFile) {
                                        try {
                                            /** @var Server $server */
                                            $server = Filament::getTenant();

                                            if (!isset($versionData['id'], $versionData['version_number'], $versionData['files'])) {
                                                throw new Exception('Invalid version data structure');
                                            }

                                            if (!$primaryFile) {
                                                throw new Exception('No downloadable file found');
                                            }

                                            $installedPlugin = $this->getInstalledMod($record['project_id']);

                                            $this->performInstallOrUpdate($server, $fileRepository, $record, $versionData, $primaryFile, $installedPlugin);

                                            $this->installedPluginsMetadata = null;
                                            $this->versionsCache = [];
                                            $this->js('$wire.$refresh()');

                                            Notification::make()
                                                ->title(trans('pelican-minecraft-plugins::strings.notifications.install_success'))
                                                ->body(trans('pelican-minecraft-plugins::strings.notifications.install_success_body', [
                                                    'name' => $record['title'],
                                                    'version' => $versionData['version_number'],
                                                ]))
                                                ->success()
                                                ->send();
                                        } catch (Exception $exception) {
                                            report($exception);

                                            $this->installedPluginsMetadata = null;
                                            $this->versionsCache = [];
                                            $this->js('$wire.$refresh()');

                                            Notification::make()
                                                ->title(trans('pelican-minecraft-plugins::strings.notifications.install_failed'))
                                                ->body(trans('pelican-minecraft-plugins::strings.notifications.install_failed_body'))
                                                ->danger()
                                                ->send();
                                        }
                                    });
                                $sectionIcon = null;
                                $sectionIconColor = null;
                            }

                            $section = Section::make($versionData['version_number'] ?? '')
                                ->headerActions([$headerAction])
                                ->schema($sectionComponents)
                                ->collapsible()
                                ->collapsed(!($versionData['featured'] ?? false));

                            if ($sectionIcon !== null) {
                                $section = $section->icon($sectionIcon)->iconColor($sectionIconColor);
                            }

                            $sections[] = $section;
                        }

                        return $sections;
                    }),
                Action::make('install_latest')
                    ->iconButton()
                    ->extraAttributes(['class' => 'mx-0.5'])
                    ->icon('tabler-download')
                    ->color('success')
                    ->tooltip(trans('pelican-minecraft-plugins::strings.actions.install_latest'))
                    ->hidden(fn (array $record): bool => ($record['not_on_modrinth'] ?? false)
                        || (($record['provider'] ?? null) === 'spigot' && !empty($record['premium'])))
                    ->visible(function (array $record) {
                        if (empty($record['project_id'])) {
                            return false;
                        }

                        $installedPlugin = $this->getInstalledMod($record['project_id']);

                        if (!is_null($installedPlugin)) {
                            return false;
                        }

                        return true;
                    })
                    ->action(function (array $record, DaemonFileRepository $fileRepository) {
                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();

                            $versions = $this->getProviderVersions($record['project_id'], $server);

                            if (empty($versions)) {
                                throw new Exception('No compatible versions found');
                            }

                            $latestVersion = $versions[0];

                            if (!isset($latestVersion['id'], $latestVersion['version_number'], $latestVersion['files'])) {
                                throw new Exception('Invalid version data structure');
                            }

                            $primaryFile = $this->getPrimaryFile($latestVersion['files']);

                            if (!$primaryFile) {
                                throw new Exception('No downloadable file found');
                            }

                            $this->performInstallOrUpdate($server, $fileRepository, $record, $latestVersion, $primaryFile);

                            $this->installedPluginsMetadata = null;
                            $this->versionsCache = [];

                            Notification::make()
                                ->title(trans('pelican-minecraft-plugins::strings.notifications.install_success'))
                                ->body(trans('pelican-minecraft-plugins::strings.notifications.install_success_body', [
                                    'name' => $record['title'],
                                    'version' => $latestVersion['version_number'],
                                ]))
                                ->success()
                                ->send();
                        } catch (Exception $exception) {
                            report($exception);

                            $this->installedPluginsMetadata = null;
                            $this->versionsCache = [];

                            Notification::make()
                                ->title(trans('pelican-minecraft-plugins::strings.notifications.install_failed'))
                                ->body(trans('pelican-minecraft-plugins::strings.notifications.install_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('update')
                    ->iconButton()
                    ->extraAttributes(['class' => 'mx-0.5'])
                    ->icon('tabler-refresh')
                    ->color('warning')
                    ->tooltip(trans('pelican-minecraft-plugins::strings.actions.update'))
                    ->hidden(fn (array $record): bool => $record['not_on_modrinth'] ?? false)
                    ->visible(fn (array $record): bool => empty($record['premium'])
                        && $this->recordHasAvailableUpdate($record))
                    ->requiresConfirmation()
                    ->modalHeading(trans('pelican-minecraft-plugins::strings.modals.update_heading'))
                    ->modalDescription(function (array $record) {
                        $installedPlugin = $this->getInstalledMod($record['project_id']);
                        $versions = $this->getCachedVersions($record['project_id']);

                        return trans('pelican-minecraft-plugins::strings.modals.update_description', [
                            'old_version' => $installedPlugin['version_number'] ?? 'unknown',
                            'new_version' => $versions[0]['version_number'] ?? 'unknown',
                        ]);
                    })
                    ->action(function (array $record, DaemonFileRepository $fileRepository) {
                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();

                            $installedPlugin = $this->getInstalledMod($record['project_id']);

                            if (!$installedPlugin) {
                                throw new Exception('Mod not found in metadata');
                            }

                            $versions = $this->getProviderVersions($record['project_id'], $server);

                            if (empty($versions)) {
                                throw new Exception('No compatible versions found');
                            }

                            $latestVersion = $versions[0];

                            if (!isset($latestVersion['id'], $latestVersion['version_number'], $latestVersion['files'])) {
                                throw new Exception('Invalid version data structure');
                            }

                            $primaryFile = $this->getPrimaryFile($latestVersion['files']);

                            if (!$primaryFile) {
                                throw new Exception('No downloadable file found');
                            }

                            $this->performInstallOrUpdate($server, $fileRepository, $record, $latestVersion, $primaryFile, $installedPlugin);

                            $this->installedPluginsMetadata = null;
                            $this->versionsCache = [];

                            Notification::make()
                                ->title(trans('pelican-minecraft-plugins::strings.notifications.update_success'))
                                ->body(trans('pelican-minecraft-plugins::strings.notifications.update_success_body', [
                                    'version' => $latestVersion['version_number'],
                                ]))
                                ->success()
                                ->send();
                        } catch (Exception $exception) {
                            report($exception);

                            $this->installedPluginsMetadata = null;
                            $this->versionsCache = [];

                            Notification::make()
                                ->title(trans('pelican-minecraft-plugins::strings.notifications.update_failed'))
                                ->body(trans('pelican-minecraft-plugins::strings.notifications.update_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('manual_update')
                    ->iconButton()
                    ->extraAttributes(['class' => 'mx-0.5'])
                    ->icon('tabler-external-link')
                    ->color('warning')
                    ->tooltip(trans('pelican-minecraft-plugins::strings.actions.manual_spigot_update'))
                    ->url(fn (array $record): string => 'https://www.spigotmc.org/resources/'.($record['spigot_resource_id'] ?? substr((string) ($record['project_id'] ?? ''), 7)).'/', true)
                    ->visible(fn (array $record): bool => ($record['provider'] ?? null) === 'spigot'
                        && !empty($record['premium'])
                        && ($this->activeTab !== 'installed'
                            || ($this->updatesOnly && $this->recordHasAvailableUpdate($record)))),
                Action::make('installed')
                    ->iconButton()
                    ->extraAttributes(['class' => 'mx-0.5'])
                    ->icon('tabler-check')
                    ->color('success')
                    ->tooltip(trans('pelican-minecraft-plugins::strings.actions.installed'))
                    ->disabled()
                    ->hidden(fn (array $record): bool => $record['not_on_modrinth'] ?? false)
                    ->visible(function (array $record) {
                        if (empty($record['project_id'])) {
                            return false;
                        }

                        $installedPlugin = $this->getInstalledMod($record['project_id']);

                        if (is_null($installedPlugin)) {
                            return false;
                        }

                        $versions = $this->getCachedVersions($record['project_id']);

                        if (empty($versions)) {
                            return true;
                        }

                        return $installedPlugin['version_id'] === $versions[0]['id'];
                    }),
                Action::make('uninstall')
                    ->iconButton()
                    ->extraAttributes(['class' => 'mx-0.5'])
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->tooltip(trans('pelican-minecraft-plugins::strings.actions.uninstall'))
                    ->visible(function (array $record) {
                        if (($record['not_on_modrinth'] ?? false) === true) {
                            return true;
                        }

                        if (empty($record['project_id'])) {
                            return false;
                        }

                        return !is_null($this->getInstalledMod($record['project_id']));
                    })
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record) => trans('pelican-minecraft-plugins::strings.modals.uninstall_heading'))
                    ->modalDescription(fn (array $record) => trans('pelican-minecraft-plugins::strings.modals.uninstall_description', ['name' => $record['title']]))
                    ->action(function (array $record, DaemonFileRepository $fileRepository) {
                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();

                            $safeFilename = $this->getUninstallFilename($record);

                            $type = static::detectProjectType($server);
                            if (!$type) {
                                throw new Exception('Server does not support Minecraft plugins');
                            }

                            $folder = MinecraftModrinth::getProjectFolder($server, $fileRepository, $type);

                            Http::daemon($server->node)
                                ->post("/api/servers/{$server->uuid}/files/delete", [
                                    'root' => '/',
                                    'files' => [$folder.'/'.$safeFilename],
                                ])
                                ->throw();

                            Cache::forget(MinecraftModrinth::getHashScanCacheKey($server, $type));
                            $this->unknownFiles = array_values(
                                array_filter($this->unknownFiles, fn (string $filename) => strtolower($filename) !== strtolower($safeFilename))
                            );

                            $metadataRemoved = true;
                            if (!empty($record['project_id'])) {
                                $metadataRemoved = MinecraftModrinth::removePluginMetadata($server, $fileRepository, $record['project_id'], $type);
                            }

                            if (!$metadataRemoved) {
                                Log::warning('Failed to remove mod metadata after successful file deletion', [
                                    'project_id' => $record['project_id'],
                                    'server_id' => $server->id,
                                ]);

                                if (is_array($this->installedPluginsMetadata)) {
                                    $this->installedPluginsMetadata = array_values(
                                        array_filter($this->installedPluginsMetadata, fn ($plugin) => $plugin['project_id'] !== $record['project_id'])
                                    );
                                }

                                unset($this->versionsCache[$record['project_id']]);
                            } else {
                                $this->installedPluginsMetadata = null;
                                $this->versionsCache = [];
                            }

                            if ($this->activeTab === 'installed') {
                                $this->js('$wire.$refresh()');
                            }

                            Notification::make()
                                ->title(trans('pelican-minecraft-plugins::strings.notifications.uninstall_success'))
                                ->body(trans('pelican-minecraft-plugins::strings.notifications.uninstall_success_body', [
                                    'name' => $record['title'],
                                ]))
                                ->success()
                                ->send();
                        } catch (Exception $exception) {
                            report($exception);

                            $this->installedPluginsMetadata = null;
                            $this->versionsCache = [];

                            if ($this->activeTab === 'installed') {
                                $this->js('$wire.$refresh()');
                            }

                            Notification::make()
                                ->title(trans('pelican-minecraft-plugins::strings.notifications.uninstall_failed'))
                                ->body(trans('pelican-minecraft-plugins::strings.notifications.uninstall_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $type = static::detectProjectType($server);
        if (!$type) {
            return [];
        }

        if ($type === ModrinthProjectType::Plugin) {
            return [];
        }

        /** @var DaemonFileRepository $fileRepository */
        $fileRepository = app(DaemonFileRepository::class);
        $folder = MinecraftModrinth::getProjectFolder($server, $fileRepository, $type);

        $actions = [];

        if ($type === ModrinthProjectType::Plugin) {
            $actions[] = Action::make('source_modrinth')
                ->label('Modrinth')
                ->icon('tabler-world-search')
                ->color(fn (): string => $this->usesExternalProvider() ? 'gray' : 'primary')
                ->action(fn () => $this->switchSource('modrinth'));
            $actions[] = Action::make('source_hangar')
                ->label('Hangar')
                ->icon('tabler-box')
                ->color(fn (): string => $this->usesHangar() ? 'primary' : 'gray')
                ->action(fn () => $this->switchSource('hangar'));
            $actions[] = Action::make('source_spigot')
                ->label('Spigot')
                ->icon('tabler-plug')
                ->color(fn (): string => $this->usesSpiget() ? 'primary' : 'gray')
                ->action(fn () => $this->switchSource('spigot'));

        }

        $actions = array_merge($actions, [
            Action::make('open_folder')
                ->tooltip(fn () => trans('pelican-minecraft-plugins::strings.page.open_folder', ['folder' => $folder]))
                ->icon('tabler-folder-open')
                ->url(fn () => ListFiles::getUrl(['path' => $folder]), true),
            Action::make('update_all')
                ->label(trans('pelican-minecraft-plugins::strings.actions.update_all_plugins'))
                ->icon('tabler-download')
                ->color('warning')
                ->requiresConfirmation()
                ->action(function (DaemonFileRepository $fileRepository) use ($server) {
                    $result = $this->performBulkUpdate($server, $fileRepository);

                    if ($result['updated'] === 0 && $result['failed'] === 0) {
                        Notification::make()
                            ->title(trans('pelican-minecraft-plugins::strings.notifications.bulk_update_none'))
                            ->info()
                            ->send();

                        return;
                    }

                    if ($result['failed'] > 0) {
                        Notification::make()
                            ->title(trans('pelican-minecraft-plugins::strings.notifications.bulk_update_partial', [
                                'updated' => $result['updated'],
                                'failed' => $result['failed'],
                            ]))
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(trans('pelican-minecraft-plugins::strings.notifications.bulk_update_success', [
                            'count' => $result['updated'],
                        ]))
                        ->success()
                        ->send();
                })
                ->visible(fn () => static::detectProjectType($server) !== null),
            Action::make('scan_plugins')
                ->label(trans('pelican-minecraft-plugins::strings.actions.scan'))
                ->tooltip(trans('pelican-minecraft-plugins::strings.actions.rescan_plugins_for_updates'))
                ->icon('tabler-search')
                ->action(function () use ($server, $type) {
                    $this->clearPluginSourceDetectionCache($server);
                    Cache::forget(MinecraftModrinth::getHashScanCacheKey($server, $type));
                    $this->redirect(static::getUrl());
                })
                ->visible(fn () => static::detectProjectType($server) !== null),
        ]);

        return $actions;
    }

    public function content(Schema $schema): Schema
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $type = static::detectProjectType($server);
        $loader = MinecraftLoader::fromServer($server);
        $minecraftVersion = MinecraftModrinth::getMinecraftVersion($server);
        $serverCondition = null;

        try {
            $serverCondition = method_exists($server, 'condition')
                ? $server->condition
                : (method_exists($server, 'retrieveStatus') ? $server->retrieveStatus() : $server->status);
        } catch (\Throwable $exception) {
            report($exception);
            $serverCondition = $server->status;
        }

        $serverStatusValue = $serverCondition instanceof \BackedEnum
            ? $serverCondition->value
            : (is_string($serverCondition) ? $serverCondition : null);
        $serverStatus = match (strtolower((string) $serverStatusValue)) {
            'running' => 'Online',
            'offline', 'missing' => 'Offline',
            default => is_object($serverCondition) && method_exists($serverCondition, 'getLabel')
                ? $serverCondition->getLabel()
                : trans('pelican-minecraft-plugins::strings.page.unknown'),
        };
        $serverOnline = in_array(strtolower((string) $serverStatusValue), ['running', 'online'], true);
        $serverStatusTone = $serverOnline
            ? 'online'
            : (in_array(strtolower((string) $serverStatusValue), ['offline', 'missing', 'exited', 'dead', 'install_failed', 'reinstall_failed', 'suspended'], true)
                ? 'offline'
                : 'pending');
        $installedCount = 0;
        try {
            $availableUpdatesCount = $this->getAvailableUpdatesCount();
        } catch (\Throwable $exception) {
            report($exception);
            $availableUpdatesCount = 0;
        }
        $pluginFolder = $type?->getFolder($server) ?? 'plugins';

        try {
            /** @var DaemonFileRepository $fileRepository */
            $fileRepository = app(DaemonFileRepository::class);
            $pluginFolder = MinecraftModrinth::getProjectFolder($server, $fileRepository, $type);
            $files = $fileRepository->setServer($server)->getDirectory($pluginFolder);
            $extension = $type?->getFileExtension() ?? '.jar';
            $installedCount = collect($files)
                ->filter(fn ($file) => isset($file['name']) && str($file['name'])->lower()->endsWith($extension))
                ->count();
        } catch (Exception $exception) {
            report($exception);
        }

        return $schema
            ->components([
                View::make('pelican-minecraft-plugins::filament.server.plugin-manager')
                    ->viewData([
                        'server' => $server,
                        'type' => $type,
                        'loader' => $loader,
                        'minecraftVersion' => $minecraftVersion,
                        'installedCount' => $installedCount,
                        'availableUpdatesCount' => $availableUpdatesCount,
                        'source' => $this->source,
                        'activeTab' => $this->activeTab,
                        'updatesOnly' => $this->updatesOnly,
                        'serverStatus' => $serverStatus,
                        'serverOnline' => $serverOnline,
                        'serverStatusTone' => $serverStatusTone,
                    ])
                    ->columnSpanFull(),
                Grid::make(3)
                    ->schema([
                        Grid::make(1)
                            ->schema([
                                EmbeddedTable::make(),
                            ])
                            ->columnSpan(2),
                        View::make('pelican-minecraft-plugins::filament.server.plugin-manager-sidebar')
                            ->viewData([
                                'server' => $server,
                                'type' => $type,
                                'loader' => $loader,
                                'minecraftVersion' => $minecraftVersion,
                                'installedCount' => $installedCount,
                                'availableUpdatesCount' => $availableUpdatesCount,
                                'pluginFolder' => $pluginFolder,
                                'serverStatus' => $serverStatus,
                                'serverOnline' => $serverOnline,
                                'serverStatusTone' => $serverStatusTone,
                            ])
                            ->columnSpan(1),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
