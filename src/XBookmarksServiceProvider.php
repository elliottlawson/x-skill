<?php

declare(strict_types=1);

namespace UnoSkills\XBookmarks;

use App\Services\SkillDatabaseManager;
use App\Services\SkillRegistry;
use Illuminate\Support\ServiceProvider;

class XBookmarksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->app->make(SkillDatabaseManager::class)
            ->connectionFor('x-bookmarks');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->app->make(SkillRegistry::class)
            ->register($this->app->make(XBookmarksSkill::class));
    }
}
