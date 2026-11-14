<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\CenterUser;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PlatformUserController extends Controller
{
    /**
     * List all platform users with center memberships and platform admin state.
     */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));

        $query = User::query()
            ->with([
                'centerMemberships.center',
                'centerMemberships.roles',
            ]);

        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (! empty($status)) {
            $query->where('status', $status);
        }

        $users = $query->orderBy('name')->paginate(25);

        $transformed = $users->through(function (User $user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'email_verified' => $user->hasVerifiedEmail(),
                'is_platform_admin' => $user->isPlatformAdmin(),
                'created_at' => $user->created_at?->toISOString(),
                'centers' => $user->centerMemberships->map(fn (CenterUser $cu) => [
                    'center_id' => $cu->center_id,
                    'center_name' => $cu->center?->name,
                    'center_slug' => $cu->center?->slug,
                    'status' => $cu->status,
                    'roles' => $cu->roles->map(fn (Role $r) => [
                        'key' => $r->key,
                        'name' => $r->name,
                    ]),
                ]),
            ];
        });

        return response()->json($transformed);
    }

    /**
     * Grant or revoke Super Admin (Platform Admin) privileges for a user.
     */
    public function toggleSuperAdmin(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'is_super_admin' => ['required', 'boolean'],
        ]);

        $shouldBeSuperAdmin = (bool) $validated['is_super_admin'];
        $currentUser = $request->user();

        // Safety: Prevent self-demotion if the current user is the only platform admin
        if (! $shouldBeSuperAdmin && $currentUser && $currentUser->id === $user->id) {
            $adminCount = PlatformAdmin::count();
            if ($adminCount <= 1) {
                throw ValidationException::withMessages([
                    'is_super_admin' => ['Cannot revoke your own Super Admin access when you are the sole Platform Administrator.'],
                ]);
            }
        }

        if ($shouldBeSuperAdmin) {
            PlatformAdmin::firstOrCreate(['user_id' => $user->id]);
        } else {
            PlatformAdmin::where('user_id', $user->id)->delete();
        }

        return response()->json([
            'message' => $shouldBeSuperAdmin
                ? 'Super Admin privileges granted.'
                : 'Super Admin privileges revoked.',
            'is_platform_admin' => $user->isPlatformAdmin(),
        ]);
    }

    /**
     * Update global user account status (active, disabled, suspended).
     */
    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:active,disabled,suspended'],
        ]);

        $currentUser = $request->user();

        // Cannot disable your own account
        if ($currentUser && $currentUser->id === $user->id && $validated['status'] !== User::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'status' => ['Cannot deactivate your own account.'],
            ]);
        }

        $user->status = $validated['status'];

        // If disabling or suspending, increment token_version to invalidate all existing sessions immediately
        if ($user->status !== User::STATUS_ACTIVE) {
            $user->token_version = (int) ($user->token_version ?? 1) + 1;
        }

        $user->save();

        return response()->json([
            'message' => 'User status updated successfully.',
            'status' => $user->status,
        ]);
    }

    /**
     * Assign a user to any center with specified roles.
     */
    public function assignCenter(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'center_id' => ['required', 'integer', 'exists:centers,id'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'exists:roles,key'],
        ]);

        $center = Center::findOrFail($validated['center_id']);

        $centerUser = CenterUser::firstOrCreate(
            [
                'center_id' => $center->id,
                'user_id' => $user->id,
            ],
            [
                'status' => 'active',
            ]
        );

        $roleIds = Role::whereIn('key', $validated['roles'])->pluck('id')->all();
        $centerUser->roles()->sync($roleIds);

        return response()->json([
            'message' => "User successfully assigned to center '{$center->name}'.",
            'center' => [
                'id' => $center->id,
                'name' => $center->name,
                'slug' => $center->slug,
            ],
            'roles' => $validated['roles'],
        ]);
    }
}
