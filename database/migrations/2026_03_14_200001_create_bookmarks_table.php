<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Connection renamed from skill_x-bookmarks to skill_x during package rename.

return new class extends Migration
{
    protected $connection = 'skill_x';

    public function up(): void
    {
        Schema::connection($this->connection)->create('bookmarks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('tweet_id')->index();
            $table->text('text');
            $table->string('author_id');
            $table->string('author_username')->nullable();
            $table->string('author_name')->nullable();
            $table->string('created_at');
            $table->string('bookmarked_at');
            $table->boolean('has_media')->default(false);
            $table->boolean('has_links')->default(false);
            $table->text('links_json')->nullable();
            $table->text('media_json')->nullable();
            $table->string('conversation_id')->nullable();

            $table->unique(['user_id', 'tweet_id']);
        });

        Schema::connection($this->connection)->create('sync_state', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('key');
            $table->text('value')->nullable();

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('sync_state');
        Schema::connection($this->connection)->dropIfExists('bookmarks');
    }
};
