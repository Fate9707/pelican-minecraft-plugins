<?php

namespace FateFighter\MinecraftPlugins\Services;

use App\Models\Server;
use FateFighter\MinecraftPlugins\Enums\MinecraftLoader;
use Illuminate\Support\Facades\Http;

class HangarService
{
    /** @return array<string, mixed>|null */
    public function findProjectByName(Server $server, string $name): ?array
    {
        $expected = $this->normalizeProjectName($name);
        if ($expected === '') {
            return null;
        }

        foreach ($this->getProjects($server, 1, $name)['hits'] as $project) {
            if ($this->normalizeProjectName((string) ($project['title'] ?? '')) === $expected) {
                return $project;
            }
        }

        return null;
    }

    private function normalizeProjectName(string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($name)) ?? '';
    }

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    public function getProjects(Server $server, int $page = 1, ?string $search = null): array
    {
        $platform = $this->getPlatform($server) ?? 'PAPER';
        $minecraftVersion = app(MinecraftModrinthService::class)->getMinecraftVersion($server);

        $data = [
            'limit' => 20,
            'offset' => ($page - 1) * 20,
            'sort' => '-downloads',
            'platform' => $platform,
        ];

        if ($minecraftVersion !== null && $minecraftVersion !== '') {
            $data['version'] = $minecraftVersion;
        }

        if ($search !== null && trim($search) !== '') {
            $data['query'] = trim($search);
        }

        $cacheKey = 'hangar_projects:'.md5(json_encode($data));

        return cache()->remember($cacheKey, now()->addMinutes(30), function () use ($data) {
            try {
                $response = Http::acceptJson()
                    ->withUserAgent('pelican-minecraft-plugins')
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->throw()
                    ->get('https://hangar.papermc.io/api/v1/projects', $data)
                    ->json();

                $hits = collect($response['result'] ?? [])
                    ->filter(fn ($project) => is_array($project) && !empty($project['name']))
                    ->map(function (array $project): array {
                        $namespace = $project['namespace'] ?? [];
                        $slug = $namespace['slug'] ?? $project['slug'] ?? $project['name'];
                        $owner = $namespace['owner'] ?? $project['owner'] ?? '';

                        return [
                            'project_id' => 'hangar:'.$slug,
                            'slug' => (string) $slug,
                            'hangar_path' => trim($owner.'/'.$slug, '/'),
                            'title' => $project['name'],
                            'description' => $project['description'] ?? '',
                            'icon_url' => $project['avatarUrl'] ?? null,
                            'author' => $owner,
                            'downloads' => $project['stats']['downloads'] ?? 0,
                            'date_modified' => $project['lastUpdated'] ?? $project['createdAt'] ?? null,
                            'project_type' => 'plugin',
                            'provider' => 'hangar',
                        ];
                    })
                    ->values()
                    ->all();

                $pagination = $response['pagination'] ?? [];
                $total = $pagination['count']
                    ?? $pagination['total']
                    ?? $pagination['totalCount']
                    ?? count($hits);

                return [
                    'hits' => $hits,
                    'total_hits' => (int) $total,
                ];
            } catch (\Throwable $exception) {
                report($exception);

                return [
                    'hits' => [],
                    'total_hits' => 0,
                ];
            }
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function getVersions(string $projectSlug, Server $server, ?string $platformOverride = null): array
    {
        $platform = in_array($platformOverride, ['PAPER', 'VELOCITY', 'WATERFALL'], true)
            ? $platformOverride
            : ($this->getPlatform($server) ?? 'PAPER');

        $minecraftVersion = app(MinecraftModrinthService::class)->getMinecraftVersion($server);
        // Bump the key so cached responses from earlier plugin versions are not
        // reused after changing the Hangar version-stat normalization.
        $cacheKey = "hangar_versions:v3:$projectSlug:$platform:$minecraftVersion";

        return cache()->remember($cacheKey, now()->addMinutes(5), function () use ($projectSlug, $platform, $minecraftVersion) {
            try {
                $response = Http::acceptJson()
                    ->withUserAgent('pelican-minecraft-plugins')
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->throw()
                    ->get('https://hangar.papermc.io/api/v1/projects/'.rawurlencode($projectSlug).'/versions', [
                        'limit' => 50,
                        'offset' => 0,
                        'platform' => $platform,
                    ])
                    ->json();

                $versions = collect($response['result'] ?? [])
                    ->filter(fn ($version) => is_array($version))
                    ->map(fn (array $version) => $this->normalizeVersion($version, $platform))
                    ->filter(fn (?array $version) => $version !== null)
                    ->filter(fn (array $version) => $this->supportsMinecraftVersion($version['_platform_dependencies'] ?? [], $platform, $minecraftVersion))
                    ->map(function (array $version) {
                        unset($version['_platform_dependencies']);

                        return $version;
                    })
                    ->values()
                    ->all();

                usort($versions, fn (array $a, array $b) => strcmp($b['date_published'] ?? '', $a['date_published'] ?? ''));

                return $versions;
            } catch (\Throwable $exception) {
                report($exception);

                return [];
            }
        });
    }

    public function getPlatform(Server $server): ?string
    {
        return match (MinecraftLoader::fromServer($server)) {
            MinecraftLoader::Paper, MinecraftLoader::Purpur, MinecraftLoader::Folia => 'PAPER',
            MinecraftLoader::Velocity => 'VELOCITY',
            MinecraftLoader::Waterfall, MinecraftLoader::Bungeecord => 'WATERFALL',
            default => null,
        };
    }

    /** @param array<string, mixed> $version
     *  @return array<string, mixed>|null
     */
    private function normalizeVersion(array $version, string $platform): ?array
    {
        $platformDownloads = $version['downloads'][$platform] ?? [];
        if (is_array($platformDownloads) && array_is_list($platformDownloads)) {
            $download = $platformDownloads[0] ?? null;
        } else {
            $download = $platformDownloads;
        }

        if (!is_array($download)) {
            return null;
        }

        $url = $download['downloadUrl'] ?? $download['externalUrl'] ?? null;
        if (!is_string($url) || !str_starts_with(strtolower($url), 'https://')) {
            return null;
        }

        $fileInfo = $download['fileInfo'] ?? [];
        $filename = $fileInfo['name']
            ?? $download['name']
            ?? basename((string) parse_url($url, PHP_URL_PATH));

        if (!is_string($filename) || $filename === '') {
            return null;
        }

        $dependencies = $version['platformDependencies'][$platform] ?? [];
        $stats = $version['stats'] ?? [];
        $downloads = $stats['totalDownloads']
            ?? $stats['downloads']
            ?? array_sum(is_array($stats['platformDownloads'] ?? null) ? $stats['platformDownloads'] : []);

        return [
            'id' => (string) ($version['name'] ?? $version['version'] ?? ''),
            'version_number' => (string) ($version['name'] ?? $version['version'] ?? ''),
            'version_type' => strtolower((string) ($version['channel']['name'] ?? 'release')),
            'downloads' => (int) $downloads,
            'date_published' => $version['createdAt'] ?? $version['publishedAt'] ?? null,
            'changelog' => $version['description'] ?? $version['notes'] ?? null,
            'featured' => false,
            'files' => [[
                'primary' => true,
                'filename' => $filename,
                'url' => $url,
            ]],
            '_platform_dependencies' => is_array($dependencies) ? $dependencies : [],
        ];
    }

    /** @param array<int, string> $dependencies */
    private function supportsMinecraftVersion(array $dependencies, string $platform, ?string $minecraftVersion): bool
    {
        if (!$minecraftVersion || $dependencies === []) {
            return true;
        }

        foreach ($dependencies as $dependency) {
            if (!is_string($dependency) || trim($dependency) === '') {
                continue;
            }

            $range = explode('-', $dependency, 2);
            $minimum = $range[0];
            $maximum = $range[1] ?? $range[0];

            if ($this->isWithinVersionRange($minecraftVersion, $minimum, $maximum)) {
                return true;
            }
        }

        return false;
    }

    private function isWithinVersionRange(string $version, string $minimum, string $maximum): bool
    {
        if ($version === $minimum || $version === $maximum) {
            return true;
        }

        // Hangar may express a family such as 1.21 while the panel reports 1.21.11.
        if (count(explode('.', $maximum)) < count(explode('.', $version)) && str_starts_with($version, $maximum.'.')) {
            return true;
        }

        return version_compare($version, $minimum, '>=') && version_compare($version, $maximum, '<=');
    }
}
