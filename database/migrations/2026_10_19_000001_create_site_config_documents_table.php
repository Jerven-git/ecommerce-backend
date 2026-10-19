<?php

use App\Models\Scopes\StoreScope;
use App\Models\SiteConfig;
use App\Support\WebsiteEditor\WebsiteDocumentStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_config_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_config_id')->unique()->constrained('site_config')->cascadeOnDelete();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('schema_version')->default(WebsiteDocumentStore::SCHEMA_VERSION);
            $table->json('payload');
            $table->timestamps();
        });

        SiteConfig::withoutGlobalScope(StoreScope::class)
            ->orderBy('id')
            ->chunkById(250, function ($configs): void {
                $documents = app(WebsiteDocumentStore::class);

                foreach ($configs as $config) {
                    $documents->syncFromLegacy($config, refresh: false);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_config_documents');
    }
};
