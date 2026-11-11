@php
    $locale = app()->getLocale();
    $routeName = request()->route()?->getName() ?? 'site.index';
    $routeParams = request()->route()?->parameters() ?? [];
    $localeNames = ['vi' => 'Tiếng Việt', 'en' => 'English', 'ru' => 'Русский'];
    $contactLinks = array_filter([
        'zalo' => isset($contacts['zalo']) ? 'https://zalo.me/'.preg_replace('/\D+/', '', $contacts['zalo']) : null,
        'telegram' => isset($contacts['telegram']) ? 'https://t.me/'.ltrim($contacts['telegram'], '@') : null,
        'whatsapp' => isset($contacts['whatsapp']) ? 'https://wa.me/'.preg_replace('/\D+/', '', $contacts['whatsapp']) : null,
        'phone' => isset($contacts['phone']) ? 'tel:'.preg_replace('/[^\d+]+/', '', $contacts['phone']) : null,
    ]);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>@yield('title', $agency.' · '.__('site.tagline'))</title>
    <meta name="description" content="@yield('description', __('site.hero_text'))">
    @foreach ($localeNames as $code => $name)
        <link rel="alternate" hreflang="{{ $code }}" href="{{ route($routeName, array_merge($routeParams, ['locale' => $code])) }}">
    @endforeach
    @vite('resources/css/app.css')
    <style>
        :root { --site-bg: #f8fafc; --site-text: #0f172a; --site-muted: #64748b; --site-card: #ffffff; --site-accent: #0f766e; --site-accent-text: #ffffff; }
        body { background: var(--site-bg); color: var(--site-text); }
        .site-card { background: var(--site-card); }
        .site-muted { color: var(--site-muted); }
        .site-accent { background: var(--site-accent); color: var(--site-accent-text); }
        .site-accent-text { color: var(--site-accent); }
        [data-tg-only] { display: none; }
        .in-telegram [data-tg-only] { display: initial; }
        .in-telegram [data-hide-in-telegram] { display: none; }
    </style>
</head>
<body class="min-h-screen antialiased">
    <header class="site-card border-b border-slate-200" data-hide-in-telegram>
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-3">
            <a href="{{ route('site.index') }}" class="text-lg font-semibold">{{ $agency }}</a>
            <nav class="flex gap-1 text-sm" aria-label="Language">
                @foreach ($localeNames as $code => $name)
                    <a href="{{ route($routeName, array_merge($routeParams, ['locale' => $code])) }}"
                       @class(['rounded px-2 py-1', 'site-accent' => $code === $locale, 'site-muted hover:bg-slate-100' => $code !== $locale])
                       hreflang="{{ $code }}">{{ $name }}</a>
                @endforeach
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-6">
        @yield('content')
    </main>

    @if ($contactLinks !== [])
        <div class="fixed bottom-4 right-4 z-20 flex flex-col items-end gap-2" style="margin-bottom: env(safe-area-inset-bottom, 0px)" data-hide-in-telegram>
            <span class="site-card rounded-full px-3 py-1 text-xs shadow">{{ __('site.chat_with_us') }}</span>
            @foreach ($contactLinks as $channel => $href)
                <a href="{{ $href }}" target="_blank" rel="noopener" class="site-accent rounded-full px-4 py-2 text-sm font-medium shadow">
                    {{ __('site.channels.'.$channel) }}
                </a>
            @endforeach
        </div>
    @endif

    <footer class="site-muted mx-auto max-w-6xl px-4 pb-24 pt-6 text-xs" data-hide-in-telegram>
        {{ __('site.footer', ['agency' => $agency]) }}
    </footer>

    <script>
        // Telegram Mini App: Telegram opens the page with #tgWebAppData=… in the URL.
        // Only then the Telegram script is loaded; on the normal website nothing changes.
        (function () {
            if (location.hash.indexOf('tgWebAppData') === -1 && !sessionStorage.getItem('in-telegram')) {
                return;
            }
            var s = document.createElement('script');
            s.src = 'https://telegram.org/js/telegram-web-app.js';
            s.onload = function () {
                var app = window.Telegram && window.Telegram.WebApp;
                if (!app || !app.initData) {
                    return;
                }
                try { sessionStorage.setItem('in-telegram', '1'); } catch (e) {}
                app.ready();
                app.expand();
                var t = app.themeParams || {};
                var root = document.documentElement.style;
                if (t.bg_color) root.setProperty('--site-bg', t.secondary_bg_color || t.bg_color);
                if (t.text_color) root.setProperty('--site-text', t.text_color);
                if (t.hint_color) root.setProperty('--site-muted', t.hint_color);
                if (t.bg_color) root.setProperty('--site-card', t.bg_color);
                if (t.button_color) root.setProperty('--site-accent', t.button_color);
                if (t.button_text_color) root.setProperty('--site-accent-text', t.button_text_color);
                document.documentElement.classList.add('in-telegram');
                document.querySelectorAll('input[name="tg_init_data"]').forEach(function (el) { el.value = app.initData; });
                var user = app.initDataUnsafe && app.initDataUnsafe.user;
                document.querySelectorAll('input[name="name"]').forEach(function (el) {
                    if (!el.value && user) el.value = [user.first_name, user.last_name].filter(Boolean).join(' ');
                });
                if (user && user.username) {
                    document.querySelectorAll('select[name="contact_channel"]').forEach(function (el) {
                        if (!el.dataset.touched) { el.value = 'telegram_self'; el.dispatchEvent(new Event('change')); }
                    });
                }
            };
            document.head.appendChild(s);
        })();
    </script>
</body>
</html>
