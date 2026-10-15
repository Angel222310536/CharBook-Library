<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EntitlementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_resolves_features_per_plan(): void
    {
        $free = User::factory()->create(['subscription_tier' => 'free']);
        $premium = User::factory()->create(['subscription_tier' => 'premium']);
        $plus = User::factory()->create(['subscription_tier' => 'premium_plus']);

        // Premium: temas/fuentes sí; voces/early access NO.
        $this->assertFalse(Entitlements::can($free, 'premium_themes'));
        $this->assertTrue(Entitlements::can($premium, 'premium_themes'));
        $this->assertFalse(Entitlements::can($premium, 'ai_voices'));
        $this->assertFalse(Entitlements::can($premium, 'early_access'));

        // Premium Plus: todo.
        $this->assertTrue(Entitlements::can($plus, 'ai_voices'));
        $this->assertTrue(Entitlements::can($plus, 'early_access'));

        // Estados de UI.
        $this->assertSame('requires_premium', Entitlements::state($free, 'premium_themes'));
        $this->assertSame('requires_premium_plus', Entitlements::state($free, 'ai_audiobooks'));
        $this->assertSame('coming_soon', Entitlements::state($plus, 'ai_audiobooks'));
        $this->assertSame('available', Entitlements::state($plus, 'ai_voices'));
    }

    public function test_plus_voice_endpoint_blocked_for_free_and_premium(): void
    {
        Sanctum::actingAs(User::factory()->create(['subscription_tier' => 'free']));
        $this->patchJson('/api/me/plus/voice', ['voice' => 'calida'])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['subscription_tier' => 'premium']));
        $this->patchJson('/api/me/plus/voice', ['voice' => 'calida'])->assertForbidden();
    }

    public function test_plus_voice_endpoint_saves_for_plus(): void
    {
        $plus = User::factory()->create(['subscription_tier' => 'premium_plus']);
        Sanctum::actingAs($plus);

        $this->patchJson('/api/me/plus/voice', ['voice' => 'profunda'])
            ->assertOk()
            ->assertJsonPath('voice', 'profunda');
        $this->assertSame('profunda', $plus->fresh()->preferences['reader_voice']);

        // Voz inexistente → 422.
        $this->patchJson('/api/me/plus/voice', ['voice' => 'robot'])->assertStatus(422);
    }

    public function test_advanced_profile_endpoint_requires_plus(): void
    {
        Sanctum::actingAs(User::factory()->create(['subscription_tier' => 'premium']));
        $this->patchJson('/api/me/plus/profile', ['banner' => 'aurora'])->assertForbidden();

        $plus = User::factory()->create(['subscription_tier' => 'premium_plus']);
        Sanctum::actingAs($plus);
        $this->patchJson('/api/me/plus/profile', ['banner' => 'aurora'])->assertOk();
        $this->assertSame('aurora', $plus->fresh()->preferences['profile_banner']);
    }

    public function test_advanced_profile_accepts_official_banners_and_no_banner(): void
    {
        $plus = User::factory()->create(['subscription_tier' => 'premium_plus']);
        Sanctum::actingAs($plus);

        $officialBanners = [
            'aurora',
            'biblioteca',
            'oceano',
            'bosque',
            'pergamino',
            'noche',
            'obsidiana',
            'dorado',
            'nebulosa',
            'atelier',
        ];

        foreach ($officialBanners as $banner) {
            $this->patchJson('/api/me/plus/profile', ['banner' => $banner])
                ->assertOk()
                ->assertJsonPath('banner', $banner);

            $this->assertSame($banner, $plus->fresh()->preferences['profile_banner']);
        }

        $this->patchJson('/api/me/plus/profile', ['banner' => null])
            ->assertOk()
            ->assertJsonPath('banner', null);

        $this->assertNull($plus->fresh()->preferences['profile_banner']);
    }

    public function test_advanced_profile_rejects_unknown_banner(): void
    {
        $plus = User::factory()->create(['subscription_tier' => 'premium_plus']);
        Sanctum::actingAs($plus);

        $this->patchJson('/api/me/plus/profile', ['banner' => 'inventado'])
            ->assertStatus(422);

        $this->assertNull($plus->fresh()->preferences['profile_banner'] ?? null);
    }

    public function test_user_endpoint_exposes_plan_and_entitlements(): void
    {
        $plus = User::factory()->create(['subscription_tier' => 'premium_plus']);
        Sanctum::actingAs($plus);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('plan', 'premium_plus')
            ->assertJsonPath('is_premium_plus', true)
            ->assertJsonPath('entitlements.early_access.can', true)
            ->assertJsonPath('entitlements.ai_voices.can', true);
    }
}
