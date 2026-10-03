<x-layout title="Privacy Policy">
    <article class="w-full max-w-3xl rounded-xl border border-slate-200 bg-white p-8 text-sm leading-relaxed text-slate-700 shadow-sm">
        <h1 class="mb-1 text-2xl font-semibold text-slate-900">Privacy Policy</h1>
        <p class="mb-6 text-xs text-slate-500">{{ config('app.name') }} · Last updated {{ now()->format('d F Y') }}</p>

        <h2 class="mt-6 mb-2 font-semibold text-slate-900">What this app does</h2>
        <p>{{ config('app.name') }} lets its account owner publish posts, photos, videos and live videos to the Facebook Pages, Instagram accounts, YouTube channels and TikTok accounts that the owner connects.</p>

        <h2 class="mt-6 mb-2 font-semibold text-slate-900">Information we access</h2>
        <ul class="list-inside list-disc space-y-1">
            <li>Basic profile information of connected accounts (name, ID and profile picture).</li>
            <li>Access tokens that allow publishing to the accounts you connect. Tokens are stored encrypted.</li>
            <li>The text, photos and videos you upload to publish.</li>
        </ul>

        <h2 class="mt-6 mb-2 font-semibold text-slate-900">How we use it</h2>
        <p>The information is used only to publish the content you create to the accounts you choose, and to show you the result. We do not sell, share or use it for advertising.</p>

        <h2 class="mt-6 mb-2 font-semibold text-slate-900">Google and YouTube data</h2>
        <p>This app uses YouTube API Services to upload videos to your channel. By connecting YouTube you agree to the
            <a href="https://www.youtube.com/t/terms" class="text-indigo-600 underline">YouTube Terms of Service</a> and the
            <a href="https://policies.google.com/privacy" class="text-indigo-600 underline">Google Privacy Policy</a>.
            You can remove this app's access at any time at <a href="https://myaccount.google.com/permissions" class="text-indigo-600 underline">myaccount.google.com/permissions</a>.</p>

        <h2 class="mt-6 mb-2 font-semibold text-slate-900">Storage and deletion</h2>
        <p>Uploaded media is deleted automatically 7 days after publishing. Removing an account on the Accounts page deletes its stored tokens. To delete all your data, contact us at the address below.</p>

        <h2 class="mt-6 mb-2 font-semibold text-slate-900">Contact</h2>
        <p>
            @if (config('app.contact_email'))
                Email: <a href="mailto:{{ config('app.contact_email') }}" class="text-indigo-600 underline">{{ config('app.contact_email') }}</a>
            @else
                Contact the app owner.
            @endif
        </p>
    </article>
</x-layout>
