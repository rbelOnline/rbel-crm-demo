<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Session (cookie) authentication for the first-party SPA via Sanctum.
 * The SPA first calls GET /sanctum/csrf-cookie, then posts credentials.
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): UserResource
    {
        $request->authenticate();
        $request->session()->regenerate();

        $user = $request->user();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        AuditLogger::record('login', 'auth', $user->id, null, null, "{$user->name} signed in");

        return new UserResource($user);
    }

    public function logout(Request $request): JsonResponse
    {
        AuditLogger::record('logout', 'auth', $request->user()?->id, null, null, 'Signed out');

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
