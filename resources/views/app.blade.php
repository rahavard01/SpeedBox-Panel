@php
  // Dev mode detection for Vite (npm run dev)
  $isViteDev = app()->environment('local') && is_file(public_path('hot'));

  // Production manifest
  $manifestPath = public_path('build/manifest.json');
  $manifest = (!$isViteDev && is_file($manifestPath))
      ? json_decode(file_get_contents($manifestPath), true)
      : null;

  $entry = $manifest['resources/js/app.js'] ?? null;
  $jsFile = $entry['file'] ?? null;
  $cssFiles = $entry['css'] ?? [];
@endphp

<!doctype html>
<html lang="fa" dir="ltr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <title>SPEEDBOX PANEL</title>

  <meta name="csrf-token" content="{{ csrf_token() }}">

  <script src="/speedbox/certificate-dns.js?v={{ filemtime(public_path('speedbox/certificate-dns.js')) }}"></script>
  <script src="/speedbox/node-editor.js?v={{ filemtime(public_path('speedbox/node-editor.js')) }}"></script>
  <script src="/speedbox/emergency-configs.js?v={{ filemtime(public_path('speedbox/emergency-configs.js')) }}"></script>
  <script src="/speedbox/tunnel-cdn.js?v={{ filemtime(public_path('speedbox/tunnel-cdn.js')) }}"></script>
  <script src="/speedbox/wattunnel.js?v={{ filemtime(public_path('speedbox/wattunnel.js')) }}"></script>
  <script src="/speedbox/network-tunnels.js?v={{ filemtime(public_path('speedbox/network-tunnels.js')) }}"></script>
  <script src="/speedbox/tunnels.js?v={{ filemtime(public_path('speedbox/tunnels.js')) }}"></script>
  <script src="/speedbox/infrastructure.js?v={{ filemtime(public_path('speedbox/infrastructure.js')) }}"></script>
  <script src="/speedbox/register-infrastructure.js?v={{ filemtime(public_path('speedbox/register-infrastructure.js')) }}"></script>
  <script src="/speedbox/panel-tooltip.js?v={{ filemtime(public_path('speedbox/panel-tooltip.js')) }}"></script>
  <script src="/speedbox/partner-statistics.js?v={{ filemtime(public_path('speedbox/partner-statistics.js')) }}"></script>

  {{-- Vite --}}
  @if($isViteDev)
    {{-- Dev/HMR --}}
    @vite('resources/js/app.js')
  @else
    {{-- Production (RELATIVE) --}}
    @if($cssFiles)
      @foreach($cssFiles as $css)
        <link rel="stylesheet" href="{{ '/build/' . ltrim($css, '/') }}">
      @endforeach
    @endif

    @if($jsFile)
      <script type="module" src="{{ '/build/' . ltrim($jsFile, '/') }}"></script>
    @endif
  @endif

  <link rel="stylesheet" href="/speedbox/panel-controls.css?v={{ filemtime(public_path('speedbox/panel-controls.css')) }}">
  <link rel="stylesheet" href="/speedbox/bot-faqs.css?v={{ filemtime(public_path('speedbox/bot-faqs.css')) }}">
  <link rel="stylesheet" href="/speedbox/branding.css?v={{ filemtime(public_path('speedbox/branding.css')) }}">
  <link rel="stylesheet" href="/speedbox/infrastructure.css?v={{ filemtime(public_path('speedbox/infrastructure.css')) }}">
  <link rel="stylesheet" href="/speedbox/network-tunnels.css?v={{ filemtime(public_path('speedbox/network-tunnels.css')) }}">
  <link rel="stylesheet" href="/speedbox/tunnels.css?v={{ filemtime(public_path('speedbox/tunnels.css')) }}">
  <link rel="stylesheet" href="/speedbox/emergency-configs.css?v={{ filemtime(public_path('speedbox/emergency-configs.css')) }}">
  <link rel="stylesheet" href="/speedbox/partner-statistics.css?v={{ filemtime(public_path('speedbox/partner-statistics.css')) }}">

  {{-- Icons & PWA (RELATIVE) --}}
  <link rel="icon" type="image/x-icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="16x16"  href="/favicon-16x16.png">
  <link rel="icon" type="image/png" sizes="32x32"  href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="48x48"  href="/favicon-48x48.png">
  <link rel="icon" type="image/png" sizes="64x64"  href="/favicon-64x64.png">
  <link rel="icon" type="image/png" sizes="96x96"  href="/favicon-96x96.png">
  <link rel="icon" type="image/png" sizes="128x128" href="/favicon-128x128.png">
  <link rel="icon" type="image/png" sizes="256x256" href="/favicon-256x256.png">
  <link rel="icon" type="image/png" sizes="192x192" href="/android-chrome-192x192.png">
  <link rel="icon" type="image/png" sizes="384x384" href="/android-chrome-384x384.png">
  <link rel="icon" type="image/png" sizes="512x512" href="/android-chrome-512x512.png">

  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

  <link rel="manifest" href="/site.webmanifest">

  <meta name="theme-color" content="#070000">
  <meta name="theme-color" media="(prefers-color-scheme: light)" content="#070000">
  <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#070000">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-capable" content="yes">
</head>
<body>
  <noscript>برای استفاده از این برنامه لازم است JavaScript مرورگر شما فعال باشد.</noscript>

  <div id="app"></div>

  <script id="__BOOTSTRAP__" type="application/json">
    {!! json_encode($bootstrap ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
  </script>
  @include('infrastructure')
</body>
</html>



