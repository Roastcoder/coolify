{{-- Fork-only: page shell for StandaloneCoders Mail. --}}
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title ?? 'StandaloneCoders Mail' }}</title>
    <link rel="icon" href="/standalonecoders-favicon.png" type="image/png">
    @vite(['resources/css/app.css'])
</head>

<body class="h-full min-h-screen bg-white font-sans text-slate-700 antialiased">
    {{ $slot }}
</body>

</html>
