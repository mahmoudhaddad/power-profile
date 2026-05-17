<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback()
    {
        $googleUser = Socialite::driver('google')->user();

        $user = User::updateOrCreate(
            ['email' => $googleUser->getEmail()],
            [
                'name'                  => $googleUser->getName(),
                'google_id'             => $googleUser->getId(),
                'avatar'                => $googleUser->getAvatar(),
                'google_token'          => $googleUser->token,
                'google_refresh_token'  => $googleUser->refreshToken,
                'email_verified_at'     => now(),
                'password'              => null,
            ]
        );

        $token = $user->createToken('google-auth')->plainTextToken;

        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

        return redirect("{$frontendUrl}/auth/callback?token={$token}");
    }
}
