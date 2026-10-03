<?php

namespace App\Services\Connectors;

use App\Enums\Platform;
use App\Exceptions\PublishingException;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Connects TikTok accounts. Access tokens last 24 hours and refresh tokens one year.
 */
class TikTokConnector implements Connector
{
    /**
     * @var list<string>
     */
    public const SCOPES = ['user.info.basic', 'video.publish'];

    private const TOKEN_URL = 'https://open.tiktokapis.com/v2/oauth/token/';

    public function authorizationUrl(string $state): string
    {
        return 'https://www.tiktok.com/v2/auth/authorize/?'.http_build_query([
            'client_key' => config('services.tiktok.client_key'),
            'scope' => implode(',', self::SCOPES),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ]);
    }

    public function connect(string $code, User $user): int
    {
        $tokens = $this->requestToken([
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri(),
        ]);

        $profile = Http::withToken((string) $tokens->json('access_token'))->connectTimeout(10)->timeout(30)
            ->get('https://open.tiktokapis.com/v2/user/info/', ['fields' => 'open_id,display_name,avatar_url']);

        PublishingException::throwUnlessSuccessful($profile, 'TikTok profile');

        $user->socialAccounts()->updateOrCreate(
            ['platform' => Platform::TikTok, 'platform_account_id' => (string) $tokens->json('open_id')],
            [
                'name' => $profile->json('data.user.display_name') ?? 'TikTok account',
                'avatar_url' => $profile->json('data.user.avatar_url'),
                ...$this->tokenAttributes($tokens),
            ],
        );

        return 1;
    }

    /**
     * Return a valid access token, refreshing it first when it is about to expire.
     */
    public function freshAccessToken(SocialAccount $account): string
    {
        if (! $account->tokenExpiresSoon()) {
            return $account->access_token;
        }

        if ($account->refresh_token === null) {
            throw new PublishingException('TikTok access expired. Please reconnect this account.');
        }

        $tokens = $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ]);

        $account->update($this->tokenAttributes($tokens));

        return $account->access_token;
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function requestToken(array $parameters): Response
    {
        $response = Http::asForm()->connectTimeout(10)->timeout(30)->post(self::TOKEN_URL, [
            'client_key' => config('services.tiktok.client_key'),
            'client_secret' => config('services.tiktok.client_secret'),
            ...$parameters,
        ]);

        PublishingException::throwUnlessSuccessful($response, 'TikTok login');

        if ($response->json('access_token') === null) {
            throw new PublishingException('TikTok login: '.PublishingException::extractMessage($response));
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenAttributes(Response $tokens): array
    {
        return [
            'access_token' => $tokens->json('access_token'),
            'refresh_token' => $tokens->json('refresh_token'),
            'token_expires_at' => now()->addSeconds((int) $tokens->json('expires_in', 86400)),
        ];
    }

    private function redirectUri(): string
    {
        return rtrim(config('app.url'), '/').route('connect.callback', 'tiktok', absolute: false);
    }
}
