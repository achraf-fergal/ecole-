<?php

namespace App\Http\Middleware;

use App\Models\Center;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCenterRole
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->isPlatformAdmin()) {
            return $next($request);
        }

        /** @var Center|null $center */
        $center = $request->attributes->get('current_center') ?? $request->route('center');

        if (! $center instanceof Center) {
            return response()->json([
                'message' => 'Center context missing.',
            ], 400);
        }

        if (! $user->hasAnyRoleInCenter($center, $roles)) {
            return response()->json([
                'message' => 'Forbidden. Insufficient role permissions.',
            ], 403);
        }

        return $next($request);
    }
}
