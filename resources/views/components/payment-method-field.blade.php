{{--
    Payment method (App\Enums\PaymentMethod) + its reference (bKash txn ID,
    cheque no., …), bound to two Livewire properties. The reference box only
    shows for methods that have one. Pick a method's matching "Paid from"
    account in the component's updated hook with PaymentMethod::pickAccount().

    @props method     Livewire property for the method value, e.g. "paymentMethod" or "pay.method"
    @props reference  Livewire property for the reference, e.g. "paymentReference"
    @props value      current method value (to label the reference box)
    @props label      field label
    @props cases      PaymentMethod cases to offer (default: PaymentMethod::manual())
    @props inputClass / labelClass  panel styling (defaults match the admin panel)
--}}
@props([
    'method',
    'reference',
    'value' => null,
    'label' => 'Payment Method',
    'cases' => null,
    'inputClass' => 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500',
    'labelClass' => 'block text-xs font-medium text-gray-600 mb-1.5',
])
@php
    $pmCurrent = \App\Enums\PaymentMethod::tryFrom((string) $value);
    $pmReferenceLabel = $pmCurrent?->referenceLabel();
@endphp
<div {{ $attributes->merge(['class' => 'grid grid-cols-1 sm:grid-cols-2 gap-4']) }}>
    <div>
        <label class="{{ $labelClass }}">{{ $label }} <span class="text-red-500">*</span></label>
        <select wire:model.live="{{ $method }}" class="{{ $inputClass }}">
            @foreach(\App\Enums\PaymentMethod::options($cases) as $pmValue => $pmLabel)
                <option value="{{ $pmValue }}">{{ $pmLabel }}</option>
            @endforeach
        </select>
        @error($method) <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
    </div>
    @if($pmReferenceLabel)
        <div>
            <label class="{{ $labelClass }}">{{ $pmReferenceLabel }}</label>
            <input wire:model="{{ $reference }}" type="text" maxlength="100" placeholder="{{ $pmReferenceLabel }}" class="{{ $inputClass }}">
            @error($reference) <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>
    @endif
</div>
