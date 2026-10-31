<?php

namespace App\Notifications;

use App\Models\ChangeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Entscheidung über einen Antrag — an den antragstellenden Benutzer (Spec club-permissions §4.5, N4). */
class ChangeRequestDecided extends Notification implements ShouldQueue
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
        $mail = (new MailMessage)
            ->subject('Antrag '.$request->status_label.': '.$request->type_label.' – '.$request->athlete?->display_name)
            ->greeting('Ihr Antrag wurde '.$request->status_label)
            ->line('Ihr Antrag auf '.$request->type_label.' für '.$request->athlete?->display_name.' wurde '
                .$request->status_label.'.');

        if ($request->response) {
            $mail->line('Antwort des Verbands: '.$request->response);
        }

        return $mail->action('Meine Anträge', route('change-requests.index'));
    }
}
