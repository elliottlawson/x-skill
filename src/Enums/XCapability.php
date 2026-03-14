<?php

declare(strict_types=1);

namespace UnoSkills\X\Enums;

use App\Enums\DangerLevel;

enum XCapability: string
{
    case Bookmarks = 'bookmarks';
    case Tweets = 'tweets';

    /**
     * @return array<int, string>
     */
    public function requiredScopes(): array
    {
        return match ($this) {
            self::Bookmarks => ['bookmark.read'],
            self::Tweets => ['tweet.read', 'users.read'],
        };
    }

    public function settingsKey(): string
    {
        return $this->value.'_enabled';
    }

    public function label(): string
    {
        return match ($this) {
            self::Bookmarks => 'Bookmarks',
            self::Tweets => 'Tweet Reading',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Bookmarks => 'Search, browse, and sync your X bookmarks',
            self::Tweets => 'Read and summarize tweets by URL or ID',
        };
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function dangerLevel(): DangerLevel
    {
        return match ($this) {
            self::Bookmarks => DangerLevel::Moderate,
            self::Tweets => DangerLevel::Safe,
        };
    }
}
