<x-layout title="Accounts">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <p class="text-sm text-slate-600">Connect the Pages and channels you want to publish to.</p>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('connect.redirect', 'meta') }}" class="rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700">+ Facebook Pages &amp; Instagram</a>
            <a href="{{ route('connect.redirect', 'google') }}" class="rounded-md bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700">+ YouTube channel</a>
            <a href="{{ route('connect.redirect', 'tiktok') }}" class="rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-black">+ TikTok</a>
        </div>
    </div>

    <p class="mb-6 text-sm text-gray-600">
        Facebook login connects every Page you select and the Instagram business account linked to each Page.
        To add another YouTube channel or TikTok account, click the button again and choose a different account.
    </p>

    @if ($accounts->isEmpty())
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-10 text-center text-gray-500">
            No accounts yet. Connect one with the buttons above.
        </div>
    @else
        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <tbody class="divide-y divide-gray-100">
                    @foreach ($accounts as $account)
                        <tr @class(['opacity-50' => ! $account->is_active])>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    @if ($account->avatar_url)
                                        <img src="{{ $account->avatar_url }}" alt="" class="size-8 shrink-0 rounded-full">
                                    @else
                                        <div class="size-8 shrink-0 rounded-full bg-gray-200"></div>
                                    @endif
                                    <div class="min-w-0">
                                        <div class="font-medium">{{ $account->name }}</div>
                                        @if ($account->username)
                                            <div class="truncate text-gray-500">{{ $account->username }}</div>
                                        @endif
                                        <div class="text-xs text-gray-500 sm:hidden">{{ $account->platform->label() }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="hidden px-4 py-3 text-gray-600 sm:table-cell">{{ $account->platform->label() }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <form method="POST" action="{{ route('accounts.update', $account) }}" class="inline">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="is_active" value="{{ $account->is_active ? 0 : 1 }}">
                                    <button class="text-indigo-600 hover:underline">{{ $account->is_active ? 'Disable' : 'Enable' }}</button>
                                </form>
                                <form method="POST" action="{{ route('accounts.destroy', $account) }}" class="ml-3 inline"
                                      data-confirm="Remove {{ $account->name }}? Its post history will also be removed." onsubmit="return confirm(this.dataset.confirm)">
                                    @csrf @method('DELETE')
                                    <button class="text-red-600 hover:underline">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-layout>
