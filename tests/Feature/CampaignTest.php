<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->superAdmin()->create());
    }

    public function test_index_lists_campaigns(): void
    {
        Campaign::factory()->create(['name' => 'Older', 'start_date' => now()->subMonth()->toDateString()]);
        Campaign::factory()->banner()->create(['name' => 'Newer', 'start_date' => now()->toDateString()]);

        $this->getJson('/api/backend/campaigns')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Newer')
            ->assertJsonPath('data.0.show_on_home', true)
            ->assertJsonPath('data.0.image_url', 'https://example.com/banner.jpg')
            ->assertJsonPath('data.0.start_date', now()->toDateString());
    }

    public function test_campaign_can_be_created_with_an_uploaded_banner(): void
    {
        Storage::fake('public');

        $response = $this->post('/api/backend/campaigns', [
            'name' => ' Songkran Sale ',
            'multiplier' => 1.5,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'show_on_home' => '1',
            'sort_order' => 2,
            'banner_title' => 'Songkran Sale',
            'button_label' => 'Shop now',
            'link_url' => '/products',
            'image_file' => UploadedFile::fake()->image('banner.jpg', 1200, 400),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Songkran Sale')
            ->assertJsonPath('data.multiplier', '1.50')
            ->assertJsonPath('data.show_on_home', true)
            ->assertJsonPath('data.is_active', true);

        $campaign = Campaign::sole();
        Storage::disk('public')->assertExists($campaign->image);
        $this->assertSame(url('storage/'.$campaign->image), $response->json('data.image_url'));
    }

    public function test_campaign_can_be_updated_and_a_new_banner_replaces_the_old_file(): void
    {
        Storage::fake('public');
        $campaign = Campaign::factory()->create(['image' => 'campaigns/old.jpg', 'end_date' => '2026-12-31']);
        Storage::disk('public')->put('campaigns/old.jpg', 'old');

        $this->patch("/api/backend/campaigns/{$campaign->id}", [
            'banner_title' => 'New title',
            'image_file' => UploadedFile::fake()->image('new.png'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.banner_title', 'New title');

        Storage::disk('public')->assertMissing('campaigns/old.jpg');
        Storage::disk('public')->assertExists($campaign->refresh()->image);
    }

    public function test_campaign_can_be_deactivated_and_reactivated(): void
    {
        $campaign = Campaign::factory()->create();

        $this->patchJson("/api/backend/campaigns/{$campaign->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('message', 'Campaign deactivated')
            ->assertJsonPath('data.is_active', false);

        $this->patchJson("/api/backend/campaigns/{$campaign->id}/status", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_campaign_fields_are_validated(): void
    {
        $this->postJson('/api/backend/campaigns', [
            'multiplier' => 11,
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-01',
            'link_url' => 'javascript:alert(1)',
            'image' => 'not a url',
            'sort_order' => 'first',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name', 'multiplier', 'end_date' => 'cannot be before', 'link_url' => 'page path', 'image', 'sort_order',
            ]);

        $campaign = Campaign::factory()->create(['start_date' => '2026-11-10', 'end_date' => '2026-11-20']);

        $this->patchJson("/api/backend/campaigns/{$campaign->id}", ['end_date' => '2026-11-05'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_date' => 'cannot be before']);
    }

    public function test_deleting_a_campaign_removes_its_uploaded_banner(): void
    {
        Storage::fake('public');
        $campaign = Campaign::factory()->create(['image' => 'campaigns/banner.jpg']);
        Storage::disk('public')->put('campaigns/banner.jpg', 'banner');

        $this->deleteJson("/api/backend/campaigns/{$campaign->id}")->assertOk();

        $this->assertModelMissing($campaign);
        Storage::disk('public')->assertMissing('campaigns/banner.jpg');
    }

    public function test_public_banners_list_only_live_home_page_campaigns_in_order(): void
    {
        $second = Campaign::factory()->banner()->create(['banner_title' => 'Second', 'sort_order' => 2, 'end_date' => null]);
        $first = Campaign::factory()->banner()->create(['banner_title' => 'First', 'sort_order' => 1]);
        Campaign::factory()->banner()->inactive()->create();
        Campaign::factory()->create(['show_on_home' => false]);
        Campaign::factory()->banner()->create(['start_date' => now()->subMonth(), 'end_date' => now()->subDay()]);
        Campaign::factory()->banner()->create(['start_date' => now()->addDay(), 'end_date' => now()->addMonth()]);

        // Guests can read the banners: no staff or customer token.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/campaigns/banners')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.banner_title', 'First')
            ->assertJsonPath('data.0.image_url', 'https://example.com/banner.jpg')
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonMissingPath('data.0.is_active')
            ->assertJsonMissingPath('data.0.sort_order');
    }

    public function test_campaigns_need_the_campaigns_page(): void
    {
        $campaign = Campaign::factory()->create();
        Sanctum::actingAs(User::factory()->withPages(['dashboard'])->create());

        $this->getJson('/api/backend/campaigns')->assertForbidden();
        $this->patchJson("/api/backend/campaigns/{$campaign->id}/status", ['is_active' => false])->assertForbidden();
        $this->deleteJson("/api/backend/campaigns/{$campaign->id}")->assertForbidden();

        Sanctum::actingAs(User::factory()->withPages(['campaigns'])->create());

        $this->getJson('/api/backend/campaigns')->assertOk();
    }
}
