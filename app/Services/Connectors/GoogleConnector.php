<?php

namespace App\Services\Connectors;

use App\Enums\Platform;
use App\Exceptions\PublishingException;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Connects YouTube channels. Google access tokens last one hour and are
 * renewed with the stored refresh token.
 */
class GoogleConnector implements Connector
{
    /**
     * @var list<string>
     */
    public const SCOPES = [
        'https://www.googleapis.com/auth/youtube.upload',
        'https://www.googleapis.com/auth/youtube.readonly',
        // Needed to edit a video's title/description after it is published.
        'https://www.googleapis.com/auth/youtube.force-ssl',
    ];

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function authorizationUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'select_account consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    public function connect(string $code, User $user): int
    {
        $response = Http::asForm()->connectTimeout(10)->timeout(30)->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri(),
        ]);

        PublishingException::throwUnlessSuccessful($response, 'Google login');

        $accessToken = (string) $response->json('access_token');
        $refreshToken = $response->json('refresh_token');
        $expiresAt = now()->addSeconds((int) $response->json('expires_in', 3600));

        $channels = Http::withToken($accessToken)->connectTimeout(10)->timeout(30)
            ->get('https://www.googleapis.com/youtube/v3/channels', ['part' => 'snippet', 'mine' => 'true']);

        PublishingException::throwUnlessSuccessful($channels, 'YouTube channel');

        $connected = 0;

        foreach ($channels->json('items', []) as $channel) {
            $attributes = [
                'name' => $channel['snippet']['title'],
                'username' => $channel['snippet']['customUrl'] ?? null,
                'avatar_url' => $channel['snippet']['thumbnails']['default']['url'] ?? null,
                'access_token' => $accessToken,
                'token_expires_at' => $expiresAt,
            ];

            if ($refreshToken !== null) {
                $attributes['refresh_token'] = $refreshToken;
            }

            $user->socialAccounts()->updateOrCreate(
                ['platform' => Platform::YouTube, 'platform_account_id' => (string) $channel['id']],
                $attributes,
            );
            $connected++;
        }

        if ($connected === 0) {
            throw new PublishingException('This Google account has no YouTube channel.');
        }

        return $connected;
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
            throw new PublishingException('YouTube access expired. Please reconnect this channel.');
        }

        $response = Http::asForm()->connectTimeout(10)->timeout(30)->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $account->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        PublishingException::throwUnlessSuccessful($response, 'YouTube token refresh (reconnect the channel if this persists)');

        $account->update([
            'access_token' => $response->json('access_token'),
            'token_expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
        ]);

        return $account->access_token;
    }

    private function redirectUri(): string
    {
        return rtrim(config('app.url'), '/').route('connect.callback', 'google', absolute: false);
    }
}
