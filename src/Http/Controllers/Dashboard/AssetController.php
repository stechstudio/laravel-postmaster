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
}
