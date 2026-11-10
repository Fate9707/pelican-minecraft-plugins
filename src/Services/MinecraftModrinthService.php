<?php

namespace FateFighter\MinecraftPlugins\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use FateFighter\MinecraftPlugins\Enums\ModrinthProjectType;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MinecraftModrinthService
{
    public function getMinecraftVersion(Server $server): ?string
    {
        $version = $server->variables()->where(fn ($builder) => $builder->where('env_variable', 'MINECRAFT_VERSION')->orWhere('env_variable', 'MC_VERSION'))->first()?->server_value;

        if (!$version || $version === 'latest') {
            return config('pelican-minecraft-modrinth.latest_minecraft_version');
        }

        return $version;
    }

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    public function getModrinthProjects(Server $server, int $page = 1, ?string $search = null, ?ModrinthProjectType $type = null): array
    {
        $type ??= ModrinthProjectType::fromServer($server);

        if (!$type) {
            return [
                'hits' => [],
                'total_hits' => 0,
            ];
        }

        $minecraftLoader = $type->getModrinthLoader($server);
        $projectType = $type->value;
        $minecraftVersion = $this->getMinecraftVersion($server);

        if (!$minecraftLoader) {
            // Keep discovery usable even when the egg does not expose its loader.
            // Installation/version selection still needs a detected loader.
            $facets = "[[\"versions:$minecraftVersion\"],[\"project_type:{$projectType}\"]]";
        } else {
            $facets = "[[\"categories:$minecraftLoader\"],[\"versions:$minecraftVersion\"],[\"project_type:{$projectType}\"]]";
        }

        $data = [
            'offset' => ($page - 1) * 20,
            'limit' => 20,
            'facets' => $facets,
        ];

        $key = "modrinth_projects:{$projectType}:$minecraftVersion:" . ($minecraftLoader ?? 'unknown') . ":$page";

        if ($search) {
            $data['query'] = $search;

            $key .= ":$search";
        }

        return cache()->remember($key, now()->addMinutes(30), function () use ($data) {
            try {
                return Http::asJson()
                    ->timeout(5)
                    ->connectTimeout(5)
                    ->throw()
                    ->get('https://api.modrinth.com/v2/search', $data)
                    ->json();
            } catch (Exception $exception) {
                report($exception);

                return [
                    'hits' => [],
                    'total_hits' => 0,
                ];
            }
        });
    }

    /** @return array<string, mixed>|null */
    public function findProjectByName(Server $server, string $name, ?ModrinthProjectType $type = null): ?array
    {
        $expected = preg_replace('/[^a-z0-9]+/', '', strtolower(trim($name))) ?? '';
        if ($expected === '') {
            return null;
        }

        $projects = Cache::remember('modrinth_find_name:'.md5($expected), now()->addMinutes(10), function () use ($name, $type, $server) {
            try {
                return Http::asJson()
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->throw()
                    ->get('https://api.modrinth.com/v2/search', [
                        'query' => $name,
                        'limit' => 20,
                        'facets' => '[ ["project_type:'.($type?->value ?? ModrinthProjectType::fromServer($server)?->value ?? 'plugin').'" ] ]',
                    ])
                    ->json();
            } catch (Exception $exception) {
                report($exception);

                return ['hits' => []];
            }
        });
        foreach (is_array($projects) ? ($projects['hits'] ?? []) : [] as $project) {
            if (!is_array($project)) {
                continue;
            }

            $title = preg_replace('/[^a-z0-9]+/', '', strtolower((string) ($project['title'] ?? ''))) ?? '';
            $slug = preg_replace('/[^a-z0-9]+/', '', strtolower((string) ($project['slug'] ?? ''))) ?? '';
            if ($title !== $expected && $slug !== $expected) {
                continue;
            }

            $project['provider'] = 'modrinth';

            return $project;
        }

        return null;
    }

