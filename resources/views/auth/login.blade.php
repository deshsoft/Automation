<x-layout title="Log in">
    <div class="w-full max-w-sm">
        <div class="mb-8 text-center">
            <div class="mx-auto flex size-12 items-center justify-center rounded-xl bg-indigo-600 text-xl font-bold text-white shadow-sm">S</div>
            <h1 class="mt-4 text-2xl font-semibold tracking-tight">{{ config('app.name') }}</h1>
            <p class="mt-1 text-sm text-slate-500">Sign in to manage your Pages and channels</p>
        </div>

        <form method="POST" action="{{ route('login.store') }}" class="space-y-4 rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
            @csrf
            <div>
                <label for="email" class="mb-1 block text-sm font-medium">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-indigo-500 focus:outline-none">
                @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password" class="mb-1 block text-sm font-medium">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-indigo-500 focus:outline-none">
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="remember" value="1" class="rounded"> Remember me
            </label>
            <button class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 font-medium text-white shadow-sm hover:bg-indigo-700">Sign in</button>
        </form>

        <p class="mt-6 text-center text-xs text-slate-500">
            <a href="{{ route('privacy') }}" class="hover:underline">Privacy Policy</a> ·
            <a href="{{ route('terms') }}" class="hover:underline">Terms of Service</a>
        </p>
    </div>
</x-layout>
