<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * How a player appears to others: their name, and whether they show in the
 * public rankings with their photo.
 */
class AccountSettingsController extends Controller
{
    /**
     * A name can change once in this many days, so rankings stay readable.
     */
    public const NAME_COOLDOWN_DAYS = 7;

    /**
     * New accounts can fix their name freely for this long after sign-up.
     */
    public const NEW_ACCOUNT_GRACE_MINUTES = 60;

    public function edit(Request $request): View
    {
        return view('account.settings', [
            'user' => $request->user(),
            'nextNameChange' => $this->nextNameChange($request),
        ]);
    }

    public function updateName(Request $request): RedirectResponse
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);

        $data = $request->validate([
            // No control or format characters (they can hide or spoof text).
            'name' => ['required', 'string', 'max:24', 'not_regex:/[\p{Cc}\p{Cf}]/u'],
        ], [
            'name.not_regex' => 'The name contains characters that cannot be shown.',
        ]);

        $user = $request->user();

        if ($data['name'] === $user->name) {
            return back()->with('status', 'That is already your name.');
        }

        if ($next = $this->nextNameChange($request)) {
            throw ValidationException::withMessages([
                'name' => "You can change your name again on {$next->toFormattedDateString()}.",
            ]);
        }

        $user->forceFill([
            'name' => $data['name'],
            'name_changed_at' => $this->isNewAccount($request) ? null : now(),
        ])->save();

        return back()->with('status', "You now appear as {$user->name}.");
    }

    public function updatePrivacy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'show_in_rankings' => ['required', 'boolean'],
            'show_avatar' => ['required', 'boolean'],
        ]);

        $request->user()->forceFill([
            'show_in_rankings' => (bool) $data['show_in_rankings'],
            'show_avatar' => (bool) $data['show_avatar'],
        ])->save();

        return back()->with('status', 'Privacy settings saved.');
    }

    /**
     * When the cooldown ends, or null if the name can change now.
     */
    private function nextNameChange(Request $request): ?Carbon
    {
        $changedAt = $request->user()->name_changed_at;
        if ($changedAt === null) {
            return null;
        }

        $next = $changedAt->copy()->addDays(self::NAME_COOLDOWN_DAYS);

        return $next->isFuture() ? $next : null;
    }

    private function isNewAccount(Request $request): bool
    {
        return $request->user()->created_at?->gt(now()->subMinutes(self::NEW_ACCOUNT_GRACE_MINUTES)) ?? false;
    }
}
