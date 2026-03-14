<?php

declare(strict_types=1);

namespace UnoSkills\XBookmarks;

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

    public function __construct(
        private readonly ConnectBridgeOAuthService $connectBridge,
    ) {}

    /**
     * @return array{bookmarks: array, total: int}|array{error: string}
     */
    public function searchBookmarks(int $userId, string $query, int $limit = 10): array
    {
        $results = DB::connection('x-bookmarks')
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
     * @return array{bookmarks: array, total: int, offset: int}
     */
    public function listBookmarks(int $userId, int $limit = 20, int $offset = 0): array
    {
        $total = DB::connection('x-bookmarks')
            ->table('bookmarks')
            ->where('user_id', $userId)
            ->count();

        $results = DB::connection('x-bookmarks')
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
     * @return array{id: string, text: string, author_username: ?string, ...}|array{error: string}
     */
    public function getBookmark(int $userId, string $tweetId): array
    {
        $bookmark = DB::connection('x-bookmarks')
            ->table('bookmarks')
            ->where('user_id', $userId)
            ->where('tweet_id', $tweetId)
            ->first();

        if (! $bookmark) {
            return ['error' => 'Bookmark not found'];
        }

        return $this->formatBookmark($bookmark, full: true);
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

            DB::connection('x-bookmarks')
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

        $response->throw();

        return $response->json();
    }

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
        $existing = DB::connection('x-bookmarks')
            ->table('bookmarks')
            ->where('user_id', $userId)
            ->where('tweet_id', $parsed['tweet_id'])
            ->exists();

        if ($existing) {
            return false;
        }

        DB::connection('x-bookmarks')
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
