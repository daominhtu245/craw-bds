<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer token auth for collectors. Fails closed when INGEST_TOKEN is not set.
 */
class AuthenticateIngestToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('leadradar.ingest.token');
        $given = (string) $request->bearerToken();

        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
