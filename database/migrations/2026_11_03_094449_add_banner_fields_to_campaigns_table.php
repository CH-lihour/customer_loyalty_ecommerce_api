<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('name_kh')->nullable()->after('name');
            $table->text('description')->nullable()->after('name_kh');
            $table->string('image')->nullable()->after('description');
            $table->string('banner_title')->nullable()->after('image');
            $table->string('banner_subtitle')->nullable()->after('banner_title');
            $table->string('button_label')->nullable()->after('banner_subtitle');
            $table->string('link_url', 2048)->nullable()->after('button_label');
            $table->boolean('show_on_home')->default(false)->after('is_active');
            $table->integer('sort_order')->default(0)->after('show_on_home');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn([
                'name_kh', 'description', 'image', 'banner_title', 'banner_subtitle',
                'button_label', 'link_url', 'show_on_home', 'sort_order',
            ]);
        });
    }
};
