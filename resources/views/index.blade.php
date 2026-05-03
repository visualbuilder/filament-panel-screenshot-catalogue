<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>Screenshot catalogue — {{ $panel }} ({{ $version }})</title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: #0d0d10;
            --surface: #17181c;
            --border: #2a2b32;
            --text: #e7e7ea;
            --muted: #8f8f99;
            --accent: #d264ed;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            line-height: 1.5;
        }
        .layout {
            display: grid;
            grid-template-columns: 16rem 1fr;
            min-height: 100vh;
        }
        @media (max-width: 48rem) {
            .layout { grid-template-columns: 1fr; }
            aside { position: static !important; height: auto !important; }
        }
        aside {
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            background: var(--surface);
            border-right: 1px solid var(--border);
            padding: 1.5rem 1rem;
        }
        aside h3 {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            margin: 0 0 0.75rem 0.5rem;
        }
        aside nav { display: flex; flex-direction: column; gap: 0.125rem; }
        aside nav a {
            display: block;
            padding: 0.5rem 0.625rem;
            border-radius: 0.375rem;
            color: var(--text);
            text-decoration: none;
            font-size: 0.8125rem;
            line-height: 1.3;
            word-break: break-word;
        }
        aside nav a:hover { background: rgb(255 255 255 / 0.06); }
        aside nav a.active {
            background: rgb(255 255 255 / 0.10);
            color: var(--accent);
            box-shadow: inset 2px 0 0 var(--accent);
        }
        aside nav a code {
            font-family: ui-monospace, "SF Mono", Menlo, monospace;
            font-size: 0.75rem;
            color: var(--muted);
        }
        .body-pane { min-width: 0; }
        header {
            padding: 2.5rem 2rem 1.5rem;
            border-bottom: 1px solid var(--border);
        }
        header h1 {
            margin: 0 0 0.5rem;
            font-size: 1.75rem;
            letter-spacing: -0.01em;
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }
        header h1 img.brand {
            height: 2rem;
            width: auto;
            display: block;
        }
        header h1 .title-divider {
            width: 1px;
            height: 1.5rem;
            background: var(--border);
        }
        header h1 .title-text {
            font-weight: 500;
            color: var(--text);
        }
        header .meta {
            color: var(--muted);
            font-size: 0.875rem;
        }
        header .meta code {
            background: var(--surface);
            padding: 0.125rem 0.5rem;
            border-radius: 0.25rem;
            color: var(--accent);
        }
        main { padding: 2rem; max-width: 1600px; margin: 0 auto; }
        section.page {
            margin-bottom: 3rem;
        }
        section.page h2 {
            margin: 0 0 1.25rem;
            font-size: 1.875rem;
            font-weight: 700;
            letter-spacing: -0.01em;
            color: var(--text);
        }
        section.page h2::before {
            content: '';
            display: inline-block;
            width: 0.5rem;
            height: 1.5rem;
            background: var(--accent);
            border-radius: 0.25rem;
            margin-right: 0.75rem;
            vertical-align: -0.25rem;
        }
        /* One viewport per row, light + dark side by side. Mobile + tablet
           rows are narrower so each card hugs its device width and the
           pair sits centred on the page — gives a stronger sense of
           "this is what it looks like on that device". */
        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
            margin-inline: auto;
        }
        .grid-tablet { max-width: 1200px; }
        .grid-mobile { max-width: 560px; }
        @media (max-width: 64rem) {
            .grid { grid-template-columns: minmax(0, 1fr); }
        }

        /* Device chrome — wraps the screenshot in a frame that suggests
           a real device chassis. Pure CSS; no real bezel asset. */
        .variant.device {
            background: #14151a;
            border: 1px solid #2a2b32;
        }
        .variant.device .label {
            background: transparent;
            border-bottom: 0;
            padding-bottom: 0.25rem;
            color: var(--muted);
        }
        .variant.device a {
            overflow: hidden;
            background: #000;
        }
        /* Tablet — substantial bezel for an iPad-like silhouette. */
        .variant.device-tablet {
            padding: 1.25rem 1rem;
            border-radius: 1.5rem;
            box-shadow: 0 0 0 1px #3a3b42 inset;
        }
        .variant.device-tablet a {
            border-radius: 0.5rem;
        }
        /* Mobile — narrower body, more pronounced rounded corners. */
        .variant.device-mobile {
            padding: 1rem 0.625rem;
            border-radius: 2rem;
            box-shadow: 0 0 0 1px #3a3b42 inset;
        }
        .variant.device-mobile a {
            border-radius: 1.25rem;
        }
        .variant {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .variant .label {
            padding: 0.5rem 0.75rem;
            font-size: 0.75rem;
            color: var(--muted);
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .variant .label span.mode {
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-size: 0.625rem;
        }
        .variant a {
            display: block;
            background: #000;
            min-height: 4rem;
        }
        .variant img {
            display: block;
            width: 100%;
            height: auto;
        }
        .variant.missing {
            opacity: 0.4;
            justify-content: center;
            text-align: center;
            padding: 2rem;
            color: var(--muted);
            font-size: 0.75rem;
        }
        footer {
            padding: 1.5rem 2rem;
            color: var(--muted);
            font-size: 0.75rem;
            text-align: center;
            border-top: 1px solid var(--border);
        }
        footer a { color: var(--accent); }

        /* Lightbox: native <dialog> + ::backdrop, plus a tiny JS handler. */
        dialog#lightbox {
            border: 0;
            padding: 0;
            background: transparent;
            color: #fff;
            max-width: 100vw;
            max-height: 100vh;
            width: 100vw;
            height: 100vh;
            margin: 0;
        }
        dialog#lightbox::backdrop {
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(4px);
        }
        dialog#lightbox .lightbox-stage {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            padding: 2rem;
            box-sizing: border-box;
            cursor: zoom-out;
        }
        dialog#lightbox img {
            max-width: 100%;
            max-height: calc(100% - 3rem);
            object-fit: contain;
            box-shadow: 0 30px 80px -20px rgba(0, 0, 0, 0.6);
            border-radius: 6px;
            cursor: default;
        }
        dialog#lightbox .lightbox-caption {
            margin-top: 1rem;
            font-size: 0.875rem;
            color: rgba(255, 255, 255, 0.75);
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            letter-spacing: 0.02em;
            text-align: center;
            user-select: text;
        }
        dialog#lightbox .lightbox-close {
            position: fixed;
            top: 1rem;
            right: 1.25rem;
            font-size: 1.75rem;
            line-height: 1;
            background: transparent;
            color: #fff;
            border: 0;
            cursor: pointer;
            padding: 0.25rem 0.5rem;
            opacity: 0.7;
        }
        dialog#lightbox .lightbox-close:hover { opacity: 1; }
        .variant-link { cursor: zoom-in; }
    </style>
