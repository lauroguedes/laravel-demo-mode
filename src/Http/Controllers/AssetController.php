<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the floating bar's one script.
 *
 * A route rather than a published file, so the script can never be a version
 * behind the package that renders the payload it reads — publishing puts a copy
 * in public/ that nothing updates, and the failure is a bar that silently stops
 * understanding a field added in a patch release.
 *
 * A route rather than an inline block, so a Content-Security-Policy can allow it
 * with 'self' instead of a hash that changes whenever the file does. That is the
 * whole reason a demo under a strict policy can have a bar at all.
 *
 * The URL carries a hash of the file, so the response is immutable for a year and
 * a new version is a new URL. There is nothing to invalidate.
 */
final class AssetController
{
    public const string SCRIPT = __DIR__.'/../../../resources/dist/demo-bar.js';

    public function __invoke(Request $request): Response
    {
        $contents = (string) file_get_contents(self::SCRIPT);
        $etag = '"'.self::version().'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return new Response(status: Response::HTTP_NOT_MODIFIED, headers: ['ETag' => $etag]);
        }

        return new Response($contents, Response::HTTP_OK, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => $etag,
        ]);
    }

    /**
     * Short, and computed from the file rather than the package version, because
     * a developer editing a local checkout of this package wants their edit to
     * appear without inventing a version number.
     *
     * Keyed on the modification time rather than memoised outright. A plain
     * static survives for the life of an Octane worker, and the body is read
     * fresh on every request — so an edited file was served with the previous
     * request's ETag and the previous cache-busting URL. Since the response is
     * immutable for a year, a browser holding that URL would never ask again.
     */
    public static function version(): string
    {
        /** @var array<int, string> $cache */
        static $cache = [];

        $mtime = (int) filemtime(self::SCRIPT);

        return $cache[$mtime] ??= substr(hash_file('xxh128', self::SCRIPT) ?: 'dev', 0, 10);
    }
}
