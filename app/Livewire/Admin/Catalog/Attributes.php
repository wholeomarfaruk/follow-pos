<?php

namespace App\Livewire\Admin\Catalog;

use App\Livewire\Traits\WithMediaPicker;
use App\Models\Attribute;
use App\Models\AttributeValue;
use Illuminate\Support\Str;
use Livewire\Component;

class Attributes extends Component
{
    use WithMediaPicker;

    public string $search = '';

    // create attribute
    public bool   $createModal = false;
    public string $newName     = '';
    public string $newSlug     = '';
    public string $newType     = 'select';
    public bool   $slugLocked  = false;

    // edit attribute
    public bool   $editModal = false;
    public ?int   $editingId = null;
    public string $editName  = '';
    public string $editSlug  = '';
    public string $editType  = 'select';
    public string $editStatus = 'active';

    // values manager
    public bool   $valuesModal      = false;
    public ?int   $valuesAttributeId = null;
    public string $newValueText     = '';
    public string $newValueSlug     = '';
    public string $newSwatchType    = '';
    public string $newSwatchColor   = '#000000';
    public $newSwatchImageId        = null;
    public bool   $valueSlugLocked  = false;

    // edit value (inline, within the values manager modal)
    public ?int   $editingValueId    = null;
    public string $editValueText     = '';
    public string $editValueSlug     = '';
    public string $editSwatchColor   = '#000000';
    public $editSwatchImageId        = null;

    public function updatedNewName(string $value): void
    {
        if (! $this->slugLocked) {
            $this->newSlug = Str::slug($value);
        }
    }

    public function updatedNewSlug(): void
    {
        $this->slugLocked = true;
    }

    public function updatedNewValueText(string $value): void
    {
        if (! $this->valueSlugLocked) {
            $this->newValueSlug = Str::slug($value);
        }
    }

    public function updatedNewValueSlug(): void
    {
        $this->valueSlugLocked = true;
    }

    public function reorder(array $orderedIds): void
    {
        foreach ($orderedIds as $index => $id) {
            Attribute::where('id', $id)->update(['sort_order' => $index + 1]);
        }
    }

    public function openCreateModal(): void
    {
        $this->reset(['newName', 'newSlug', 'newType', 'slugLocked']);
        $this->resetValidation();
        $this->createModal = true;
    }

    public function createAttribute(): void
    {
        // Unique among live attributes only — a removed one with this slug is restored below.
        $this->validate([
            'newName' => 'required|string|max:100',
            'newSlug' => 'required|string|max:120|unique:attributes,slug,NULL,id,deleted_at,NULL',
            'newType' => 'required|in:select,color,image,text',
        ]);

        $fields = ['name' => $this->newName, 'slug' => $this->newSlug, 'type' => $this->newType, 'status' => 'active'];

        // The slug stays taken by a removed attribute (unique index), so adding it again brings that one back.
        if ($attribute = Attribute::onlyTrashed()->where('slug', $this->newSlug)->first()) {
            $attribute->restore();
            $attribute->update($fields);
            $event = 'restored';
            $message = 'Attribute restored — its earlier values are back too';
        } else {
            $attribute = Attribute::create($fields);
            $event = 'created';
            $message = 'Attribute added successfully';
        }

        activity('catalog')
            ->causedBy(auth()->user())
            ->performedOn($attribute)
            ->event($event)
            ->log("Attribute \"{$attribute->name}\" was " . ($event === 'restored' ? 'restored' : 'added'));

        $this->createModal = false;
        $this->dispatch('toast', ['type' => 'success', 'message' => $message]);
    }

    public function restoreAttribute(int $id): void
    {
        $attribute = Attribute::onlyTrashed()->findOrFail($id);
        $attribute->restore();

        activity('catalog')
            ->causedBy(auth()->user())
            ->performedOn($attribute)
            ->event('restored')
            ->log("Attribute \"{$attribute->name}\" was restored");

        $this->dispatch('toast', ['type' => 'success', 'message' => "{$attribute->name} restored"]);
    }

