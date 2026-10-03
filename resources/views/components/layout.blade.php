@props(['title' => 'Dashboard', 'refresh' => false, 'wide' => false])
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    @if ($refresh)
        <meta http-equiv="refresh" content="15">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full text-slate-900 antialiased">
    @auth
        @php($navigation = [
            ['route' => 'dashboard', 'active' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'home'],
            ['route' => 'posts.create', 'active' => 'posts.create', 'label' => 'Create post', 'icon' => 'create'],
            ['route' => 'posts.index', 'active' => ['posts.index', 'posts.show'], 'label' => 'Posts', 'icon' => 'posts'],
            ['route' => 'live.index', 'active' => 'live.*', 'label' => 'Go Live', 'icon' => 'live'],
            ['route' => 'downloads.index', 'active' => 'downloads.*', 'label' => 'Download video', 'icon' => 'download'],
            ['route' => 'accounts.index', 'active' => 'accounts.*', 'label' => 'Accounts', 'icon' => 'accounts'],
        ])

        {{-- Mobile backdrop --}}
        <div data-sidebar-backdrop class="fixed inset-0 z-30 hidden bg-slate-900/50 lg:hidden"></div>

        {{-- Sidebar --}}
        <aside data-sidebar class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-slate-900 text-slate-300 transition-transform lg:translate-x-0">
            <div class="flex h-16 items-center gap-3 px-6">
                <div class="flex size-8 items-center justify-center rounded-lg bg-indigo-500 font-bold text-white">S</div>
                <span class="font-semibold text-white">{{ config('app.name') }}</span>
                <button type="button" data-sidebar-close class="ml-auto text-slate-400 hover:text-white lg:hidden" aria-label="Close menu">
                    <x-icon name="close" />
                </button>
            </div>

            <nav class="flex-1 space-y-1 px-3 py-4">
                @foreach ($navigation as $item)
                    @php($isActive = request()->routeIs(...(array) $item['active']))
                    <a href="{{ route($item['route']) }}" @class([
                        'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition',
                        'bg-slate-800 text-white' => $isActive,
                        'hover:bg-slate-800/60 hover:text-white' => ! $isActive,
                    ])>
                        <x-icon :name="$item['icon']" :class="$isActive ? 'size-5 text-indigo-400' : 'size-5'" />
                        {{ $item['label'] }}
                    </a>
                @endforeach

                <div class="px-3 pt-6 pb-2 text-xs font-semibold tracking-wider text-slate-500 uppercase">Admin</div>
                @php($isSystemActive = request()->routeIs('system.*'))
                <a href="{{ route('system.show') }}" @class([
                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition',
                    'bg-slate-800 text-white' => $isSystemActive,
                    'hover:bg-slate-800/60 hover:text-white' => ! $isSystemActive,
                ])>
                    <x-icon name="cog" :class="$isSystemActive ? 'size-5 text-indigo-400' : 'size-5'" />
                    System
                </a>
            </nav>

            <div class="border-t border-slate-800 p-4">
                <div class="flex items-center gap-3">
                    <div class="flex size-9 items-center justify-center rounded-full bg-slate-700 text-sm font-semibold text-white">
                        {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium text-white">{{ auth()->user()->name }}</div>
                        <div class="truncate text-xs text-slate-400">{{ auth()->user()->email }}</div>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="rounded-md p-1.5 text-slate-400 hover:bg-slate-800 hover:text-white" title="Log out" aria-label="Log out">
                            <x-icon name="logout" />
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <div class="lg:pl-64">
            {{-- Top bar --}}
            <header class="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6">
                <button type="button" data-sidebar-open class="text-slate-500 hover:text-slate-900 lg:hidden" aria-label="Open menu">
                    <x-icon name="menu" class="size-6" />
                </button>
                <h1 class="truncate text-lg font-semibold">{{ $title }}</h1>
                <div class="ml-auto flex items-center gap-2">
                    @unless (request()->routeIs('posts.create'))
                        <a href="{{ route('posts.create') }}" class="hidden items-center gap-2 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 sm:inline-flex">
                            <x-icon name="create" class="size-4" /> Create post
                        </a>
                    @endunless
                </div>
            </header>

            <main @class(['mx-auto px-4 py-6 sm:px-6 lg:py-8', 'max-w-7xl' => $wide, 'max-w-6xl' => ! $wide])>
                <x-scheduler-warning />

                @if (session('success'))
                    <div class="mb-6 flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                        <x-icon name="check" class="size-5 shrink-0" /> {{ session('success') }}
                    </div>
                @endif
                @if (session('error'))
                    <div class="mb-6 flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                        <x-icon name="x-circle" class="size-5 shrink-0" /> {{ session('error') }}
                    </div>
                @endif

                {{ $slot }}
            </main>
        </div>
    @else
        <main class="flex min-h-full items-center justify-center px-4 py-12">
            {{ $slot }}
        </main>
    @endauth
</body>
</html>
