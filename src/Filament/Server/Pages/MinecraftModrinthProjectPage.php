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
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
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

    public int $availableUpdatesCount = 0;

    public bool $availableUpdatesComputed = false;

    /** @var array<int, string> */
    public array $availableUpdateProjectIds = [];

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

    public function getHeading(): ?string
    {
        return null;
    }

    public function mount(): void
    {
        if (!in_array($this->source, ['modrinth', 'hangar'], true)
            || static::detectProjectType(Filament::getTenant()) !== ModrinthProjectType::Plugin) {
            $this->source = 'modrinth';
        }

        $this->loadDefaultActiveTab();
    }

    public function switchSource(string $source): void
    {
        if (!in_array($source, ['modrinth', 'hangar'], true)
            || static::detectProjectType(Filament::getTenant()) !== ModrinthProjectType::Plugin) {
            return;
        }

        $this->source = $source;
        $this->activeTab = 'all';
        $this->updatesOnly = false;
        $this->versionsCache = [];
        $this->availableUpdatesComputed = false;
        $this->setPage(1);
    }

    public function showAllPlugins(): void
    {
        $this->updatesOnly = false;
        $this->activeTab = 'all';
        $this->setPage(1);
    }

    public function showInstalledPlugins(): void
    {
        $this->updatesOnly = false;
        $this->activeTab = 'installed';
        $this->setPage(1);
    }

    public function showPluginUpdates(): void
    {
        $this->updatesOnly = true;
        $this->activeTab = 'installed';
        $this->installedPluginsMetadata = null;
        $this->versionsCache = [];
        $this->availableUpdatesComputed = false;
        $this->setPage(1);

        // Refresh the installed-file scan once when the Updates tab is opened.
        // Rendering the page repeatedly should not trigger another daemon scan.
        try {
            /** @var Server $server */
            $server = Filament::getTenant();
            /** @var DaemonFileRepository $fileRepository */
            $fileRepository = app(DaemonFileRepository::class);
            MinecraftModrinth::scanAndImportPlugins($server, $fileRepository, static::detectProjectType($server));
            $this->installedPluginsMetadata = null;
        } catch (\Throwable $exception) {
            report($exception);
        }

        $this->availableUpdatesCount = $this->getAvailableUpdatesCount();
    }

    public function updatedActiveTab(?string $activeTab): void
    {
        if ($activeTab !== 'installed') {
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

    /** @return array<int, array<string, mixed>> */
    private function getProviderVersions(string $projectId, Server $server, bool $forSourceDetection = false): array
    {
        if (str_starts_with($projectId, 'hangar:')) {
            return app(HangarService::class)->getVersions(substr($projectId, 7), $server);
        }

        if (str_contains($projectId, ':')) {
            return [];
        }

        return MinecraftModrinth::getModrinthVersions($projectId, $server, static::detectProjectType($server), $forSourceDetection);
    }

    /** @param array<string, mixed> $record */
    private function canSelectPluginVersion(array $record): bool
    {
        $projectId = (string) ($record['project_id'] ?? '');

        if ($projectId === '' || (str_contains($projectId, ':') && !str_starts_with($projectId, 'hangar:'))) {
            return false;
        }

        return !($record['not_on_modrinth'] ?? false);
    }

    private function getAvailableUpdatesCount(): int
    {
        if ($this->availableUpdatesComputed) {
            return $this->availableUpdatesCount;
        }

        $this->availableUpdateProjectIds = [];

        foreach ($this->getInstalledPluginsMetadata() as $installedPlugin) {
            $projectId = (string) ($installedPlugin['project_id'] ?? '');
            if ($projectId === '') {
                continue;
            }

            $versions = $this->getCachedVersions($projectId);
            if (isset($versions[0]['id']) && ($installedPlugin['version_id'] ?? null) !== $versions[0]['id']) {
                $this->availableUpdateProjectIds[] = $projectId;
            }
        }

        $this->availableUpdateProjectIds = array_values(array_unique($this->availableUpdateProjectIds));
        $this->availableUpdatesCount = count($this->availableUpdateProjectIds);
        $this->availableUpdatesComputed = true;

        return $this->availableUpdatesCount;
    }

    /** @param array<string, mixed> $record */
    private function recordHasAvailableUpdate(array $record): bool
    {
        $projectId = (string) ($record['project_id'] ?? '');

        return $projectId !== '' && in_array($projectId, $this->availableUpdateProjectIds, true);
    }

    /** @return array<string, mixed>|null */
    private function discoverInstalledPluginSource(Server $server, DaemonFileRepository $fileRepository, string $filename, ?string $fileFingerprint = null): ?array
    {
        $cacheKey = 'plugin_source_detection:v21:'.$server->id.':'.sha1(strtolower($filename));
        $cachedCandidate = Cache::get($cacheKey);

        // Reading a JAR through the daemon is expensive. The directory listing
        // gives us a stable size/modified-time fingerprint, so reuse a previous
        // successful or unresolved detection while that file remains unchanged.
        if ($fileFingerprint !== null
            && is_array($cachedCandidate)
            && hash_equals((string) ($cachedCandidate['_source_detection_fingerprint'] ?? ''), $fileFingerprint)) {
            if (!empty($cachedCandidate['unknown_source'])) {
                return null;
            }

            if (!empty($cachedCandidate['_metadata_saved'])) {
                return $cachedCandidate;
            }
        }

        $jarIdentity = $this->readPluginIdentityFromJar($server, $fileRepository, $filename);
        $jarHash = (string) ($jarIdentity['sha512'] ?? '');
        if (is_array($cachedCandidate)
            && (($cachedCandidate['artifact_sha512'] ?? null) !== $jarHash
                || (($cachedCandidate['provider'] ?? 'modrinth') !== 'modrinth'))) {
            Cache::forget($cacheKey);
            $cachedCandidate = null;
        }

        if (is_array($cachedCandidate) && !empty($cachedCandidate['unknown_source'])) {
            Log::warning('Plugin source detection unresolved (cached result)', [
                'server_id' => $server->id,
                'filename' => $filename,
                'plugin_name' => $jarIdentity['name'] ?? null,
                'plugin_version' => $jarIdentity['version'] ?? null,
                'plugin_website' => $jarIdentity['website'] ?? null,
                'artifact_sha512' => $jarHash !== '' ? $jarHash : null,
            ]);

            return null;
        }

        $projectName = trim((string) ($jarIdentity['name'] ?? ''));
        $website = (string) ($jarIdentity['website'] ?? '');
        $candidate = is_array($cachedCandidate) ? $cachedCandidate : null;

        if ($candidate === null) {
            $isModrinthUrl = str_contains(strtolower($website), 'modrinth.com/');

            if ($candidate === null && $isModrinthUrl) {
                $pathParts = array_values(array_filter(explode('/', trim((string) parse_url($website, PHP_URL_PATH), '/'))));
                $modrinthSlug = (string) (end($pathParts) ?: '');
                if (is_string($modrinthSlug) && $modrinthSlug !== '') {
                    $candidate = MinecraftModrinth::findProjectByName(
                        $server,
                        $modrinthSlug,
                        static::detectProjectType($server)
                    );
                    if ($candidate) {
                        $candidate['project_id'] = $candidate['id'] ?? $candidate['project_id'] ?? '';
                        $candidate['provider'] = 'modrinth';
                    }
                }
            }

            // Match the JAR by its SHA-512 checksum against Modrinth's public
            // version hash API before trying title-based marketplace search.
            // This recognizes renamed JARs and never relies on plugin-specific
            // mapping tables.
            if ($candidate === null && strlen($jarHash) === 128) {
                $matchedVersion = Cache::remember(
                    'modrinth_artifact_hash:v1:'.$jarHash,
                    now()->addMinutes(30),
                    fn () => MinecraftModrinth::lookupVersionsByHashes([$filename => $jarHash])[$jarHash] ?? null
                );

                if (is_array($matchedVersion) && !empty($matchedVersion['project_id'])) {
                    $modrinthProject = MinecraftModrinth::findProjectById((string) $matchedVersion['project_id']);
                    if (is_array($modrinthProject)) {
                        $candidate = $modrinthProject;
                        $candidate['provider'] = 'modrinth';
                        $candidate['_matched_version'] = $matchedVersion;
                    }
                }
            }

            if ($candidate !== null) {
                $candidate['provider'] = 'modrinth';
                $candidate['artifact_sha512'] = $jarHash;
                Cache::put($cacheKey, $candidate, now()->addHours(6));
            } else {
                // Unknowns are retried on the next explicit rescan.
                Cache::put($cacheKey, [
                    'unknown_source' => true,
                    'artifact_sha512' => $jarHash,
                    '_source_detection_fingerprint' => $fileFingerprint,
                ], $fileFingerprint !== null ? now()->addMinutes(10) : now()->addMinute());
                Log::warning('Plugin source detection unresolved', [
                    'server_id' => $server->id,
                    'filename' => $filename,
                    'plugin_name' => $projectName !== '' ? $projectName : null,
                    'plugin_version' => $jarIdentity['version'] ?? null,
                    'plugin_website' => $website !== '' ? $website : null,
                ]);
            }
        }

        if (!is_array($candidate) || !empty($candidate['unknown_source'])) {
            return null;
        }

        $candidate['project_id'] = $candidate['project_id'] ?? $candidate['id'] ?? '';
        $candidate['provider'] = 'modrinth';

        $matchedVersion = is_array($candidate['_matched_version'] ?? null) ? $candidate['_matched_version'] : [];
        $versionNumber = (string) ($matchedVersion['version_number'] ?? '');
        if ($versionNumber === '') {
            $versionNumber = preg_match('/(?:[-_ ]v?)(\d+(?:\.\d+){1,3}(?:[-+][A-Za-z0-9._-]+)?)\.jar$/i', $filename, $versionMatch) === 1
                ? $versionMatch[1]
                : 'Detected';
        }
        $candidateTitle = $candidate['title'] ?? ($projectName !== '' ? $projectName : $filename);

        $saved = MinecraftModrinth::savePluginMetadata(
            $server,
            $fileRepository,
            (string) $candidate['project_id'],
            (string) ($candidate['slug'] ?? $candidate['project_id']),
            (string) $candidateTitle,
            (string) ($matchedVersion['id'] ?? ('detected:'.sha1(strtolower($filename)))),
            $versionNumber,
            $filename,
            isset($candidate['author']) ? (string) $candidate['author'] : null,
            static::detectProjectType($server)
        );

        if (!$saved) {
            Log::warning('Plugin source matched but metadata could not be saved', [
                'server_id' => $server->id,
                'filename' => $filename,
                'project_id' => $candidate['project_id'] ?? null,
                'provider' => $candidate['provider'] ?? null,
                'version_id' => $matchedVersion['id'] ?? null,
            ]);
        } elseif ($fileFingerprint !== null) {
            $candidate['_source_detection_fingerprint'] = $fileFingerprint;
            $candidate['_metadata_saved'] = true;
            Cache::put($cacheKey, $candidate, now()->addHours(6));
        }

        return $saved ? $candidate : null;
    }

    /** @return array{name?: string, website?: string, version?: string, aliases?: array<int, string>, sha512?: string} */
    private function readPluginIdentityFromJar(Server $server, DaemonFileRepository $fileRepository, string $filename): array
    {
        $type = static::detectProjectType($server);
        if (!$type) {
            return [];
        }

        try {
            $folder = MinecraftModrinth::getProjectFolder($server, $fileRepository, $type);
            $contents = $fileRepository->setServer($server)->getContent("{$folder}/{$filename}");
            if (!is_string($contents) || $contents === '') {
                return [];
            }

            $identity = ['sha512' => hash('sha512', $contents)];
            $aliases = [];
            foreach (['paper-plugin.yml', 'plugin.yml', 'bungee.yml'] as $descriptorName) {
                $descriptor = $this->readZipEntry($contents, $descriptorName);
                if (!is_string($descriptor)) {
                    continue;
                }

                $descriptorNameValue = $this->readYamlScalar($descriptor, 'name');
                if ($descriptorNameValue !== null) {
                    $aliases[] = $descriptorNameValue;
                    $identity['name'] ??= $descriptorNameValue;
                }

                if (!isset($identity['website'])) {
                    $descriptorWebsite = $this->readYamlScalar($descriptor, 'website');
                    if ($descriptorWebsite !== null) {
                        $identity['website'] = $descriptorWebsite;
                    }
                }

                if (!isset($identity['version'])) {
                    $descriptorVersion = $this->readYamlScalar($descriptor, 'version');
                    if ($descriptorVersion !== null) {
                        $identity['version'] = $descriptorVersion;
                    }
                }
            }

            $velocityDescriptor = $this->readZipEntry($contents, 'velocity-plugin.json');
            if (is_string($velocityDescriptor)) {
                $velocityData = json_decode($velocityDescriptor, true);
                if (is_array($velocityData)) {
                    foreach (['name', 'url', 'website'] as $sourceKey) {
                        $targetKey = $sourceKey === 'name' ? 'name' : 'website';
                        $value = $velocityData[$sourceKey] ?? null;
                        if (is_string($value) && trim($value) !== '') {
                            if ($targetKey === 'name') {
                                $aliases[] = trim($value);
                                $identity['name'] ??= trim($value);
                            } else {
                                $identity['website'] ??= trim($value);
                            }
                        }
                    }
                }
            }

            $manifest = $this->readZipEntry($contents, 'META-INF/MANIFEST.MF');
            if (is_string($manifest)
                && preg_match_all('/^(?:Implementation|Specification)-Title:\s*(.+)$/mi', $manifest, $matches) > 0) {
                foreach ($matches[1] as $manifestTitle) {
                    $manifestTitle = trim($manifestTitle);
                    if ($manifestTitle !== '') {
                        $aliases[] = $manifestTitle;
                        $identity['name'] ??= $manifestTitle;
                    }
                }
            }

            if (!isset($identity['website']) && is_string($manifest)
                && preg_match('/^(?:Implementation|Specification)-URL:\s*(https?:\/\/\S+)$/mi', $manifest, $matches) === 1) {
                $identity['website'] = trim($matches[1]);
            }

            $identity['aliases'] = array_values(array_unique($aliases));

            return $identity;
        } catch (\Throwable $exception) {
            report($exception);

            return [];
        }
    }

    private function readZipEntry(string $contents, string $entryName): ?string
    {
        $archiveLength = strlen($contents);
        $searchOffset = max(0, $archiveLength - 65557);
        $endRecordPosition = strrpos(substr($contents, $searchOffset), "PK\x05\x06");
        if ($endRecordPosition === false) {
            return null;
        }

        $endRecordPosition += $searchOffset;
        if ($archiveLength - $endRecordPosition < 22) {
            return null;
        }

        $endRecord = unpack('vdisk/vstart/ventriesDisk/ventries/VcentralSize/VcentralOffset/vcommentLength', substr($contents, $endRecordPosition + 4, 18));
        if (!is_array($endRecord)
            || ($endRecord['entries'] ?? 0) === 0xffff
            || ($endRecord['centralOffset'] ?? 0) === 0xffffffff
            || ($endRecord['centralSize'] ?? 0) === 0xffffffff) {
            return null;
        }

        $centralOffset = (int) $endRecord['centralOffset'];
        $centralEnd = $centralOffset + (int) $endRecord['centralSize'];
        if ($centralOffset < 0 || $centralEnd > $endRecordPosition) {
            return null;
        }

        $offset = $centralOffset;
        for ($index = 0; $index < (int) $endRecord['entries'] && $offset + 46 <= $centralEnd; $index++) {
            if (substr($contents, $offset, 4) !== "PK\x01\x02") {
                return null;
            }

            $header = unpack('vflags/vmethod/x4/Vcrc/Vcompressed/Vuncompressed/vnameLength/vextraLength/vcommentLength/x8/VlocalOffset', substr($contents, $offset + 8, 38));
            if (!is_array($header)) {
                return null;
            }

            $nameLength = (int) $header['nameLength'];
            $extraLength = (int) $header['extraLength'];
            $commentLength = (int) $header['commentLength'];
            $name = substr($contents, $offset + 46, $nameLength);

            if ($name === $entryName) {
                $compressedSize = (int) $header['compressed'];
                $uncompressedSize = (int) $header['uncompressed'];
                $localOffset = (int) $header['localOffset'];
                if ($uncompressedSize > 1048576 || $localOffset + 30 > $archiveLength
                    || substr($contents, $localOffset, 4) !== "PK\x03\x04") {
                    return null;
                }

                $localHeader = unpack('vflags/vmethod/x16/vnameLength/vextraLength', substr($contents, $localOffset + 6, 24));
                if (!is_array($localHeader)) {
                    return null;
                }

                $dataOffset = $localOffset + 30 + (int) $localHeader['nameLength'] + (int) $localHeader['extraLength'];
                if ($compressedSize > $archiveLength || $dataOffset + $compressedSize > $archiveLength) {
                    return null;
                }

                $data = substr($contents, $dataOffset, $compressedSize);
                $result = match ((int) $header['method']) {
                    0 => $data,
                    8 => gzinflate($data),
                    default => null,
                };

                return is_string($result) && strlen($result) === $uncompressedSize ? $result : null;
            }

            $offset += 46 + $nameLength + $extraLength + $commentLength;
        }

        return null;
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
        $stagingFilename = '.plugin-update-'.bin2hex(random_bytes(8)).'.part';

        $type = static::detectProjectType($server);
        if (!$type) {
            throw new Exception('Server does not support Minecraft plugins');
        }

        $folder = MinecraftModrinth::getProjectFolder($server, $fileRepository, $type);

        try {
            try {
                $fileRepository->setServer($server)->pull($primaryFile['url'], $folder, [
                    'filename' => $stagingFilename,
                    'use_header' => false,
                    'foreground' => true,
                ]);
            } catch (\Throwable $downloadException) {
                $fallbackUrl = $primaryFile['fallback_url'] ?? null;
                if (!is_string($fallbackUrl) || $fallbackUrl === '') {
                    throw $downloadException;
                }

                $fileRepository->setServer($server)->pull($fallbackUrl, $folder, [
                    'filename' => $stagingFilename,
                    'use_header' => false,
                    'foreground' => true,
                ]);
            }
        } catch (\Throwable $downloadException) {
            try {
                $fileRepository->setServer($server)->deleteFiles($folder, [$stagingFilename]);
            } catch (\Throwable $cleanupException) {
                report($cleanupException);
            }

            throw $downloadException;
        }

        $downloadedFilenames = collect($fileRepository->setServer($server)->getDirectory($folder))
            ->filter(fn ($file) => is_array($file) && isset($file['name']))
            ->pluck('name')
            ->map(fn (string $filename) => strtolower($filename));
        if (!$downloadedFilenames->contains(strtolower($stagingFilename))) {
            try {
                $fileRepository->setServer($server)->deleteFiles($folder, [$stagingFilename]);
            } catch (\Throwable $cleanupException) {
                report($cleanupException);
            }

            throw new Exception('Downloaded plugin file was not found in the target directory');
        }

        $sameFile = $oldFilename !== null && strtolower($oldFilename) === strtolower($safeNewFilename);
        $targetAlreadyExists = $downloadedFilenames->contains(strtolower($safeNewFilename));
        $backupOriginalFilename = null;
        if ($sameFile) {
            $backupOriginalFilename = $oldFilename;
        } elseif ($oldFilename !== null && $targetAlreadyExists) {
            // Some providers change the JAR filename between releases. Keep
            // any pre-existing target safe while replacing the tracked plugin.
            $backupOriginalFilename = $safeNewFilename;
        }

        $backupFilename = $backupOriginalFilename !== null
            ? '.plugin-backup-'.bin2hex(random_bytes(8)).'.part'
            : null;

        // Keep the currently installed JAR untouched until the replacement has
        // finished downloading. A failed pull to the final name can otherwise
        // truncate or remove that file.
        if ($backupFilename !== null) {
            $fileRepository->setServer($server)->renameFiles($folder, [[
                'from' => $backupOriginalFilename,
                'to' => $backupFilename,
            ]]);
        } elseif ($targetAlreadyExists) {
            // Don't overwrite an existing jar unless it is the tracked file
            // that is being backed up and replaced.
            $fileRepository->setServer($server)->deleteFiles($folder, [$stagingFilename]);
            throw new Exception('A file with the target plugin filename already exists');
        }

        try {
            $fileRepository->setServer($server)->renameFiles($folder, [[
                'from' => $stagingFilename,
                'to' => $safeNewFilename,
            ]]);
        } catch (\Throwable $promotionException) {
            if ($backupFilename !== null) {
                try {
                    $fileRepository->setServer($server)->renameFiles($folder, [[
                        'from' => $backupFilename,
                        'to' => $backupOriginalFilename,
                    ]]);
                } catch (\Throwable $restoreException) {
                    report($restoreException);
                }
            }

            try {
                $fileRepository->setServer($server)->deleteFiles($folder, [$stagingFilename]);
            } catch (\Throwable $cleanupException) {
                report($cleanupException);
            }

            throw $promotionException;
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
            if ($backupFilename !== null) {
                try {
                    $fileRepository->setServer($server)->deleteFiles($folder, [$safeNewFilename]);
                    $fileRepository->setServer($server)->renameFiles($folder, [[
                        'from' => $backupFilename,
                        'to' => $backupOriginalFilename,
                    ]]);
                } catch (\Throwable $rollbackException) {
                    report($rollbackException);
                }
            } else {
                try {
                    $fileRepository->setServer($server)->deleteFiles($folder, [$safeNewFilename]);
                } catch (\Throwable $rollbackException) {
                    report($rollbackException);
                }
            }

            throw new Exception('Failed to save mod metadata');
        }

        if ($backupFilename !== null) {
            try {
                $fileRepository->setServer($server)->deleteFiles($folder, [$backupFilename]);
            } catch (\Throwable $cleanupException) {
                // A leftover backup is safer than rolling back a valid update
                // and risking deletion of the plugin that just downloaded.
                report($cleanupException);
            }
        }

        if ($oldFilename && strtolower($oldFilename) !== strtolower($safeNewFilename)) {
            try {
                $fileRepository->setServer($server)->deleteFiles($folder, [$oldFilename]);
            } catch (\Throwable $cleanupException) {
                // The update is already committed; keep the new plugin and
                // report the cleanup issue instead of deleting the new JAR.
                report($cleanupException);
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

                if (str_contains((string) $installedPlugin['project_id'], ':')) {
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
            $this->availableUpdatesComputed = false;
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

                $filenameHash = sha1(strtolower($file['name']));
                foreach (['v17', 'v18', 'v19', 'v20', 'v21'] as $cacheVersion) {
                    Cache::forget('plugin_source_detection:'.$cacheVersion.':'.$server->id.':'.$filenameHash);
                }
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

                    $type = static::detectProjectType($server);
                    $folder = $type ? MinecraftModrinth::getProjectFolder($server, $fileRepository, $type) : null;
                    try {
                        $contents = $folder !== null
                            ? $fileRepository->setServer($server)->getDirectory($folder)
                            : [];
                    } catch (Exception $exception) {
                        report($exception);
                        $contents = [];
                    }
                    $fileFingerprints = [];
                    foreach (is_array($contents) ? $contents : [] as $file) {
                        if (!is_array($file) || empty($file['name']) || !isset($file['size'])) {
                            continue;
                        }

                        $modifiedAt = $file['modified_at'] ?? $file['modified'] ?? $file['mtime'] ?? null;
                        if ($modifiedAt === null) {
                            continue;
                        }

                        $fileFingerprints[strtolower((string) $file['name'])] = sha1(implode('|', [
                            (string) $file['name'],
                            (string) $file['size'],
                            (string) $modifiedAt,
                        ]));
                    }

                    $staleMetadataRemoved = false;
                    foreach ($installedPlugins as $plugin) {
                        if (!str_starts_with((string) ($plugin['version_id'] ?? ''), 'detected:')) {
                            continue;
                        }

                        if ($this->discoverInstalledPluginSource(
                            $server,
                            $fileRepository,
                            $plugin['filename'],
                            $fileFingerprints[strtolower($plugin['filename'])] ?? null
                        )) {
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
                        $this->availableUpdatesComputed = false;
                        $installedPlugins = $this->getInstalledPluginsMetadata();
                    }

                    $metadataBefore = count($installedPlugins);

                    try {
                        // First import exact Modrinth hash matches, then
                        // identify other sources from their JAR metadata.
                        $unknownFiles = MinecraftModrinth::scanAndImportPlugins($server, $fileRepository, static::detectProjectType($server));
                        $this->installedPluginsMetadata = null;
                        $this->availableUpdatesComputed = false;
                        $installedPlugins = $this->getInstalledPluginsMetadata();

                        $importedCount = max(0, count($installedPlugins) - $metadataBefore);
                        if ($importedCount > 0) {
                            Notification::make()
                                ->title(trans('pelican-minecraft-plugins::strings.notifications.scan_success', ['count' => $importedCount]))
                                ->success()
                                ->send();
                        }

                        if ($type) {
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
                                if ($this->discoverInstalledPluginSource(
                                    $server,
                                    $fileRepository,
                                    $filename,
                                    $fileFingerprints[strtolower($filename)] ?? null
                                )) {
                                    $identifiedAny = true;
                                }
                            }

                            if ($identifiedAny) {
                                $this->installedPluginsMetadata = null;
                                $this->availableUpdatesComputed = false;
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
                    if (!empty($installedPlugins)) {
                        $installedPages = (int) ceil(count($installedPlugins) / $perPage);
                        for ($installedPage = 1; $installedPage <= $installedPages; $installedPage++) {
                            $projects = array_merge($projects, MinecraftModrinth::getInstalledPluginsFromModrinth($installedPlugins, $installedPage));
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
                        $projects = array_values(array_filter(
                            $projects,
                            fn (array $project): bool => in_array(
                                (string) ($project['project_id'] ?? ''),
                                $this->availableUpdateProjectIds,
                                true
                            )
                        ));
                    }

                    $totalCount = count($projects);
                    $lastPage = max(1, (int) ceil($totalCount / $perPage));
                    // The Updates view can contain far fewer rows than the
                    // Installed view. Keep a stale `page` query parameter
                    // from making a non-empty filtered result look empty.
                    $page = min(max(1, $page), $lastPage);
                    $offset = ($page - 1) * $perPage;
                    $pagedProjects = array_slice($projects, $offset, $perPage);

                    return new LengthAwarePaginator($pagedProjects, $totalCount, $perPage, $page);
                } else {
                    $response = $this->source === 'hangar'
                        ? app(HangarService::class)->getProjects($server, $page, $search)
                        : MinecraftModrinth::getModrinthProjects($server, $page, $search, static::detectProjectType($server));
                    return new LengthAwarePaginator($response['hits'], $response['total_hits'], 20, $page);
                }
            })
            ->paginated([20])
            ->columns([
                Split::make([
                    ImageColumn::make('icon_url')
                        ->label('')
                        ->imageSize(40)
                        ->grow(false),
                    Stack::make([
                        TextColumn::make('title')
                            ->label('')
                            ->weight('bold')
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

                                return (strlen($description) > 95) ? substr($description, 0, 95).'...' : $description;
                            }),
                    ]),
                    TextColumn::make('provider')
                        ->label('')
                        ->badge()
                        ->formatStateUsing(fn (?string $state) => trans(match ($state) {
                            'hangar' => 'pelican-minecraft-plugins::strings.badges.source_hangar',
                            'modrinth' => 'pelican-minecraft-plugins::strings.badges.source_modrinth',
                            default => 'pelican-minecraft-plugins::strings.badges.unknown_source',
                        }))
                        ->color(fn (?string $state) => match ($state) {
                            'hangar' => 'info',
                            'modrinth' => 'success',
                            default => 'gray',
                        })
                        ->visible(fn (): bool => $this->activeTab === 'installed')
                        ->grow(false),
                    TextColumn::make('downloads')
                        ->label('')
                        ->icon('tabler-download')
                        ->numeric()
                        ->alignEnd()
                        ->width('8rem')
                        ->visible(fn (): bool => $this->activeTab !== 'installed')
                        ->grow(false),
                    TextColumn::make('date_modified')
                        ->label('')
                        ->icon('tabler-calendar')
                        ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state, 'UTC')->diffForHumans() : '')
                        ->tooltip(fn ($state) => $state ? Carbon::parse($state, 'UTC')->timezone(user()->timezone ?? 'UTC')->format($table->getDefaultDateTimeDisplayFormat()) : '')
                        ->width('8rem')
                        ->visible(fn (): bool => $this->activeTab === 'installed')
                        ->grow(false),
                ])->from('md'),
            ])
            ->recordUrl(function (array $record) {
                if (!empty($record['unavailable']) || ($record['not_on_modrinth'] ?? false)) {
                    return null;
                }

                if (($record['provider'] ?? $this->source) === 'hangar') {
                    $path = $record['hangar_path'] ?? trim(($record['author'] ?? '').'/'.($record['slug'] ?? ''), '/');

                    return $path !== '' ? "https://hangar.papermc.io/{$path}" : null;
                }

                /** @var Server $server */
                $server = Filament::getTenant();
                $projectType = static::detectProjectType($server)?->value ?? $record['project_type'];

                return "https://modrinth.com/{$projectType}/{$record['slug']}";
            }, true)
            ->recordActions([
                Action::make('versions')
                    ->iconButton()
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
                                            $this->availableUpdatesComputed = false;
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
                                            $this->availableUpdatesComputed = false;
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
                    ->icon('tabler-download')
                    ->color('success')
                    ->tooltip(trans('pelican-minecraft-plugins::strings.actions.install_latest'))
                    ->hidden(fn (array $record): bool => $record['not_on_modrinth'] ?? false)
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
                            $this->availableUpdatesComputed = false;
                            $this->resetPage();
                            $this->js('$wire.$refresh()');

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
                            $this->availableUpdatesComputed = false;

                            Notification::make()
                                ->title(trans('pelican-minecraft-plugins::strings.notifications.install_failed'))
                                ->body(trans('pelican-minecraft-plugins::strings.notifications.install_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('update')
                    ->iconButton()
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
                            $this->availableUpdatesComputed = false;
                            $this->resetPage();
                            $this->js('$wire.$refresh()');

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
                            $this->availableUpdatesComputed = false;

                            Notification::make()
                                ->title(trans('pelican-minecraft-plugins::strings.notifications.update_failed'))
                                ->body(trans('pelican-minecraft-plugins::strings.notifications.update_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('installed')
                    ->iconButton()
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
                                $this->availableUpdatesComputed = false;
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
                            $this->availableUpdatesComputed = false;

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
            $this->availableUpdatesCount = $this->getAvailableUpdatesCount();
        } catch (\Throwable $exception) {
            report($exception);
            $this->availableUpdatesCount = 0;
        }
        $availableUpdatesCount = $this->availableUpdatesCount;
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

        $managerViewData = [
            'server' => $server,
            'type' => $type,
            'loader' => $loader,
            'minecraftVersion' => $minecraftVersion,
            'installedCount' => $installedCount,
            'availableUpdatesCount' => $availableUpdatesCount,
            'activeTab' => $this->activeTab,
            'source' => $this->source,
            'updatesOnly' => $this->updatesOnly,
            'serverStatus' => $serverStatus,
            'serverOnline' => $serverOnline,
            'serverStatusTone' => $serverStatusTone,
        ];

        return $schema
            ->components([
                View::make('pelican-minecraft-plugins::filament.server.plugin-manager')
                    ->viewData($managerViewData)
                    ->columnSpanFull(),
                View::make('pelican-minecraft-plugins::filament.server.plugin-manager-tabs')
                    ->viewData($managerViewData)
                    ->columnSpanFull(),
                Grid::make(['default' => 1, 'md' => 2])
                    ->schema([
                        Section::make('Modrinth')
                            ->description('Plugins and mods')
                            ->icon('mcbrand-modrinth')
                            ->iconColor('success')
                            ->headerActions($this->source === 'modrinth' ? [
                                Action::make('active_modrinth_source')
                                    ->label(trans('pelican-minecraft-plugins::strings.page.source_selected'))
                                    ->icon('tabler-check')
                                    ->color('success')
                                    ->disabled()
                                    ->button(),
                            ] : [])
                            ->extraAttributes([
                                'wire:click' => "switchSource('modrinth')",
                                'class' => 'cursor-pointer '.($this->source === 'modrinth' ? 'ring-2 ring-primary-500' : ''),
                            ]),
                        Section::make(new HtmlString('<span class="inline-flex items-center gap-2"><img src="https://hangar.papermc.io/_nuxt/hangar-logo.DNKyJEtq.svg" alt="" width="20" height="20" class="object-contain">Hangar</span>'))
                            ->description('Paper / Velocity plugins')
                            ->headerActions($this->source === 'hangar' ? [
                                Action::make('active_hangar_source')
                                    ->label(trans('pelican-minecraft-plugins::strings.page.source_selected'))
                                    ->icon('tabler-check')
                                    ->color('success')
                                    ->disabled()
                                    ->button(),
                            ] : [])
                            ->extraAttributes([
                                'wire:click' => "switchSource('hangar')",
                                'class' => 'cursor-pointer '.($this->source === 'hangar' ? 'ring-2 ring-primary-500' : ''),
                            ]),
                    ])
                    ->visible(static::detectProjectType($server) === ModrinthProjectType::Plugin)
                    ->columnSpanFull(),
                View::make('pelican-minecraft-plugins::filament.server.plugin-manager-filters')
                    ->viewData($managerViewData)
                    ->columnSpanFull(),
                Grid::make(['default' => 1, 'xl' => 3])
                    ->schema([
                        EmbeddedTable::make()
                            ->columnSpan(['default' => 1, 'xl' => 2]),
                        Grid::make(1)
                            ->schema([
                                Section::make(trans('pelican-minecraft-plugins::strings.page.server_information'))
                                    ->icon('tabler-server-2')
                                    ->schema([
                                        TextEntry::make('server_name')
                                            ->label('')
                                            ->state($server->name)
                                            ->weight('bold'),
                                        TextEntry::make('server_type')
                                            ->label('')
                                            ->state(($loader?->getLabel() ?? trans('pelican-minecraft-plugins::strings.page.unknown')).' · '.($minecraftVersion ?? trans('pelican-minecraft-plugins::strings.page.unknown'))),
                                        TextEntry::make('server_status')
                                            ->label('')
                                            ->state($serverStatus)
                                            ->badge()
                                            ->color(match ($serverStatusTone) {
                                                'online' => 'success',
                                                'offline' => 'danger',
                                                default => 'warning',
                                            }),
                                    ]),
                                Section::make(trans('pelican-minecraft-plugins::strings.page.quick_actions'))
                                    ->icon('tabler-bolt')
                                    ->schema([
                                        Actions::make([
                                            Action::make('update_all')
                                                ->label(trans('pelican-minecraft-plugins::strings.actions.update_all_plugins'))
                                                ->icon('tabler-refresh')
                                                ->color('warning')
                                                ->button()
                                                ->requiresConfirmation()
                                                ->action(function (DaemonFileRepository $fileRepository) use ($server): void {
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
                                                }),
                                        ])->fullWidth(),
                                        Actions::make([
                                            Action::make('scan_plugins')
                                                ->label(trans('pelican-minecraft-plugins::strings.actions.scan'))
                                                ->icon('tabler-search')
                                                ->color('gray')
                                                ->button()
                                                ->action(function () use ($server, $type): void {
                                                    $this->clearPluginSourceDetectionCache($server);
                                                    Cache::forget(MinecraftModrinth::getHashScanCacheKey($server, $type));
                                                    $this->redirect(static::getUrl());
                                                }),
                                        ])->fullWidth(),
                                        Actions::make([
                                            Action::make('open_plugin_folder')
                                                ->label(trans('pelican-minecraft-plugins::strings.page.open_folder', ['folder' => $pluginFolder]))
                                                ->icon('tabler-folder')
                                                ->color('gray')
                                                ->button()
                                                ->url(fn () => ListFiles::getUrl(['path' => $pluginFolder]), true),
                                        ])->fullWidth(),
                                    ]),
                                Section::make(trans('pelican-minecraft-plugins::strings.page.statistics'))
                                    ->icon('tabler-chart-bar')
                                    ->schema([
                                        TextEntry::make('total_plugins')
                                            ->label(trans('pelican-minecraft-plugins::strings.page.total_plugins'))
                                            ->state($installedCount)
                                            ->inlineLabel()
                                            ->badge()
                                            ->color('success'),
                                        TextEntry::make('available_updates')
                                            ->label(trans('pelican-minecraft-plugins::strings.page.available_updates'))
                                            ->state($availableUpdatesCount)
                                            ->inlineLabel()
                                            ->badge()
                                            ->color('info'),
                                        TextEntry::make('supported_sources')
                                            ->label(trans('pelican-minecraft-plugins::strings.page.supported_sources'))
                                            ->state(static::detectProjectType($server) === ModrinthProjectType::Plugin ? 2 : 1)
                                            ->inlineLabel()
                                            ->badge()
                                            ->color('gray'),
                                    ]),
                            ])
                            ->columnSpan(['default' => 1, 'xl' => 1]),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
