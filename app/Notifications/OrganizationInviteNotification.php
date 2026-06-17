<?php

namespace App\Notifications;

use App\Models\OrganizationInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationInviteNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected OrganizationInvite $invite,
    ) {
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $registerUrl = route('register.invite', ['token' => $this->invite->token]);

        return (new MailMessage)
            ->subject('You have been invited to join '.$this->invite->organization->name)
            ->greeting('You are invited')
            ->line('You have been invited to join '.$this->invite->organization->name.' as '.$this->invite->role.'.')
            ->action('Accept invitation', $registerUrl)
            ->line('This invite expires on '.$this->invite->expires_at?->toDayDateTimeString().'.');
    }
}
