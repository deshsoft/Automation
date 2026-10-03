<?php

namespace App\Http\Controllers;

use App\Exceptions\PublishingException;
use App\Services\Connectors\Connector;
use App\Services\Connectors\GoogleConnector;
use App\Services\Connectors\MetaConnector;
use App\Services\Connectors\TikTokConnector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ConnectAccountController extends Controller
{
    /**
     * @var list<string>
     */
    public const PROVIDERS = ['meta', 'google', 'tiktok'];

    /**
     * @var array<string, array{connector: class-string<Connector>, config: string, label: string}>
     */
    private const CONNECTORS = [
        'meta' => ['connector' => MetaConnector::class, 'config' => 'services.facebook.client_id', 'label' => 'Facebook / Instagram'],
        'google' => ['connector' => GoogleConnector::class, 'config' => 'services.google.client_id', 'label' => 'YouTube'],
        'tiktok' => ['connector' => TikTokConnector::class, 'config' => 'services.tiktok.client_key', 'label' => 'TikTok'],
    ];

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $definition = self::CONNECTORS[$provider];

        if (blank(config($definition['config']))) {
            return redirect()->route('accounts.index')
                ->with('error', "{$definition['label']} app keys are missing. Add them to the .env file first.");
        }

        $state = Str::random(40);
        $request->session()->put("oauth_state.{$provider}", $state);

        return redirect()->away(app($definition['connector'])->authorizationUrl($state));
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $definition = self::CONNECTORS[$provider];
        $expectedState = $request->session()->pull("oauth_state.{$provider}");

        if ($expectedState === null || ! hash_equals($expectedState, (string) $request->query('state'))) {
            return redirect()->route('accounts.index')->with('error', 'The login session expired. Please try connecting again.');
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            $reason = $request->query('error_description') ?? $request->query('error_message') ?? $request->query('error') ?? 'Access was not granted.';

            return redirect()->route('accounts.index')->with('error', "{$definition['label']}: {$reason}");
        }

        try {
            $count = app($definition['connector'])->connect((string) $request->query('code'), $request->user());
        } catch (PublishingException|RequestException|ConnectionException $exception) {
            report($exception);

            return redirect()->route('accounts.index')->with('error', $exception->getMessage());
        }

        if ($count === 0) {
            return redirect()->route('accounts.index')
                ->with('error', "{$definition['label']}: no accounts were found. Make sure you selected your Pages during login.");
        }

        return redirect()->route('accounts.index')->with('success', "{$definition['label']}: {$count} account(s) connected.");
    }
}
