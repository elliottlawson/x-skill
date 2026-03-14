<?php

declare(strict_types=1);

namespace UnoSkills\X;

use App\Contracts\HasSettings;
use App\Enums\DangerLevel;
use App\Models\InstalledSkill;
use App\Models\User;
use App\Services\ConnectBridgeOAuthService;
use App\Skills\BaseSkill;
use Prism\Prism\Tool as PrismTool;
use UnoSkills\X\Enums\XCapability;

class XSkill extends BaseSkill implements HasSettings
{
    private ?InstalledSkill $installedSkill = null;

    public function __construct(
        private readonly XApiClient $apiClient,
        private readonly ConnectBridgeOAuthService $connectBridge,
    ) {}

    public function name(): string
    {
        return 'x';
    }

    public function description(): string
    {
        return 'Search bookmarks, read tweets, and interact with the X platform.';
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
        $capabilities = [];

        foreach (XCapability::cases() as $cap) {
            if ($this->isCapabilityActive($cap)) {
                $capabilities[] = $cap->label();
            }
        }

        if (empty($capabilities)) {
            return 'The X (Twitter) skill is installed but no capabilities are currently active. The user may need to connect their X account through Settings > Connections, or enable capabilities in the skill settings.';
        }

        $list = implode(', ', $capabilities);

        return "You have access to the user's X/Twitter account with these capabilities: {$list}. If the user hasn't connected their X account yet, let them know they need to connect it through Settings > Connections > OAuth Providers in Connect Bridge.";
    }

    /**
     * @return array<int, PrismTool>
     */
    public function tools(): array
    {
        $tools = [];

        if ($this->isCapabilityActive(XCapability::Bookmarks)) {
            $tools[] = $this->searchBookmarks();
            $tools[] = $this->browseBookmarks();
            $tools[] = $this->syncBookmarks();
        }

        if ($this->isCapabilityActive(XCapability::Tweets)) {
            $tools[] = $this->readTweet();
        }

        return $tools;
    }

    // ─── HasSettings ────────────────────────────────────────────

    /**
     * @return array<int, array{key: string, label: string, description?: string, type: string, default?: mixed}>
     */
    public function settingsSchema(): array
    {
        $grantedScopes = $this->refreshGrantedScopes();
        $fields = [];

        foreach (XCapability::cases() as $cap) {
            $hasScopes = $this->hasRequiredScopes($cap, $grantedScopes);

            $field = [
                'key' => $cap->settingsKey(),
                'label' => $cap->label(),
                'description' => $cap->description(),
                'type' => 'toggle',
                'default' => $cap->enabledByDefault(),
            ];

            if (! $hasScopes) {
                $missing = array_diff($cap->requiredScopes(), $grantedScopes);
                $field['disabled'] = true;
                $field['disabled_reason'] = 'Missing required scopes: '.implode(', ', $missing).'. Reconnect your X account with these permissions.';
            }

            $fields[] = $field;
        }

        return $fields;
    }

    public function setupInstructions(): ?string
    {
        return <<<'MD'
        ## Connect your X account

        - Go to **Settings > Connections** and connect to **Connect Bridge**
        - In Connect Bridge, add your **X (Twitter)** account
        - Return here and enable the capabilities you want

        ## Required scopes

        - **Bookmarks**: `bookmark.read` — search, browse, and sync your bookmarks
        - **Tweet Reading**: `tweet.read`, `users.read` — read and summarize tweets by URL
        MD;
    }

    /**
     * @return array<int, array{label: string, status: string, message: string}>
     */
    public function statusDetails(): array
    {
        $details = [];

        // Check Connect Bridge
        if (! ConnectBridgeOAuthService::isConfigured()) {
            $details[] = [
                'label' => 'Connect Bridge',
                'status' => 'error',
                'message' => 'Connect Bridge URL not configured. Set it in Settings > Connections.',
            ];

            return $details;
        }

        $details[] = [
            'label' => 'Connect Bridge',
            'status' => 'ok',
            'message' => 'Connected',
        ];

        // Check X account connection
        $grantedScopes = $this->getSkillConfig('granted_scopes', []);
        $hasAnyScopes = ! empty($grantedScopes);

        if (! $hasAnyScopes) {
            $details[] = [
                'label' => 'X Account',
                'status' => 'warning',
                'message' => 'No X account connected, or scopes not yet discovered. Visit the settings page to refresh.',
            ];
        } else {
            $details[] = [
                'label' => 'X Account',
                'status' => 'ok',
                'message' => 'Connected with scopes: '.implode(', ', $grantedScopes),
            ];
        }

        // Per-capability status
        foreach (XCapability::cases() as $cap) {
            $active = $this->isCapabilityActive($cap);
            $details[] = [
                'label' => $cap->label(),
                'status' => $active ? 'ok' : 'warning',
                'message' => $active ? 'Active' : 'Inactive — missing scopes or disabled in settings',
            ];
        }

        return $details;
    }

