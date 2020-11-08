@props(['title' => null, 'background' => 'bg-stone-50'])
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%230f766e'/%3E%3Cpath d='M9 13h14l-1.5 10h-11z' fill='white'/%3E%3Cpath d='M12 13a4 4 0 0 1 8 0' stroke='white' stroke-width='2' fill='none'/%3E%3C/svg%3E">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{ $head ?? '' }}
</head>
<body {{ $attributes->class(['min-h-full font-sans text-stone-900 antialiased', $background]) }}>
    {{ $slot }}
</body>
</html>
