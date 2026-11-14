<?php

namespace App\Http\Middleware;

use App\Models\Center;
use App\Models\CenterUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateTenantAccess
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Resolve center from route parameter or header.
        $centerParam = $request->route('center');

        if ($centerParam instanceof Center) {
            $center = $centerParam;
        } elseif (is_string($centerParam) || is_numeric($centerParam)) {
            $center = Center::where('slug', (string) $centerParam)
                ->orWhere('id', (int) $centerParam)
                ->first();
        } else {
            $headerSlug = $request->header('X-Center-Slug');
            $center = $headerSlug ? Center::where('slug', $headerSlug)->first() : null;
        }

        if (! $center) {
            return response()->json([
                'message' => 'Center not found.',
            ], 404);
        }

        // Platform Super Admins have universal tenant authorization.
        if ($user->isPlatformAdmin()) {
            $request->attributes->set('current_center', $center);

            return $next($request);
        }

        // Tenant users must have an active membership in this specific center.
        $membership = CenterUser::where('center_id', $center->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            return response()->json([
                'message' => 'Unauthorized access to this center.',
            ], 403);
        }

        $request->attributes->set('current_center', $center);
        $request->attributes->set('current_membership', $membership);

        return $next($request);
    }
}