    // ─── Tool definitions ───────────────────────────────────────

    private function searchBookmarks(): PrismTool
    {
        return \Prism\Prism\Facades\Tool::as('x_search_bookmarks')
            ->for('Search through the user\'s X/Twitter bookmarks by keyword. Returns matching tweets with author info, text, and metadata.')
            ->withStringParameter('query', 'Search query to find in bookmark text')
            ->withNumberParameter('limit', 'Maximum results to return (default: 10)', required: false)
            ->using(function (string $query, ?int $limit = null): string {
                $userId = $this->resolveUserId();

                if ($userId === null) {
                    return json_encode(['error' => 'No user context available']);
                }

                return json_encode($this->apiClient->searchBookmarks($userId, $query, $limit ?? 10));
            });
    }

    private function browseBookmarks(): PrismTool
    {
        return \Prism\Prism\Facades\Tool::as('x_browse_bookmarks')
            ->for('List the user\'s most recent X/Twitter bookmarks. Use this to browse bookmarks without a specific search query.')
            ->withNumberParameter('limit', 'Number of bookmarks to return (default: 20)', required: false)
            ->withNumberParameter('offset', 'Number of bookmarks to skip for pagination (default: 0)', required: false)
            ->using(function (?int $limit = null, ?int $offset = null): string {
                $userId = $this->resolveUserId();

                if ($userId === null) {
                    return json_encode(['error' => 'No user context available']);
                }

                return json_encode($this->apiClient->listBookmarks($userId, $limit ?? 20, $offset ?? 0));
            });
    }

    private function syncBookmarks(): PrismTool
    {
        return \Prism\Prism\Facades\Tool::as('x_sync_bookmarks')
            ->for('Sync bookmarks from X/Twitter. Fetches the latest bookmarks from the X API and stores them locally. Only use when the user explicitly asks to sync or refresh their bookmarks.')
            ->using(function (): string {
                $userId = $this->resolveUserId();

                if ($userId === null) {
                    return json_encode(['error' => 'No user context available']);
                }

                return json_encode($this->apiClient->syncBookmarks($userId));
            });
    }

    private function readTweet(): PrismTool
    {
        return \Prism\Prism\Facades\Tool::as('x_read_tweet')
            ->for('Fetch and read a tweet by its URL or ID. Supports x.com and twitter.com URLs, or bare tweet IDs. Returns the tweet text, author, media, links, and engagement metrics.')
            ->withStringParameter('tweet', 'A tweet URL (x.com or twitter.com) or numeric tweet ID')
            ->using(function (string $tweet): string {
                $userId = $this->resolveUserId();

                if ($userId === null) {
                    return json_encode(['error' => 'No user context available']);
                }

                return json_encode($this->apiClient->fetchTweet($userId, $tweet));
            });
    }

    // ─── Helpers ────────────────────────────────────────────────

    private function resolveUserId(): ?int
    {
        $raw = $this->getContext()['user_id'] ?? null;

        return $raw !== null ? (int) $raw : null;
    }

    private function isCapabilityActive(XCapability $cap): bool
    {
        $grantedScopes = $this->getSkillConfig('granted_scopes', []);

        if (! $this->hasRequiredScopes($cap, $grantedScopes)) {
            return false;
        }

        return (bool) $this->getSkillConfig($cap->settingsKey(), $cap->enabledByDefault());
    }

    /**
     * @param  array<int, string>  $grantedScopes
     */
    private function hasRequiredScopes(XCapability $cap, array $grantedScopes): bool
    {
        foreach ($cap->requiredScopes() as $scope) {
            if (! in_array($scope, $grantedScopes, true)) {
                return false;
            }
        }

        return true;
    }

    private function getSkillConfig(string $key, mixed $default = null): mixed
    {
        $this->installedSkill ??= InstalledSkill::where('name', $this->name())->first();

        return $this->installedSkill?->getConfig($key, $default) ?? $default;
    }

    /**
     * Fetch granted scopes from Connect Bridge and cache in InstalledSkill config.
     *
     * @return array<int, string>
     */
    private function refreshGrantedScopes(): array
    {
        $userId = $this->resolveUserId();

        if ($userId === null) {
            return $this->getSkillConfig('granted_scopes', []);
        }

        $user = User::find($userId);

        if (! $user) {
            return $this->getSkillConfig('granted_scopes', []);
        }

        $account = $this->connectBridge->getProviderAccount($user, 'x-twitter');

        if (! $account) {
            return [];
        }

        $scopes = $account['granted_scopes'] ?? [];

        // Cache in InstalledSkill config
        $this->installedSkill ??= InstalledSkill::where('name', $this->name())->first();

        if ($this->installedSkill) {
            $this->installedSkill->setConfig('granted_scopes', $scopes)->save();
        }

        return $scopes;
    }
}
