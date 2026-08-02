<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\UserStatus;
use App\Exceptions\AccountInactiveException;
use App\Exceptions\InvalidCredentialsException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticationService
{
    /**
     * @param  array{email: string, password: string}  $credentials
     *
     * @throws InvalidCredentialsException
     * @throws AccountInactiveException
     */
    public function login(array $credentials, Request $request): User
    {
        $authenticated = Auth::guard('web')->attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
        ]);

        if (! $authenticated) {
            throw new InvalidCredentialsException;
        }

        /** @var User $user */
        $user = Auth::guard('web')->user();

        if ($user->status === UserStatus::Inactive) {
            Auth::guard('web')->logout();

            throw new AccountInactiveException;
        }

        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => now(),
        ])->save();

        return $user->load(['role', 'department', 'jobTitle']);
    }

    public function logout(Request $request): void
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    public function currentUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user->loadMissing(['role', 'department', 'jobTitle']);
    }
}
