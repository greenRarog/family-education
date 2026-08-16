<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="turnstile-site-key" content="{{ config('services.turnstile.site_key') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script
        src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit"
        async
        defer
    ></script>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
</head>
<body>
<div id="app"></div>
</body>
</html>
