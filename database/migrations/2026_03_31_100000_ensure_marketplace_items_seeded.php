<?php

use App\Models\MarketplaceItem;
use Database\Seeders\MarketplaceItemSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catalog rows are optional at install time; many environments run migrate without db:seed.
     * Seed the marketplace once when the table exists but has no rows so the UI is usable.
     */
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_items')) {
            return;
        }

        if (MarketplaceItem::query()->exists()) {
            return;
        }

        Artisan::call('db:seed', [
            '--class' => MarketplaceItemSeeder::class,
            '--force' => true,
        ]);
    }
};
