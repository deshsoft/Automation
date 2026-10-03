<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SocialAccountController extends Controller
{
    public function index(Request $request): View
    {
        $accounts = $request->user()->socialAccounts()->orderBy('platform')->orderBy('name')->get();

        return view('accounts.index', ['accounts' => $accounts]);
    }

    public function update(Request $request, SocialAccount $account): RedirectResponse
    {
        Gate::authorize('update', $account);

        $account->update(['is_active' => $request->boolean('is_active')]);

        return back()->with('success', $account->is_active ? "{$account->name} enabled." : "{$account->name} disabled.");
    }

    public function destroy(SocialAccount $account): RedirectResponse
    {
        Gate::authorize('delete', $account);

        $account->delete();

        return back()->with('success', "{$account->name} removed.");
    }
}