    /**
     * @param array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}> $installedPlugins
     * @return array<int, array<string, mixed>>
     */
    public function getInstalledPluginsFromModrinth(array $installedPlugins, int $page = 1): array
    {
        if (empty($installedPlugins)) {
            return [];
        }

        $projectIds = collect($installedPlugins)->pluck('project_id')->unique()->values()->all();

        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        $pageIds = array_slice($projectIds, $offset, $perPage);

        if (empty($pageIds)) {
            return [];
        }

        $idsParam = '["'.implode('","', $pageIds).'"]';
        $key = 'modrinth_bulk:'.md5($idsParam);

        $modrinthProjects = cache()->remember($key, now()->addMinutes(30), function () use ($idsParam) {
            try {
                return Http::asJson()
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->throw()
                    ->get('https://api.modrinth.com/v2/projects', [
                        'ids' => $idsParam,
                    ])
                    ->json();
            } catch (Exception $exception) {
                report($exception);

                return [];
            }
        });

        if (!is_array($modrinthProjects)) {
            $modrinthProjects = [];
        }

        $modrinthMap = [];
        foreach ($modrinthProjects as $project) {
            if (isset($project['id'])) {
                $modrinthMap[$project['id']] = $project;
            }
        }

        $results = [];
        foreach ($pageIds as $projectId) {
            $installedPlugin = null;
            foreach ($installedPlugins as $plugin) {
                if ($plugin['project_id'] === $projectId) {
                    $installedPlugin = $plugin;
                    break;
                }
            }

            if (!$installedPlugin) {
                continue;
            }

            if (isset($modrinthMap[$projectId])) {
                $project = $modrinthMap[$projectId];
                $project['project_id'] = $project['id'];
                $project['provider'] = 'modrinth';
                if (isset($project['updated']) && !isset($project['date_modified'])) {
                    $project['date_modified'] = $project['updated'];
                }
                if (isset($installedPlugin['author']) && !isset($project['author'])) {
                    $project['author'] = $installedPlugin['author'];
                }
                $results[] = $project;
            } else {
                $results[] = [
                    'project_id' => $installedPlugin['project_id'],
                    'slug' => $installedPlugin['project_slug'],
                    'title' => $installedPlugin['project_title'],
                    'description' => trans('pelican-minecraft-plugins::strings.page.plugin_unavailable'),
                    'icon_url' => null,
                    'author' => $installedPlugin['author'] ?? '',
                    'downloads' => 0,
                    'date_modified' => $installedPlugin['installed_at'],
                    'project_type' => '',
                    'unavailable' => true,
                    'provider' => 'modrinth',
                ];
            }
        }

        return $results;
    }

    /** @return array<int, mixed> */
    public function getModrinthVersions(string $projectId, Server $server, ?ModrinthProjectType $type = null): array
    {
        $type ??= ModrinthProjectType::fromServer($server);
        $minecraftLoader = $type?->getModrinthLoader($server);

        if (!$minecraftLoader) {
            return [];
        }

        $minecraftVersion = $this->getMinecraftVersion($server);

        $data = [
            'game_versions' => "[\"$minecraftVersion\"]",
            'loaders' => "[\"$minecraftLoader\"]",
        ];

        return cache()->remember("modrinth_versions:$projectId:$minecraftVersion:$minecraftLoader", now()->addMinutes(30), function () use ($projectId, $data) {
            try {
                $versions = Http::asJson()
                    ->timeout(5)
                    ->connectTimeout(5)
                    ->throw()
                    ->get("https://api.modrinth.com/v2/project/$projectId/version", $data)
                    ->json();

                if (!empty($versions) && is_array($versions) && isset($versions[0]['date_published'])) {
                    usort($versions, function ($a, $b) {
                        return strcmp($b['date_published'] ?? '', $a['date_published'] ?? '');
                    });
                }

                return $versions;
            } catch (Exception $exception) {
                report($exception);

                return [];
            }
        });
    }

