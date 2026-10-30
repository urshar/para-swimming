<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChangeRequest;
use App\Services\ChangeRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * Eingang der Anträge für Admins (Spec club-permissions §4.5, Phase 6): Liste nach Status, Detail mit Vorher/Nachher,
 * freigeben oder ablehnen. Die Routen liegen in der Admin-Gruppe (RequireAdmin).
 */
class ChangeRequestController extends Controller
{
    public function __construct(private readonly ChangeRequestService $service) {}

    public function index(Request $request): View
    {
        $status = $request->query('status', ChangeRequest::STATUS_OPEN);
        if ($status !== 'ALL' && ! array_key_exists($status, ChangeRequest::STATUS_LABELS)) {
            $status = ChangeRequest::STATUS_OPEN;
        }

        $changeRequests = ChangeRequest::with(['athlete', 'club'])
            ->when($status !== 'ALL', fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.change-requests.index', compact('changeRequests', 'status'));
    }

    public function show(ChangeRequest $changeRequest): View
    {
        $changeRequest->load(['athlete.club', 'club', 'fromClub']);

        return view('admin.change-requests.show', compact('changeRequest'));
    }

    /**
     * @throws Throwable
     */
    public function approve(Request $request, ChangeRequest $changeRequest): RedirectResponse
    {
        $this->ensureOpen($changeRequest);

        $validated = $request->validate([
            'response' => 'nullable|string|max:2000',
            'joined_at' => [Rule::requiredIf($changeRequest->type === ChangeRequest::TYPE_TRANSFER), 'nullable', 'date'],
        ]);

        $this->service->approve($changeRequest, $request->user(), $validated['response'] ?? null,
            $validated['joined_at'] ?? null);

        return redirect()
            ->route('admin.change-requests.index')
            ->with('success', $changeRequest->type_label.' für '.$changeRequest->athlete?->display_name.' freigegeben.');
    }

    /**
     * @throws ValidationException
     */
    public function reject(Request $request, ChangeRequest $changeRequest): RedirectResponse
    {
        $this->ensureOpen($changeRequest);

        // Beim Ablehnen ist eine Antwort an den Verein Pflicht (P6-3).
        $validated = $request->validate(['response' => 'required|string|max:2000'], [
            'response.required' => 'Bitte eine Antwort an den Verein angeben.',
        ]);

        $this->service->reject($changeRequest, $request->user(), $validated['response']);

        return redirect()
            ->route('admin.change-requests.index')
            ->with('success', $changeRequest->type_label.' für '.$changeRequest->athlete?->display_name.' abgelehnt.');
    }

    /**
     * @throws ValidationException
     */
    private function ensureOpen(ChangeRequest $changeRequest): void
    {
        if (! $changeRequest->isOpen()) {
            throw ValidationException::withMessages(['decision' => 'Dieser Antrag ist nicht mehr offen.']);
        }
    }
}
