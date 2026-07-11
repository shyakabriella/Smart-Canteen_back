<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RegisterController extends BaseController
{
    /**
     * Register API.
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'       => 'required|string|max:255',
            'email'      => 'required|email|max:255|unique:users,email',
            'phone'      => 'required|string|max:30|unique:users,phone',
            'password'   => 'required|string|min:6',
            'c_password' => 'required|same:password',

            'device_id'    => 'nullable|string|max:255',
            'device_name'  => 'nullable|string|max:255',
            'device_type'  => 'nullable|string|max:100',
            'device_token' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        $user = User::create([
            'name'     => trim((string) $request->name),
            'email'    => strtolower(trim((string) $request->email)),
            'phone'    => trim((string) $request->phone),
            'password' => $request->password,

            'role'   => User::ROLE_STUDENT,
            'status' => User::STATUS_ACTIVE,

            'wallet_balance' => 0,

            'user_code' => 'STD-' . strtoupper(Str::random(8)),
            'qr_code'   => null,

            'device_id'    => $request->device_id,
            'device_name'  => $request->device_name,
            'device_type'  => $request->device_type ?? 'mobile',
            'device_token' => $request->device_token,

            'email_verified_at' => null,
            'phone_verified_at' => null,
            'last_login_at'     => now(),
        ]);

        $token = $user
            ->createToken('SmartCanteenApp')
            ->plainTextToken;

        return $this->sendCreated([
            'token'      => $token,
            'token_type' => 'Bearer',

            'user' => $this->formatUser($user),
        ], 'User registered successfully.');
    }

    /**
     * Login API.
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|email',
            'password' => 'required|string',

            'device_id'    => 'nullable|string|max:255',
            'device_name'  => 'nullable|string|max:255',
            'device_type'  => 'nullable|string|max:100',
            'device_token' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your input.'
            );
        }

        $credentials = [
            'email'    => strtolower(trim((string) $request->email)),
            'password' => $request->password,
        ];

        if (!Auth::attempt($credentials)) {
            return $this->sendUnauthorized(
                'Invalid email or password.'
            );
        }

        /** @var User $user */
        $user = Auth::user();

        if ($user->status !== User::STATUS_ACTIVE) {
            Auth::logout();

            return $this->sendForbidden(
                'Your account is not active. Please contact the administrator.'
            );
        }

        $user->update([
            'last_login_at' => now(),

            'device_id' =>
                $request->device_id ?? $user->device_id,

            'device_name' =>
                $request->device_name ?? $user->device_name,

            'device_type' =>
                $request->device_type ?? $user->device_type,

            'device_token' =>
                $request->device_token ?? $user->device_token,
        ]);

        $user->refresh();

        $token = $user
            ->createToken('SmartCanteenApp')
            ->plainTextToken;

        return $this->sendResponse([
            'token'      => $token,
            'token_type' => 'Bearer',

            'user' => $this->formatUser($user),
        ], 'User logged in successfully.');
    }

    /**
     * Logout current API token.
     */
    public function logout(Request $request): JsonResponse
    {
        $accessToken = $request->user()?->currentAccessToken();

        if ($accessToken) {
            $accessToken->delete();
        }

        return $this->sendResponse(
            [],
            'User logged out successfully.'
        );
    }

    /**
     * Get logged-in user profile.
     */
    public function profile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->sendResponse(
            $this->formatUser($user, true),
            'Profile retrieved successfully.'
        );
    }

    /**
     * List users for administrative forms and filters.
     *
     * Endpoint:
     * GET /api/users
     */
    public function users(Request $request): JsonResponse
    {
        /** @var User $authenticatedUser */
        $authenticatedUser = $request->user();

        /*
         * Listing every user exposes account information.
         * Only administrative and canteen management roles
         * should access this endpoint.
         */
        $allowedRoles = [
            'admin',
            'administrator',
            'manager',
            'canteen_manager',
            'canteen_staff',
            'staff',
        ];

        $currentRole = strtolower(
            trim((string) $authenticatedUser->role)
        );

        if (!in_array($currentRole, $allowedRoles, true)) {
            return $this->sendForbidden(
                'You are not allowed to view the users list.'
            );
        }

        $validator = Validator::make($request->all(), [
            'search'   => 'nullable|string|max:255',
            'role'     => 'nullable|string|max:100',
            'status'   => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:200',
            'page'     => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                $validator->errors(),
                'Please check your filters.'
            );
        }

        $query = User::query()
            ->select([
                'id',
                'name',
                'email',
                'phone',
                'role',
                'status',
                'wallet_balance',
                'user_code',
                'qr_code',
                'profile_photo',
                'device_type',
                'last_login_at',
                'created_at',
                'updated_at',
            ])
            ->orderBy('name')
            ->orderBy('id');

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($userQuery) use ($search) {
                $userQuery
                    ->where('name', 'like', '%' . $search . '%')
                    ->orWhere(
                        'email',
                        'like',
                        '%' . $search . '%'
                    )
                    ->orWhere(
                        'phone',
                        'like',
                        '%' . $search . '%'
                    )
                    ->orWhere(
                        'user_code',
                        'like',
                        '%' . $search . '%'
                    );
            });
        }

        if ($request->filled('role')) {
            $query->where(
                'role',
                trim((string) $request->role)
            );
        }

        if ($request->filled('status')) {
            $query->where(
                'status',
                trim((string) $request->status)
            );
        }

        $perPage = min(
            max((int) $request->input('per_page', 50), 1),
            200
        );

        $users = $query
            ->paginate($perPage)
            ->appends($request->query());

        return $this->sendResponse(
            $users,
            'Users retrieved successfully.'
        );
    }

    /**
     * Format user data returned by authentication endpoints.
     */
    private function formatUser(
        User $user,
        bool $includeProfileFields = false
    ): array {
        $data = [
            'id'             => $user->id,
            'name'           => $user->name,
            'email'          => $user->email,
            'phone'          => $user->phone,
            'role'           => $user->role,
            'status'         => $user->status,
            'wallet_balance' => $user->wallet_balance,
            'user_code'      => $user->user_code,
            'device_type'    => $user->device_type,
            'last_login_at'  => $user->last_login_at,
        ];

        if ($includeProfileFields) {
            $data['qr_code'] = $user->qr_code;
            $data['profile_photo'] = $user->profile_photo;
            $data['device_id'] = $user->device_id;
            $data['device_name'] = $user->device_name;
            $data['email_verified_at'] =
                $user->email_verified_at;
            $data['phone_verified_at'] =
                $user->phone_verified_at;
        }

        return $data;
    }
}
