<?php

namespace App\Services;

use App\Enums\Module;
use App\Models\Athlete;
use App\Models\ChangeRequest;
use App\Models\User;
use App\Notifications\AthleteTransferredAway;
use App\Notifications\ChangeRequestDecided;
use App\Notifications\ChangeRequestSubmitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Anträge der Vereine (Spec club-permissions §4.5, Phase 6): stellen, zurückziehen, freigeben, ablehnen — samt
 * E-Mails (N4, P6-1). Ob der Benutzer das darf, prüft vorher App\Policies\ChangeRequestPolicy; hier stehen die
 * fachlichen Regeln (ein offener Antrag je Art und Athlet, Ummeldung nur bei eindeutigem Treffer).
 */
final readonly class ChangeRequestService
{
    public function __construct(
        private ImportSuggestionService $suggestions,
        private AthleteClubTransferService $transfers,
    ) {}

    /**
     * @param  array{first_name: string, last_name: string, name_prefix: string|null}  $name
     *
     * @throws ValidationException
     */
    public function requestNameChange(User $user, Athlete $athlete, array $name, string $reason): ChangeRequest
    {
        return $this->submit($user, $athlete, ChangeRequest::TYPE_NAME, $name, $reason);
    }

    /**
     * Ummeldung zum eigenen Verein (N2). Der aufnehmende Verein sieht fremde Athleten nicht (F5) und findet den
     * Athleten nur über genaue Angaben; kein oder mehrere Treffer ergeben dieselbe neutrale Meldung (P6-2).
     *
     * @throws ValidationException
     */
    public function requestTransfer(User $user, string $firstName, string $lastName, string $birthDate,
        string $joinedAt, string $reason): ChangeRequest
    {
        $athlete = $this->transferCandidate($firstName, $lastName, $birthDate);

        if ($athlete === null) {
            throw ValidationException::withMessages([
                'athlete' => 'Kein eindeutiger Athlet gefunden – bitte Angaben prüfen oder an den Verband wenden.',
            ]);
        }
        if ($athlete->club_id === $user->club_id) {
            throw ValidationException::withMessages(['athlete' => 'Der Athlet gehört bereits zum eigenen Verein.']);
        }

        return $this->submit($user, $athlete, ChangeRequest::TYPE_TRANSFER, ['joined_at' => $joinedAt], $reason);
    }

    public function withdraw(ChangeRequest $request): void
    {
        $request->update(['status' => ChangeRequest::STATUS_WITHDRAWN]);
    }

    /**
     * Freigabe: übernimmt den Namen bzw. trägt den Vereinswechsel ein (Eintrittsdatum ggf. vom Admin geändert, P6-4).
     *
     * @throws Throwable
     */
    public function approve(ChangeRequest $request, User $admin, ?string $response, ?string $joinedAt): void
    {
        $athlete = $request->athlete;

        if ($athlete === null || $athlete->trashed()) {
            throw ValidationException::withMessages(['decision' => 'Der Athlet ist gelöscht — bitte ablehnen.']);
        }
        if ($request->type === ChangeRequest::TYPE_TRANSFER && $athlete->club_id === $request->club_id) {
            throw ValidationException::withMessages(['decision' => 'Der Athlet gehört bereits zum beantragenden Verein.']);
        }

        $fromClubId = $athlete->club_id;

        DB::transaction(function () use ($request, $admin, $response, $joinedAt, $athlete) {
            if ($request->type === ChangeRequest::TYPE_NAME) {
                $athlete->update([
                    'first_name' => $request->values['first_name'],
                    'last_name' => $request->values['last_name'],
                    'name_prefix' => $request->values['name_prefix'] ?? null,
                ]);
            } else {
                $date = $joinedAt ?? $request->values['joined_at'];
                $this->transfers->transfer($athlete, (int) $request->club_id, $date, 'Antrag #'.$request->id);
                $request->values = ['joined_at' => $date];
            }

            $this->decide($request, $admin, ChangeRequest::STATUS_APPROVED, $response);
        });

        $this->notifyDecision($request);

        if ($request->type === ChangeRequest::TYPE_TRANSFER && $fromClubId !== null) {
            Notification::send($this->athleteWriters($fromClubId), new AthleteTransferredAway($request));
        }
    }

    public function reject(ChangeRequest $request, User $admin, string $response): void
    {
        $this->decide($request, $admin, ChangeRequest::STATUS_REJECTED, $response);
        $this->notifyDecision($request);
    }

    /** Genau ein Athlet mit diesem Namen (normalisiert verglichen, wie beim Import) und Geburtsdatum, sonst null. */
    public function transferCandidate(string $firstName, string $lastName, string $birthDate): ?Athlete
    {
        $first = $this->suggestions->normalizeName($firstName);
        $last = $this->suggestions->normalizeName($lastName);

        $matches = Athlete::whereDate('birth_date', $birthDate)->get()
            ->filter(fn (Athlete $athlete) => $this->suggestions->normalizeName($athlete->first_name) === $first
                && $this->suggestions->normalizeName($athlete->last_name) === $last);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * @param  array<string, string|null>  $values
     *
     * @throws ValidationException
     */
    private function submit(User $user, Athlete $athlete, string $type, array $values, string $reason): ChangeRequest
    {
        $open = ChangeRequest::open()->where('athlete_id', $athlete->id)->where('type', $type)->exists();
        if ($open) {
            throw ValidationException::withMessages([
                'athlete' => 'Für diesen Athleten gibt es bereits einen offenen Antrag dieser Art. Ein neuer ist erst '
                    .'nach dem Zurückziehen möglich.',
            ]);
        }

        $request = ChangeRequest::create([
            'type' => $type,
            'athlete_id' => $athlete->id,
            'club_id' => $user->club_id,
            'from_club_id' => $athlete->club_id,
            'values' => $values,
            'reason' => $reason,
            'status' => ChangeRequest::STATUS_OPEN,
            'requested_by' => $user->id,
            'requested_by_name' => $user->name,
        ]);

        Notification::send(User::where('is_admin', true)->get(), new ChangeRequestSubmitted($request));

        return $request;
    }

    private function decide(ChangeRequest $request, User $admin, string $status, ?string $response): void
    {
        $request->fill([
            'status' => $status,
            'response' => $response,
            'decided_by' => $admin->id,
            'decided_by_name' => $admin->name,
            'decided_at' => now(),
        ])->save();
    }

    private function notifyDecision(ChangeRequest $request): void
    {
        $request->requester?->notify(new ChangeRequestDecided($request));
    }

    /** @return list<User> Benutzer eines Vereins mit "Athleten schreiben" (P6-1). */
    private function athleteWriters(int $clubId): array
    {
        return User::where('club_id', $clubId)->where('is_admin', false)->get()
            ->filter(fn (User $user) => $user->canWrite(Module::Athletes))
            ->values()
            ->all();
    }
}