</head>
<body>
<div class="layout">
    <aside>
        <h3>Pages ({{ $shotsBySlug->count() }})</h3>
        <nav>
            @foreach ($shotsBySlug->keys() as $slug)
                <a href="#{{ \Illuminate\Support\Str::slug($slug) }}">{{ $labelsBySlug[$slug] ?? $slug }}</a>
            @endforeach
        </nav>
    </aside>

    <div class="body-pane">
    <header>
        <h1>
            @php($brandLabel = $brandName ?? 'Panel Screenshots')
            <img src="logo.svg" alt="{{ $brandLabel }}" class="brand" onerror="this.style.display='none'">
            <span class="title-divider"></span>
            <span class="title-text">{{ \Illuminate\Support\Str::headline($panel) }} Panel Screenshots</span>
        </h1>
        <div class="meta">
            <code>env: {{ $env }}</code>
            <code>version: {{ $version }}</code>
            <code>captured: {{ $capturedAt->format('Y-m-d H:i') }} UTC</code>
            ·
            {{ $shotsBySlug->count() }} pages × {{ count($viewports) }} viewports × {{ count($modes) }} modes
        </div>
    </header>

    <main>
        @php
            $viewportsByName = collect($viewports)->keyBy('name');
            // One row per viewport, in fixed device-size order.
            $orderedViewports = collect(['desktop', 'tablet', 'mobile'])
                ->filter(fn ($name) => $viewportsByName->has($name))
                ->values()
                ->all();
        @endphp

        @foreach ($shotsBySlug as $slug => $shots)
            @php $shotsByVariant = $shots->keyBy(fn ($s) => $s['viewport'] . '-' . $s['mode']); @endphp
            <section class="page" id="{{ \Illuminate\Support\Str::slug($slug) }}">
                <h2>{{ $labelsBySlug[$slug] ?? $slug }}</h2>

                @foreach ($orderedViewports as $viewportName)
                    <div class="grid grid-{{ $viewportName }}">
                        @foreach ($modes as $mode)
                            @include('filament-screenshot-catalogue::partials.variant', [
                                'shot' => $shotsByVariant[$viewportName . '-' . $mode] ?? null,
                                'viewport' => $viewportsByName[$viewportName],
                                'mode' => $mode,
                                'slug' => $slug,
                            ])
                        @endforeach
                    </div>
                @endforeach
            </section>
        @endforeach
    </main>

    <footer>
        Generated by <code>screenshot:capture</code> · NB-2505
    </footer>
    </div>
