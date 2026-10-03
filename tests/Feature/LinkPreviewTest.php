<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LinkPreviewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinkPreviewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, list<string>>  $addresses
     */
    private function resolveHostsTo(array $addresses): void
    {
        $this->app->instance(LinkPreviewer::class, new LinkPreviewer(fn (string $host) => $addresses[$host] ?? []));
    }

    public function test_preview_returns_the_open_graph_details_of_a_post(): void
    {
        $this->resolveHostsTo(['www.facebook.com' => ['157.240.1.35']]);
        Http::fake([
            'www.facebook.com/*' => Http::response(<<<'HTML'
                <html><head>
                    <meta property="og:site_name" content="Facebook">
                    <meta property="og:title" content="Dhaka North News">
                    <meta property="og:description" content="&#039;রোমিং ফর ঢাকা নর্থ&#039; কর্মসূচির দ্বিতীয় দিন">
                    <meta property="og:image" content="https://scontent.example/photo.jpg">
                </head></html>
                HTML),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('link-preview', ['url' => 'https://www.facebook.com/dhakanorth/posts/123']))
            ->assertOk()
            ->assertJson([
                'site_name' => 'Facebook',
                'title' => 'Dhaka North News',
                'description' => "'রোমিং ফর ঢাকা নর্থ' কর্মসূচির দ্বিতীয় দিন",
                'image' => 'https://scontent.example/photo.jpg',
            ]);

        Http::assertSent(fn (Request $request) => str_contains($request->header('User-Agent')[0], 'facebookexternalhit'));
    }

    public function test_preview_follows_a_share_link_redirect(): void
    {
        $this->resolveHostsTo(['www.facebook.com' => ['157.240.1.35']]);
        Http::fake([
            'www.facebook.com/share/p/abc*' => Http::response('', 302, ['Location' => '/dhakanorth/posts/123']),
            'www.facebook.com/dhakanorth/posts/123' => Http::response('<meta property="og:title" content="Real post">'),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('link-preview', ['url' => 'https://www.facebook.com/share/p/abc/']))
            ->assertOk()
            ->assertJsonPath('title', 'Real post');
    }

    public function test_private_network_addresses_are_never_fetched(): void
    {
        $this->resolveHostsTo(['internal.example' => ['10.0.0.5'], 'localhost' => ['127.0.0.1']]);

        foreach (['http://internal.example/admin', 'http://localhost:8090/posts'] as $url) {
            $this->actingAs(User::factory()->create())
                ->getJson(route('link-preview', ['url' => $url]))
                ->assertNotFound();
        }

        Http::assertNothingSent();
    }

    public function test_redirect_to_a_private_address_is_not_followed(): void
    {
        $this->resolveHostsTo(['public.example' => ['93.184.216.34'], 'internal.example' => ['192.168.1.1']]);
        Http::fake(['public.example/*' => Http::response('', 302, ['Location' => 'http://internal.example/secret'])]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('link-preview', ['url' => 'http://public.example/go']))
            ->assertNotFound();

        Http::assertSentCount(1);
    }

    public function test_text_that_is_not_a_link_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(route('link-preview', ['url' => 'রোমিং ফর ঢাকা নর্থ']))
            ->assertUnprocessable();
    }

    public function test_guests_cannot_use_the_preview(): void
    {
        $this->getJson(route('link-preview', ['url' => 'https://example.com']))->assertUnauthorized();
    }
}
