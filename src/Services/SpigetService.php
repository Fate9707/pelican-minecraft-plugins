<?php

namespace FateFighter\MinecraftPlugins\Services;

use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SpigetService
{
    private const API = 'https://api.spiget.org/v2';
    // Spiget's sparse fields selector is safest with top-level API fields;
    // requesting dotted nested fields can make search calls return errors.
    private const RESOURCE_FIELDS = 'id,name,tag,description,author,downloads,releaseDate,updateDate,testedVersions,icon,premium,external';

    /** @return array<string, mixed>|null */
    public function findProjectByName(string $name): ?array
    {
        return $this->findProjectByNames([$name]);
    }

    /** @param array<int, string> $names
     *  @return array<string, mixed>|null
     */
    public function findKnownProjectByNames(array $names): ?array
    {
        // Stable Spigot resource IDs cover names whose JAR metadata differs
        // from the marketplace title or whose title contains promotional text.
        $knownIds = [
            'atpinataparty' => '127416',
            'besttools' => '81490',
            'bloons' => '106243',
            'chatcolor2' => '22692',
            'essentials' => '9089',
            'essentialsspawn' => '9089',
            'playerauctions' => '83073',
            'playerwarps' => '66692',
            'protectionlib' => '9368',
            'voidspawn' => '19350',
            'worldguardextraflags' => '4823',
            'infiniteannouncements' => '71266',
        ];

        foreach ($names as $name) {
            $normalized = $this->normalizeProjectName($name);
            if (isset($knownIds[$normalized])) {
                return $this->findProjectById($knownIds[$normalized]);
            }
        }

        return null;
    }

    /** @param array<int, string> $names
     *  @return array<string, mixed>|null
     */
    public function findProjectByNames(array $names): ?array
    {
        $queries = [];
        foreach ($names as $name) {
            $normalized = $this->normalizeProjectName($name);
            if ($normalized !== '' && !isset($queries[$normalized])) {
                // Keep the caller's order: plugin.yml names are more reliable
                // than implementation titles or JAR filenames.
                $queries[$normalized] = trim($name);
            }
        }

        if ($queries === []) {
            return null;
        }

        $resourcesByQuery = [];
        $missingQueries = [];
        foreach ($queries as $normalized => $query) {
            $cacheKey = 'spiget_search_resources:v4:'.md5($normalized);
            if (cache()->has($cacheKey)) {
                $resourcesByQuery[$normalized] = cache()->get($cacheKey, []);
            } else {
                $missingQueries[$normalized] = $query;
            }
        }

        foreach (array_chunk($missingQueries, 4, true) as $batch) {
            try {
                $responses = Http::pool(fn (Pool $pool) => array_map(
                    fn (string $query) => $pool->as(md5($this->normalizeProjectName($query)))
                        ->acceptJson()
                        ->withUserAgent('pelican-minecraft-plugins')
                        ->timeout(10)
                        ->connectTimeout(5)
                        ->get(self::API.'/search/resources/'.rawurlencode($query), [
                            'field' => 'name',
                            'size' => 100,
                            'page' => 0,
                            'sort' => '-downloads',
                            'fields' => self::RESOURCE_FIELDS,
                        ]),
                    $batch
                ));

                foreach ($batch as $normalized => $query) {
                    $response = $responses[md5($normalized)] ?? null;
                    if ($response && !$response->successful()) {
                        Log::warning('Spiget identity search failed', [
                            'query' => $query,
                            'http_status' => $response->status(),
                        ]);
                    }
                    $resources = $response && $response->successful()
                        ? $this->extractResources($response->json())
                        : [];
                    cache()->put('spiget_search_resources:v4:'.md5($normalized), $resources, now()->addMinutes(5));
                    $resourcesByQuery[$normalized] = $resources;
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        $rankedMatches = [];
        foreach (array_keys($queries) as $priority => $normalized) {
            $resources = $resourcesByQuery[$normalized] ?? [];
            $exactMatches = [];
            foreach ($resources as $resource) {
                if (!is_array($resource) || empty($resource['id'])) {
                    continue;
                }

                $resourceName = $this->normalizeProjectName((string) ($resource['name'] ?? ''));
                if ($resourceName === $normalized) {
                    $exactMatches[(string) $resource['id']] = $resource;
                    continue;
                }

                // A trailing plus is part of some resource brands. Don't let
                // fuzzy matching collapse BetterRTP+ into BetterRTP (or vice versa).
                if (str_contains($normalized, '+') !== str_contains($resourceName, '+')) {
                    continue;
                }

                if ($resourceName === '') {
                    continue;
                }

                similar_text($normalized, $resourceName, $score);
                // Spigot titles often append a tagline/version range after
                // the actual resource name (e.g. Player Auctions + details).
                // Treat a long, exact title prefix/phrase as a strong match.
                if (strlen($normalized) >= 8 && str_contains($resourceName, $normalized)) {
                    $score = max($score, 90 + min(9, (strlen($normalized) / strlen($resourceName)) * 9));
                }
                $lengthDifference = abs(strlen($normalized) - strlen($resourceName));
                $isAmbiguousPrefix = $lengthDifference > 1
                    && (str_starts_with($resourceName, $normalized) || str_starts_with($normalized, $resourceName));

                if ($score >= 86 && !$isAmbiguousPrefix) {
                    $resourceId = (string) $resource['id'];
                    if (!isset($rankedMatches[$resourceId])
                        || $score > $rankedMatches[$resourceId]['score']
                        || ($score === $rankedMatches[$resourceId]['score'] && $priority < $rankedMatches[$resourceId]['priority'])) {
                        $rankedMatches[$resourceId] = [
                            'resource' => $resource,
                            'score' => $score,
                            'priority' => $priority,
                        ];
                    }
                }
            }

            // Prefer one exact result for the strongest identity (normally
            // plugin.yml's `name`). Other aliases may legitimately match
            // different marketplace entries and should not make it ambiguous.
            if (count($exactMatches) === 1) {
                return $this->normalizeProject(reset($exactMatches));
            }
        }

        uasort($rankedMatches, fn (array $left, array $right) => ($right['score'] <=> $left['score'])
            ?: ($left['priority'] <=> $right['priority']));
        $rankedMatches = array_values($rankedMatches);
        $bestMatch = $rankedMatches[0] ?? null;
        $nextMatch = $rankedMatches[1] ?? null;

        if ($bestMatch !== null && ($nextMatch === null
            || $bestMatch['score'] - $nextMatch['score'] >= 5
            || ($bestMatch['score'] === $nextMatch['score'] && $bestMatch['priority'] < $nextMatch['priority']))) {
            return $this->normalizeProject($bestMatch['resource']);
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function findProjectById(string $resourceId): ?array
    {
        if (!ctype_digit($resourceId)) {
            return null;
        }

        $cacheKey = 'spiget_find_project_id:v4:'.$resourceId;
        $project = cache()->remember($cacheKey, now()->addMinutes(30), function () use ($resourceId) {
            try {
                $resource = Http::acceptJson()
                    ->withUserAgent('pelican-minecraft-plugins')
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->throw()
                    ->get(self::API.'/resources/'.$resourceId)
                    ->json();

                return is_array($resource) ? $this->normalizeProject($resource) : [];
            } catch (\Throwable $exception) {
                report($exception);

                return [];
            }
        });

        return is_array($project) && !empty($project['project_id']) ? $project : null;
    }

    /** @param array<int, string> $resourceIds
     *  @return array<string, array<string, mixed>> keyed by numeric resource ID
     */
    public function getProjectsByIds(array $resourceIds): array
    {
        $resourceIds = array_values(array_unique(array_filter(
            $resourceIds,
            fn ($id) => is_string($id) && ctype_digit($id)
        )));
        $missingIds = array_values(array_filter(
            $resourceIds,
            fn (string $id) => !cache()->has('spiget_resource:'.$id)
        ));

        foreach (array_chunk($missingIds, 4) as $batch) {
            try {
                $responses = Http::pool(fn (Pool $pool) => array_map(
                    fn (string $id) => $pool->as('resource_'.$id)
                        ->acceptJson()
                        ->withUserAgent('pelican-minecraft-plugins')
                        ->timeout(10)
                        ->connectTimeout(5)
                        ->get(self::API.'/resources/'.$id),
                    $batch
                ));

                foreach ($batch as $id) {
                    $response = $responses['resource_'.$id] ?? null;
                    if ($response && $response->successful() && is_array($resource = $response->json())) {
                        cache()->put('spiget_resource:'.$id, $resource, now()->addHours(12));
                    }
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        $projects = [];
        foreach ($resourceIds as $id) {
            $resource = cache()->get('spiget_resource:'.$id);
            if (is_array($resource)) {
                $projects[$id] = $this->normalizeProject($resource);
            }
        }

        return $projects;
    }

    private function normalizeProjectName(string $name): string
    {
        // Keep a trailing plus: Spigot has distinct resources named
        // "BetterRTP" and "BetterRTP+".
        return preg_replace('/[^a-z0-9+]+/', '', strtolower($name)) ?? '';
    }

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    public function getProjects(Server $server, int $page = 1, ?string $search = null): array
    {
        $minecraftVersion = app(MinecraftModrinthService::class)->getMinecraftVersion($server);
        if (!$minecraftVersion) {
            return ['hits' => [], 'total_hits' => 0];
        }

        $query = trim((string) $search);
        $cacheSuffix = md5(json_encode([$minecraftVersion, $page, $query]));
        $cacheKey = 'spiget_projects:v9:'.$cacheSuffix;
        $staleCacheKey = 'spiget_projects_stale:v3:'.$cacheSuffix;

        return cache()->remember($cacheKey, now()->addMinutes(10), function () use ($minecraftVersion, $page, $query, $staleCacheKey) {
            try {
                $resources = $query === ''
                    ? $this->getResourcesForVersion($minecraftVersion, $page)
                    : $this->searchResourcesForVersion($query, $minecraftVersion, $page);
                $resources['hits'] = $this->enrichResourceMetadata($resources['hits']);

                $result = [
                    'hits' => array_map(fn (array $resource) => $this->normalizeProject($resource), $resources['hits']),
                    'total_hits' => $resources['total_hits'],
                ];

                if ($result['hits'] !== []) {
                    cache()->put($staleCacheKey, $result, now()->addDay());
                }

                return $result;
            } catch (\Throwable $exception) {
                report($exception);

                return cache()->get(
                    $staleCacheKey,
                    cache()->get('spiget_projects:v5:'.$cacheSuffix, ['hits' => [], 'total_hits' => 0])
                );
            }
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function getVersions(string $resourceId, Server $server): array
    {
        $resourceId = preg_replace('/^spigot:/', '', $resourceId) ?? $resourceId;
        if (!ctype_digit($resourceId)) {
            return [];
        }

        $minecraftVersion = app(MinecraftModrinthService::class)->getMinecraftVersion($server);
        // Invalidate cached version entries created before Spiget's proxy
        // download URL was added to older versions.
        $cacheKey = "spiget_versions:v11:$resourceId:$minecraftVersion";

        return cache()->remember($cacheKey, now()->addMinutes(30), function () use ($resourceId, $minecraftVersion) {
            try {
                $resource = Http::acceptJson()
                    ->withUserAgent('pelican-minecraft-plugins')
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->throw()
                    ->get(self::API.'/resources/'.$resourceId)
                    ->json();

                if (!is_array($resource)) {
                    return [];
                }

                $response = Http::acceptJson()
                    ->withUserAgent('pelican-minecraft-plugins')
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->throw()
                    ->get(self::API.'/resources/'.$resourceId.'/versions', [
                        'size' => 50,
                        'page' => 0,
                        'sort' => '-releaseDate',
                    ]);
                $versions = $response->json();

                if (!is_array($versions)) {
                    return [];
                }

                $normalized = [];
                $hasLatestCdnDownload = false;
                foreach ($versions as $version) {
                    if (!is_array($version) || !$this->versionMatches($minecraftVersion, $resource['testedVersions'] ?? [])) {
                        continue;
                    }

                    $versionId = (string) ($version['id'] ?? '');
                    $files = [];
                    if ($versionId !== '' && empty($resource['premium'])) {
                        $apiDownloadUrl = self::API.'/resources/'.$resourceId.'/versions/'.$versionId.'/download';
                        $downloadUrl = $apiDownloadUrl;
                        $fallbackUrl = null;

                        // SpigotMC can return 403 to Wings' downloader. Try
                        // Spiget's CDN for the newest compatible file first,
                        // then use Spiget's proxy for older versions hosted on
                        // SpigotMC. Keep the regular API URL as a fallback for
                        // external resources or unavailable proxy downloads.
                        if (!$hasLatestCdnDownload) {
                            $downloadUrl = 'https://cdn.spiget.org/file/spiget-resources/'.$resourceId.'.jar';
                            $fallbackUrl = $apiDownloadUrl;
                            $hasLatestCdnDownload = true;
                        } else {
                            // The external flag is not reliable enough to
                            // decide whether Wings can fetch historical files.
                            // Route every older Spigot version through Spiget's
                            // proxy from the panel.
                            $downloadUrl = $apiDownloadUrl.'/proxy';
                            $fallbackUrl = $apiDownloadUrl;
                        }

                        $file = [
                            'primary' => true,
                            'filename' => 'spigot-'.$resourceId.'-'.$versionId.'.jar',
                            'url' => $downloadUrl,
                        ];
                        if ($fallbackUrl !== null) {
                            $file['fallback_url'] = $fallbackUrl;
                        }
                        if (str_ends_with($downloadUrl, '/proxy')) {
                            $file['download_via_panel'] = true;
                        }

                        $files[] = $file;
                    }

                    $normalized[] = [
                        'id' => $versionId,
                        'version_number' => (string) ($version['name'] ?? $versionId),
                        'version_type' => 'release',
                        'downloads' => (int) ($version['downloads'] ?? 0),
                        'date_published' => $this->timestampToIso($version['releaseDate'] ?? null),
                        'changelog' => null,
                        'featured' => false,
                        'files' => $files,
                    ];
                }

                return $normalized;
            } catch (\Throwable $exception) {
                report($exception);

                return [];
            }
        });
    }

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    private function getResourcesForVersion(string $minecraftVersion, int $page): array
    {
        $versions = [$minecraftVersion];
        if (preg_match('/^(\d+\.\d+)\./', $minecraftVersion, $matches) === 1) {
            $versions[] = $matches[1];
        }

        foreach (array_unique($versions) as $version) {
            $base = self::API.'/resources/for/'.rawurlencode($version);
            $countResponse = Http::acceptJson()
                ->withUserAgent('pelican-minecraft-plugins')
                ->timeout(10)
                ->connectTimeout(5)
                ->get($base, ['size' => 1, 'page' => 0, 'sort' => '-downloads', 'fields' => self::RESOURCE_FIELDS]);

            if ($countResponse->status() === 404) {
                continue;
            }

            $countResponse->throw();
            $total = max(0, (int) $countResponse->header('X-Total', $countResponse->header('X-Page-Count', 0)));

            $response = Http::acceptJson()
                ->withUserAgent('pelican-minecraft-plugins')
                ->timeout(10)
                ->connectTimeout(5)
                ->throw()
                ->get($base, [
                    'size' => 20,
                    'page' => max(0, $page - 1),
                    'sort' => '-downloads',
                    'fields' => self::RESOURCE_FIELDS,
                ]);

            $hits = $this->extractResources($response->json());

            if ($hits !== [] || $total > 0 || $version === end($versions)) {
                return ['hits' => $hits, 'total_hits' => $total];
            }
        }

        return ['hits' => [], 'total_hits' => 0];
    }

    /** @param array<int, array<string, mixed>> $resources
     *  @return array<int, array<string, mixed>>
     */
    private function enrichResourceMetadata(array $resources): array
    {
        $resourceIds = array_values(array_unique(array_filter(array_map(
            fn (array $resource) => isset($resource['id']) ? (string) $resource['id'] : null,
            $resources
        ), fn (?string $id) => $id !== null && ctype_digit($id))));

        $missingResourceIds = array_values(array_filter(
            $resourceIds,
            fn (string $id) => !cache()->has("spiget_resource:$id")
        ));

        if ($missingResourceIds !== []) {
            foreach (array_chunk($missingResourceIds, 4) as $batch) {
                try {
                    $responses = Http::pool(fn (Pool $pool) => array_map(
                        fn (string $id) => $pool->as('resource_'.$id)
                            ->acceptJson()
                            ->withUserAgent('pelican-minecraft-plugins')
                            ->timeout(10)
                            ->connectTimeout(5)
                            ->get(self::API.'/resources/'.$id),
                        $batch
                    ));

                    foreach ($batch as $id) {
                        $details = $responses['resource_'.$id] ?? null;
                        if ($details && $details->successful() && is_array($payload = $details->json())) {
                            cache()->put("spiget_resource:$id", $payload, now()->addHours(12));
                        }
                    }
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        }

        $resources = array_map(function (array $resource): array {
            $id = (string) ($resource['id'] ?? '');
            $details = $id !== '' ? cache()->get("spiget_resource:$id", []) : [];

            return is_array($details) ? array_merge($resource, $details) : $resource;
        }, $resources);

        $authorIds = [];
        foreach ($resources as $resource) {
            $author = $resource['author'] ?? null;
            $authorId = is_array($author) ? ($author['id'] ?? null) : (is_numeric($author) ? $author : null);
            $authorName = is_array($author) ? ($author['name'] ?? $author['username'] ?? null) : null;

            if ($authorName === null && $authorId !== null && ctype_digit((string) $authorId)) {
                $authorIds[] = (string) $authorId;
            }
        }

        $authorIds = array_values(array_unique($authorIds));
        $missingAuthorIds = array_values(array_filter(
            $authorIds,
            fn (string $id) => !cache()->has("spiget_author:$id")
        ));

        if ($missingAuthorIds !== []) {
            foreach (array_chunk($missingAuthorIds, 4) as $batch) {
                try {
                    $responses = Http::pool(fn (Pool $pool) => array_map(
                        fn (string $id) => $pool->as('author_'.$id)
                            ->acceptJson()
                            ->withUserAgent('pelican-minecraft-plugins')
                            ->timeout(10)
                            ->connectTimeout(5)
                            ->get(self::API.'/authors/'.$id),
                        $batch
                    ));

                    foreach ($batch as $id) {
                        $response = $responses['author_'.$id] ?? null;
                        $author = $response && $response->successful() ? $response->json() : null;
                        if (is_array($author) && is_string($author['name'] ?? null)) {
                            cache()->put("spiget_author:$id", $author['name'], now()->addDay());
                        }
                    }
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        }

        return $resources;
    }

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    private function searchResourcesForVersion(string $query, string $minecraftVersion, int $page): array
    {
        $size = 100;
        $first = Http::acceptJson()
            ->withUserAgent('pelican-minecraft-plugins')
            ->timeout(10)
            ->connectTimeout(5)
            ->throw()
            ->get(self::API.'/search/resources/'.rawurlencode($query), [
                'field' => 'name',
                'size' => $size,
                'page' => 0,
                'sort' => '-downloads',
                'fields' => self::RESOURCE_FIELDS,
            ]);

        $pageCount = max(1, (int) $first->header('X-Page-Count', 1));
        $matches = array_values(array_filter(
            $this->extractResources($first->json()),
            fn (array $resource) => $this->versionMatches($minecraftVersion, $resource['testedVersions'] ?? [])
        ));

        for ($currentPage = 1; $currentPage < $pageCount; $currentPage++) {
            $response = Http::acceptJson()
                ->withUserAgent('pelican-minecraft-plugins')
                ->timeout(10)
                ->connectTimeout(5)
                ->throw()
                ->get(self::API.'/search/resources/'.rawurlencode($query), [
                    'field' => 'name',
                    'size' => $size,
                    'page' => $currentPage,
                    'sort' => '-downloads',
                    'fields' => self::RESOURCE_FIELDS,
                ]);

            foreach ($this->extractResources($response->json()) as $resource) {
                if ($this->versionMatches($minecraftVersion, $resource['testedVersions'] ?? [])) {
                    $matches[] = $resource;
                }
            }
        }

        $uniqueMatches = [];
        foreach ($matches as $resource) {
            $id = (string) ($resource['id'] ?? '');
            if ($id !== '') {
                $uniqueMatches[$id] = $resource;
            }
        }

        $matches = array_values($uniqueMatches);
        $total = count($matches);
        $hits = array_slice($matches, (max(1, $page) - 1) * 20, 20);

        return ['hits' => $hits, 'total_hits' => $total];
    }

    /** @return array<int, array<string, mixed>> */
    private function extractResources(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        // The version-filter endpoint can wrap its paginated resource list in
        // a `match` property; plain list endpoints return the array directly.
        if (isset($payload['match']) && is_array($payload['match'])) {
            $payload = $payload['match'];
        }

        return array_values(array_filter(
            $payload,
            fn ($resource) => is_array($resource)
                && isset($resource['id'])
                && (isset($resource['name']) || isset($resource['title']))
        ));
    }

    /** @param array<int, mixed> $testedVersions */
    private function versionMatches(?string $minecraftVersion, array $testedVersions): bool
    {
        // Older Spigot resources often omit tested-version metadata. Keep
        // their releases selectable when compatibility cannot be determined.
        if (!$minecraftVersion || $testedVersions === []) {
            return true;
        }

        foreach ($testedVersions as $testedVersion) {
            if (!is_string($testedVersion)) {
                continue;
            }

            if ($testedVersion === $minecraftVersion || str_starts_with($minecraftVersion, $testedVersion.'.')) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $resource
     *  @return array<string, mixed>
     */
    private function normalizeProject(array $resource): array
    {
        $id = (string) ($resource['id'] ?? '');
        $title = (string) ($resource['name'] ?? $resource['title'] ?? 'Spigot resource '.$id);
        $authorData = $resource['author'] ?? null;
        $authorId = is_array($authorData) ? ($authorData['id'] ?? null) : (is_numeric($authorData) ? $authorData : null);
        $author = is_array($authorData)
            ? ($authorData['name'] ?? $authorData['username'] ?? null)
            : (is_string($authorData) && !is_numeric($authorData) ? $authorData : null);
        $author = $author ?: ($authorId !== null
            ? cache()->get('spiget_author:'.(string) $authorId, (string) $authorId)
            : '');
        $iconData = $resource['icon'] ?? null;
        $iconPath = is_array($iconData) ? ($iconData['url'] ?? null) : $iconData;
        $iconUrl = is_string($iconPath) && $iconPath !== ''
            ? (str_starts_with($iconPath, 'http') ? $iconPath : 'https://www.spigotmc.org/'.ltrim($iconPath, '/'))
            : null;
        if ($iconUrl === null && $id !== '') {
            $iconUrl = 'https://www.spigotmc.org/data/resource_icons/'.substr($id, 0, 3).'/'.$id.'.jpg';
        }
        $tag = trim(html_entity_decode(strip_tags((string) ($resource['tag'] ?? '')), ENT_QUOTES | ENT_HTML5));
        $description = $tag;

        if ($description === '') {
            $description = trim((string) ($resource['description'] ?? ''));
            $decodedDescription = $description !== '' ? base64_decode($description, true) : false;
            if (is_string($decodedDescription) && preg_match('/^\s*<(?:[a-z!\/])/i', $decodedDescription) === 1) {
                $description = $decodedDescription;
            }

            $description = trim(html_entity_decode(strip_tags($description), ENT_QUOTES | ENT_HTML5));
        }

        $description = trim(preg_replace('/\s+/', ' ', $description) ?? '');

        return [
            'project_id' => 'spigot:'.$id,
            'slug' => str($title)->slug()->toString(),
            'title' => $title,
            'description' => $description,
            'icon_url' => $iconUrl,
            'author' => (string) $author,
            'downloads' => (int) ($resource['downloads'] ?? $resource['stats']['downloads'] ?? $resource['downloadCount'] ?? 0),
            'date_modified' => $this->timestampToIso($resource['updateDate'] ?? $resource['updatedAt'] ?? $resource['releaseDate'] ?? null),
            'project_type' => 'plugin',
            'provider' => 'spigot',
            'spigot_resource_id' => $id,
            'premium' => (bool) ($resource['premium'] ?? false),
            'external' => (bool) ($resource['external'] ?? false),
        ];
    }

    /** @return array{url: string, filename: string}|null */
    private function timestampToIso(mixed $timestamp): ?string
    {
        if (!is_numeric($timestamp) || (int) $timestamp <= 0) {
            return null;
        }

        try {
            $timestamp = (float) $timestamp;

            // Spiget timestamps are usually Unix seconds; some responses may
            // expose milliseconds. Distinguish them by their magnitude.
            if ($timestamp < 100_000_000_000) {
                return Carbon::createFromTimestamp((int) $timestamp, 'UTC')->toIso8601String();
            }

            return Carbon::createFromTimestampMsUTC((int) $timestamp)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
