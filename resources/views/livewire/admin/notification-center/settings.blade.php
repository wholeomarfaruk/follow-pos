<div x-data x-init="$store.pageName = { name: 'Notification Center · Settings', slug: 'notification-settings' }">

    @include('livewire.admin.notification-center.partials.tabs')

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
        <form wire:submit.prevent="save" class="bg-white rounded-2xl shadow-sm border border-gray-200">
            <div class="px-5 py-4 border-b border-gray-100">
                <h2 class="text-sm font-semibold text-gray-900">Sending</h2>
                <p class="text-xs text-gray-400">Applies to every rule and campaign. Gateways themselves are set up under Settings › Advance.</p>
            </div>
            <div class="p-5 space-y-5">
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model="s.enabled" @disabled(! $canManage) class="mt-0.5 rounded border-gray-300 text-indigo-600">
                    <span><span class="block text-sm font-medium text-gray-800">Notification Center on</span><span class="block text-xs text-gray-500">Off = no rule or campaign sends anything (login / OTP messages are separate and keep working).</span></span>
                </label>

                <div>
                    <p class="text-xs font-medium text-gray-600 mb-2">Channels</p>
                    <div class="flex flex-wrap gap-4">
                        @foreach ($channels as $ch => $label)
                            <label class="flex items-center gap-1.5 text-sm text-gray-700"><input type="checkbox" wire:model="s.channel_{{ $ch }}" @disabled(! $canManage) class="rounded border-gray-300 text-indigo-600"> {{ $label }}</label>
                        @endforeach
                    </div>
                </div>

                <div class="pt-4 border-t border-gray-100">
                    <label class="flex items-center gap-2 text-sm font-medium text-gray-800 mb-2">
                        <input type="checkbox" wire:model.live="s.quiet_enabled" @disabled(! $canManage) class="rounded border-gray-300 text-indigo-600"> Quiet hours
                    </label>
                    <p class="text-xs text-gray-500 mb-2">Reminders, alerts and offers by SMS / push created in this window wait until it ends. Transactional messages (order placed, payment received) still go at once.</p>
                    @if ($s['quiet_enabled'])
                        <div class="flex items-center gap-2">
                            <input wire:model="s.quiet_start" type="time" @disabled(! $canManage) class="rounded-lg border border-gray-300 px-3 py-2 text-sm">
                            <span class="text-sm text-gray-500">to</span>
                            <input wire:model="s.quiet_end" type="time" @disabled(! $canManage) class="rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        </div>
                        @error('s.quiet_start') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        @error('s.quiet_end') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    @endif
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-4 border-t border-gray-100">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">SMS gateway</label>
                        <select wire:model="s.sms_driver" @disabled(! $canManage) class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                            <option value="system">The active gateway (SMS settings)</option>
                            @foreach ($gateways as $g)
                                <option value="{{ $g['key'] }}">{{ $g['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Sender ID / mask <span class="text-gray-400">(optional)</span></label>
                        <input wire:model="s.sms_sender_id" type="text" @disabled(! $canManage) class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Daily SMS cap <span class="text-gray-400">(0 = no limit)</span></label>
                        <input wire:model="s.sms_daily_cap" type="number" min="0" @disabled(! $canManage) class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        <p class="text-[11px] text-gray-400 mt-1">Protects your SMS balance; over the cap, messages are skipped (see Outbox).</p>
                        @error('s.sms_daily_cap') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Batch size per minute</label>
                        <input wire:model="s.batch_size" type="number" min="10" max="1000" @disabled(! $canManage) class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        <p class="text-[11px] text-gray-400 mt-1">How many campaign / delayed messages go out each minute.</p>
                        @error('s.batch_size') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                @if ($canManage)
                    <div class="flex justify-end pt-2">
                        <button type="submit" class="px-5 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700">Save settings</button>
                    </div>
                @endif
            </div>
        </form>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
            <div class="px-5 py-4 border-b border-gray-100">
                <h2 class="text-sm font-semibold text-gray-900">Do-not-send list</h2>
                <p class="text-xs text-gray-400">Customers or leads who asked not to get messages. "Marketing" blocks offers and greetings only; "Everything" also blocks reminders and receipts.</p>
            </div>
            <div class="p-5 space-y-4">
                @if ($canManage)
                    <form wire:submit.prevent="addOptOut" class="flex flex-wrap items-start gap-2">
                        <div class="flex-1 min-w-[160px]">
                            <input wire:model="optPhone" type="text" placeholder="Phone 01XXXXXXXXX" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                            @error('optPhone') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <select wire:model="optChannel" class="rounded-lg border border-gray-300 px-3 py-2 text-sm">
                            <option value="*">Every channel</option>
                            @foreach ($channels as $ch => $label)
                                <option value="{{ $ch }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <select wire:model="optCategory" class="rounded-lg border border-gray-300 px-3 py-2 text-sm">
                            <option value="marketing">Marketing</option>
                            <option value="all">Everything</option>
                        </select>
                        <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700">Add</button>
                    </form>
                @endif

                <div class="divide-y divide-gray-100">
                    @forelse ($optOuts as $o)
                        <div class="flex items-center justify-between py-2.5 text-sm">
                            <div>
                                @if ($o->profile)
                                    <span class="text-gray-800">{{ $o->profile->display_name }}</span> <span class="text-xs text-gray-400">{{ $o->profile->code }} · {{ $o->profile->phone }}</span>
                                @elseif ($o->lead)
                                    <span class="text-gray-800">{{ $o->lead->name }}</span> <span class="text-xs text-gray-400">lead {{ $o->lead->code }} · {{ $o->lead->phone }}</span>
                                @else
                                    <span class="text-gray-400">(deleted)</span>
                                @endif
                                <span class="block text-xs text-gray-500">{{ $o->category === 'all' ? 'Everything' : 'Marketing' }} · {{ $o->channel === '*' ? 'every channel' : ($channels[$o->channel] ?? $o->channel) }}</span>
                            </div>
                            @if ($canManage)
                                <button wire:click="removeOptOut({{ $o->id }})" type="button" class="text-xs text-rose-600 hover:text-rose-800">Remove</button>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-gray-400 py-2">Nobody has opted out.</p>
                    @endforelse
                </div>
                @if ($optOuts->hasPages())
                    <div>{{ $optOuts->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</div>
