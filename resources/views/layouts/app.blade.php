<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'BotBTC') }}</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" type="image/x-icon" href="/favicon.ico">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/4.2.0/crypto-js.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/axios@1.13.2/dist/axios.min.js"></script>
    <script>
        window.axios = axios;
        axios.defaults.headers.common['X-CSRF-TOKEN'] = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    </script>

    <style>
        :root {
            --bg:        #0d1117;
            --surface:   #161b27;
            --surface2:  #1e2537;
            --border:    #2a3148;
            --gold:      #f0b90b;
            --gold-dim:  rgba(240,185,11,.12);
            --green:     #00d68f;
            --green-dim: rgba(0,214,143,.12);
            --red:       #ff4757;
            --red-dim:   rgba(255,71,87,.12);
            --text:      #e2e8f4;
            --muted:     #8892a8;
        }

        * { box-sizing: border-box; }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            min-height: 100vh;
        }

        /* ── Navbar ── */
        .navbar {
            background: var(--surface) !important;
            border-bottom: 1px solid var(--border);
            padding: .75rem 0;
        }
        .navbar-brand {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--gold) !important;
            letter-spacing: .5px;
        }
        .navbar-brand i { margin-right: 6px; }
        .nav-link, .dropdown-toggle {
            color: var(--muted) !important;
            font-size: .85rem;
            font-weight: 500;
        }
        .nav-link:hover, .dropdown-toggle:hover { color: var(--text) !important; }
        .dropdown-menu {
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 6px;
        }
        .dropdown-item {
            color: var(--muted);
            border-radius: 6px;
            padding: 8px 14px;
            font-size: .85rem;
        }
        .dropdown-item:hover { background: var(--border); color: var(--text); }
        .navbar-toggler { border-color: var(--border); }
        .navbar-toggler-icon { filter: invert(.5); }

        /* ── Cards ── */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            color: var(--text);
        }
        .card-header {
            background: transparent;
            border-bottom: 1px solid var(--border);
            font-weight: 600;
            padding: 1rem 1.25rem;
        }

        /* ── Forms ── */
        .form-control, .form-select {
            background: var(--surface2);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 8px;
        }
        .form-control:focus, .form-select:focus {
            background: var(--surface2);
            border-color: var(--gold);
            color: var(--text);
            box-shadow: 0 0 0 3px var(--gold-dim);
        }
        .form-control::placeholder { color: var(--muted); }
        .form-label { color: var(--muted); font-size: .82rem; font-weight: 500; margin-bottom: 5px; }
        .form-check-input { background-color: var(--surface2); border-color: var(--border); }
        .form-check-input:checked { background-color: var(--gold); border-color: var(--gold); }
        .form-check-label { color: var(--muted); font-size: .85rem; }

        /* ── Buttons ── */
        .btn-gold {
            background: var(--gold);
            color: #000;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            padding: 10px 22px;
        }
        .btn-gold:hover { background: #d9a60a; color: #000; }
        .btn-outline-muted {
            border: 1px solid var(--border);
            color: var(--muted);
            border-radius: 8px;
            background: transparent;
        }
        .btn-outline-muted:hover { background: var(--border); color: var(--text); }
        .btn-success { background: var(--green); border: none; font-weight: 600; border-radius: 8px; color: #000; }
        .btn-success:hover { background: #00b87a; color: #000; }
        .btn-danger  { background: var(--red); border: none; font-weight: 600; border-radius: 8px; }
        .btn-primary { background: #3b82f6; border: none; font-weight: 600; border-radius: 8px; }
        .btn-link    { color: var(--muted); font-size: .82rem; }
        .btn-link:hover { color: var(--text); }

        /* ── Tables ── */
        .table {
            color: var(--text);
            border-color: var(--border);
            margin-bottom: 0;
        }
        .table thead th {
            color: var(--muted);
            font-size: .78rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .5px;
            border-color: var(--border);
            background: var(--surface2);
            padding: 10px 14px;
        }
        .table tbody td {
            border-color: var(--border);
            padding: 12px 14px;
            vertical-align: middle;
            font-size: .88rem;
        }
        .table tbody tr:hover { background: var(--surface2); }

        /* ── Badges/Tags ── */
        .badge-green { background: var(--green-dim); color: var(--green); padding: 3px 9px; border-radius: 20px; font-size: .78rem; font-weight: 600; }
        .badge-red   { background: var(--red-dim);   color: var(--red);   padding: 3px 9px; border-radius: 20px; font-size: .78rem; font-weight: 600; }
        .badge-gold  { background: var(--gold-dim);  color: var(--gold);  padding: 3px 9px; border-radius: 20px; font-size: .78rem; font-weight: 600; }

        /* ── Stat tiles ── */
        .stat-tile {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.1rem 1.25rem;
        }
        .stat-tile .label {
            color: var(--muted);
            font-size: .75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 6px;
        }
        .stat-tile .value {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text);
        }
        .stat-tile .sub {
            color: var(--muted);
            font-size: .78rem;
            margin-top: 3px;
        }
        .stat-tile .icon {
            font-size: 1.4rem;
            opacity: .25;
        }

        /* ── Util ── */
        .text-green  { color: var(--green) !important; }
        .text-red    { color: var(--red)   !important; }
        .text-gold   { color: var(--gold)  !important; }
        .text-muted  { color: var(--muted) !important; }
        .divider     { border-color: var(--border); }
        .section-title { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); margin-bottom: 1rem; }
        hr { border-color: var(--border); opacity: 1; }

        /* ── Invalid feedback ── */
        .invalid-feedback { font-size: .8rem; }
        .is-invalid { border-color: var(--red) !important; }
    </style>
</head>
<body>
<div id="app">
    <nav class="navbar navbar-expand-md">
        <div class="container">
            <a class="navbar-brand" href="{{ url('/') }}">
                <i class="fa-brands fa-bitcoin"></i> BotBTC
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navContent">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navContent">
                <ul class="navbar-nav ms-auto align-items-md-center gap-md-1">
                    @guest
                        @if (Route::has('login'))
                            <li class="nav-item">
                                <a class="nav-link" href="{{ route('login') }}">Entrar</a>
                            </li>
                        @endif
                    @else
                        <li class="nav-item dropdown">
                            <a id="navbarDropdown" class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                                <i class="fa-regular fa-circle-user me-1"></i>{{ Auth::user()->name }}
                            </a>
                            <div class="dropdown-menu dropdown-menu-end">
                                <a class="dropdown-item" href="{{ route('logout') }}"
                                   onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                                    <i class="fa-solid fa-right-from-bracket me-2"></i>Sair
                                </a>
                                <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">@csrf</form>
                            </div>
                        </li>
                    @endguest
                </ul>
            </div>
        </div>
    </nav>

    <main class="py-4">
        @yield('content')
    </main>
</div>

</body>
</html>
