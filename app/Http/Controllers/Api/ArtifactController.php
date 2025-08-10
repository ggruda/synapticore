<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

/**
 * API Controller for artifact management.
 */
class ArtifactController extends Controller
{
    /**
     * Download an artifact.
     */
    public function download(Request $request)
    {
        $path = $request->get('path');

        if (! $path) {
            return response()->json([
                'success' => false,
                'message' => 'Path parameter is required',
            ], 400);
        }

        // Security check - prevent directory traversal
        if (str_contains($path, '..') || str_contains($path, '~')) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid path',
            ], 400);
        }

        $disk = Storage::disk(config('filesystems.default') === 'spaces' ? 'spaces' : 'local');

        if (! $disk->exists($path)) {
            return response()->json([
                'success' => false,
                'message' => 'Artifact not found',
            ], 404);
        }

        try {
            $content = $disk->get($path);
            $mimeType = $disk->mimeType($path);
            $filename = basename($path);

            return response($content, 200)
                ->header('Content-Type', $mimeType)
                ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to download artifact',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List artifacts for a ticket.
     */
    public function list(Request $request)
    {
        $ticketId = $request->get('ticket_id');

        if (! $ticketId) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket ID is required',
            ], 400);
        }

        $disk = Storage::disk(config('filesystems.default') === 'spaces' ? 'spaces' : 'local');

        try {
            $artifacts = [];

            // List artifacts in ticket directory
            $basePath = "artifacts/tickets/{$ticketId}";

            if ($disk->exists($basePath)) {
                $files = $disk->allFiles($basePath);

                foreach ($files as $file) {
                    $artifacts[] = [
                        'path' => $file,
                        'name' => basename($file),
                        'size' => $disk->size($file),
                        'modified' => $disk->lastModified($file),
                        'url' => url('/api/artifacts/download?path='.urlencode($file)),
                    ];
                }
            }

            // List failure bundles
            $bundlePath = "failure_bundles/tickets/{$ticketId}";

            if ($disk->exists($bundlePath)) {
                $files = $disk->allFiles($bundlePath);

                foreach ($files as $file) {
                    $artifacts[] = [
                        'path' => $file,
                        'name' => basename($file),
                        'size' => $disk->size($file),
                        'modified' => $disk->lastModified($file),
                        'type' => 'failure_bundle',
                        'url' => url('/api/artifacts/download?path='.urlencode($file)),
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'ticket_id' => $ticketId,
                    'artifacts' => $artifacts,
                    'total' => count($artifacts),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to list artifacts',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload an artifact.
     */
    public function upload(Request $request)
    {
        $request->validate([
            'ticket_id' => 'required|integer',
            'file' => 'required|file|max:10240', // 10MB max
            'type' => 'required|string|in:junit,coverage,logs,custom',
        ]);

        $ticketId = $request->get('ticket_id');
        $type = $request->get('type');
        $file = $request->file('file');

        try {
            $filename = $type.'_'.time().'_'.$file->getClientOriginalName();
            $path = "artifacts/tickets/{$ticketId}/".date('Y-m-d')."/{$filename}";

            $disk = Storage::disk(config('filesystems.default') === 'spaces' ? 'spaces' : 'local');
            $disk->put($path, $file->get());

            return response()->json([
                'success' => true,
                'message' => 'Artifact uploaded successfully',
                'data' => [
                    'path' => $path,
                    'filename' => $filename,
                    'size' => $file->getSize(),
                    'url' => url('/api/artifacts/download?path='.urlencode($path)),
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload artifact',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
