<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     * Supports both direct browser signed URL clicks and JSON client requests.
     */
    public function verify(Request $request, string $id, string $hash): JsonResponse|RedirectResponse
    {
        $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/');

        if (! $request->hasValidSignature()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Invalid or expired verification link.',
                ], 403);
            }

            return redirect()->to("{$frontendUrl}/verify-email?status=invalid");
        }

        $user = User::find($id);

        if (! $user) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'User not found.',
                ], 404);
            }

            return redirect()->to("{$frontendUrl}/verify-email?status=invalid");
        }

        if (! hash_equals(sha1($user->getEmailForVerification()), (string) $hash)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Invalid verification hash.',
                ], 403);
            }

            return redirect()->to("{$frontendUrl}/verify-email?status=invalid");
        }

        if ($user->hasVerifiedEmail()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Email already verified.',
                ]);
            }

            return redirect()->to("{$frontendUrl}/verify-email?status=already-verified");
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Email verified successfully.',
            ]);
        }

        return redirect()->to("{$frontendUrl}/verify-email?status=verified");
    }

    /**
     * Resend email verification notification.
     */
    public function resend(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email already verified.',
            ]);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Verification link sent.',
        ]);
    }
}
