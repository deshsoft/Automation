<x-layout title="Terms of Service">
    <article class="w-full max-w-3xl rounded-xl border border-slate-200 bg-white p-8 text-sm leading-relaxed text-slate-700 shadow-sm">
        <h1 class="mb-1 text-2xl font-semibold text-slate-900">Terms of Service</h1>
        <p class="mb-6 text-xs text-slate-500">{{ config('app.name') }} · Last updated {{ now()->format('d F Y') }}</p>

        <ol class="list-inside list-decimal space-y-3">
            <li>{{ config('app.name') }} is a tool for publishing your own content to social media accounts you own or manage.</li>
            <li>You are responsible for the content you publish and must follow the terms of Facebook, Instagram, YouTube and TikTok, including the <a href="https://www.youtube.com/t/terms" class="text-indigo-600 underline">YouTube Terms of Service</a>.</li>
            <li>Only connect accounts you are allowed to manage. You can disconnect them at any time.</li>
            <li>The service is provided as is, without any guarantee that every post will be published. Platform limits and outages can prevent publishing.</li>
            <li>We may change these terms. Continued use means you accept the updated terms.</li>
        </ol>

        <p class="mt-6">See also the <a href="{{ route('privacy') }}" class="text-indigo-600 underline">Privacy Policy</a>.</p>
    </article>
</x-layout>
