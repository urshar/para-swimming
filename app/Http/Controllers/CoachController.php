<?php

namespace App\Http\Controllers;

use App\Enums\CoachLevel;
use App\Enums\Module;
use App\Models\Club;
use App\Models\Coach;
use App\Models\CoachQualification;
use App\Support\ListUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Trainer der Vereine (Spec club-permissions §4.6, Phase 8). Lesen und Schreiben nach Modul "Trainer", bei "eigener
 * Verein" nur die Trainer des eigenen Vereins (CoachPolicy, Coach::scopeVisibleTo()). Aus- und Fortbildungen pflegt
 * CoachQualificationController auf der Detailseite.
 */
class CoachController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $level = CoachLevel::tryFrom((string) $request->query('level'));

        $query = Coach::visibleTo($user)
            ->with(['club', 'qualifications', 'user'])
            ->orderBy('last_name')
            ->orderBy('first_name');

        if ($search = $request->query('search')) {
            $query->where(fn (Builder $q) => $q->where('last_name', 'like', '%'.$search.'%')
                ->orWhere('first_name', 'like', '%'.$search.'%'));
        }
        if ($clubId = $request->integer('club_id')) {
            $query->where('club_id', $clubId);
        }
        if ($level !== null) {
            $this->whereLevel($query, $level);
        }
        if ($request->boolean('active')) {
            $query->where('is_active', true);
        }

        $coaches = $query->paginate(25)->withQueryString();
        $clubs = $user->canReadAll(Module::Coaches)
            ? Club::whereHas('coaches')->orderBy('name')->get(['id', 'name', 'short_name'])
            : collect();

        return view('coaches.index', compact('coaches', 'clubs', 'level'));
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Coach::class);
        $coach = new Coach(['is_active' => true, 'club_id' => $this->ownClubId($request) ?? $request->integer('club_id') ?: null]);
        $clubs = $this->selectableClubs($request);

        return view('coaches.form', compact('coach', 'clubs'));
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Coach::class);
        $data = $this->validateCoach($request);
        Gate::authorize('createForClub', [Coach::class, (int) $data['club_id']]);

        $coach = Coach::create($data);

        return redirect()->route('coaches.show', $coach)->with('success', 'Trainer angelegt.');
    }

    /** Den Verlauf zeigt die Vereinsseite (Spec §4.6: Verlauf am Verein), nicht der einzelne Trainer. */
    public function show(Coach $coach): View
    {
        Gate::authorize('view', $coach);
        $coach->load(['club', 'user', 'qualifications' => fn ($q) => $q->orderByDesc('completed_on')->orderByDesc('id')]);

        return view('coaches.show', compact('coach'));
    }

    public function edit(Request $request, Coach $coach): View
    {
        Gate::authorize('update', $coach);
        $clubs = $this->selectableClubs($request);

        return view('coaches.form', compact('coach', 'clubs'));
    }

    public function update(Request $request, Coach $coach): RedirectResponse
    {
        Gate::authorize('update', $coach);
        $data = $this->validateCoach($request);
        Gate::authorize('createForClub', [Coach::class, (int) $data['club_id']]);

        // Ein verknüpfter Benutzer gehört zum Verein des Trainers; den Verein erst nach Lösen der Verknüpfung ändern.
        if ($data['club_id'] !== $coach->club_id && $coach->user()->exists()) {
            return back()->withInput()->withErrors(['club_id' => 'Der Trainer ist mit einem Benutzer verknüpft; den Verein bitte zuerst in der Benutzerverwaltung ändern.']);
        }

        $coach->update($data);

        return redirect()->route('coaches.show', $coach)->with('success', 'Trainer gespeichert.');
    }

    public function destroy(Coach $coach): RedirectResponse
    {
        Gate::authorize('update', $coach);
        if ($coach->user()->exists()) {
            return back()->with('error', 'Der Trainer ist mit einem Benutzer verknüpft und kann nicht gelöscht werden – bitte stattdessen deaktivieren.');
        }

        $coach->delete();

        return redirect()->to(ListUrl::to('coaches'))->with('success', 'Trainer gelöscht.');
    }

    /** Höchste Ausbildung = $level (P8-2): eine Ausbildung dieser Stufe, keine höhere. */
    private function whereLevel(Builder $query, CoachLevel $level): void
    {
        $educations = fn (Builder $q, array $levels) => $q->where('kind', CoachQualification::KIND_EDUCATION)
            ->whereIn('level', $levels);
        $higher = array_map(
            fn (CoachLevel $l) => $l->value,
            array_values(array_filter(CoachLevel::trained(), fn (CoachLevel $l) => $l->rank() > $level->rank())),
        );

        if ($level === CoachLevel::None) {
            $query->whereDoesntHave('qualifications', fn (Builder $q) => $educations($q, array_map(
                fn (CoachLevel $l) => $l->value, CoachLevel::trained())));

            return;
        }

        $query->whereHas('qualifications', fn (Builder $q) => $educations($q, [$level->value]))
            ->when($higher !== [], fn (Builder $q) => $q->whereDoesntHave('qualifications', fn (Builder $w) => $educations($w, $higher)));
    }

    /** Verein des Benutzers, wenn er Trainer nur im eigenen Verein schreiben darf; sonst null (freie Auswahl). */
    private function ownClubId(Request $request): ?int
    {
        return $request->user()->canWriteAll(Module::Coaches) ? null : $request->user()->club_id;
    }

    /** @return Collection<int, Club> */
    private function selectableClubs(Request $request): Collection
    {
        return $this->ownClubId($request) === null
            ? Club::orderBy('name')->get(['id', 'name', 'short_name'])
            : collect();
    }

    /** @return array<string, mixed> */
    private function validateCoach(Request $request): array
    {
        $data = $request->validate([
            'club_id' => [Rule::requiredIf($this->ownClubId($request) === null), 'nullable', 'exists:clubs,id'],
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => Club::PHONE_RULES,
            'notes' => 'nullable|string|max:5000',
            'is_active' => 'boolean',
        ]);

        // Bei "eigener Verein" ist der Verein fest der eigene (wie beim Anlegen von Athleten).
        $data['club_id'] = $this->ownClubId($request) ?? (int) $data['club_id'];
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
