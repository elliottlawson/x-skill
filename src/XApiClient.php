<?php

declare(strict_types=1);

namespace UnoSkills\X;

use App\Models\User;
use App\Services\ConnectBridgeOAuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class XApiClient
{
    private const X_API_BASE = 'https://api.x.com/2';

    private const PAGE_SIZE = 100;

    private const MAX_BOOKMARKS = 800;

    private const DB_CONNECTION = 'skill_x';

    public function __construct(
        private readonly ConnectBridgeOAuthService $connectBridge,
    ) {}

    // ─── Bookmarks ──────────────────────────────────────────────

    /**
     * @return array{bookmarks: array, total: int, query: string}
     */
    public function searchBookmarks(int $userId, string $query, int $limit = 10): array
    {
        $results = DB::connection(self::DB_CONNECTION)
            ->table('bookmarks')
            ->where('user_id', $userId)
            ->where('text', 'ILIKE', '%'.str_replace(['%', '_'], ['\%', '\_'], $query).'%')
            ->orderByDesc('bookmarked_at')
            ->limit(min($limit, 50))
            ->get();

        return [
            'bookmarks' => $results->map(fn ($b) => $this->formatBookmark($b))->all(),
            'total' => $results->count(),
            'query' => $query,
        ];
    }

    /**
     * @return array{bookmarks: array, total: int, offset: int, showing: int}
     */
    public function listBookmarks(int $userId, int $limit = 20, int $offset = 0): array
    {
        $total = DB::connection(self::DB_CONNECTION)
            ->table('bookmarks')
            ->where('user_id', $userId)
            ->count();

        $results = DB::connection(self::DB_CONNECTION)
            ->table('bookmarks')
            ->where('user_id', $userId)
            ->orderByDesc('bookmarked_at')
            ->offset($offset)
            ->limit(min($limit, 50))
            ->get();

        return [
            'bookmarks' => $results->map(fn ($b) => $this->formatBookmark($b))->all(),
            'total' => $total,
            'offset' => $offset,
            'showing' => $results->count(),
        ];
    }

    /**
     * @return array{fetched: int, new: int}|array{error: string}
     */
    public function syncBookmarks(int $userId): array
    {
        $user = User::find($userId);

        if (! $user) {
            return ['error' => 'User not found'];
        }

        $tokenData = $this->connectBridge->getProviderTokenBySlug($user, 'x-twitter');

        if (! $tokenData) {
            return ['error' => 'X/Twitter account not connected. Connect it through Settings > Connections > OAuth Providers, then manage your X account in Connect Bridge.'];
        }

        $accessToken = $tokenData['access_token'];

        try {
            $xUserId = $this->getXUserId($accessToken);
            $newCount = 0;
            $totalFetched = 0;
            $paginationToken = null;

            while ($totalFetched < self::MAX_BOOKMARKS) {
                $data = $this->fetchBookmarksPage($accessToken, $xUserId, $paginationToken);

                if (! isset($data['data'])) {
                    break;
                }

                $usersMap = $this->buildUsersMap($data);
                $mediaMap = $this->buildMediaMap($data);

                foreach ($data['data'] as $tweet) {
                    $parsed = $this->parseTweet($tweet, $usersMap, $mediaMap);

                    if ($this->upsertBookmark($userId, $parsed)) {
                        $newCount++;
                    }

                    $totalFetched++;
                }

                $paginationToken = $data['meta']['next_token'] ?? null;

                if (! $paginationToken) {
                    break;
                }
            }

            DB::connection(self::DB_CONNECTION)
                ->table('sync_state')
                ->updateOrInsert(
                    ['user_id' => $userId, 'key' => 'last_sync_at'],
                    ['value' => now()->toIso8601String()],
                );

            return ['fetched' => $totalFetched, 'new' => $newCount];
        } catch (\Throwable $e) {
            Log::error('X Bookmarks sync failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return ['error' => 'Sync failed: '.$e->getMessage()];
        }
    }

    // ─── Tweets ─────────────────────────────────────────────────

    /**
     * Fetch a tweet (or thread context) by ID or URL.
     *
     * @return array{tweet: array}|array{error: string}
     */
    public function fetchTweet(int $userId, string $tweetRef): array
    {
        $tweetId = $this->extractTweetId($tweetRef);

        if (! $tweetId) {
            return ['error' => 'Could not parse a tweet ID from the provided reference. Provide a tweet URL or numeric ID.'];
        }

        $user = User::find($userId);

        if (! $user) {
            return ['error' => 'User not found'];
        }

        $tokenData = $this->connectBridge->getProviderTokenBySlug($user, 'x-twitter');

        if (! $tokenData) {
            return ['error' => 'X/Twitter account not connected. Connect it through Settings > Connections.'];
        }

        $accessToken = $tokenData['access_token'];

        try {
            $response = Http::withToken($accessToken)
                ->timeout(30)
                ->get(self::X_API_BASE."/tweets/{$tweetId}", [
                    'tweet.fields' => 'created_at,author_id,conversation_id,entities,in_reply_to_user_id,attachments,public_metrics',
                    'expansions' => 'author_id,attachments.media_keys,referenced_tweets.id',
                    'user.fields' => 'username,name',
                    'media.fields' => 'url,preview_image_url,type,width,height,alt_text',
                ]);

            if ($response->status() === 403) {
                return ['error' => 'X API access denied. This may require a different API plan.'];
            }

            $response->throw();

            $data = $response->json();

            if (! isset($data['data'])) {
                return ['error' => 'Tweet not found'];
            }

            $usersMap = $this->buildUsersMap($data);
            $mediaMap = $this->buildMediaMap($data);

            $tweet = $this->parseTweet($data['data'], $usersMap, $mediaMap);

            // Include public metrics if available
            if (isset($data['data']['public_metrics'])) {
                $tweet['metrics'] = $data['data']['public_metrics'];
            }

            // Build tweet URL
            $tweet['url'] = $tweet['author_username']
                ? "https://x.com/{$tweet['author_username']}/status/{$tweet['tweet_id']}"
                : null;

            return ['tweet' => $tweet];
        } catch (\Throwable $e) {
            Log::error('X tweet fetch failed', [
                'user_id' => $userId,
                'tweet_id' => $tweetId,
                'error' => $e->getMessage(),
            ]);

            return ['error' => 'Failed to fetch tweet: '.$e->getMessage()];
        }
    }

    /**
     * Extract a tweet ID from a URL or bare ID string.
     */
    public function extractTweetId(string $tweetRef): ?string
    {
        $tweetRef = trim($tweetRef);

        // Bare numeric ID
        if (preg_match('/^\d+$/', $tweetRef)) {
            return $tweetRef;
        }

        // x.com or twitter.com URL
        if (preg_match('#(?:x\.com|twitter\.com)/\w+/status/(\d+)#i', $tweetRef, $matches)) {
            return $matches[1];
        }

        return null;
    }

    // ─── Internal helpers ───────────────────────────────────────

    private function getXUserId(string $accessToken): string
    {
        $response = Http::withToken($accessToken)
            ->timeout(30)
            ->get(self::X_API_BASE.'/users/me');

        $response->throw();

        return $response->json('data.id');
    }

    private function fetchBookmarksPage(string $accessToken, string $xUserId, ?string $paginationToken = null): array
    {
        $params = [
            'tweet.fields' => 'created_at,author_id,conversation_id,entities,in_reply_to_user_id,attachments',
            'expansions' => 'author_id,attachments.media_keys',
            'user.fields' => 'username,name',
            'media.fields' => 'url,preview_image_url,type,width,height,alt_text',
            'max_results' => (string) self::PAGE_SIZE,
        ];

        if ($paginationToken) {
            $params['pagination_token'] = $paginationToken;
        }

        $response = Http::withToken($accessToken)
            ->timeout(30)
            ->get(self::X_API_BASE."/users/{$xUserId}/bookmarks", $params);

        if ($response->status() === 403) {
            throw new \RuntimeException('X API access denied. This may require a different API plan.');
        }

        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, array{username: string, name: string}>
     */
    private function buildUsersMap(array $data): array
    {
        $map = [];

        foreach ($data['includes']['users'] ?? [] as $user) {
            $map[$user['id']] = [
                'username' => $user['username'],
                'name' => $user['name'],
            ];
        }

        return $map;
    }

    /**
     * @return array<string, array>
     */
    private function buildMediaMap(array $data): array
    {
        $map = [];

        foreach ($data['includes']['media'] ?? [] as $media) {
            $map[$media['media_key']] = $media;
        }

        return $map;
    }

    private function parseTweet(array $tweet, array $usersMap, array $mediaMap): array
    {
        $author = $usersMap[$tweet['author_id']] ?? [];
        $entities = $tweet['entities'] ?? [];
        $urls = array_map(fn ($u) => $u['expanded_url'], $entities['urls'] ?? []);
        $mediaKeys = $tweet['attachments']['media_keys'] ?? [];

        $mediaItems = [];

        foreach ($mediaKeys as $key) {
            if (isset($mediaMap[$key])) {
                $m = $mediaMap[$key];
                $mediaItems[] = [
                    'type' => $m['type'] ?? null,
                    'url' => $m['url'] ?? null,
                    'preview_image_url' => $m['preview_image_url'] ?? null,
                ];
            }
        }

        return [
            'tweet_id' => $tweet['id'],
            'text' => $tweet['text'],
            'author_id' => $tweet['author_id'],
            'author_username' => $author['username'] ?? null,
            'author_name' => $author['name'] ?? null,
            'created_at' => $tweet['created_at'],
            'has_media' => ! empty($mediaItems),
            'has_links' => ! empty($urls),
            'links' => $urls,
            'media' => $mediaItems,
            'conversation_id' => $tweet['conversation_id'] ?? null,
        ];
    }

    private function upsertBookmark(int $userId, array $parsed): bool
    {
        $existing = DB::connection(self::DB_CONNECTION)
            ->table('bookmarks')
            ->where('user_id', $userId)
            ->where('tweet_id', $parsed['tweet_id'])
            ->exists();

        if ($existing) {
            return false;
        }

        DB::connection(self::DB_CONNECTION)
            ->table('bookmarks')
            ->insert([
                'user_id' => $userId,
                'tweet_id' => $parsed['tweet_id'],
                'text' => $parsed['text'],
                'author_id' => $parsed['author_id'],
                'author_username' => $parsed['author_username'],
                'author_name' => $parsed['author_name'],
                'created_at' => $parsed['created_at'],
                'bookmarked_at' => now()->toIso8601String(),
                'has_media' => $parsed['has_media'],
                'has_links' => $parsed['has_links'],
                'links_json' => ! empty($parsed['links']) ? json_encode($parsed['links']) : null,
                'media_json' => ! empty($parsed['media']) ? json_encode($parsed['media']) : null,
                'conversation_id' => $parsed['conversation_id'],
            ]);

        return true;
    }

    private function formatBookmark(object $bookmark, bool $full = false): array
    {
        $result = [
            'tweet_id' => $bookmark->tweet_id,
            'text' => $bookmark->text,
            'author' => $bookmark->author_username
                ? "@{$bookmark->author_username}"
                : $bookmark->author_id,
            'author_name' => $bookmark->author_name,
            'created_at' => $bookmark->created_at,
            'bookmarked_at' => $bookmark->bookmarked_at,
            'url' => $bookmark->author_username
                ? "https://x.com/{$bookmark->author_username}/status/{$bookmark->tweet_id}"
                : null,
        ];

        if ($bookmark->has_media) {
            $result['has_media'] = true;
        }

        if ($bookmark->has_links) {
            $result['has_links'] = true;
        }

        if ($full) {
            if ($bookmark->links_json) {
                $result['links'] = json_decode($bookmark->links_json, true);
            }

            if ($bookmark->media_json) {
                $result['media'] = json_decode($bookmark->media_json, true);
            }
        }

        return $result;
    }
}
