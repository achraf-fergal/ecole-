<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! method_exists($user, 'isPlatformAdmin') || ! $user->isPlatformAdmin()) {
            return new JsonResponse([
                'message' => 'Platform Super Admin access required.',
                'code' => 'SUPER_ADMIN_REQUIRED',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
