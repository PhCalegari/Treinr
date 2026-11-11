<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PixPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportPixTest extends TestCase
{
    use RefreshDatabase;

    public function test_pix_payload_matches_central_bank_example()
    {
        // Exemplo do Manual de Padrões para Iniciação do PIX (BR Code estático).
        $this->assertSame(
            '00020126580014br.gov.bcb.pix0136123e4567-e12b-12d1-a456-4266554400005204000053039865802BR5913Fulano de Tal6008BRASILIA62070503***63041D3D',
            PixPayload::make('123e4567-e12b-12d1-a456-426655440000', 'Fulano de Tal', 'BRASILIA'),
        );
    }

    public function test_support_is_hidden_without_a_pix_key()
    {
        config(['services.pix.key' => null]);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn ($p) => $p->where('supportEnabled', false));
        $this->getJson(route('support.pix'))->assertNotFound();
    }

    public function test_support_returns_pix_code_and_qr()
    {
        config(['services.pix' => ['key' => 'dev@exemplo.com', 'name' => 'Pedro Calegári', 'city' => 'São Paulo', 'description' => 'Apoio ao Treinr']]);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn ($p) => $p->where('supportEnabled', true));
        $response = $this->getJson(route('support.pix'))->assertOk();

        $payload = $response->json('payload');
        $this->assertStringContainsString('0115dev@exemplo.com', $payload);
        $this->assertStringContainsString('5914Pedro Calegari', $payload); // sem acento, como os bancos esperam
        $this->assertStringContainsString('6009Sao Paulo', $payload);
        $this->assertMatchesRegularExpression('/6304[0-9A-F]{4}$/', $payload);
        $this->assertStringStartsWith('<?xml', $response->json('qr_svg'));

        $this->post('/logout');
        $this->getJson(route('support.pix'))->assertUnauthorized();
    }
}
