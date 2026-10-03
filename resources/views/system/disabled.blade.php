<x-layout title="System">
    <div class="w-full max-w-lg rounded-xl border border-slate-200 bg-white p-8 text-sm text-slate-700 shadow-sm">
        <h1 class="mb-2 text-xl font-semibold text-slate-900">System page is switched off</h1>
        <p>To use it, add a long secret (at least 32 characters) to the <code class="rounded bg-slate-100 px-1">.env</code> file on the server, for example with cPanel File Manager:</p>
        <pre class="mt-3 overflow-x-auto rounded-lg bg-slate-900 p-3 text-xs text-slate-100">SYSTEM_TOKEN=your-long-random-secret-of-32-or-more-characters</pre>
        <p class="mt-3">Then reload this page and enter that token.</p>
    </div>
</x-layout>
