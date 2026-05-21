<?php

namespace STS\Postmaster\Http\Controllers\Dashboard;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the dashboard's stylesheet straight from the package.
 *
 * The package's files are not under the application's public path, so the
 * stylesheet cannot be linked directly — but PHP can still read it off disk.
 * This action streams it back over a normal route, which means no asset
 * publishing step is needed. response()->file() sets the content type and
 * handles conditional requests (Last-Modified / 304) for browser caching.
 */
class AssetController
{
    /**
     * @return BinaryFileResponse
     */
    public function css()
    {
        return response()
            ->file(__DIR__.'/../../../../resources/dist/postmaster.css', [
                'Content-Type' => 'text/css',
            ]);
    }

    /**
     * The logo mark, served straight from the package.
     *
     * @return BinaryFileResponse
     */
    public function logo()
    {
        return response()
            ->file(__DIR__.'/../../../../resources/svg/hat.svg', [
                'Content-Type' => 'image/svg+xml',
            ]);
    }

    /**
     * Alpine.js, vendored and served from the package — the dashboard pulls
     * in no third-party JavaScript, so it works offline and behind strict
     * networks, with no CDN supply-chain surface on a sensitive admin page.
     *
     * @return BinaryFileResponse
     */
    public function alpine()
    {
        return response()
            ->file(__DIR__.'/../../../../resources/dist/alpine.js', [
                'Content-Type' => 'text/javascript',
            ]);
    }
}
