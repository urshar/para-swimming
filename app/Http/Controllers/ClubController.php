<?php

namespace App\Http\Controllers;

use App\Enums\Module;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Nation;
use App\Support\AuditHistory;
use App\Support\ClubFieldRules;
use App\Support\ListUrl;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ClubController extends Controller
{
    public function index(Request $request): View
    {
        $query = Club::with('nation')
            ->withCount([
                'athletes as athletes_active_count' => fn ($q) => $q->whereNull('status'),
                'athletes as athletes_inactive_count' => fn ($q) => $q->where('status', 'INACTIVE'),
            ])
            ->orderBy('name');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('code', 'like', '%'.$search.'%');
            });
        }

        if ($nationId = $request->query('nation_id')) {
            $query->where('nation_id', $nationId);
        }

        $clubs = $query->paginate(25)->withQueryString();
        $nations = Nation::orderBy('code')->get();

        return view('clubs.index', compact('clubs', 'nations'));
    }

    public function create(): View
    {
        $nations = Nation::active()->orderBy('code')->get();
        $locked = [];

        return view('clubs.form', compact('nations', 'locked'));
    }

    public function store(Request $request): RedirectResponse
    {
        $club = Club::create($this->validateClub($request, null, []));

        return redirect()
            ->route('clubs.show', $club)
            ->with('success', 'Club erfolgreich angelegt.');
    }

    public function show(Club $club): View
    {
        $club->load('nation');
        $club->loadCount(['athletes', 'entries', 'results']);

        // Athletenliste nur, wenn der Benutzer die Athleten dieses Vereins sehen darf (Spec club-permissions §4.8);
        // bei "eigener Verein" also nur auf der eigenen Vereinsseite. Die Zahlen oben bleiben für alle sichtbar.
        $user = auth()->user();
        $athletes = $user->canReadAll(Module::Athletes)
            || ($user->canRead(Module::Athletes) && $user->club_id === $club->id)
            ? $club->athletes()
                ->with('sportClasses')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->paginate(20)
            : null;

        // Verlauf aus dem Änderungsprotokoll nur für Admins (Spec club-permissions §4.7).
        $history = $user->is_admin
            ? AuditHistory::build(AuditLog::forClub($club), request()->query('verlauf') === 'alle')
            : null;

        return view('clubs.show', compact('club', 'athletes', 'history'));
    }

    public function edit(Request $request, Club $club): View
    {
        Gate::authorize('update', $club);
        $nations = Nation::active()->orderBy('code')->get();
        $locked = ClubFieldRules::for($request->user())->locked();

        return view('clubs.form', compact('club', 'nations', 'locked'));
    }

    public function update(Request $request, Club $club): RedirectResponse
    {
        Gate::authorize('update', $club);
        $club->update($this->validateClub($request, $club, ClubFieldRules::for($request->user())->locked()));

        return redirect()
            ->route('clubs.show', $club)
            ->with('success', 'Club aktualisiert.');
    }

    public function destroy(Club $club): RedirectResponse
    {
        // Prüfen ob Athleten zugeordnet sind
        if ($club->athletes()->exists()) {
            return back()->with('error', 'Club kann nicht gelöscht werden — es sind noch '.
                    $club->athletes()->count().' Athleten zugeordnet.');
        }

        $club->delete();

        return redirect()
            ->to(ListUrl::to('clubs'))
            ->with('success', 'Club gelöscht.');
    }

    /**
     * Gesperrte Felder (ClubFieldRules) fallen aus den Regeln und damit aus den Daten: Sie werden ignoriert, nicht
     * gespeichert. Der Code ist je Nation eindeutig, auch gegenüber gelöschten Vereinen (P7-2); verglichen wird in
     * der normalisierten Form, die Club::setCodeAttribute() speichert (P7-1).
     *
     * @param  array<string, string>  $locked
     * @return array<string, mixed>
     */
    private function validateClub(Request $request, ?Club $club, array $locked): array
    {
        $nationId = isset($locked['nation_id']) ? $club?->nation_id : $request->integer('nation_id');

        return $request->validate(array_diff_key([
            'name' => 'required|string|max:255',
            'short_name' => 'nullable|string|max:40',
            'code' => ['nullable', 'string', 'max:10', function (string $attribute, mixed $value, Closure $fail) use ($nationId, $club) {
                $holder = $nationId && is_string($value) ? Club::holderOfCode($value, $nationId, $club?->id) : null;
                if ($holder) {
                    $fail('Der Code ist in dieser Nation schon an "'.$holder->name.'"'
                        .($holder->trashed() ? ' (gelöscht)' : '').' vergeben.');
                }
            }],
            'nation_id' => 'required|exists:nations,id',
            'type' => 'required|in:CLUB,NATIONALTEAM,REGIONALTEAM,UNATTACHED,VERBAND',
            'regional_association' => 'nullable|in:'.implode(',', array_keys(Club::REGIONAL_ASSOCIATIONS)),
            'swrid' => 'nullable|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'address_street' => 'nullable|string|max:255',
            'address_zip' => 'nullable|string|max:20',
            'address_city' => 'nullable|string|max:255',
            'address_country' => 'nullable|exists:nations,code',
        ], $locked));
    }
}
