<x-layout title="System">
    <div class="w-full max-w-sm">
        <form method="POST" action="{{ route('system.unlock') }}" class="space-y-4 rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
            @csrf
            <div>
                <h1 class="text-xl font-semibold text-slate-900">System tools</h1>
                <p class="mt-1 text-sm text-slate-500">Enter the <code>SYSTEM_TOKEN</code> from the server's .env file.</p>
            </div>
            <div>
                <label for="token" class="mb-1 block text-sm font-medium">Access token</label>
                <input id="token" name="token" type="password" required autofocus autocomplete="off"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-indigo-500 focus:outline-none">
                @error('token') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <button class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 font-medium text-white hover:bg-indigo-700">Unlock</button>
        </form>
    </div>
</x-layout>
