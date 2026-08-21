<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    @php($currentStep = $step ?? 1)
    <title>@yield('title', 'Install') · {{ \TheNguyen\CMS\Support\CmsInfo::BRAND }}</title>
    <style>
        :root {
            --bg: #0b0d12;
            --bg-soft: #12151c;
            --surface: #171b24;
            --surface-2: #1f2430;
            --border: #2a3140;
            --text: #e8ebf2;
            --muted: #9aa3b4;
            --accent: #f59e0b;
            --accent-soft: rgba(245, 158, 11, 0.14);
            --ok: #34d399;
            --bad: #f87171;
            --radius: 16px;
            --shadow: 0 24px 60px -20px rgba(0, 0, 0, 0.7);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Inter, sans-serif;
            color: var(--text);
            background:
                radial-gradient(1100px 600px at 85% -10%, rgba(245, 158, 11, 0.10), transparent 60%),
                radial-gradient(900px 500px at -10% 110%, rgba(99, 102, 241, 0.10), transparent 55%),
                var(--bg);
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 40px 20px 64px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 28px;
        }
        .brand__mark {
            width: 44px; height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--accent), #b45309);
            display: grid; place-items: center;
            font-weight: 800; font-size: 20px; color: #1b1206;
            box-shadow: 0 8px 24px -8px rgba(245, 158, 11, 0.6);
        }
        .brand__name { font-size: 20px; font-weight: 700; letter-spacing: -0.01em; }
        .brand__ver { font-size: 12px; color: var(--muted); }

        .card {
            width: 100%;
            max-width: 640px;
            background: linear-gradient(180deg, var(--surface), var(--bg-soft));
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .steps {
            display: flex;
            gap: 4px;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            background: rgba(255, 255, 255, 0.015);
        }
        .steps__item {
            flex: 1;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            display: flex; flex-direction: column; gap: 6px;
        }
        .steps__bar { height: 4px; border-radius: 999px; background: var(--surface-2); }
        .steps__item--done .steps__bar { background: var(--accent); }
        .steps__item--done { color: var(--text); }
        .steps__item--active .steps__bar { background: var(--accent); }
        .steps__item--active { color: var(--accent); }

        .card__body { padding: 32px 32px 36px; }
        h1 { font-size: 24px; margin: 0 0 8px; letter-spacing: -0.02em; }
        .lead { color: var(--muted); margin: 0 0 24px; line-height: 1.55; }

        label { display: block; font-size: 13px; font-weight: 600; margin: 0 0 6px; }
        .field { margin-bottom: 18px; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        input, select {
            width: 100%;
            padding: 11px 13px;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-size: 14px;
            transition: border-color .15s, box-shadow .15s;
        }
        input:focus, select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-soft);
        }
        .hint { font-size: 12px; color: var(--muted); margin-top: 5px; }
        .err { font-size: 12px; color: var(--bad); margin-top: 5px; }

        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 22px;
            border-radius: 10px;
            border: 1px solid transparent;
            font-size: 14px; font-weight: 600;
            cursor: pointer; text-decoration: none;
            transition: transform .08s, filter .15s, background .15s;
        }
        .btn:active { transform: translateY(1px); }
        .btn--primary { background: var(--accent); color: #1b1206; }
        .btn--primary:hover { filter: brightness(1.06); }
        .btn--primary[disabled] { opacity: .45; cursor: not-allowed; }
        .btn--ghost { background: transparent; color: var(--muted); border-color: var(--border); }
        .btn--ghost:hover { color: var(--text); border-color: var(--muted); }
        .actions { display: flex; justify-content: space-between; align-items: center; margin-top: 28px; gap: 12px; }

        .alert {
            padding: 12px 14px; border-radius: 10px; font-size: 13px; line-height: 1.5;
            margin-bottom: 22px; border: 1px solid;
        }
        .alert--error { background: rgba(248, 113, 113, 0.10); border-color: rgba(248, 113, 113, 0.4); color: #fecaca; }
        .alert--ok { background: rgba(52, 211, 153, 0.10); border-color: rgba(52, 211, 153, 0.4); color: #bbf7d0; }

        .checks { list-style: none; margin: 0 0 8px; padding: 0; }
        .checks li {
            display: flex; align-items: center; justify-content: space-between;
            padding: 11px 14px; border: 1px solid var(--border); border-radius: 10px;
            margin-bottom: 8px; background: var(--surface-2); font-size: 14px;
        }
        .checks .val { font-size: 12px; color: var(--muted); }
        .badge { font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 999px; }
        .badge--ok { background: rgba(52, 211, 153, 0.16); color: var(--ok); }
        .badge--bad { background: rgba(248, 113, 113, 0.16); color: var(--bad); }

        .url-box {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 14px 16px; border: 1px solid var(--border); border-radius: 10px;
            background: var(--surface-2); margin-bottom: 12px;
        }
        .url-box .label { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; }
        .url-box a { color: var(--accent); text-decoration: none; font-weight: 600; word-break: break-all; }

        @media (max-width: 560px) {
            .row { grid-template-columns: 1fr; }
            .card__body { padding: 24px 20px 28px; }
            .steps__item { font-size: 0; gap: 0; }
        }
    </style>
</head>
<body>
    <div class="brand">
        <div class="brand__mark">TN</div>
        <div>
            <div class="brand__name">{{ \TheNguyen\CMS\Support\CmsInfo::BRAND }}</div>
            <div class="brand__ver">Installer · v{{ \TheNguyen\CMS\Support\CmsInfo::version() }}</div>
        </div>
    </div>

    <div class="card">
        @php($labels = ['Welcome', 'Requirements', 'Configure', 'Admin', 'Review', 'Finish'])
        <div class="steps">
            @foreach ($labels as $i => $name)
                @php($n = $i + 1)
                <div class="steps__item {{ $n < $currentStep ? 'steps__item--done' : ($n === $currentStep ? 'steps__item--active' : '') }}">
                    <span>{{ $n }}. {{ $name }}</span>
                    <span class="steps__bar"></span>
                </div>
            @endforeach
        </div>
        <div class="card__body">
            @yield('content')
        </div>
    </div>
</body>
</html>
