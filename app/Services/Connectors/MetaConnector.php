<?php

namespace App\Services\Connectors;

use App\Enums\Platform;
use App\Exceptions\PublishingException;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Connects Facebook Pages and the Instagram business accounts linked to them.
 *
 * Page tokens obtained from a long-lived user token do not expire, so no refresh is needed.
 */
class MetaConnector implements Connector
{
    /**
     * @var list<string>
     */
    public const SCOPES = [
        'pages_show_list',
        'pages_read_engagement',
        'pages_manage_posts',
        'publish_video',
        'business_management',
        'instagram_basic',
        'instagram_content_publish',
    ];

    public function authorizationUrl(string $state): string
    {
        $configId = config('services.facebook.config_id');

        // "Facebook Login for Business" apps use a configuration ID instead of a scope list.
        $permissions = filled($configId)
            ? ['config_id' => $configId]
            : ['scope' => implode(',', self::SCOPES)];

        return 'https://www.facebook.com/'.$this->version().'/dialog/oauth?'.http_build_query([
            'client_id' => config('services.facebook.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            ...$permissions,
            'response_type' => 'code',
        ]);
    }

    public function connect(string $code, User $user): int
    {
        $shortLivedToken = $this->requestToken([
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ]);

        $longLivedToken = $this->requestToken([
            'grant_type' => 'fb_exchange_token',
            'fb_exchange_token' => $shortLivedToken,
        ]);

        $response = Http::connectTimeout(10)->timeout(30)->get($this->graphUrl('me/accounts'), [
            'fields' => 'id,name,access_token,picture{url},instagram_business_account{id,username,name,profile_picture_url}',
            'limit' => 100,
            'access_token' => $longLivedToken,
        ]);

        PublishingException::throwUnlessSuccessful($response, 'Facebook pages');

        $connected = 0;

        foreach ($response->json('data', []) as $page) {
            $user->socialAccounts()->updateOrCreate(
                ['platform' => Platform::Facebook, 'platform_account_id' => (string) $page['id']],
                [
                    'name' => $page['name'],
                    'avatar_url' => $page['picture']['data']['url'] ?? null,
                    'access_token' => $page['access_token'],
                ],
            );
            $connected++;

            $instagram = $page['instagram_business_account'] ?? null;

            if ($instagram !== null) {
                $user->socialAccounts()->updateOrCreate(
                    ['platform' => Platform::Instagram, 'platform_account_id' => (string) $instagram['id']],
                    [
                        'name' => $instagram['name'] ?? $instagram['username'] ?? $page['name'],
                        'username' => $instagram['username'] ?? null,
                        'avatar_url' => $instagram['profile_picture_url'] ?? null,
                        'access_token' => $page['access_token'],
                        'meta' => ['facebook_page_id' => (string) $page['id']],
                    ],
                );
                $connected++;
            }
        }

        return $connected;
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function requestToken(array $parameters): string
    {
        $response = Http::connectTimeout(10)->timeout(30)->get($this->graphUrl('oauth/access_token'), [
            'client_id' => config('services.facebook.client_id'),
            'client_secret' => config('services.facebook.client_secret'),
            ...$parameters,
        ]);

        PublishingException::throwUnlessSuccessful($response, 'Facebook login');

        return (string) $response->json('access_token');
    }

    private function redirectUri(): string
    {
        return rtrim(config('app.url'), '/').route('connect.callback', 'meta', absolute: false);
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.$this->version().'/'.$path;
    }

    private function version(): string
    {
        return config('services.facebook.graph_version');
    }
}
