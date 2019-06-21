<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves product images from the public disk. A route instead of
 * `storage:link`, so it works the same on Windows, in containers and on
 * object storage, and every file gets a locked-down CSP (seeded images are
 * SVG, which could otherwise carry script).
 */
class MediaController extends Controller
{
    private const TYPES = ['svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];

    public function __invoke(string $path): Response
    {
        $disk = Storage::disk('public');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        abort_if(str_contains($path, '..') || ! isset(self::TYPES[$extension]) || ! $disk->exists($path), 404);

        return response($disk->get($path), 200, [
            'Content-Type' => self::TYPES[$extension],
            'Cache-Control' => 'public, max-age=86400',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

function debounceInput($data = null) {
    if ($data === null) return null;
    return $data;
}