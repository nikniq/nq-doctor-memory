<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function notice(Request $request): View|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('game');
        }

        return view('auth.verify-email');
    }

    /**
     * Handle the signed link from the verification email.
     *
     * Unlike the framework default this works without being signed in, so
     * the link can be opened on another device. It only verifies; it never
     * signs anyone in, so the link is not a login credential.
     */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            abort(403, 'This verification link is not valid.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        $status = 'Your email is verified. Your games now count towards the player rankings.';

        if ($request->user()?->is($user)) {
            return redirect()->route('game')->with('status', $status);
        }

        return redirect()->route('login')->with('status', $status.' Log in to continue.');
    }

    public function resend(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('game');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'A new verification link has been sent to your email address.');
    }
}
