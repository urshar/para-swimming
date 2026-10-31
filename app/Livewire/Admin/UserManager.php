<?php

namespace App\Livewire\Admin;

use App\Enums\AccessScope;
use App\Enums\Module;
use App\Enums\RoleTemplate;
use App\Models\Club;
use App\Models\Coach;
use App\Models\User;
use App\Services\PermissionTemplateService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * UserManager
 *
 * Admin-seitige Benutzerverwaltung inkl. Rechte-Vorlage, Rechte-Matrix und 2FA-Reset (Spec club-permissions
 * Phase 2). Nur für Admins: die Route hat die Middleware "admin", Livewire-Aktionen laufen aber nicht über die
 * Route-Middleware der Seite, deshalb prüft boot() bei jedem Request selbst.
 */
class UserManager extends Component
{
    use WithPagination;

    /** Sentinel für "alle Vorlagen" im Filter (Flux liest value="" nicht zuverlässig). */
    private const string FILTER_ALL = 'ALL';

    /** Filterwert für Benutzer ohne gemerkte Vorlage (Admins und Altbestand). */
    private const string FILTER_NONE = 'NONE';

    // ── Suche und Filter ──────────────────────────────────────────────────────

    public string $search = '';

    public string $templateFilter = self::FILTER_ALL;

    // ── Formular-State ────────────────────────────────────────────────────────

    public bool $showModal = false;

    public bool $isEditing = false;

    public ?int $editingUserId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public bool $is_admin = false;

    public ?int $club_id = null;

    /** Verknüpfter Trainer (Spec club-permissions N7, P8-4); nur Trainer des gewählten Vereins. */
    public ?int $coach_id = null;

    public string $role_template = '';

    /** Vorlage beim Anlegen von Hand gewählt? Dann folgt sie nicht mehr dem Verein. */
    public bool $templateTouched = false;

    // ── Rechte-Matrix (Flyout) ────────────────────────────────────────────────

    public bool $showPermissions = false;

    public ?int $permissionsUserId = null;

    /** @var array<string, array{read: string, write: string}> */
    public array $matrix = [];

    // ── Vorlage für alle neu anwenden ─────────────────────────────────────────

    public bool $showReapply = false;

