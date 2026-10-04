<x-layout title="Go Live">
    <p class="mb-6 text-sm text-gray-600">
        This creates a live video on every Page you select and gives you the stream keys.
        Then you start streaming once from OBS (computer) or Larix Broadcaster (phone), and the video goes to all Pages.
    </p>

    @if ($pages->isEmpty())
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-10 text-center text-gray-500">
            Connect your Facebook Pages first on the <a href="{{ route('accounts.index') }}" class="text-indigo-600 underline">Accounts</a> page.
        </div>
    @else
        <form method="POST" action="{{ route('live.store') }}" class="space-y-5" data-live-form>
            @csrf

            @if ($errors->any())
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    <ul class="list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <section class="rounded-lg border border-gray-200 bg-white p-5">
                <h2 class="mb-3 font-semibold">Go live on</h2>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    @foreach ($pages as $page)
                        <label class="flex cursor-pointer items-center gap-3 rounded-md border border-gray-200 px-3 py-2 hover:bg-gray-50 has-checked:border-indigo-400 has-checked:bg-indigo-50">
                            <input type="checkbox" name="accounts[]" value="{{ $page->id }}" class="rounded" data-live-page data-name="{{ $page->name }}" @checked(in_array($page->id, old('accounts', [])))>
                            @if ($page->avatar_url)
                                <img src="{{ $page->avatar_url }}" alt="" class="size-6 rounded-full">
                            @endif
                            <span class="truncate text-sm">{{ $page->name }}</span>
                        </label>
                    @endforeach
                </div>
            </section>

            <section class="space-y-3 rounded-lg border border-gray-200 bg-white p-5">
                <h2 class="font-semibold">How to go live</h2>
                <label class="flex items-start gap-2 text-sm">
                    <input type="radio" name="mode" value="all" data-live-mode class="mt-0.5" @checked(old('mode', 'all') === 'all')>
                    <span><span class="font-medium">Live on every selected Page</span><br>
                        <span class="text-gray-500">Each Page broadcasts the video itself. Needs about 4 Mbps upload per Page.</span></span>
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <input type="radio" name="mode" value="share" data-live-mode class="mt-0.5" @checked(old('mode') === 'share')>
                    <span><span class="font-medium">Live on one main Page, other Pages share it</span><br>
                        <span class="text-gray-500">Only one stream (about 4 Mbps), and all viewers and comments gather on one live video.</span></span>
                </label>

                <div data-live-share-fields @class(['space-y-3 rounded-md bg-gray-50 p-3', 'hidden' => old('mode') !== 'share'])>
                    <div>
                        <label for="main_account_id" class="mb-1 block text-sm font-medium">Main Page (goes live)</label>
                        <select id="main_account_id" name="main_account_id" data-live-main data-old="{{ old('main_account_id') }}"
                                class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"></select>
                        <p class="mt-1 text-xs text-gray-500">Select at least two Pages above. All the others will share the main Page's live.</p>
                    </div>
                    <div>
                        <label for="share_message" class="mb-1 block text-sm font-medium">Text the other Pages add when sharing <span class="font-normal text-gray-500">(optional)</span></label>
                        <textarea id="share_message" name="share_message" rows="2" maxlength="2000"
                                  class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">{{ old('share_message') }}</textarea>
                    </div>
                </div>
            </section>

            <section class="space-y-4 rounded-lg border border-gray-200 bg-white p-5">
                <div>
                    <label for="title" class="mb-1 block font-semibold">Title</label>
                    <input id="title" name="title" type="text" maxlength="255" required value="{{ old('title') }}"
                           class="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:outline-none">
                </div>
                <div>
                    <label for="description" class="mb-1 block font-semibold">Description</label>
                    <textarea id="description" name="description" rows="4" maxlength="5000"
                              class="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:outline-none">{{ old('description') }}</textarea>
                </div>
            </section>

            <button class="rounded-md bg-red-600 px-6 py-2.5 font-medium text-white hover:bg-red-700">● Create live</button>
        </form>
    @endif

    @if ($liveStreams->isNotEmpty())
        <h2 class="mt-10 mb-3 text-lg font-semibold">Recent lives</h2>
        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <tbody class="divide-y divide-gray-100">
                    @foreach ($liveStreams as $liveStream)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('live.show', $liveStream) }}" class="font-medium text-indigo-600 hover:underline">{{ $liveStream->title }}</a>
                            </td>
                            <td class="px-4 py-3">
                                @if ($liveStream->isLive())
                                    <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">● Live</span>
                                @else
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">Ended</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ $liveStream->targets_count }} Page(s)</td>
                            <td class="px-4 py-3 whitespace-nowrap text-gray-600"><x-local-time :time="$liveStream->created_at" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-layout>
