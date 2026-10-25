<?php

namespace App\Livewire\Admin\Users;

use App\Enums\Profiles\MasterProfileType;
use App\Models\Country;
use App\Models\MasterProfile as MasterProfileModel;
use App\Services\MasterProfileUuidGenerator;
use App\Support\PhoneNumber;
use Livewire\Component;
use Livewire\WithPagination;

class MasterProfile extends Component
{
    use WithPagination;

    public string $search       = '';
    public string $filterType   = '';
    public string $filterStatus = '';
    public string $filterRole   = '';

    protected string $paginationTheme = 'tailwind';

    // create
    public bool   $createModal    = false;
    public string $newType        = 'individual';
    public string $newDisplayName = '';
    public string $newCountryCode = '+880';
    public string $newPhone       = '';
    public string $newEmail       = '';
    public string $newNotes       = '';

    // A removed profile with the same phone / email: offer to bring it back instead.
    public ?int   $deletedMatchId = null;
    public bool   $createAnyway   = false;

    // edit
    public bool   $editModal       = false;
    public ?int   $editingId       = null;
    public string $editType        = 'individual';
    public string $editDisplayName = '';
    public string $editCountryCode = '+880';
    public string $editPhone       = '';
    public string $editEmail       = '';
    public string $editNotes       = '';
    public string $editStatus      = 'active';

    public function updatingSearch(): void       { $this->resetPage(); }
    public function updatingFilterType(): void   { $this->resetPage(); }
    public function updatingFilterStatus(): void { $this->resetPage(); }
    public function updatingFilterRole(): void   { $this->resetPage(); }

    public function openCreateModal(): void
    {
        $this->reset(['newType', 'newDisplayName', 'newCountryCode', 'newPhone', 'newEmail', 'newNotes', 'deletedMatchId', 'createAnyway']);
        $this->newType = MasterProfileType::INDIVIDUAL->value;
        $this->newCountryCode = Country::query()
            ->active()
            ->where('is_register_allowed', true)
            ->whereNotNull('phone_code')
            ->orderBy('sort_order')
            ->value('phone_code') ?? '+880';
        $this->resetValidation();
        $this->createModal = true;
    }

    public function createProfile(MasterProfileUuidGenerator $uuidGenerator): void
    {
        $this->validate([
            'newType'        => 'required|in:individual,organization',
            'newDisplayName' => 'required|string|max:150',
            'newCountryCode' => 'nullable|string|max:8',
            'newPhone'       => 'nullable|string|max:20',
            'newEmail'       => 'nullable|email|max:150',
            'newNotes'       => 'nullable|string',
        ]);

        $normalizedPhone = null;
        $countryCode = null;

        if ($this->newPhone !== '') {
            $isoCode = Country::query()->where('phone_code', $this->newCountryCode)->value('code');
            $normalized = PhoneNumber::normalize($this->newPhone, $isoCode);
            $countryCode = $normalized['country_code'];
            $normalizedPhone = $normalized['phone'];
        }

        // Same phone / email as a removed profile: restore that one, or create a new one on purpose.
        $deleted = null;
        if (! $this->createAnyway && ($normalizedPhone || $this->newEmail !== '')) {
            $deleted = MasterProfileModel::onlyTrashed()
                ->where(fn ($q) => $q->when($normalizedPhone, fn ($w) => $w->orWhere('phone', $normalizedPhone))
                    ->when($this->newEmail !== '', fn ($w) => $w->orWhere('email', $this->newEmail)))
                ->latest('deleted_at')->first();
        }

        if ($deleted) {
            $this->deletedMatchId = $deleted->id;
            $this->addError('newPhone', "আগে মোছা profile \"{$deleted->display_name}\" এই phone / email ব্যবহার করত — সেটা restore করুন, অথবা নতুন করেই তৈরি করুন।");
            return;
        }

        $profile = MasterProfileModel::create([
            'uuid'         => $uuidGenerator->generate(),
            'type'         => $this->newType,
            'display_name' => $this->newDisplayName,
            'country_code' => $countryCode,
            'phone'        => $normalizedPhone,
            'email'        => $this->newEmail ?: null,
            'notes'        => $this->newNotes ?: null,
            'status'       => 'active',
            'created_by'   => auth()->id(),
        ]);

        activity('users')
            ->causedBy(auth()->user())
            ->performedOn($profile)
            ->event('created')
            ->log("Master profile \"{$profile->display_name}\" was added");

        $this->createModal = false;
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Master profile added successfully']);
    }

    /** "নতুন করেই তৈরি করুন" after the removed-profile notice. */
    public function createNewAnyway(MasterProfileUuidGenerator $uuidGenerator): void
    {
        $this->createAnyway = true;
        $this->createProfile($uuidGenerator);
        $this->createAnyway = false;
    }

