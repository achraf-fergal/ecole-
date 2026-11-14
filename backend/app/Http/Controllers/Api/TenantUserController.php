<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TenantUserController extends Controller
{
    /**
     * Resolve the center from request attributes or route parameter.
     */
    protected function resolveCenter(Request $request, Center|string $center): Center
    {
        if ($center instanceof Center) {
            return $center;
        }

        $current = $request->attributes->get('current_center');
        if ($current instanceof Center) {
            return $current;
        }

        return Center::where('slug', (string) $center)
            ->orWhere('id', (int) $center)
            ->firstOrFail();
    }

    /**
     * List all users associated with the target center.
     */
    public function index(Request $request, Center|string $center): JsonResponse
    {
        $center = $this->resolveCenter($request, $center);
        $search = trim((string) $request->query('search', ''));
        $roleFilter = trim((string) $request->query('role', ''));

        $query = User::query()
            ->whereHas('centerMemberships', function ($q) use ($center, $roleFilter) {
                $q->where('center_id', $center->id);
                if (! empty($roleFilter)) {
                    $q->whereHas('roles', function ($rq) use ($roleFilter) {
                        $rq->where('key', $roleFilter);
                    });
                }
            })
            ->with([
                'centerMemberships' => function ($q) use ($center) {
                    $q->where('center_id', $center->id)->with('roles');
                },
            ]);

        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('name')->paginate(20);

        $transformed = $users->through(function (User $user) use ($center) {
            $membership = $user->centerMemberships->firstWhere('center_id', $center->id);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'global_status' => $user->status,
                'membership_status' => $membership?->status ?? 'active',
                'joined_at' => $membership?->created_at?->toISOString(),
                'roles' => $membership?->roles->map(fn (Role $r) => [
                    'id' => $r->id,
                    'key' => $r->key,
                    'name' => $r->name,
                ])->values() ?? [],
            ];
        });

        return response()->json($transformed);
    }

    /**
     * List available roles that can be assigned within the center.
     */
    public function availableRoles(): JsonResponse
    {
        $roles = Role::query()
            ->select(['id', 'key', 'name', 'description'])
            ->orderBy('id')
            ->get();

        return response()->json([
            'roles' => $roles,
        ]);
    }

    /**
     * Add an existing or new user to this center with specified roles.
     */
    public function store(Request $request, Center|string $center): JsonResponse
    {
        $center = $this->resolveCenter($request, $center);
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'exists:roles,key'],
        ]);

        $normalizedEmail = strtolower(trim($validated['email']));

        $user = User::where('email', $normalizedEmail)->first();

        if (! $user) {
            $user = User::create([
                'name' => trim($validated['name'] ?? '') ?: 'New Member',
                'email' => $normalizedEmail,
                'password' => null,
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
                'token_version' => 1,
            ]);
        }

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

        $centerUser->load('roles');

        return response()->json([
            'message' => 'User added to center successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'global_status' => $user->status,
                'membership_status' => $centerUser->status,
                'roles' => $centerUser->roles->map(fn (Role $r) => [
                    'id' => $r->id,
                    'key' => $r->key,
                    'name' => $r->name,
                ]),
            ],
        ], 201);
    }

    /**
     * Update roles for a user in this center.
     */
    public function updateRoles(Request $request, Center|string $center, User $user): JsonResponse
    {
        $center = $this->resolveCenter($request, $center);
        $validated = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'exists:roles,key'],
        ]);

        $centerUser = CenterUser::where('center_id', $center->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $centerUser) {
            return response()->json([
                'message' => 'User is not a member of this center.',
            ], 404);
        }

        $currentUser = $request->user();
        $isSelf = $currentUser && $currentUser->id === $user->id;

        // Safety: Prevent admin from removing the last center_admin in the center
        $isDemotingCenterAdmin = ! in_array('center_admin', $validated['roles'], true)
            && $centerUser->roles()->where('key', 'center_admin')->exists();

        if ($isDemotingCenterAdmin) {
            $otherAdminsCount = CenterUser::where('center_id', $center->id)
                ->where('user_id', '!=', $user->id)
                ->where('status', 'active')
                ->whereHas('roles', function ($q) {
                    $q->where('key', 'center_admin');
                })
                ->count();

            if ($otherAdminsCount === 0 && ! ($currentUser && $currentUser->isPlatformAdmin())) {
                throw ValidationException::withMessages([
                    'roles' => ['Cannot remove the only remaining Center Admin for this center.'],
                ]);
            }
        }

        $roleIds = Role::whereIn('key', $validated['roles'])->pluck('id')->all();
        $centerUser->roles()->sync($roleIds);

        $centerUser->load('roles');

        return response()->json([
            'message' => 'Roles updated successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $centerUser->roles->map(fn (Role $r) => [
                    'id' => $r->id,
                    'key' => $r->key,
                    'name' => $r->name,
                ]),
            ],
        ]);
    }

    /**
     * Update center membership status (active or suspended).
     */
    public function updateStatus(Request $request, Center|string $center, User $user): JsonResponse
    {
        $center = $this->resolveCenter($request, $center);
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:active,suspended'],
        ]);

        $centerUser = CenterUser::where('center_id', $center->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $centerUser->status = $validated['status'];
        $centerUser->save();

        return response()->json([
            'message' => 'Membership status updated successfully.',
            'status' => $centerUser->status,
        ]);
    }

    /**
     * Remove a user from this center.
     */
    public function destroy(Request $request, Center|string $center, User $user): JsonResponse
    {
        $center = $this->resolveCenter($request, $center);
        $centerUser = CenterUser::where('center_id', $center->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        // Safety check: Cannot remove yourself if you are the last center admin
        if ($centerUser->roles()->where('key', 'center_admin')->exists()) {
            $otherAdmins = CenterUser::where('center_id', $center->id)
                ->where('user_id', '!=', $user->id)
                ->where('status', 'active')
                ->whereHas('roles', function ($q) {
                    $q->where('key', 'center_admin');
                })
                ->count();

            if ($otherAdmins === 0 && ! ($request->user()?->isPlatformAdmin())) {
                return response()->json([
                    'message' => 'Cannot remove the only remaining Center Admin for this center.',
                ], 422);
            }
        }

        $centerUser->roles()->detach();
        $centerUser->delete();

        return response()->json([
            'message' => 'User removed from center successfully.',
        ]);
    }
}
