<?php

namespace App\Notifications;

use App\Models\ChangeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Freigegebene Ummeldung — an den bisherigen Verein des Athleten (Spec club-permissions P6-1). */
class AthleteTransferredAway extends Notification implements ShouldQueue
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
            ->subject('Ummeldung: '.$request->athlete?->display_name)
            ->greeting('Ummeldung eines Athleten')
            ->line($request->athlete?->display_name.' wurde mit Wirkung ab '
                .date('d.m.Y', strtotime($request->values['joined_at'])).' zu '.$request->club?->display_name
                .' umgemeldet.')
            ->line('Bei Fragen wenden Sie sich bitte an den Verband.');
    }
}