    /** Bring back the removed profile the create form matched. */
    public function restoreMatched(): void
    {
        if ($this->deletedMatchId) {
            $this->restoreProfile($this->deletedMatchId);
            $this->createModal = false;
        }
    }

    public function restoreProfile(int $id): void
    {
        $profile = MasterProfileModel::onlyTrashed()->findOrFail($id);
        $profile->restore();
        $this->deletedMatchId = null;

        activity('users')
            ->causedBy(auth()->user())
            ->performedOn($profile)
            ->event('restored')
            ->log("Master profile \"{$profile->display_name}\" was restored");

        $this->dispatch('toast', ['type' => 'success', 'message' => "{$profile->display_name} restored"]);
    }

    public function editProfile(int $id): void
    {
        $profile = MasterProfileModel::findOrFail($id);

        $this->editingId       = $profile->id;
        $this->editType        = $profile->type->value;
        $this->editDisplayName = $profile->display_name;
        $this->editCountryCode = $profile->country_code ?? '+880';
        $this->editPhone       = $profile->phone ?? '';
        $this->editEmail       = $profile->email ?? '';
        $this->editNotes       = $profile->notes ?? '';
        $this->editStatus      = $profile->status;
        $this->resetValidation();
        $this->editModal       = true;
    }

    public function updateProfile(): void
    {
        $profile = MasterProfileModel::findOrFail($this->editingId);

        $this->validate([
            'editType'        => 'required|in:individual,organization',
            'editDisplayName' => 'required|string|max:150',
            'editCountryCode' => 'nullable|string|max:8',
            'editPhone'       => 'nullable|string|max:20',
            'editEmail'       => 'nullable|email|max:150',
            'editNotes'       => 'nullable|string',
            'editStatus'      => 'required|in:active,inactive,blocked,archived',
        ]);

        $normalizedPhone = null;
        $countryCode = null;

        if ($this->editPhone !== '') {
            $isoCode = Country::query()->where('phone_code', $this->editCountryCode)->value('code');
            $normalized = PhoneNumber::normalize($this->editPhone, $isoCode);
            $countryCode = $normalized['country_code'];
            $normalizedPhone = $normalized['phone'];
        }

        $profile->update([
            'type'         => $this->editType,
            'display_name' => $this->editDisplayName,
            'country_code' => $countryCode,
            'phone'        => $normalizedPhone,
            'email'        => $this->editEmail ?: null,
            'notes'        => $this->editNotes ?: null,
            'status'       => $this->editStatus,
            'updated_by'   => auth()->id(),
        ]);

        activity('users')
            ->causedBy(auth()->user())
            ->performedOn($profile)
            ->event('updated')
            ->log("Master profile \"{$profile->display_name}\" was updated");

        $this->editModal = false;
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Master profile updated successfully']);
    }

    public function deleteProfile(int $id): void
    {
        $profile = MasterProfileModel::findOrFail($id);
        $name    = $profile->display_name;
        $profile->delete();

        activity('users')
            ->causedBy(auth()->user())
            ->withProperties(['name' => $name])
            ->event('deleted')
            ->log("Master profile \"{$name}\" was deleted");

        $this->dispatch('toast', ['type' => 'success', 'message' => 'Master profile deleted']);
    }

    public function render(): mixed
    {
        $profiles = MasterProfileModel::query()
            ->with('roles:id,master_profile_id,role')
            ->when(in_array($this->filterRole, MasterProfileModel::ROLES, true), fn($q) => $q->withRole($this->filterRole))
            ->when($this->search, fn($q) => $q->where(fn($s) => $s
                ->where('display_name', 'like', "%{$this->search}%")
                ->orWhere('uuid', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")
            ))
            ->when($this->filterType !== '', fn($q) => $q->where('type', $this->filterType))
            ->when($this->filterStatus === 'deleted', fn($q) => $q->onlyTrashed())
            ->when(! in_array($this->filterStatus, ['', 'deleted'], true), fn($q) => $q->where('status', $this->filterStatus))
            ->orderByDesc('id')
            ->paginate(15);

        $countries = Country::query()
            ->active()
            ->whereNotNull('phone_code')
            ->orderBy('sort_order')
            ->get(['code', 'name', 'phone_code']);

        return view('livewire.admin.users.master-profile', [
            'profiles'         => $profiles,
            'totalCount'       => MasterProfileModel::count(),
            'individualCount'  => MasterProfileModel::where('type', MasterProfileType::INDIVIDUAL)->count(),
            'organizationCount'=> MasterProfileModel::where('type', MasterProfileType::ORGANIZATION)->count(),
            'countries'        => $countries,
        ])->layout('layouts.admin.admin');
    }
}