    public string $reapplyTemplate = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin() === true, 403);
    }

    // ── Computed Properties ───────────────────────────────────────────────────

    #[Computed]
    public function users(): LengthAwarePaginator
    {
        return User::with(['club', 'coach', 'modulePermissions'])
            ->when($this->search, function ($q) {
                $q->where(function ($inner) {
                    $inner->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->templateFilter === self::FILTER_NONE, fn ($q) => $q->whereNull('role_template'))
            ->when(RoleTemplate::tryFrom($this->templateFilter),
                fn ($q, RoleTemplate $t) => $q->where('role_template', $t->value))
            ->orderBy('name')
            ->paginate(20);
    }

    /** IDs der Benutzer auf der aktuellen Seite, deren Matrix von der gemerkten Vorlage abweicht. */
    #[Computed]
    public function customizedIds(): array
    {
        return $this->users->getCollection()
            ->filter(fn (User $user) => $this->service()->isCustomized($user))
            ->pluck('id')
            ->all();
    }

    /** @return SupportCollection<int, Club> */
    #[Computed]
    public function clubs(): SupportCollection
    {
        // Sortiert nach der angezeigten Bezeichnung (Kurzname, sonst Name).
        return Club::get(['id', 'name', 'short_name'])
            ->sortBy(fn (Club $club) => mb_strtolower($club->short_name ?: $club->name))
            ->values();
    }

    /** Aktive Trainer des gewählten Vereins, die noch frei oder mit diesem Benutzer verknüpft sind. */
    #[Computed]
    public function coaches(): Collection
    {
        if ($this->club_id === null) {
            return new Collection;
        }

        return Coach::where('club_id', $this->club_id)
            ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $this->coach_id))
            ->whereDoesntHave('user', fn ($q) => $q->whereKeyNot($this->editingUserId ?? 0))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    /** Ersetzt das Speichern die angepasste Matrix des bearbeiteten Benutzers? Dann fragt der Button nach. */
    #[Computed]
    public function templateWillOverwrite(): bool
    {
        if (! $this->isEditing || $this->is_admin) {
            return false;
        }

        $user = User::find($this->editingUserId);

        return $user !== null
            && $user->role_template?->value !== $this->role_template
            && $this->service()->isCustomized($user);
    }

    #[Computed]
    public function permissionsUser(): ?User
    {
        return $this->permissionsUserId === null ? null : User::find($this->permissionsUserId);
    }

    /** @return array{total: int, customized: int} */
    #[Computed]
    public function reapplyStats(): array
    {
        $template = RoleTemplate::tryFrom($this->reapplyTemplate);

        if ($template === null) {
            return ['total' => 0, 'customized' => 0];
        }

        $users = User::with('modulePermissions')
            ->where('role_template', $template->value)
            ->where('is_admin', false)
            ->get();

        return [
            'total' => $users->count(),
            'customized' => $users->filter(fn (User $user) => $this->service()->isCustomized($user))->count(),
        ];
    }

    // ── Such-/Filter-Reset bei Pagination ─────────────────────────────────────

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingTemplateFilter(): void
    {
        $this->resetPage();
    }

    // ── Vorlage folgt beim Anlegen dem Verein ─────────────────────────────────

    public function updatedClubId(): void
    {
        // Ein Trainer gehört zu genau einem Verein; ein Vereinswechsel löst die Verknüpfung.
        $this->coach_id = null;

        if (! $this->isEditing && ! $this->templateTouched) {
            $this->role_template = $this->defaultTemplate()->value;
        }
    }

    /** Mit einem Trainer verknüpft schlägt das Anlegen die Vorlage "Trainer" vor (P8-4). */
    public function updatedCoachId(): void
    {
        if (! $this->isEditing && ! $this->templateTouched) {
            $this->role_template = $this->defaultTemplate()->value;
        }
    }

    public function updatedRoleTemplate(): void
    {
        $this->templateTouched = true;
    }

    // ── Modal öffnen ──────────────────────────────────────────────────────────

    public function openCreate(): void
    {
        $this->resetForm();
        $this->isEditing = false;
        $this->showModal = true;
    }

    public function openEdit(int $userId): void
    {
        $user = User::findOrFail($userId);

        $this->resetForm();
        $this->isEditing = true;
        $this->editingUserId = $userId;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->is_admin = (bool) $user->is_admin;
        $this->club_id = $user->club_id;
        $this->coach_id = $user->coach_id;
        $this->role_template = $user->role_template?->value ?? $this->defaultTemplate()->value;
        $this->showModal = true;
    }

    // ── Speichern (neu + bearbeiten) ──────────────────────────────────────────

    /**
     * @throws Throwable
     */
    public function save(): void
    {
        if ($this->isEditing) {
            $this->update();
        } else {
            $this->create();
        }
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    // ── Löschen ───────────────────────────────────────────────────────────────

    public function delete(int $userId): void
    {
        // Eigenen Account nicht löschbar
        if ($userId === auth()->id()) {
            session()->now('error', 'Sie können Ihren eigenen Account nicht löschen.');

            return;
        }

        User::findOrFail($userId)->delete();
        session()->now('success', 'Benutzer gelöscht.');
    }

    // ── Zwei-Faktor zurücksetzen ──────────────────────────────────────────────

    public function resetTwoFactor(int $userId): void
    {
        // Den eigenen Account setzt ein anderer Admin zurück, im Notfall "php artisan users:reset-two-factor".
        if ($userId === auth()->id()) {
            session()->now('error', 'Ihre eigene Zwei-Faktor-Anmeldung setzt ein anderer Administrator zurück.');

            return;
        }

        User::findOrFail($userId)->resetTwoFactor();

        session()->now('success', 'Zwei-Faktor-Anmeldung zurückgesetzt.');
    }

    // ── Rechte-Matrix ─────────────────────────────────────────────────────────

    public function openPermissions(int $userId): void
    {
        $user = User::findOrFail($userId);

        if ($user->is_admin) {
            return;
        }

        $this->permissionsUserId = $userId;
        $this->loadMatrix($user);
        $this->resetValidation();
        $this->showPermissions = true;
    }

    /**
     * @throws Throwable
     */
    public function savePermissions(): void
    {
        $user = User::findOrFail($this->permissionsUserId);
        $matrix = [];

        foreach ($this->matrix as $key => $scopes) {
            $read = AccessScope::tryFrom($scopes['read'] ?? '');
            $write = AccessScope::tryFrom($scopes['write'] ?? '');

            if ($read === null || $write === null) {
                $this->addError("permissions.$key", 'Ungültiger Bereich.');

                return;
            }

            $matrix[$key] = ['read' => $read, 'write' => $write];
        }

        $this->service()->setPermissions($user, $matrix);

        $this->closePermissions();
        session()->now('success', "Rechte von $user->name gespeichert.");
    }

    /**
     * Überträgt die gemerkte Vorlage erneut auf diesen einen Benutzer (verwirft einzelne Abweichungen).
     *
     * @throws Throwable
     */
    public function reapplyForPermissionsUser(): void
    {
        $user = User::findOrFail($this->permissionsUserId);

        if ($user->role_template === null) {
            return;
        }

        $this->service()->applyTemplate($user, $user->role_template);
        $this->loadMatrix($user);
        $this->resetValidation();
        session()->now('success', "Vorlage \"{$user->role_template->label()}\" neu angewendet.");
    }

    public function closePermissions(): void
    {
        $this->showPermissions = false;
        $this->permissionsUserId = null;
        $this->matrix = [];
        $this->resetValidation();
    }

    // ── Vorlage für alle neu anwenden ─────────────────────────────────────────

    public function openReapply(): void
    {
        $this->reapplyTemplate = RoleTemplate::Club->value;
        $this->showReapply = true;
    }

    /**
     * @throws Throwable
     */
    public function confirmReapply(): void
    {
        $template = RoleTemplate::tryFrom($this->reapplyTemplate);

        if ($template === null) {
            $this->addError('reapplyTemplate', 'Bitte eine Vorlage wählen.');

            return;
        }

        $count = $this->service()->reapplyTemplate($template);

        $this->showReapply = false;
        session()->now('success', "Vorlage \"{$template->label()}\" auf $count Benutzer neu angewendet.");
    }

    public function render(): View
    {
        return view('admin.users.index', [
            'templates' => RoleTemplate::cases(),
            'modules' => Module::cases(),
            'filterAll' => self::FILTER_ALL,
            'filterNone' => self::FILTER_NONE,
        ]);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    private function resetForm(): void
    {
        $this->editingUserId = null;
        $this->name = '';
        $this->email = '';
        $this->password = '';
        $this->is_admin = false;
        $this->club_id = null;
        $this->coach_id = null;
        $this->role_template = RoleTemplate::Empty->value;
        $this->templateTouched = false;
        $this->resetValidation();
    }

    private function defaultTemplate(): RoleTemplate
    {
        return match (true) {
            $this->club_id === null => RoleTemplate::Empty,
            $this->coach_id !== null => RoleTemplate::Coach,
            default => RoleTemplate::Club,
        };
    }

    private function loadMatrix(User $user): void
    {
        $user->unsetRelation('modulePermissions');

        $this->matrix = array_map(
            fn (array $scopes) => ['read' => $scopes['read']->value, 'write' => $scopes['write']->value],
            $this->service()->matrixFor($user),
        );
    }

    private function service(): PermissionTemplateService
    {
        return app(PermissionTemplateService::class);
    }

    /** @return array<string, array<int, mixed>> */
    private function formRules(bool $passwordRequired): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingUserId)],
            'password' => [$passwordRequired ? 'required' : 'nullable', Password::defaults()],
            'is_admin' => ['boolean'],
            'club_id' => ['nullable', 'exists:clubs,id'],
            // Trainer des gewählten Vereins, höchstens ein Benutzer je Trainer (P8-4).
            'coach_id' => [
                'nullable',
                Rule::exists('coaches', 'id')->where('club_id', $this->club_id ?? 0),
                Rule::unique('users', 'coach_id')->ignore($this->editingUserId),
            ],
            'role_template' => $this->is_admin ? ['nullable'] : ['required', Rule::enum(RoleTemplate::class)],
        ];
    }

    /**
     * @throws Throwable
     */
    private function update(): void
    {
        $user = User::findOrFail($this->editingUserId);

        $data = $this->validate($this->formRules(false));

        // Selbstschutz: sich selbst die Admin-Rechte nehmen sperrt die Benutzerverwaltung aus.
        if ($user->id === auth()->id() && ! $data['is_admin']) {
            $this->addError('is_admin', 'Sie können sich selbst die Admin-Rechte nicht entziehen.');

            return;
        }

        $updateData = [
            'name' => $data['name'],
            'email' => $data['email'],
            'is_admin' => $data['is_admin'],
            'club_id' => $data['is_admin'] ? null : $data['club_id'],
            'coach_id' => $data['is_admin'] ? null : $data['coach_id'],
        ];

        if (! empty($data['password'])) {
            $updateData['password'] = Hash::make($data['password']);
        }

        // Admins brauchen keine Vorlage; die Matrix bleibt gespeichert, wirkt aber nicht.
        if ($data['is_admin']) {
            $updateData['role_template'] = null;
        }

        $user->update($updateData);

        // Nur eine geänderte Vorlage ersetzt die Matrix; sonst bleiben einzelne Abweichungen stehen.
        $template = $data['is_admin'] ? null : RoleTemplate::from($data['role_template']);

        if ($template !== null && $user->role_template !== $template) {
            $this->service()->applyTemplate($user, $template);
        }

        $this->closeModal();
        session()->now('success', 'Benutzer erfolgreich aktualisiert.');
    }

    /**
     * @throws Throwable
     */
    private function create(): void
    {
        $data = $this->validate($this->formRules(true));

        // Admin hat keinen Verein und keine Vorlage
        if ($data['is_admin']) {
            $data['club_id'] = null;
            $data['coach_id'] = null;
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_admin' => $data['is_admin'],
            'club_id' => $data['club_id'],
            'coach_id' => $data['coach_id'],
        ]);

        if (! $data['is_admin']) {
            $this->service()->applyTemplate($user, RoleTemplate::from($data['role_template']));
        }

        $this->closeModal();
        session()->now('success', 'Benutzer erfolgreich angelegt.');
    }
}
