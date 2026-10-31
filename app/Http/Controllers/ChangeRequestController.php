<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\ChangeRequest;
use App\Services\ChangeRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Anträge aus Sicht des Vereins (Spec club-permissions §4.5, Phase 6): "Meine Anträge", Namensänderung und Ummeldung
 * beantragen, offene Anträge zurückziehen. Entschieden wird in Admin\ChangeRequestController.
 */
class ChangeRequestController extends Controller
{
    public function __construct(private readonly ChangeRequestService $service) {}

    public function index(Request $request): View
    {
        Gate::authorize('create', ChangeRequest::class);

        $changeRequests = ChangeRequest::where('club_id', $request->user()->club_id)
            ->with('athlete')
            ->latest()
            ->paginate(25);

        return view('change-requests.index', compact('changeRequests'));
    }

    public function createName(Athlete $athlete): View
    {
        Gate::authorize('requestName', [ChangeRequest::class, $athlete]);

        return view('change-requests.name', compact('athlete'));
    }

    /**
     * @throws ValidationException
     */
    public function storeName(Request $request, Athlete $athlete): RedirectResponse
    {
        Gate::authorize('requestName', [ChangeRequest::class, $athlete]);

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'name_prefix' => 'nullable|string|max:50',
            'reason' => 'required|string|max:2000',
        ]);

        $name = [
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'name_prefix' => $validated['name_prefix'] ?? null,
        ];
        if ($name === ['first_name' => $athlete->first_name, 'last_name' => $athlete->last_name, 'name_prefix' => $athlete->name_prefix]) {
            throw ValidationException::withMessages(['first_name' => 'Der Name ist unverändert.']);
        }

        $this->service->requestNameChange($request->user(), $athlete, $name, $validated['reason']);

        return redirect()
            ->route('athletes.show', $athlete)
            ->with('success', 'Antrag auf Namensänderung gestellt. Der Verband entscheidet darüber.');
    }

    public function createTransfer(): View
    {
        Gate::authorize('create', ChangeRequest::class);

        return view('change-requests.transfer');
    }

    /**
     * @throws ValidationException
     */
    public function storeTransfer(Request $request): RedirectResponse
    {
        Gate::authorize('create', ChangeRequest::class);

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'birth_date' => 'required|date',
            'joined_at' => 'required|date',
            'reason' => 'required|string|max:2000',
        ]);

        $this->service->requestTransfer($request->user(), $validated['first_name'], $validated['last_name'],
            $validated['birth_date'], $validated['joined_at'], $validated['reason']);

        return redirect()
            ->route('change-requests.index')
            ->with('success', 'Antrag auf Ummeldung von '.$validated['first_name'].' '.$validated['last_name']
                .' gestellt. Der Verband entscheidet darüber.');
    }

    public function withdraw(ChangeRequest $changeRequest): RedirectResponse
    {
        Gate::authorize('withdraw', $changeRequest);

        $this->service->withdraw($changeRequest);

        return redirect()
            ->route('change-requests.index')
            ->with('success', 'Antrag zurückgezogen.');
    }
}