    /**
     * @param array<string, string> $hashMap [filename => sha512hash]
     * @return array<string, mixed> [sha512hash => versionData]
     */
    public function lookupVersionsByHashes(array $hashMap): array
    {
        if (empty($hashMap)) {
            return [];
        }

        $hashes = array_values($hashMap);

        try {
            $result = Http::asJson()
                ->timeout(10)
                ->connectTimeout(5)
                ->throw()
                ->post('https://api.modrinth.com/v2/version_files', [
                    'hashes' => $hashes,
                    'algorithm' => 'sha512',
                ])
                ->json();

            return is_array($result) ? $result : [];
        } catch (Exception $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * @param array<string> $projectIds
     * @return array<string, mixed> [projectId => projectData]
     *
     * @throws Exception
     */
    protected function fetchProjectsByIds(array $projectIds): array
    {
        if (empty($projectIds)) {
            return [];
        }

        $projectIds = array_values(array_unique($projectIds));
        $idsParam = '["'.implode('","', $projectIds).'"]';

        try {
            $projects = Http::asJson()
                ->timeout(10)
                ->connectTimeout(5)
                ->throw()
                ->get('https://api.modrinth.com/v2/projects', [
                    'ids' => $idsParam,
                ])
                ->json();

            if (!is_array($projects)) {
                return [];
            }

            $map = [];
            foreach ($projects as $project) {
                if (isset($project['id'])) {
                    $map[$project['id']] = $project;
                }
            }

            return $map;
        } catch (Exception $exception) {
            report($exception);

            throw new Exception('Modrinth projects lookup failed', previous: $exception);
        }
    }

    protected function resolveProjectAuthor(?array $project, array $versionData): ?string
    {
        if (is_string($project['author'] ?? null) && $project['author'] !== '') {
            return $project['author'];
        }

        if (is_string($project['team'] ?? null) && $project['team'] !== '') {
            $teamUsername = $this->fetchTeamPrimaryUsername($project['team']);
            if ($teamUsername !== null) {
                return $teamUsername;
            }
        }

        if (is_string($versionData['author_id'] ?? null) && $versionData['author_id'] !== '') {
            return $this->fetchUsernameByUserId($versionData['author_id']);
        }

        return null;
    }

    protected function fetchTeamPrimaryUsername(string $teamId): ?string
    {
        $cacheKey = 'modrinth_team_primary_user:'.$teamId;

        return cache()->remember($cacheKey, now()->addMinutes(30), function () use ($teamId) {
            try {
                $members = Http::asJson()
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->throw()
                    ->get("https://api.modrinth.com/v2/team/{$teamId}/members")
                    ->json();

                if (!is_array($members) || empty($members)) {
                    return null;
                }

                foreach ($members as $member) {
                    $username = $member['user']['username'] ?? null;
                    if (is_string($username) && $username !== '') {
                        return $username;
                    }
                }

                return null;
            } catch (Exception $exception) {
                report($exception);

                return null;
            }
        });
    }

    protected function fetchUsernameByUserId(string $userId): ?string
    {
        $cacheKey = 'modrinth_user_username:'.$userId;

        return cache()->remember($cacheKey, now()->addMinutes(30), function () use ($userId) {
            try {
                $user = Http::asJson()
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->throw()
                    ->get("https://api.modrinth.com/v2/user/{$userId}")
                    ->json();

                $username = $user['username'] ?? null;

                return is_string($username) && $username !== '' ? $username : null;
            } catch (Exception $exception) {
                report($exception);

                return null;
            }
        });
    }

    /**
     * Scans the plugin folder, hashes unknown JARs, looks them up on Modrinth,
     * imports matches into metadata, and returns filenames not found on Modrinth.
     *
     * @return array<string>  Filenames with no Modrinth match
     *
     * @throws Exception
     */
    public function scanAndImportPlugins(Server $server, DaemonFileRepository $fileRepository, ?ModrinthProjectType $type = null): array
    {
        $resolvedType = $type ?? ModrinthProjectType::fromServer($server);
        $cacheKey = $this->getHashScanCacheKey($server, $resolvedType);

        return cache()->remember($cacheKey, now()->addMinutes(10), function () use ($server, $fileRepository, $resolvedType) {
            return $this->performScan($server, $fileRepository, $resolvedType);
        });
    }

    public function getHashScanCacheKey(Server $server, ?ModrinthProjectType $type = null): string
    {
        $resolvedType = $type ?? ModrinthProjectType::fromServer($server);

        return "modrinth_hash_scan:{$server->id}:".($resolvedType?->value ?? 'unknown');
    }

    public function getProjectFolder(Server $server, DaemonFileRepository $fileRepository, ?ModrinthProjectType $type = null): string
    {
        $resolvedType = $type ?? ModrinthProjectType::fromServer($server);

        return $resolvedType?->getFolder($server) ?? 'plugins';
    }

    protected function performScan(Server $server, DaemonFileRepository $fileRepository, ?ModrinthProjectType $type = null): array
    {
        $type ??= ModrinthProjectType::fromServer($server);

        if (!$type) {
            return [];
        }

        try {
            $directoryContents = $fileRepository->setServer($server)->getDirectory($this->getProjectFolder($server, $fileRepository, $type));
        } catch (Exception $exception) {
            report($exception);

            return [];
        }

        if (!is_array($directoryContents) || isset($directoryContents['error'])) {
            return [];
        }

        $extension = $type->getFileExtension();

        $diskFiles = collect($directoryContents)
            ->filter(fn ($item) => is_array($item) && isset($item['name']) && str($item['name'])->lower()->endsWith($extension))
            ->pluck('name')
            ->values()
            ->toArray();

        $installedPluginsMetadata = $this->getInstalledPluginsMetadata($server, $fileRepository, $type);

        $diskFilesLower = array_flip(array_map('strtolower', $diskFiles));
        $filteredInstalledPluginsMetadata = array_values(array_filter(
            $installedPluginsMetadata,
            fn ($installedPlugin) => isset($diskFilesLower[strtolower($installedPlugin['filename'])])
        ));

        if (count($filteredInstalledPluginsMetadata) !== count($installedPluginsMetadata)) {
            $this->saveInstalledPluginsMetadata($server, $fileRepository, $filteredInstalledPluginsMetadata, $type);
        }

        $installedPluginsMetadata = $filteredInstalledPluginsMetadata;

        if (empty($diskFiles)) {
            return [];
        }

        $knownFilenames = [];
        foreach ($installedPluginsMetadata as $installedPlugin) {
            $knownFilenames[strtolower($installedPlugin['filename'])] = true;
        }

        $unknownFiles = array_values(
            array_filter($diskFiles, function ($name) use ($knownFilenames) {
                $normalizedName = strtolower($name);

                return !isset($knownFilenames[$normalizedName]);
            })
        );

        if (empty($unknownFiles)) {
            return [];
        }

        $folder = $this->getProjectFolder($server, $fileRepository, $type);
        $hashMap = []; // [filename => sha512hash]

        foreach ($unknownFiles as $filename) {
            try {
                $content = $fileRepository->setServer($server)->getContent("{$folder}/{$filename}");
                $hashMap[$filename] = hash('sha512', $content);
            } catch (Exception $exception) {
                report($exception);
            }
        }

        if (empty($hashMap)) {
            return $unknownFiles;
        }

        $versionsByHash = $this->lookupVersionsByHashes($hashMap);

        if (empty($versionsByHash)) {
            return $unknownFiles;
        }

        $hashToFilenames = [];
        foreach ($hashMap as $filename => $hash) {
            if (!isset($hashToFilenames[$hash])) {
                $hashToFilenames[$hash] = [];
            }

            $hashToFilenames[$hash][] = $filename;
        }

        $matchedVersions = []; // [filename => versionData]
        $projectIds = [];

        foreach ($versionsByHash as $hash => $versionData) {
            if (!isset($hashToFilenames[$hash]) || !is_array($versionData) || !isset($versionData['project_id'])) {
                continue;
            }

            foreach ($hashToFilenames[$hash] as $filename) {
                $matchedVersions[$filename] = $versionData;
            }

            $projectIds[] = $versionData['project_id'];
        }

        if (empty($matchedVersions)) {
            return $unknownFiles;
        }

        try {
            $projectsMap = $this->fetchProjectsByIds(array_unique($projectIds));
        } catch (Exception $exception) {
            report($exception);
            $projectsMap = [];
        }

        $matchedFilenames = [];

        foreach ($matchedVersions as $filename => $versionData) {
            if (!isset($versionData['project_id'], $versionData['id'], $versionData['version_number'])) {
                continue;
            }

            $projectId = $versionData['project_id'];
            $project = $projectsMap[$projectId] ?? null;

            $saved = $this->savePluginMetadata(
                server: $server,
                fileRepository: $fileRepository,
                projectId: $projectId,
                projectSlug: $project['slug'] ?? $projectId,
                projectTitle: $project['title'] ?? $projectId,
                versionId: $versionData['id'],
                versionNumber: $versionData['version_number'],
                filename: $filename,
                author: $this->resolveProjectAuthor($project, $versionData),
                type: $type
            );

            if ($saved) {
                $matchedFilenames[] = $filename;
            }
        }

        return array_values(
            array_filter($unknownFiles, fn ($name) => !in_array($name, $matchedFilenames, true))
        );
    }

    /**
     * @throws Exception
     */
    protected function getMetadataFilePath(Server $server, DaemonFileRepository $fileRepository, ?ModrinthProjectType $type = null): string
    {
        $type ??= ModrinthProjectType::fromServer($server);

        if (!$type) {
            throw new Exception("Server {$server->id} does not support Minecraft plugins");
        }

        return $this->getProjectFolder($server, $fileRepository, $type).'/.modrinth-metadata.json';
    }

    /** @return array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}> */
    public function getInstalledPluginsMetadata(Server $server, DaemonFileRepository $fileRepository, ?ModrinthProjectType $type = null): array
    {
        try {
            $metadataPath = $this->getMetadataFilePath($server, $fileRepository, $type);
            $content = $fileRepository->setServer($server)->getContent($metadataPath);
            $metadata = json_decode($content, true);

            // Keep the historical key so existing plugin tracking data keeps working.
            if (!is_array($metadata) || !isset($metadata['installed_mods']) || !is_array($metadata['installed_mods'])) {
                return [];
            }

            $validInstalledPlugins = [];
            $requiredKeys = [
                'project_id',
                'project_slug',
                'project_title',
                'version_id',
                'version_number',
                'filename',
                'installed_at',
            ];

            $requiredKeysFlipped = array_flip($requiredKeys);

            foreach ($metadata['installed_mods'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $missingKeys = array_diff_key($requiredKeysFlipped, $entry);
                if (empty($missingKeys)) {
                    $validInstalledPlugins[] = $entry;
                }
            }

            return $validInstalledPlugins;
        } catch (Exception $exception) {
            return [];
        }
    }

    public function savePluginMetadata(
        Server $server,
        DaemonFileRepository $fileRepository,
        string $projectId,
        string $projectSlug,
        string $projectTitle,
        string $versionId,
        string $versionNumber,
        string $filename,
        ?string $author = null,
        ?ModrinthProjectType $type = null
    ): bool {
        try {
            return Cache::lock("modrinth_metadata:{$server->id}", 10)->block(5, function () use ($server, $fileRepository, $projectId, $projectSlug, $projectTitle, $versionId, $versionNumber, $filename, $author, $type) {
                $metadata = [
                    'installed_mods' => $this->getInstalledPluginsMetadata($server, $fileRepository, $type),
                ];

                $metadata['installed_mods'] = collect($metadata['installed_mods'])
                    ->filter(fn ($plugin) => $plugin['project_id'] !== $projectId && strtolower($plugin['filename']) !== strtolower($filename))
                    ->values()
                    ->toArray();

                $pluginEntry = [
                    'project_id' => $projectId,
                    'project_slug' => $projectSlug,
                    'project_title' => $projectTitle,
                    'version_id' => $versionId,
                    'version_number' => $versionNumber,
                    'filename' => $filename,
                    'installed_at' => now()->toIso8601String(),
                ];

                if ($author !== null) {
                    $pluginEntry['author'] = $author;
                }

                $metadata['installed_mods'][] = $pluginEntry;

                $metadataPath = $this->getMetadataFilePath($server, $fileRepository, $type);
                $response = $fileRepository->setServer($server)->putContent(
                    $metadataPath,
                    json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );

                return !$response->failed();
            }) === true;
        } catch (Exception $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * @param array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}> $installedPlugins
     */
    protected function saveInstalledPluginsMetadata(Server $server, DaemonFileRepository $fileRepository, array $installedPlugins, ?ModrinthProjectType $type = null): bool
    {
        try {
            return Cache::lock("modrinth_metadata:{$server->id}", 10)->block(5, function () use ($server, $fileRepository, $installedPlugins, $type) {
                $metadataPath = $this->getMetadataFilePath($server, $fileRepository, $type);
                $response = $fileRepository->setServer($server)->putContent(
                    $metadataPath,
                    json_encode(['installed_mods' => array_values($installedPlugins)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );

                return !$response->failed();
            }) === true;
        } catch (Exception $exception) {
            report($exception);

            return false;
        }
    }

    public function removePluginMetadata(Server $server, DaemonFileRepository $fileRepository, string $projectId, ?ModrinthProjectType $type = null): bool
    {
        try {
            return Cache::lock("modrinth_metadata:{$server->id}", 10)->block(5, function () use ($server, $fileRepository, $projectId, $type) {
                $metadata = [
                    'installed_mods' => $this->getInstalledPluginsMetadata($server, $fileRepository, $type),
                ];

                $metadata['installed_mods'] = collect($metadata['installed_mods'])
                    ->filter(fn ($plugin) => $plugin['project_id'] !== $projectId)
                    ->values()
                    ->toArray();

                $metadataPath = $this->getMetadataFilePath($server, $fileRepository, $type);
                $response = $fileRepository->setServer($server)->putContent(
                    $metadataPath,
                    json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );

                return !$response->failed();
            }) === true;
        } catch (Exception $exception) {
            report($exception);

            return false;
        }
    }

    /** @return array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}|null */
    public function getInstalledMod(Server $server, DaemonFileRepository $fileRepository, string $projectId, ?ModrinthProjectType $type = null): ?array
    {
        $installedPlugins = $this->getInstalledPluginsMetadata($server, $fileRepository, $type);

        foreach ($installedPlugins as $plugin) {
            if ($plugin['project_id'] === $projectId) {
                return $plugin;
            }
        }

        return null;
    }

    /**
     * @param array{version_id: string, version_number: string} $installedPlugin
     * @param array<int, array{id: string, version_number: string}> $availableVersions
     */
    public function isUpdateAvailable(array $installedPlugin, array $availableVersions): bool
    {
        if (empty($availableVersions)) {
            return false;
        }

        $latestVersion = $availableVersions[0];

        return $installedPlugin['version_id'] !== $latestVersion['id'];
    }

    /**
     * @return array<string>
     */
    public function getInstalledPlugins(Server $server, DaemonFileRepository $fileRepository, ?ModrinthProjectType $type = null): array
    {
        $metadata = $this->getInstalledPluginsMetadata($server, $fileRepository, $type);

        return collect($metadata)
            ->pluck('filename')
            ->map(fn ($name) => strtolower($name))
            ->toArray();
    }
}
