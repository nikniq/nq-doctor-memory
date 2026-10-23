<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * For accounts without a password (Google sign-in): proving access to the
 * email address stands in for typing a password before deletion.
 */
class ConfirmAccountDeletion extends Notification
{
    public const VALID_MINUTES = 30;

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            'account.destroy.confirm',
            now()->addMinutes(self::VALID_MINUTES),
            ['user' => $notifiable->id],
        );

        return (new MailMessage)
            ->subject('Confirm deleting your '.config('game.title').' account')
            ->line('Someone, hopefully you, asked to delete this account and all of its games and scores.')
            ->action('Review and delete', $url)
            ->line('The link works for '.self::VALID_MINUTES.' minutes. If you did not ask for this, ignore this email.');
    }
}