</div>

<dialog id="lightbox" aria-label="Screenshot preview">
    <button type="button" class="lightbox-close" aria-label="Close">&times;</button>
    <div class="lightbox-stage">
        <img alt="">
        <div class="lightbox-caption"></div>
    </div>
</dialog>

<script>
    (function () {
        const dialog = document.getElementById('lightbox');
        if (!dialog || typeof dialog.showModal !== 'function') return;

        const img = dialog.querySelector('img');
        const caption = dialog.querySelector('.lightbox-caption');
        const stage = dialog.querySelector('.lightbox-stage');
        const closeBtn = dialog.querySelector('.lightbox-close');

        document.querySelectorAll('a.variant-link').forEach((link) => {
            link.addEventListener('click', (event) => {
                // Honour middle-click / cmd-click / new-tab modifiers.
                if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) return;
                event.preventDefault();
                img.src = link.dataset.full;
                caption.textContent = link.dataset.caption || '';
                dialog.showModal();
            });
        });

        const close = () => dialog.close();
        closeBtn.addEventListener('click', close);
        // Click on the backdrop / empty stage area (not the image) closes.
        stage.addEventListener('click', (event) => {
            if (event.target !== img) close();
        });
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) close();
        });
        dialog.addEventListener('close', () => { img.src = ''; });
    })();

    // Scrollspy: highlight the sidebar link for whichever section is closest
    // to the top of the viewport. Uses IntersectionObserver with a top-band
    // root margin so the active link tracks the heading rather than waiting
    // for the section to be fully on screen.
    (function () {
        const sections = Array.from(document.querySelectorAll('section.page[id]'));
        const linkBySlug = new Map();
        document.querySelectorAll('aside nav a[href^="#"]').forEach((link) => {
            linkBySlug.set(link.getAttribute('href').slice(1), link);
        });
        if (!sections.length || !linkBySlug.size) return;

        const visible = new Set();
        const setActive = () => {
            // Of the sections currently intersecting the top band, pick
            // the one whose top edge sits closest to (but above) the trigger
            // line — that's the section the reader is "on".
            let best = null;
            let bestTop = -Infinity;
            visible.forEach((section) => {
                const top = section.getBoundingClientRect().top;
                if (top <= 120 && top > bestTop) {
                    best = section;
                    bestTop = top;
                }
            });
            // Fallback: if nothing is past the trigger line yet (top of page),
            // use the first visible section.
            if (!best && visible.size) {
                best = Array.from(visible).sort(
                    (a, b) => a.getBoundingClientRect().top - b.getBoundingClientRect().top,
                )[0];
            }

            linkBySlug.forEach((link) => link.classList.remove('active'));
            if (best) {
                const link = linkBySlug.get(best.id);
                if (link) link.classList.add('active');
            }
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) visible.add(entry.target);
                else visible.delete(entry.target);
            });
            setActive();
        }, {
            // Trigger band: 120px from the top, generous bottom margin so a
            // section stays "visible" until well past the fold.
            rootMargin: '-120px 0px -50% 0px',
            threshold: 0,
        });

        sections.forEach((s) => observer.observe(s));
    })();
</script>
</body>
</html>