    public function editAttribute(int $id): void
    {
        $attribute = Attribute::findOrFail($id);

        $this->editingId  = $attribute->id;
        $this->editName   = $attribute->name;
        $this->editSlug   = $attribute->slug;
        $this->editType   = $attribute->type;
        $this->editStatus = $attribute->status;
        $this->resetValidation();
        $this->editModal  = true;
    }

    public function updateAttribute(): void
    {
        $attribute = Attribute::findOrFail($this->editingId);

        $this->validate([
            'editName'   => 'required|string|max:100',
            'editSlug'   => 'required|string|max:120|unique:attributes,slug,' . $attribute->id . ',id,deleted_at,NULL',
            'editType'   => 'required|in:select,color,image,text',
            'editStatus' => 'required|in:active,inactive',
        ]);

        if (Attribute::onlyTrashed()->where('slug', $this->editSlug)->exists()) {
            $this->addError('editSlug', 'A removed attribute uses this slug — restore it from "Removed attributes" instead.');
            return;
        }

        $attribute->update([
            'name'   => $this->editName,
            'slug'   => $this->editSlug,
            'type'   => $this->editType,
            'status' => $this->editStatus,
        ]);

        activity('catalog')
            ->causedBy(auth()->user())
            ->performedOn($attribute)
            ->event('updated')
            ->log("Attribute \"{$attribute->name}\" was updated");

        $this->editModal = false;
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Attribute updated successfully']);
    }

    public function toggleStatus(int $id): void
    {
        $attribute = Attribute::findOrFail($id);
        $newStatus = $attribute->status === 'active' ? 'inactive' : 'active';
        $attribute->update(['status' => $newStatus]);

        $this->dispatch('toast', ['type' => 'success', 'message' => "{$attribute->name} is now {$newStatus}"]);
    }

    public function deleteAttribute(int $id): void
    {
        $attribute = Attribute::findOrFail($id);
        $name = $attribute->name;

        $attribute->delete();

        activity('catalog')
            ->causedBy(auth()->user())
            ->withProperties(['name' => $name])
            ->event('deleted')
            ->log("Attribute \"{$name}\" was deleted");

        $this->dispatch('toast', ['type' => 'success', 'message' => 'Attribute deleted']);
    }

    public function openValuesModal(int $attributeId): void
    {
        $this->valuesAttributeId = $attributeId;
        $this->resetValueForm();
        $this->cancelEditValue();
        $this->valuesModal = true;
    }

    protected function resetValueForm(): void
    {
        $this->reset(['newValueText', 'newValueSlug', 'newSwatchType', 'newSwatchImageId', 'valueSlugLocked']);
        $this->newSwatchColor = '#000000';
    }

    public function addValue(): void
    {
        $attribute = Attribute::findOrFail($this->valuesAttributeId);

        // Unique among this attribute's live values only — a removed one with this slug is restored below.
        $this->validate([
            'newValueText' => 'required|string|max:100',
            'newValueSlug' => 'required|string|max:120|unique:attribute_values,slug,NULL,id,attribute_id,' . $attribute->id . ',deleted_at,NULL',
        ]);

        $swatchType  = null;
        $swatchValue = null;

        if ($attribute->type === 'color') {
            $swatchType  = 'color';
            $swatchValue = $this->newSwatchColor;
        } elseif ($attribute->type === 'image') {
            $swatchType  = 'image';
            $swatchValue = $this->newSwatchImageId ?: null;
        }

        $fields = [
            'value'        => $this->newValueText,
            'swatch_type'  => $swatchType,
            'swatch_value' => $swatchValue,
            'sort_order'   => ($attribute->values()->max('sort_order') ?? 0) + 1,
        ];

        // The slug stays taken by a removed value (unique index), so adding it again brings that
        // row back — products that used it keep pointing at the same value.
        $removed = AttributeValue::onlyTrashed()->where('attribute_id', $attribute->id)->where('slug', $this->newValueSlug)->first();

        if ($removed) {
            $removed->restore();
            $removed->update($fields);
        } else {
            AttributeValue::create($fields + ['attribute_id' => $attribute->id, 'slug' => $this->newValueSlug]);
        }

        $this->resetValueForm();
        $this->dispatch('toast', ['type' => 'success', 'message' => $removed ? 'Value restored' : 'Value added']);
    }

