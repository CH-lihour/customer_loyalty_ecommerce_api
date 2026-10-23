<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Move each product's single image URL into product_images as its cover, then drop the column.
     */
    public function up(): void
    {
        DB::table('products')->whereNotNull('image')->where('image', '!=', '')->orderBy('id')
            ->each(function (object $product) {
                DB::table('product_images')->insert([
                    'product_id' => $product->id,
                    'path' => $product->image,
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }

    /**
     * Restore the column with each product's cover image.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('image')->nullable()->after('description');
        });

        DB::table('product_images')->orderBy('sort_order')->orderByDesc('id')->get()
            ->groupBy('product_id')
            ->each(fn ($images, $productId) => DB::table('products')->where('id', $productId)->update(['image' => $images->first()->path]));
    }
};
