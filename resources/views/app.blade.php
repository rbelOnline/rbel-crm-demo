<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="RBEL-CRM — Financial Advisor Client Management & Analytics">
    <title>RBEL-CRM · Financial Advisor Client Management & Analytics</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet">
    <script>
        // Apply the saved theme before first paint to avoid a flash.
        try {
            const t = localStorage.getItem('rbel-theme') || 'system';
            const dark = t === 'dark' || (t === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
        } catch (e) {}
    </script>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/main.tsx'])
</head>
<body class="min-h-screen">
    <div id="app"></div>
    <noscript>RBEL-CRM requires JavaScript.</noscript>
</body>
</html>
