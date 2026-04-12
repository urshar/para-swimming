<?php

namespace App\Livewire\Admin;

use App\Models\Club;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * UserManager
 *
 * Admin-seitiges CRUD für User-Verwaltung.
 * Zugriff nur für is_admin = true (per Route-Middleware gesichert).
 */
class UserManager extends Component
{
    use WithPagination;

    // ── Suchfeld ──────────────────────────────────────────────────────────────

    public string $search = '';

    // ── Formular-State ────────────────────────────────────────────────────────

    public bool $showModal = false;

    public bool $isEditing = false;

    public ?int $editingUserId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public bool $is_admin = false;

    public ?int $club_id = null;

    // ── Computed Properties ───────────────────────────────────────────────────

    #[Computed]
    public function users(): LengthAwarePaginator
    {
        return User::with('club')
            ->when($this->search, function ($q) {
                $q->where(function ($inner) {
                    $inner->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('name')
            ->paginate(20);
    }

    #[Computed]
    public function clubs(): Collection
    {
        return Club::orderBy('name')->get(['id', 'name', 'short_name']);
    }

    // ── Such-Reset bei Pagination ─────────────────────────────────────────────

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    // ── modal öffnen: Neu ─────────────────────────────────────────────────────

    public function openCreate(): void
    {
        $this->resetForm();
        $this->isEditing = false;
        $this->showModal = true;
    }

    // ── Speichern (neu + bearbeiten) ──────────────────────────────────────────

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
        $this->showModal = true;
    }

    // ── Löschen ───────────────────────────────────────────────────────────────

    public function save(): void
    {
        if ($this->isEditing) {
            $this->update();
        } else {
            $this->create();
        }
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    public function delete(int $userId): void
    {
        // Eigenen Account nicht löschbar
        if ($userId === auth()->id()) {
            session()->flash('error', 'Sie können Ihren eigenen Account nicht löschen.');

            return;
        }

        User::findOrFail($userId)->delete();
        session()->flash('success', 'Benutzer gelöscht.');
    }

    public function render(): View
    {
        return view('admin.users.index');
    }

    // ── modal öffnen: Bearbeiten ──────────────────────────────────────────────

    private function resetForm(): void
    {
        $this->editingUserId = null;
        $this->name = '';
        $this->email = '';
        $this->password = '';
        $this->is_admin = false;
        $this->club_id = null;
        $this->resetValidation();
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    private function update(): void
    {
        $user = User::findOrFail($this->editingUserId);

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$this->editingUserId],
            'password' => ['nullable', Password::defaults()],
            'is_admin' => ['boolean'],
            'club_id' => ['nullable', 'exists:clubs,id'],
        ];

        $data = $this->validate($rules);

        $updateData = [
            'name' => $data['name'],
            'email' => $data['email'],
            'is_admin' => $data['is_admin'],
            'club_id' => $data['is_admin'] ? null : $data['club_id'],
        ];

        if (! empty($data['password'])) {
            $updateData['password'] = Hash::make($data['password']);
        }

        $user->update($updateData);

        $this->closeModal();
        session()->flash('success', 'Benutzer erfolgreich aktualisiert.');
    }

    private function create(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
            'is_admin' => ['boolean'],
            'club_id' => ['nullable', 'exists:clubs,id'],
        ]);

        // Admin darf keinen Club haben (oder umgekehrt — beides erlaubt,
        // aber wir leeren club_id automatisch, wenn is_admin gesetzt ist)
        if ($data['is_admin']) {
            $data['club_id'] = null;
        }

        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_admin' => $data['is_admin'],
            'club_id' => $data['club_id'],
        ]);

        $this->closeModal();
        session()->flash('success', 'Benutzer erfolgreich angelegt.');
    }
}
