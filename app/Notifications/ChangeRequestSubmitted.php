<?php

namespace App\Notifications;

use App\Models\ChangeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Neuer Antrag eines Vereins — an alle Admins (Spec club-permissions §4.5, N4). */
class ChangeRequestSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ChangeRequest $changeRequest) {}

    /** @return list<string> */
    public function via(): array
    {
        return ['mail'];
    }

    public function toMail(): MailMessage
    {
        $request = $this->changeRequest;

        return (new MailMessage)
            ->subject('Neuer Antrag: '.$request->type_label.' – '.$request->athlete?->display_name)
            ->greeting('Neuer Antrag')
            ->line($request->club?->display_name.' beantragt eine '.$request->type_label.' für '
                .$request->athlete?->display_name.'.')
            ->line('Begründung: '.$request->reason)
            ->action('Antrag ansehen', route('admin.change-requests.show', $request));
    }
}
