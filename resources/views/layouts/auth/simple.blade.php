<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <script>
        if (window.location.hostname === '127.0.0.1') {
            window.location.hostname = 'localhost';
        }
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0" />
    <title>{{ filled($title ?? null) ? $title.' – '.config('app.name', 'PhonePOS') : config('app.name', 'PhonePOS') }}</title>

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            margin: 0;
            min-height: 100svh;
            background: #09090b;
            overflow-x: hidden;
        }

        /* ── Animated grid background ── */
        .auth-bg {
            position: fixed;
            inset: 0;
            background: #09090b;
            z-index: 0;
        }
        .auth-bg::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(139,92,246,.06) 1px, transparent 1px),
                linear-gradient(90deg, rgba(139,92,246,.06) 1px, transparent 1px);
            background-size: 40px 40px;
            animation: gridMove 20s linear infinite;
        }
        @keyframes gridMove {
            0%   { background-position: 0 0; }
            100% { background-position: 40px 40px; }
        }

        /* ── Glowing orbs ── */
        .orb {
            position: fixed;
            border-radius: 50%;
            filter: blur(80px);
            opacity: .35;
            pointer-events: none;
        }
        .orb-1 {
            width: 500px; height: 500px;
            background: radial-gradient(circle, #7c3aed, transparent 70%);
            top: -150px; left: -100px;
            animation: orbFloat1 12s ease-in-out infinite;
        }
        .orb-2 {
            width: 400px; height: 400px;
            background: radial-gradient(circle, #06b6d4, transparent 70%);
            bottom: -100px; right: -80px;
            animation: orbFloat2 15s ease-in-out infinite;
        }
        .orb-3 {
            width: 300px; height: 300px;
            background: radial-gradient(circle, #ec4899, transparent 70%);
            top: 50%; left: 50%;
            transform: translate(-50%,-50%);
            animation: orbFloat3 18s ease-in-out infinite;
        }
        @keyframes orbFloat1 {
            0%,100% { transform: translate(0,0); }
            50%      { transform: translate(60px, 40px); }
        }
        @keyframes orbFloat2 {
            0%,100% { transform: translate(0,0); }
            50%      { transform: translate(-50px, -30px); }
        }
        @keyframes orbFloat3 {
            0%,100% { transform: translate(-50%,-50%) scale(1); }
            50%      { transform: translate(-50%,-50%) scale(1.3); }
        }

        /* ── Main wrapper ── */
        .auth-wrapper {
            position: relative;
            z-index: 10;
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: 100svh;
        }
        @media (max-width: 767px) {
            .auth-wrapper {
                grid-template-columns: 1fr;
            }
            .auth-showcase {
                display: none !important;
            }
        }

        /* ── Left showcase panel ── */
        .auth-showcase {
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 48px 40px;
            background: linear-gradient(135deg, rgba(124,58,237,.15) 0%, rgba(6,182,212,.08) 100%);
            border-right: 1px solid rgba(255,255,255,.06);
        }

        /* Floating phones showcase */
        .phones-container {
            position: relative;
            width: 280px;
            height: 380px;
            margin-bottom: 40px;
        }
        .phone-card {
            position: absolute;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 25px 60px rgba(0,0,0,.5);
            transition: transform .3s ease;
        }
        .phone-card-main {
            width: 160px; height: 280px;
            left: 50%; top: 50%;
            transform: translate(-50%, -50%);
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            border: 2px solid rgba(139,92,246,.4);
            animation: floatMain 6s ease-in-out infinite;
            z-index: 3;
        }
        .phone-card-left {
            width: 130px; height: 230px;
            left: 0; top: 55%;
            transform: translateY(-50%) rotate(-12deg);
            background: linear-gradient(135deg, #0f3460 0%, #16213e 100%);
            border: 1.5px solid rgba(6,182,212,.3);
            animation: floatLeft 7s ease-in-out infinite;
            z-index: 2;
        }
        .phone-card-right {
            width: 130px; height: 230px;
            right: 0; top: 45%;
            transform: translateY(-50%) rotate(10deg);
            background: linear-gradient(135deg, #2d1b69 0%, #1a1a2e 100%);
            border: 1.5px solid rgba(236,72,153,.3);
            animation: floatRight 8s ease-in-out infinite;
            z-index: 2;
        }
        @keyframes floatMain  { 0%,100%{transform:translate(-50%,-50%) translateY(0)}  50%{transform:translate(-50%,-50%) translateY(-12px)} }
        @keyframes floatLeft  { 0%,100%{transform:translateY(-50%) rotate(-12deg) translateY(0)}  50%{transform:translateY(-50%) rotate(-12deg) translateY(10px)} }
        @keyframes floatRight { 0%,100%{transform:translateY(-50%) rotate(10deg) translateY(0)}  50%{transform:translateY(-50%) rotate(10deg) translateY(-8px)} }

        /* Phone screen content */
        .phone-screen {
            width: 100%; height: 100%;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            gap: 8px; padding: 16px;
        }
        .phone-dot { width: 40px; height: 6px; background: rgba(255,255,255,.15); border-radius: 3px; margin-bottom: 8px; }
        .phone-icon { font-size: 32px; }
        .phone-bar { height: 6px; border-radius: 3px; width: 100%; margin-top: 4px; }

        /* Feature badges */
        .feature-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: center;
            max-width: 320px;
        }
        .badge {
            display: flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.1);
            border-radius: 50px;
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 500;
            color: rgba(255,255,255,.8);
            backdrop-filter: blur(10px);
            animation: badgePop .4s ease both;
        }
        .badge-dot { width: 6px; height: 6px; border-radius: 50%; }

        /* Showcase text */
        .showcase-title {
            font-size: 28px;
            font-weight: 800;
            color: #fff;
            text-align: center;
            line-height: 1.2;
            margin-bottom: 12px;
        }
        .showcase-title span {
            background: linear-gradient(90deg, #a78bfa, #38bdf8, #f472b6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .showcase-sub {
            font-size: 14px;
            color: rgba(255,255,255,.5);
            text-align: center;
            max-width: 260px;
            line-height: 1.6;
            margin-bottom: 24px;
        }

        /* Stats row */
        .stats-row {
            display: flex;
            gap: 24px;
            margin-bottom: 32px;
        }
        .stat-item { text-align: center; }
        .stat-num { font-size: 22px; font-weight: 800; color: #fff; }
        .stat-label { font-size: 11px; color: rgba(255,255,255,.4); font-weight: 500; letter-spacing: .5px; text-transform: uppercase; margin-top: 2px; }

        /* ── Right login panel ── */
        .auth-form-panel {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 32px 24px;
            min-height: 100svh;
            background: rgba(9,9,11,.6);
            backdrop-filter: blur(20px);
        }

        .auth-form-inner {
            width: 100%;
            max-width: 400px;
        }

        /* App logo mark */
        .auth-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 32px;
        }
        .auth-logo-icon {
            width: 42px; height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, #7c3aed, #3b82f6);
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 8px 20px rgba(124,58,237,.4);
            animation: logoBounce 3s ease-in-out infinite;
            flex-shrink: 0;
        }
        @keyframes logoBounce {
            0%,100% { transform: translateY(0); }
            50%      { transform: translateY(-3px); }
        }
        .auth-logo-text { font-size: 18px; font-weight: 800; color: #fff; letter-spacing: -.3px; }
        .auth-logo-sub  { font-size: 11px; color: rgba(255,255,255,.35); font-weight: 500; letter-spacing: .5px; text-transform: uppercase; }

        /* Heading */
        .auth-heading {
            font-size: 26px;
            font-weight: 800;
            color: #fff;
            line-height: 1.2;
            margin-bottom: 6px;
        }
        .auth-sub {
            font-size: 14px;
            color: rgba(255,255,255,.45);
            margin-bottom: 28px;
            line-height: 1.5;
        }

        /* Input group */
        .form-group { margin-bottom: 18px; }
        .form-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: rgba(255,255,255,.6);
            margin-bottom: 8px;
            letter-spacing: .3px;
        }

        /* Submit button glow */
        .submit-btn-wrap {
            margin-top: 6px;
        }

        /* Remember / forgot row */
        .auth-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 8px;
        }

        /* Divider */
        .auth-divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 22px 0;
        }
        .auth-divider::before,
        .auth-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: rgba(255,255,255,.08);
        }
        .auth-divider-text {
            font-size: 12px;
            color: rgba(255,255,255,.3);
            font-weight: 500;
        }

        /* Bottom link */
        .auth-bottom {
            text-align: center;
            font-size: 13px;
            color: rgba(255,255,255,.4);
            margin-top: 22px;
        }

        /* Ticker strip at top of left panel */
        .ticker-strip {
            position: absolute;
            top: 20px;
            left: 0; right: 0;
            overflow: hidden;
            height: 32px;
            display: flex;
            align-items: center;
        }
        .ticker-inner {
            display: flex;
            gap: 32px;
            white-space: nowrap;
            animation: tickerScroll 20s linear infinite;
        }
        .ticker-item {
            font-size: 11px;
            font-weight: 600;
            color: rgba(255,255,255,.25);
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        @keyframes tickerScroll {
            0%   { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }

        /* Floating particles */
        .particle {
            position: fixed;
            width: 4px; height: 4px;
            border-radius: 50%;
            background: rgba(139,92,246,.6);
            pointer-events: none;
            animation: particleDrift linear infinite;
        }
        @keyframes particleDrift {
            0%   { transform: translateY(100vh) rotate(0deg); opacity: 0; }
            10%  { opacity: 1; }
            90%  { opacity: 1; }
            100% { transform: translateY(-100px) rotate(360deg); opacity: 0; }
        }

        /* Mobile brand bar (shown only on mobile) */
        .mobile-brand {
            display: none;
            align-items: center;
            gap: 10px;
            margin-bottom: 28px;
        }
        @media (max-width: 767px) {
            .mobile-brand { display: flex; }
            .auth-form-panel { background: transparent; padding: 28px 20px; }
        }

        /* Slide-in animation for the form */
        .slide-in {
            animation: slideInUp .6s cubic-bezier(.16,1,.3,1) both;
        }
        @keyframes slideInUp {
            0%   { opacity: 0; transform: translateY(30px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        .slide-in-delay-1 { animation-delay: .1s; }
        .slide-in-delay-2 { animation-delay: .2s; }
        .slide-in-delay-3 { animation-delay: .3s; }

        /* Shimmer for the submit button */
        .btn-shimmer {
            position: relative;
            overflow: hidden;
        }
        .btn-shimmer::after {
            content: '';
            position: absolute;
            top: 0; left: -100%;
            width: 60%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.2), transparent);
            animation: shimmerSlide 3s infinite;
        }
        @keyframes shimmerSlide {
            0%   { left: -100%; }
            50%  { left: 150%; }
            100% { left: 150%; }
        }

        /* Session status */
        .session-status {
            background: rgba(34,197,94,.1);
            border: 1px solid rgba(34,197,94,.3);
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 13px;
            color: rgb(134,239,172);
            margin-bottom: 16px;
            text-align: center;
        }

        /* Error messages */
        .error-msg {
            font-size: 11px;
            color: rgb(252,165,165);
            margin-top: 5px;
        }
    </style>
</head>
<body>
    {{-- ── Animated background ── --}}
    <div class="auth-bg"></div>
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>

    {{-- ── Floating particles (JS injected) ── --}}
    <div id="particles"></div>

    {{-- ── Main layout ── --}}
    <div class="auth-wrapper">

        {{-- ═══════════════════════════════
             LEFT — Showcase panel
        ════════════════════════════════ --}}
        <div class="auth-showcase">

            {{-- Ticker --}}
            <div class="ticker-strip">
                <div class="ticker-inner">
                    @foreach(['Phone Cases', 'Screen Protectors', 'Chargers', 'Earphones', 'Power Banks', 'PopSockets', 'Smart Watches', 'Cable Organizers'] as $item)
                        <span class="ticker-item">{{ $item }}</span>
                        <span class="ticker-item">·</span>
                    @endforeach
                    {{-- Duplicate for seamless loop --}}
                    @foreach(['Phone Cases', 'Screen Protectors', 'Chargers', 'Earphones', 'Power Banks', 'PopSockets', 'Smart Watches', 'Cable Organizers'] as $item)
                        <span class="ticker-item">{{ $item }}</span>
                        <span class="ticker-item">·</span>
                    @endforeach
                </div>
            </div>

            {{-- Floating phones --}}
            <div class="phones-container">
                {{-- Left phone --}}
                <div class="phone-card phone-card-left">
                    <div class="phone-screen">
                        <div class="phone-dot"></div>
                        <div class="phone-icon">🎧</div>
                        <div class="phone-bar" style="background:rgba(6,182,212,.4)"></div>
                        <div class="phone-bar" style="background:rgba(255,255,255,.1);width:70%"></div>
                        <div class="phone-bar" style="background:rgba(255,255,255,.08);width:50%"></div>
                    </div>
                </div>

                {{-- Main center phone --}}
                <div class="phone-card phone-card-main">
                    <div class="phone-screen">
                        <div class="phone-dot" style="background:rgba(139,92,246,.4)"></div>
                        <div class="phone-icon">📱</div>
                        <div style="font-size:10px;color:rgba(255,255,255,.6);font-weight:600;letter-spacing:.5px;text-transform:uppercase;margin-top:8px">Phone POS</div>
                        <div class="phone-bar" style="background:linear-gradient(90deg,#7c3aed,#3b82f6);margin-top:12px"></div>
                        <div class="phone-bar" style="background:rgba(255,255,255,.1);width:75%"></div>
                        <div class="phone-bar" style="background:rgba(255,255,255,.08);width:55%"></div>
                        <div style="display:flex;gap:8px;margin-top:12px">
                            <div style="width:28px;height:28px;border-radius:8px;background:rgba(139,92,246,.3);display:flex;align-items:center;justify-content:center;font-size:12px">🔋</div>
                            <div style="width:28px;height:28px;border-radius:8px;background:rgba(6,182,212,.3);display:flex;align-items:center;justify-content:center;font-size:12px">🔌</div>
                            <div style="width:28px;height:28px;border-radius:8px;background:rgba(236,72,153,.3);display:flex;align-items:center;justify-content:center;font-size:12px">🎁</div>
                        </div>
                    </div>
                </div>

                {{-- Right phone --}}
                <div class="phone-card phone-card-right">
                    <div class="phone-screen">
                        <div class="phone-dot"></div>
                        <div class="phone-icon">🔋</div>
                        <div class="phone-bar" style="background:rgba(236,72,153,.4)"></div>
                        <div class="phone-bar" style="background:rgba(255,255,255,.1);width:65%"></div>
                        <div class="phone-bar" style="background:rgba(255,255,255,.08);width:80%"></div>
                    </div>
                </div>
            </div>

            {{-- Text content --}}
            <div class="showcase-title">
                Your Phone Store<br><span>All in One Place</span>
            </div>
            <p class="showcase-sub">
                Manage sales, stock, invoices, and customers for your phone accessories business — right from your screen.
            </p>

            {{-- Stats --}}
            <div class="stats-row">
                <div class="stat-item">
                    <div class="stat-num">∞</div>
                    <div class="stat-label">Products</div>
                </div>
                <div class="stat-item" style="border-left:1px solid rgba(255,255,255,.08);border-right:1px solid rgba(255,255,255,.08);padding:0 24px">
                    <div class="stat-num">24/7</div>
                    <div class="stat-label">Access</div>
                </div>
                <div class="stat-item">
                    <div class="stat-num">Fast</div>
                    <div class="stat-label">Checkout</div>
                </div>
            </div>

            {{-- Feature badges --}}
            <div class="feature-badges">
                <div class="badge"><div class="badge-dot" style="background:#a78bfa"></div>POS Terminal</div>
                <div class="badge"><div class="badge-dot" style="background:#38bdf8"></div>Invoicing</div>
                <div class="badge"><div class="badge-dot" style="background:#34d399"></div>Stock Tracking</div>
                <div class="badge"><div class="badge-dot" style="background:#f472b6"></div>Customer Dues</div>
                <div class="badge"><div class="badge-dot" style="background:#fbbf24"></div>Reports</div>
                <div class="badge"><div class="badge-dot" style="background:#f87171"></div>Expenses</div>
            </div>
        </div>

        {{-- ═══════════════════════════════
             RIGHT — Login form
        ════════════════════════════════ --}}
        <div class="auth-form-panel">
            <div class="auth-form-inner">

                {{-- Mobile only brand --}}
                <div class="mobile-brand slide-in">
                    <div class="auth-logo-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12" y2="18"/></svg>
                    </div>
                    <div>
                        <div class="auth-logo-text">{{ config('app.name', 'PhonePOS') }}</div>
                        <div class="auth-logo-sub">Phone Accessories POS</div>
                    </div>
                </div>

                {{-- Desktop logo (hidden on mobile since mobile-brand shows) --}}
                <div class="auth-logo slide-in" style="display:flex" id="desktop-logo">
                    <div class="auth-logo-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12" y2="18"/></svg>
                    </div>
                    <div>
                        <div class="auth-logo-text">{{ config('app.name', 'PhonePOS') }}</div>
                        <div class="auth-logo-sub">Phone Accessories POS</div>
                    </div>
                </div>

                {{-- Heading --}}
                <div class="slide-in slide-in-delay-1">
                    <h1 class="auth-heading">Welcome back 👋</h1>
                    <p class="auth-sub">Sign in to manage your phone accessories store</p>
                </div>

                {{-- Session Status --}}
                @if (session('status'))
                    <div class="session-status slide-in slide-in-delay-1">
                        {{ session('status') }}
                    </div>
                @endif

                {{-- ── Login form ── --}}
                <form method="POST" action="{{ route('login.store') }}" class="slide-in slide-in-delay-2">
                    @csrf

                    {{-- Email or Username --}}
                    <div class="form-group">
                        <label class="form-label" for="email">
                            📧 Email or Username
                        </label>
                        <flux:input
                            id="email"
                            name="email"
                            type="text"
                            :value="old('email')"
                            required
                            autofocus
                            autocomplete="username"
                            placeholder="e.g. admin or you@example.com"
                        />
                        @error('email')
                            <p class="error-msg">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Password --}}
                    <div class="form-group">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                            <label class="form-label" for="password" style="margin-bottom:0">
                                🔐 Password
                            </label>
                            @if (Route::has('password.request'))
                                <a href="{{ route('password.request') }}" wire:navigate
                                   style="font-size:12px;color:rgba(139,92,246,.9);text-decoration:none;font-weight:500;transition:color .2s"
                                   onmouseover="this.style.color='#a78bfa'"
                                   onmouseout="this.style.color='rgba(139,92,246,.9)'">
                                    Forgot password?
                                </a>
                            @endif
                        </div>
                        <flux:input
                            id="password"
                            name="password"
                            type="password"
                            required
                            autocomplete="current-password"
                            placeholder="Enter your password"
                            viewable
                        />
                        @error('password')
                            <p class="error-msg">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Remember me --}}
                    <div class="auth-meta">
                        <flux:checkbox name="remember" label="{{ __('Remember me') }}" :checked="old('remember')" />
                    </div>

                    {{-- Submit --}}
                    <div class="submit-btn-wrap">
                        <button
                            type="submit"
                            class="btn-shimmer"
                            style="
                                width: 100%;
                                padding: 14px 24px;
                                border-radius: 12px;
                                border: none;
                                background: linear-gradient(135deg, #7c3aed 0%, #3b82f6 100%);
                                color: #fff;
                                font-size: 15px;
                                font-weight: 700;
                                cursor: pointer;
                                letter-spacing: .2px;
                                box-shadow: 0 8px 24px rgba(124,58,237,.35);
                                transition: transform .15s ease, box-shadow .15s ease;
                                position: relative;
                                font-family: inherit;
                            "
                            onmouseover="this.style.transform='translateY(-1px)';this.style.boxShadow='0 12px 30px rgba(124,58,237,.5)'"
                            onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='0 8px 24px rgba(124,58,237,.35)'"
                            onmousedown="this.style.transform='scale(.98)'"
                            onmouseup="this.style.transform='translateY(-1px)'"
                        >
                            Sign in to your store →
                        </button>
                    </div>

                    {{-- Passkey option --}}
                    <x-passkey-verify />

                </form>

                {{-- Divider --}}
                <div class="auth-divider slide-in slide-in-delay-3">
                    <span class="auth-divider-text">or</span>
                </div>

                {{-- Quick features row --}}
                <div class="slide-in slide-in-delay-3" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px">
                    @foreach([
                        ['📱', 'Phone Cases', '#7c3aed'],
                        ['🔌', 'Chargers', '#06b6d4'],
                        ['🎧', 'Earphones', '#ec4899'],
                        ['🔋', 'Power Banks', '#f59e0b'],
                    ] as [$icon, $label, $color])
                        <div style="
                            display:flex;align-items:center;gap:8px;
                            background:rgba(255,255,255,.04);
                            border:1px solid rgba(255,255,255,.07);
                            border-radius:10px;padding:10px 12px;
                            font-size:12px;font-weight:500;color:rgba(255,255,255,.6);
                        ">
                            <span style="font-size:16px">{{ $icon }}</span>
                            <span>{{ $label }}</span>
                        </div>
                    @endforeach
                </div>

                {{-- Sign up link --}}
                <div class="auth-bottom slide-in slide-in-delay-3">
                    <span>Don't have an account? </span>
                    <a href="{{ route('register') }}" wire:navigate
                       style="color:#a78bfa;text-decoration:none;font-weight:600;transition:color .2s"
                       onmouseover="this.style.color='#c4b5fd'"
                       onmouseout="this.style.color='#a78bfa'">
                        Create account →
                    </a>
                </div>

            </div>
        </div>
    </div>

    {{-- Persistent toast --}}
    @persist('toast')
        <flux:toast.group>
            <flux:toast />
        </flux:toast.group>
    @endpersist

    @fluxScripts

    <script>
        // Hide desktop logo on mobile (mobile-brand handles it)
        function syncLogos() {
            const desktopLogo = document.getElementById('desktop-logo');
            const mobileBrand = document.querySelector('.mobile-brand');
            if (window.innerWidth < 768) {
                if (desktopLogo) desktopLogo.style.display = 'none';
                if (mobileBrand) mobileBrand.style.display = 'flex';
            } else {
                if (desktopLogo) desktopLogo.style.display = 'flex';
                if (mobileBrand) mobileBrand.style.display = 'none';
            }
        }
        syncLogos();
        window.addEventListener('resize', syncLogos);

        // Floating particles
        (function spawnParticles() {
            const container = document.getElementById('particles');
            const colors = ['rgba(139,92,246,.7)', 'rgba(6,182,212,.7)', 'rgba(236,72,153,.6)', 'rgba(251,191,36,.6)'];
            for (let i = 0; i < 18; i++) {
                const p = document.createElement('div');
                p.className = 'particle';
                const size = Math.random() * 4 + 2;
                p.style.cssText = `
                    width:${size}px;height:${size}px;
                    left:${Math.random() * 100}%;
                    background:${colors[Math.floor(Math.random() * colors.length)]};
                    animation-duration:${8 + Math.random() * 12}s;
                    animation-delay:-${Math.random() * 15}s;
                    filter:blur(${Math.random() > .5 ? '1px' : '0'});
                `;
                container.appendChild(p);
            }
        })();
    </script>
</body>
</html>
