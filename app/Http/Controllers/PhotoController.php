<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;

class PhotoController extends Controller
{
    /**
     * Serve a photo from storage
     *
     * @param string $filename
     * @return \Illuminate\Http\Response
     */
    public function show($filename)
    {
        $filename = basename($filename);

        $path = "reports/{$filename}";

        if (!Storage::disk('public')->exists($path)) {
            $path = $filename;

            if (!Storage::disk('public')->exists($path)) {
                return response()->json(['error' => 'Image not found'], 404);
            }
        }

        $mimeType = Storage::disk('public')->mimeType($path);

        $file = Storage::disk('public')->get($path);

        return Response::make($file, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Cache-Control' => 'public, max-age=86400'
        ]);
    }
}
