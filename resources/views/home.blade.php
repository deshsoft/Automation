<x-layout title="Publish to all your Pages and channels at once">
    <div class="w-full max-w-5xl">
        <header class="mb-12 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="flex size-9 items-center justify-center rounded-lg bg-indigo-600 font-bold text-white">S</div>
                <span class="text-lg font-semibold">{{ config('app.name') }}</span>
            </div>
            <a href="{{ route('login') }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700">Sign in</a>
        </header>

        <section class="text-center">
            <h1 class="mx-auto max-w-3xl text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl">
                Write once. Publish to every Page and channel.
            </h1>
            <p class="mx-auto mt-5 max-w-2xl text-lg text-slate-600">
                {{ config('app.name') }} publishes your posts, photos and videos to your Facebook Pages, Instagram accounts,
                YouTube channels and TikTok accounts at the same time, now or on a schedule.
            </p>
            <div class="mt-8 flex justify-center gap-3">
                <a href="{{ route('login') }}" class="rounded-lg bg-indigo-600 px-5 py-2.5 font-medium text-white shadow-sm hover:bg-indigo-700">Sign in</a>
                <a href="#features" class="rounded-lg border border-slate-300 bg-white px-5 py-2.5 font-medium text-slate-700 hover:bg-slate-50">How it works</a>
            </div>
            <div class="mt-8 flex justify-center gap-2">
                @foreach (\App\Enums\Platform::cases() as $platform)
                    <x-platform-badge :platform="$platform" class="size-8 text-xs" />
                @endforeach
            </div>
        </section>

        <section id="features" class="mt-20 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                ['create', 'One post, many accounts', 'Choose the Pages and channels, write the caption, attach a photo or video and publish everywhere in one click.'],
                ['clock', 'Schedule ahead', 'Pick a date and time. Posts go out automatically, with a small gap between Pages.'],
                ['live', 'Go live on several Pages', 'Start a live video on many Facebook Pages at once, or on one main Page that the others share.'],
                ['link', 'Share links', 'Share news, videos and public posts to all your Pages with your own text.'],
                ['chart', 'See every result', 'A dashboard shows what was published, what failed and why, with one-click retry.'],
                ['accounts', 'Official connections only', 'Accounts are connected with the official login of each platform. Passwords are never stored.'],
            ] as [$icon, $heading, $text])
                <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="mb-4 inline-flex rounded-lg bg-indigo-50 p-2 text-indigo-600"><x-icon :name="$icon" class="size-5" /></div>
                    <h2 class="font-semibold text-slate-900">{{ $heading }}</h2>
                    <p class="mt-2 text-sm text-slate-600">{{ $text }}</p>
                </div>
            @endforeach
        </section>

        <section class="mt-16 rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-600 shadow-sm">
            <h2 class="mb-2 font-semibold text-slate-900">How your data is used</h2>
            <p>
                {{ config('app.name') }} only uses the permissions you grant to publish the content you create to the accounts you choose.
                YouTube uploads use YouTube API Services. Read the <a href="{{ route('privacy') }}" class="text-indigo-600 underline">Privacy Policy</a>
                for details, and the <a href="{{ route('terms') }}" class="text-indigo-600 underline">Terms of Service</a>.
            </p>
        </section>

        <footer class="mt-12 flex flex-wrap items-center justify-between gap-4 border-t border-slate-200 py-6 text-sm text-slate-500">
            <span>© {{ now()->year }} {{ config('app.name') }}</span>
            <nav class="flex gap-4">
                <a href="{{ route('privacy') }}" class="hover:text-slate-900">Privacy Policy</a>
                <a href="{{ route('terms') }}" class="hover:text-slate-900">Terms of Service</a>
                @if (config('app.contact_email'))
                    <a href="mailto:{{ config('app.contact_email') }}" class="hover:text-slate-900">Contact</a>
                @endif
            </nav>
        </footer>
    </div>
</x-layout>
