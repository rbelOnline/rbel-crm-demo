<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\PasswordUpdateRequest;
use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Resources\UserResource;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    public function update(ProfileUpdateRequest $request): UserResource
    {
        $user = $request->user();
        $old = $user->only(array_keys($request->validated()));
        $user->update($request->validated());

        $changes = array_intersect_key($user->getChanges(), $old);
        if ($changes !== []) {
            AuditLogger::record('updated', 'profile', $user->id, array_intersect_key($old, $changes), $changes, 'Updated profile');
        }

        return new UserResource($user);
    }

    public function password(PasswordUpdateRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['password' => $request->validated('password')]);

        // Sign out other browser sessions that still hold the old password.
        Auth::guard('web')->logoutOtherDevices($request->validated('password'));

        AuditLogger::record('changed_password', 'profile', $user->id, null, null, 'Changed password');

        return response()->json(['message' => 'Password updated.']);
    }
}
