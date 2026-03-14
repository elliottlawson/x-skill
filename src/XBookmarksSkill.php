<?php

declare(strict_types=1);

namespace UnoSkills\XBookmarks;

use App\Enums\DangerLevel;
use App\Skills\BaseSkill;
use Prism\Prism\Tool as PrismTool;

class XBookmarksSkill extends BaseSkill
{
    public function __construct(
        private readonly XApiClient $apiClient,
    ) {}

    public function name(): string
    {
        return 'x-bookmarks';
    }

    public function description(): string
    {
        return 'Search, browse, and sync your X/Twitter bookmarks.';
    }

    public function dangerLevel(): DangerLevel
    {
        return DangerLevel::Moderate;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function alwaysLoad(): bool
    {
        return true;
    }

    public function triggers(): array
    {
        return [
            'bookmark',
            'bookmarks',
            'twitter',
            'tweet',
            'tweets',
            'x.com',
        ];
    }

    public function systemPrompt(): ?string
    {
        return 'You have access to the user\'s X/Twitter bookmarks. You can search through them, list recent bookmarks, and fetch details about specific bookmarked tweets. Bookmarks are synced from X via Connect Bridge OAuth. If the user hasn\'t connected their X account yet, let them know they need to connect it through Settings > Connections > OAuth Providers in Connect Bridge.';
    }

    /**
     * @return array<int, PrismTool>
     */
    public function tools(): array
    {
        return [
            $this->searchBookmarks(),
            $this->listBookmarks(),
            $this->syncBookmarks(),
            $this->getBookmark(),
        ];
    }

    private function searchBookmarks(): PrismTool
    {
        return \Prism\Prism\Facades\Tool::as('x_bookmarks_search')
            ->for('Search through the user\'s X/Twitter bookmarks by keyword. Returns matching tweets with author info, text, and metadata.')
            ->withStringParameter('query', 'Search query to find in bookmark text')
            ->withNumberParameter('limit', 'Maximum results to return (default: 10)', required: false)
            ->using(function (string $query, ?int $limit = null): string {
                $limit = $limit ?? 10;
                $userId = $this->getContext()['user_id'] ?? null;

                if (! $userId) {
                    return json_encode(['error' => 'No user context available']);
                }

                return json_encode($this->apiClient->searchBookmarks($userId, $query, $limit));
            });
    }

    private function listBookmarks(): PrismTool
    {
        return \Prism\Prism\Facades\Tool::as('x_bookmarks_list')
            ->for('List the user\'s most recent X/Twitter bookmarks. Use this to browse bookmarks without a specific search query.')
            ->withNumberParameter('limit', 'Number of bookmarks to return (default: 20)', required: false)
            ->withNumberParameter('offset', 'Number of bookmarks to skip for pagination (default: 0)', required: false)
            ->using(function (?int $limit = null, ?int $offset = null): string {
                $limit = $limit ?? 20;
                $offset = $offset ?? 0;
                $userId = $this->getContext()['user_id'] ?? null;

                if (! $userId) {
                    return json_encode(['error' => 'No user context available']);
                }

                return json_encode($this->apiClient->listBookmarks($userId, $limit, $offset));
            });
    }

    private function syncBookmarks(): PrismTool
    {
        return \Prism\Prism\Facades\Tool::as('x_bookmarks_sync')
            ->for('Sync bookmarks from X/Twitter. Fetches the latest bookmarks from the X API and stores them locally. Only use when the user explicitly asks to sync or refresh their bookmarks.')
            ->using(function (): string {
                $userId = $this->getContext()['user_id'] ?? null;

                if (! $userId) {
                    return json_encode(['error' => 'No user context available']);
                }

                return json_encode($this->apiClient->syncBookmarks($userId));
            });
    }

    private function getBookmark(): PrismTool
    {
        return \Prism\Prism\Facades\Tool::as('x_bookmark_detail')
            ->for('Get full details of a specific bookmarked tweet by its ID, including links and media.')
            ->withStringParameter('tweet_id', 'The X/Twitter tweet ID')
            ->using(function (string $tweet_id): string {
                $userId = $this->getContext()['user_id'] ?? null;

                if (! $userId) {
                    return json_encode(['error' => 'No user context available']);
                }

                return json_encode($this->apiClient->getBookmark($userId, $tweet_id));
            });
    }
}
