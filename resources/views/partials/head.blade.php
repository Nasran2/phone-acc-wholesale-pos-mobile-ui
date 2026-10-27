<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
<meta name="theme-color" content="#08235d" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="{{ asset('images/twinsofte-logo.png') }}" type="image/png">
<link rel="apple-touch-icon" href="{{ asset('images/twinsofte-logo.png') }}">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
