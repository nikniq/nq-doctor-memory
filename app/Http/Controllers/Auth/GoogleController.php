<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

        if ($user->wasRecentlyCreated) {
            return redirect()->route('account.settings')
                ->with('status', "Welcome! You appear in the rankings as {$user->name}. You can change that here.");
        }

        return redirect()->intended(route('game'));
    }

    /**
     * Sign out whoever registered this unverified account and drop what they
     * recorded under it.
     */
    private function endEarlierClaim(User $user): void
    {
        DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        Game::where('user_id', $user->id)->update(['user_id' => null]);
    }

    /**
     * First name only by default: the full name and photo of a Google
     * account should not appear publicly without the player choosing so.
     */
    private function displayName(SocialiteUser $googleUser, string $email): string
    {
        $given = $googleUser->getRaw()['given_name'] ?? null;

        return Str::limit(trim($given ?: ($googleUser->getName() ?: Str::before($email, '@'))), 24, '');
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
     * that address. Someone could have pre-registered a victim's email, so
     * before linking: the password is cleared, the name is replaced with the
     * Google name, every existing session is ended and games recorded before
     * verification are detached. The owner's own session starts afterwards.
     */
    private function findOrCreateUser(SocialiteUser $googleUser): User
    {
        $email = Str::lower($googleUser->getEmail());

        $user = User::where('google_id', $googleUser->getId())->first()
            ?? User::where('email', $email)->first();

        if (! $user) {
            $user = new User;
            $user->forceFill([
                'name' => $this->displayName($googleUser, $email),
                'email' => $email,
                'password' => null,
            ]);
        } elseif (! $user->hasVerifiedEmail()) {
            $user->forceFill([
                'name' => $this->displayName($googleUser, $email),
                'password' => null,
                'remember_token' => Str::random(60),
            ]);
            $this->endEarlierClaim($user);
        }

        $user->forceFill([
            'google_id' => $googleUser->getId(),
            'avatar_url' => $googleUser->getAvatar(),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        return $user;
    }
}
