<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

class GoogleController extends Controller
{
    public function redirect(): SymfonyRedirect
    {
        abort_unless(self::isConfigured(), 404);

        return Socialite::driver('google')->redirect();
    }

    public function callback(): RedirectResponse
    {
        abort_unless(self::isConfigured(), 404);

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $exception) {
            // Cancelled consent, a replayed or expired state, or a Google outage.
            report($exception);

            return redirect()->route('login')->withErrors(['email' => 'Google sign-in did not complete. Please try again.']);
        }

        if (! $googleUser->getEmail() || ! ($googleUser->getRaw()['email_verified'] ?? false)) {
            return redirect()->route('login')->withErrors(['email' => 'Your Google account email is not verified, so it cannot be used to sign in.']);
        }

        $user = $this->findOrCreateUser($googleUser);

        Auth::login($user, remember: true);
        request()->session()->regenerate();

        return redirect()->intended(route('game'));
    }

    public static function isConfigured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    /**
     * Match by Google id first, then by email. Google has verified the email,
     * so the account is marked verified.
     *
     * When an existing account was never verified, nobody proved they owned
     * that address, so its password is cleared before linking. Otherwise
     * someone could pre-register a victim's email and keep a way in after
     * the real owner signs in with Google.
     */
    private function findOrCreateUser(SocialiteUser $googleUser): User
    {
        $email = Str::lower($googleUser->getEmail());

        $user = User::where('google_id', $googleUser->getId())->first()
            ?? User::where('email', $email)->first();

        if (! $user) {
            $user = new User;
            $user->forceFill([
                'name' => Str::limit($googleUser->getName() ?: Str::before($email, '@'), 24, ''),
                'email' => $email,
                'password' => null,
            ]);
        } elseif (! $user->hasVerifiedEmail()) {
            $user->forceFill(['password' => null]);
        }

        $user->forceFill([
            'google_id' => $googleUser->getId(),
            'avatar_url' => $googleUser->getAvatar(),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        return $user;
    }
}
