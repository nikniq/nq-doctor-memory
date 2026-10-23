<?php

namespace App\Http\Controllers;

use App\Actions\DeleteAccount;
use App\Models\User;
use App\Notifications\ConfirmAccountDeletion;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A player's right to take their data with them, or to remove it.
 */
class AccountDataController extends Controller
{
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();

        $data = [
            'exported_at' => now()->toIso8601String(),
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at?->toIso8601String(),
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'google_linked' => $user->google_id !== null,
                'avatar_url' => $user->avatar_url,
                'show_in_rankings' => $user->show_in_rankings,
                'show_avatar' => $user->show_avatar,
            ],
            'games' => $user->games()->orderBy('started_at')->get()->map(fn ($game) => [
                'difficulty' => $game->difficulty,
                'played_on' => $game->played_on?->toDateString(),
                'players' => $game->players,
                'pairs' => $game->pairs,
                'turns' => $game->turns,
                'seconds' => $game->seconds,
                'started_at' => $game->started_at?->toIso8601String(),
                'finished_at' => $game->finished_at?->toIso8601String(),
                'scored' => $game->scored_at !== null,
                'unranked_reason' => $game->unranked_reason,
            ])->all(),
            'scores' => $user->scores()->orderBy('created_at')->get()->map(fn ($score) => [
                'difficulty' => $score->difficulty,
                'played_on' => $score->played_on?->toDateString(),
                'player_name' => $score->player_name,
                'moves' => $score->moves,
                'seconds' => $score->seconds,
                'created_at' => $score->created_at?->toIso8601String(),
            ])->all(),
            'sessions' => DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->get(['ip_address', 'user_agent', 'last_activity'])
                ->map(fn ($session) => [
                    'ip_address' => $session->ip_address,
                    'user_agent' => $session->user_agent,
                    'last_activity' => date(DATE_ATOM, (int) $session->last_activity),
                ])->all(),
        ];

        return response()->streamDownload(
            fn () => print (json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'doctor-memory-data-'.now()->format('Y-m-d').'.json',
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * Delete an account that has a password, confirmed by that password.
     */
    public function destroy(Request $request, DeleteAccount $deleteAccount): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasPassword()) {
            return back()->withErrors(['password' => 'Your account has no password. Use the email link to confirm instead.']);
        }

        $request->validate(['password' => ['required', 'current_password']]);

        return $this->deleteAndSignOut($request, $user, $deleteAccount);
    }

    /**
     * Accounts without a password confirm through a link sent to their email.
     */
    public function sendDeleteLink(Request $request): RedirectResponse
    {
        $request->user()->notifyNow(new ConfirmAccountDeletion);

        return back()->with('status', 'We emailed you a link to confirm deleting your account. It works for '.ConfirmAccountDeletion::VALID_MINUTES.' minutes.');
    }

    /**
     * The emailed link: show what will be removed. Deleting needs a POST.
     */
    public function confirm(Request $request, User $user): View
    {
        abort_unless($request->user()?->is($user), 403);

        return view('account.delete', ['user' => $user, 'action' => $request->fullUrl()]);
    }

    public function destroySigned(Request $request, User $user, DeleteAccount $deleteAccount): RedirectResponse
    {
        abort_unless($request->user()?->is($user), 403);

        return $this->deleteAndSignOut($request, $user, $deleteAccount);
    }

    private function deleteAndSignOut(Request $request, User $user, DeleteAccount $deleteAccount): RedirectResponse
    {
        Auth::guard('web')->logout();
        $deleteAccount->handle($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('game')->with('status', 'Your account and games were deleted.');
    }
}