    public function restoreValue(int $id): void
    {
        $value = AttributeValue::onlyTrashed()->where('attribute_id', $this->valuesAttributeId)->findOrFail($id);

        $value->restore();
        $value->update(['sort_order' => (AttributeValue::where('attribute_id', $value->attribute_id)->max('sort_order') ?? 0) + 1]);

        $this->dispatch('toast', ['type' => 'success', 'message' => "{$value->value} restored"]);
    }

    public function deleteValue(int $id): void
    {
        AttributeValue::where('attribute_id', $this->valuesAttributeId)->findOrFail($id)->delete();

        if ($this->editingValueId === $id) {
            $this->cancelEditValue();
        }

        $this->dispatch('toast', ['type' => 'success', 'message' => 'Value removed']);
    }

    public function editValue(int $id): void
    {
        $value = AttributeValue::findOrFail($id);

        $this->editingValueId   = $value->id;
        $this->editValueText    = $value->value;
        $this->editValueSlug    = $value->slug;
        $this->editSwatchColor  = $value->swatch_type === 'color' ? ($value->swatch_value ?: '#000000') : '#000000';
        $this->editSwatchImageId = $value->swatch_type === 'image' ? $value->swatch_value : null;
        $this->resetValidation();
    }

    public function cancelEditValue(): void
    {
        $this->reset(['editingValueId', 'editValueText', 'editValueSlug', 'editSwatchImageId']);
        $this->editSwatchColor = '#000000';
    }

    public function updateValue(): void
    {
        $value = AttributeValue::findOrFail($this->editingValueId);
        $attribute = $value->attribute;

        $this->validate([
            'editValueText' => 'required|string|max:100',
            'editValueSlug' => 'required|string|max:120|unique:attribute_values,slug,' . $value->id . ',id,attribute_id,' . $attribute->id . ',deleted_at,NULL',
        ]);

        if (AttributeValue::onlyTrashed()->where('attribute_id', $attribute->id)->where('slug', $this->editValueSlug)->exists()) {
            $this->addError('editValueSlug', 'A removed value uses this slug — restore it from "Removed values" below instead.');
            return;
        }

        $swatchType  = $value->swatch_type;
        $swatchValue = $value->swatch_value;

        if ($attribute->type === 'color') {
            $swatchType  = 'color';
            $swatchValue = $this->editSwatchColor;
        } elseif ($attribute->type === 'image') {
            $swatchType  = 'image';
            $swatchValue = $this->editSwatchImageId ?: null;
        }

        $value->update([
            'value'        => $this->editValueText,
            'slug'         => $this->editValueSlug,
            'swatch_type'  => $swatchType,
            'swatch_value' => $swatchValue,
        ]);

        $this->cancelEditValue();
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Value updated']);
    }

    public function reorderValues(array $orderedIds): void
    {
        foreach ($orderedIds as $index => $id) {
            AttributeValue::where('id', $id)->update(['sort_order' => $index + 1]);
        }
    }

    public function render(): mixed
    {
        $attributes = Attribute::query()
            ->withCount('values')
            ->when($this->search, fn($q) => $q->where(fn($s) => $s
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('slug', 'like', "%{$this->search}%")
            ))
            ->orderBy('name')
            ->get();

        $valuesAttribute = $this->valuesModal && $this->valuesAttributeId
            ? Attribute::with('values')->find($this->valuesAttributeId)
            : null;

        return view('livewire.admin.catalog.attributes', [
            'attributes'        => $attributes,
            'totalCount'        => Attribute::count(),
            'activeCount'       => Attribute::where('status', 'active')->count(),
            'valuesAttribute'   => $valuesAttribute,
            'removedValues'     => $valuesAttribute
                ? AttributeValue::onlyTrashed()->where('attribute_id', $valuesAttribute->id)->orderBy('value')->get()
                : collect(),
            'removedAttributes' => Attribute::onlyTrashed()->orderBy('name')->get(['id', 'name', 'slug', 'deleted_at']),
        ])->layout('layouts.admin.admin');
    }
}
