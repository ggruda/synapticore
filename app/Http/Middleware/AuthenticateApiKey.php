<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Secret;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to authenticate API requests using API keys.
 */
class AuthenticateApiKey
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $this->extractApiKey($request);

        if (! $apiKey) {
            return response()->json([
                'success' => false,
                'message' => 'API key is required',
            ], 401);
        }

        // Find the API key in secrets
        $secret = Secret::where('key_id', 'api_key')->get()->first(function ($secret) use ($apiKey) {
            try {
                return decrypt($secret->payload) === $apiKey;
            } catch (\Exception $e) {
                return false;
            }
        });

        if (! $secret) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid API key',
            ], 401);
        }

        // Attach project to request for later use
        $request->attributes->set('project', $secret->project);

        return $next($request);
    }

    /**
     * Extract API key from request.
     */
    private function extractApiKey(Request $request): ?string
    {
        // Check Authorization header first
        if ($request->hasHeader('Authorization')) {
            $auth = $request->header('Authorization');
            if (str_starts_with($auth, 'Bearer ')) {
                return substr($auth, 7);
            }
        }

        // Check X-API-Key header
        if ($request->hasHeader('X-API-Key')) {
            return $request->header('X-API-Key');
        }

        // Check query parameter
        if ($request->has('api_key')) {
            return $request->get('api_key');
        }

        return null;
    }
}
