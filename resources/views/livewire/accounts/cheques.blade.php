{{-- Cheques — received cheques to deposit and issued cheques to honour, by date. --}}
@php
    $c = $this->cards;
    $rows = $this->rows;
    $badge = [
        'pending' => 'bg-[#f3f4f6] text-[#374151]', 'deposited' => 'bg-[#e0f2fe] text-[#075985]', 'soon' => 'bg-[#fef3c7] text-[#92400e]',
        'late' => 'bg-[#fee2e2] text-[#991b1b]', 'cleared' => 'bg-[#d1fae5] text-[#065f46]', 'bounced' => 'bg-[#fee2e2] text-[#991b1b]',
        'cancelled' => 'bg-[#f3f4f6] text-[#6b7280]',
    ];
    $input = 'w-full text-[14px] px-[12px] py-[9px] border border-[#d1d5db] rounded-[9px] bg-white';
    $lbl = 'text-[13px] font-semibold';
    $err = 'text-[12px] text-[#b91c1c]';
    $btn = 'rounded-[7px] border border-[#e5e7eb] bg-white px-[9px] py-[4px] text-[12px] font-semibold text-[#374151] hover:bg-[#f9fafb]';
    $dirCount = ['all' => $this->all->count(), 'received' => $this->all->where('direction', 'received')->count(), 'issued' => $this->all->where('direction', 'issued')->count()];
@endphp

<div class="acc-profiles box-border flex w-full max-w-[1480px] flex-col gap-[18px]">

    <x-accounts.page-head title="Cheque · পাওয়া ও দেওয়া" subtitle="Cheque clear না হওয়া পর্যন্ত বাকি / দেনা একই থাকে — clear করলে receipt / payment voucher হয়">
        @if ($canCreate)
            <button type="button" wire:click="openNew('issued')" class="rounded-[8px] border border-[#e5e7eb] bg-white px-[14px] py-[9px] text-[13px] text-[#374151]">+ Cheque দিলাম</button>
            <button type="button" wire:click="openNew('received')" class="rounded-[8px] bg-[#4f46e5] px-[16px] py-[9px] text-[13px] font-semibold text-white">+ Cheque পেলাম</button>
        @endif
    </x-accounts.page-head>

    <div class="grid gap-[14px] [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
        <x-accounts.stat label="হাতে থাকা cheque · Received" :value="$money($c['in'][0])" color="#047857" :sub="$c['in'][1]" />
        <x-accounts.stat label="৭ দিনে জমা দিতে হবে" :value="$money($c['week'][0])" :sub="$c['week'][1]" />
        <x-accounts.stat label="দেওয়া cheque · Issued" :value="$money($c['out'][0])" color="#b91c1c" :sub="$c['out'][1]" />
        <x-accounts.stat label="সেই bank-এ আছে" :value="$money($c['bank'][0])" :color="$c['bank'][2] ? '#b91c1c' : null" :sub="$c['bank'][1]" />
        <x-accounts.stat label="Bounced" :value="$c['bounced'][0]" color="#b45309" :sub="$c['bounced'][1]" />
    </div>

    <section class="overflow-hidden rounded-[14px] border border-[#e5e7eb] bg-white">
        <div class="flex flex-wrap items-center justify-between gap-[10px] border-b border-[#f0f1f4] px-[18px] py-[14px]">
            <div role="group" aria-label="Direction" class="flex flex-wrap gap-[6px]">
                @foreach ($this::DIRS as $key => $label)
                    <x-accounts.chip :on="$dir === $key" :count="$dirCount[$key]" wire:click="setDir('{{ $key }}')">{{ $label }}</x-accounts.chip>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-[6px]">
                <div role="group" aria-label="Status" class="flex flex-wrap gap-[6px]">
                    @foreach ($this::VIEWS as $key => $label)
                        <x-accounts.chip :on="$view === $key" wire:click="setView('{{ $key }}')">{{ $label }}</x-accounts.chip>
                    @endforeach
                </div>
                <input type="search" wire:model.live.debounce.300ms="q" placeholder="Cheque no, bank, party" aria-label="Search cheques" class="w-[200px] rounded-[9px] border border-[#d1d5db] px-[10px] py-[6px] text-[13px]">
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-[1000px] text-[13px]">
                <thead>
                    <tr class="text-left text-[11.5px] uppercase tracking-[0.04em] text-[#6b7280]">
                        <th class="py-[10px] pl-[18px] pr-[8px] font-semibold">Date</th>
                        <th class="px-[8px] py-[10px] font-semibold">Party</th>
                        <th class="px-[8px] py-[10px] font-semibold">Cheque</th>
                        <th class="px-[8px] py-[10px] text-right font-semibold">Amount</th>
                        <th class="px-[8px] py-[10px] font-semibold">Status</th>
                        <th class="px-[8px] py-[10px] font-semibold">Voucher</th>
                        <th class="px-[18px] py-[10px] text-right font-semibold"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $r)
                        @php [$st, $stText] = $this->badge($r); @endphp
                        <tr wire:key="cq-{{ $r->id }}" class="border-t border-[#f0f1f4] align-top">
                            <td class="whitespace-nowrap py-[11px] pl-[18px] pr-[8px]">
                                <div class="font-semibold">{{ $r->due_date->format('d M Y') }}</div>
                                @if (! $r->due_date->isSameDay($r->cheque_date))<div class="text-[11.5px] text-[#6b7280]">Cheque {{ $r->cheque_date->format('d M') }}</div>@endif
                            </td>
                            <td class="py-[11px] px-[8px]">
                                <div class="flex flex-wrap items-center gap-[6px]">
                                    @if ($r->master_profile_id)<a href="{{ route('accounts.profiles.show', $r->master_profile_id) }}" wire:navigate class="font-semibold !text-[#111827]">{{ $r->partyName() }}</a>@else<span class="font-semibold">{{ $r->partyName() }}</span>@endif
                                    <span @class(['rounded-[6px] px-[7px] py-[1px] text-[11px] font-semibold', 'bg-[#d1fae5] text-[#065f46]' => $r->isReceived(), 'bg-[#fee2e2] text-[#991b1b]' => ! $r->isReceived()])>{{ $r->isReceived() ? 'পেলাম' : 'দিলাম' }}</span>
                                </div>
                                @if ($r->orderPayment)
                                    <div class="text-[11.5px]">@if ($hasAdmin)<a href="{{ route('admin.sales.orders.show', $r->orderPayment->order_id) }}" class="!text-[#4338ca]">Order #{{ $r->orderPayment->order_id }}</a>@else Order #{{ $r->orderPayment->order_id }}@endif</div>
                                @endif
                                @if ($r->note)<div class="max-w-[260px] truncate text-[11.5px] text-[#6b7280]" title="{{ $r->note }}">{{ $r->note }}</div>@endif
                            </td>
                            <td class="px-[8px] py-[11px]">
                                <div class="font-semibold">#{{ $r->cheque_no }}</div>
                                <div class="text-[11.5px] text-[#6b7280]">{{ trim(($r->bank_name ?: 'Bank') . ($r->branch ? ", {$r->branch}" : '')) }} · {{ $r->account?->name ?? '—' }}</div>
                            </td>
                            <td class="whitespace-nowrap px-[8px] py-[11px] text-right font-bold">{{ $money($r->amount) }}</td>
                            <td class="px-[8px] py-[11px]">
                                <span class="whitespace-nowrap rounded-[6px] px-[8px] py-[3px] text-[12px] font-semibold {{ $badge[$st] ?? $badge['pending'] }}">{{ $stText }}</span>
                                @if ($r->status === 'bounced' && $r->bounce_reason)<div class="mt-[3px] text-[11.5px] text-[#991b1b]">{{ $r->bounce_reason }}</div>@endif
                                @if ($r->bounce_count && $r->status !== 'bounced')<div class="mt-[3px] text-[11.5px] text-[#b45309]">Bounced {{ $r->bounce_count }}×</div>@endif
                            </td>
                            <td class="whitespace-nowrap px-[8px] py-[11px]">
                                @if ($r->entry)
                                    <div class="font-semibold">{{ $r->entry->ref() }}</div>
                                    <div class="text-[11.5px] {{ $r->entry->status->isPending() ? 'text-[#b45309]' : 'text-[#047857]' }}">{{ $r->entry->status->isPending() ? 'Posting Pending' : $r->entry->status->label() }}</div>
                                @elseif ($r->status === 'cleared' && $r->order_payment_id)
                                    <span class="text-[11.5px] text-[#047857]">Order payment paid</span>
                                @elseif ($r->status === 'cleared')
                                    <span class="text-[11.5px] text-[#b91c1c]">Voucher deleted</span>
                                @else
                                    <span class="text-[#9ca3af]">—</span>
                                @endif
                            </td>
                            <td class="px-[18px] py-[11px]">
                                @if ($canCreate)
                                    <div class="flex flex-wrap justify-end gap-[6px]">
                                        @if ($r->isOpen())
                                            @if ($r->isReceived() && $r->status === 'pending')
                                                <button type="button" wire:click="deposit({{ $r->id }})" class="{{ $btn }}">Deposit</button>
                                            @endif
                                            <button type="button" wire:click="openAction({{ $r->id }}, 'clear')" class="rounded-[7px] border-0 bg-[#4f46e5] px-[10px] py-[4px] text-[12px] font-semibold text-white">Clear</button>
                                            <button type="button" wire:click="openAction({{ $r->id }}, 'reschedule')" class="{{ $btn }}">Reschedule</button>
                                            <button type="button" wire:click="openAction({{ $r->id }}, 'bounce')" class="{{ $btn }} !text-[#b91c1c]">Bounce</button>
                                            <button type="button" wire:click="openAction({{ $r->id }}, 'edit')" class="{{ $btn }}">Edit</button>
                                            <button type="button" wire:click="openAction({{ $r->id }}, 'cancel')" class="{{ $btn }}">Cancel</button>
                                        @elseif ($r->status === 'bounced')
                                            <button type="button" wire:click="openAction({{ $r->id }}, 'reschedule')" class="{{ $btn }}">Present again</button>
                                            <button type="button" wire:click="openAction({{ $r->id }}, 'edit')" class="{{ $btn }}">Edit</button>
                                            <button type="button" wire:click="openAction({{ $r->id }}, 'cancel')" class="{{ $btn }}">Cancel</button>
                                        @elseif ($r->status === 'cleared' && (! $r->entry || $r->entry->status->isPending()))
                                            <button type="button" wire:click="undoClear({{ $r->id }})" wire:confirm="Clear undo করবেন? Posting Pending-এর voucher মুছে যাবে।" class="{{ $btn }}">Undo clear</button>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($rows->isEmpty())
            <div class="px-[18px] py-[32px] text-center text-[#6b7280]">এই filter-এ কোনো cheque নেই।</div>
        @endif
    </section>

    {{-- + Cheque --}}
    @if ($showNew)
        @php $p = $this->picked; $issued = ($new['direction'] ?? 'received') === 'issued'; @endphp
        <x-accounts.modal :title="$issued ? '+ Cheque দিলাম · Issued' : '+ Cheque পেলাম · Received'" close="$set('showNew', false)" width="max-w-[600px]">
            <div class="flex flex-wrap gap-[6px]">
                @foreach (\App\Models\Cheque::DIRECTIONS as $k => $label)
                    <x-accounts.chip :on="$new['direction'] === $k" wire:click="$set('new.direction', '{{ $k }}')">{{ $label }}</x-accounts.chip>
                @endforeach
            </div>

            <div class="flex flex-col gap-[6px]">
                <span class="{{ $lbl }}">{{ $issued ? 'কাকে দিলাম · Paid to' : 'কার কাছ থেকে · From' }}</span>
                @if ($p)
                    <div class="flex items-center justify-between gap-3 rounded-[9px] border border-[#c7d2fe] bg-[#eef2ff] px-[12px] py-[8px]">
                        <div class="min-w-0"><div class="truncate font-semibold">{{ $p->display_name }}</div><div class="text-[11.5px] text-[#6b7280]">{{ $p->code }} · {{ $p->roles->pluck('role')->map(fn ($r) => ucfirst($r))->implode(', ') ?: 'No role' }}</div></div>
                        <button type="button" wire:click="clearParty" class="text-[12.5px] font-semibold text-[#4338ca]">Change</button>
                    </div>
                @else
                    <div class="grid grid-cols-1 gap-[8px] sm:grid-cols-2">
                        <div class="relative">
                            <input type="search" wire:model.live.debounce.300ms="partySearch" placeholder="Profile খুঁজুন (নাম / phone)" aria-label="Find profile" class="{{ $input }}">
                            @if ($this->partyMatches)
                                <div class="absolute z-20 mt-1 w-full overflow-hidden rounded-[9px] border border-[#e5e7eb] bg-white shadow-lg">
                                    @foreach ($this->partyMatches as $m)
                                        <button type="button" wire:key="cqp-{{ $m['id'] }}" wire:click="pickParty({{ $m['id'] }})" class="flex w-full justify-between gap-2 border-t border-[#f0f1f4] px-[12px] py-[7px] text-left text-[13px] first:border-t-0 hover:bg-[#f9fafb]">
                                            <span class="font-semibold">{{ $m['name'] }}</span><span class="text-[11.5px] text-[#6b7280]">{{ $m['meta'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <input type="text" wire:model="new.party_label" placeholder="অথবা নাম লিখুন" aria-label="Party name" class="{{ $input }}">
                    </div>
                @endif
                @error('new.party_label')<span class="{{ $err }}">{{ $message }}</span>@enderror
            </div>

            <div class="grid grid-cols-1 gap-[12px] sm:grid-cols-2">
                <div class="flex flex-col gap-[6px]"><label for="cq-no" class="{{ $lbl }}">Cheque no. <span class="text-[#b91c1c]">*</span></label><input id="cq-no" type="text" maxlength="60" wire:model="new.cheque_no" class="{{ $input }}">@error('new.cheque_no')<span class="{{ $err }}">{{ $message }}</span>@enderror</div>
                <div class="flex flex-col gap-[6px]"><label for="cq-amt" class="{{ $lbl }}">Amount (৳) <span class="text-[#b91c1c]">*</span></label><input id="cq-amt" type="number" min="0" step="0.01" wire:model="new.amount" class="{{ $input }}">@error('new.amount')<span class="{{ $err }}">{{ $message }}</span>@enderror</div>
                <div class="flex flex-col gap-[6px]"><label for="cq-bank" class="{{ $lbl }}">Bank</label><input id="cq-bank" type="text" maxlength="120" wire:model="new.bank_name" placeholder="যেমন: DBBL" class="{{ $input }}"></div>
                <div class="flex flex-col gap-[6px]"><label for="cq-br" class="{{ $lbl }}">Branch</label><input id="cq-br" type="text" maxlength="120" wire:model="new.branch" class="{{ $input }}"></div>
                <div class="flex flex-col gap-[6px]"><label for="cq-date" class="{{ $lbl }}">Cheque-এর তারিখ <span class="text-[#b91c1c]">*</span></label><input id="cq-date" type="text" readonly placeholder="YYYY-MM-DD" wire:model="new.cheque_date" class="flatpickr-only-date cursor-pointer bg-white {{ $input }}">@error('new.cheque_date')<span class="{{ $err }}">{{ $message }}</span>@enderror</div>
                <div class="flex flex-col gap-[6px]"><label for="cq-due" class="{{ $lbl }}">{{ $issued ? 'Release-এর তারিখ' : 'তোলার / জমার তারিখ' }}</label><input id="cq-due" type="text" readonly placeholder="Cheque-এর তারিখই" wire:model="new.due_date" class="flatpickr-only-date cursor-pointer bg-white {{ $input }}">@error('new.due_date')<span class="{{ $err }}">{{ $message }}</span>@enderror</div>
                <div class="flex flex-col gap-[6px]"><label for="cq-acc" class="{{ $lbl }}">{{ $issued ? 'যে account থেকে কাটবে' : 'যে account-এ জমা হবে' }}</label>
                    <select id="cq-acc" wire:model="new.account_id" class="{{ $input }}">@foreach ($this->cashAccounts as $a)<option value="{{ $a['id'] }}">{{ $a['name'] }} · {{ $money($a['balance']) }}</option>@endforeach</select>@error('new.account_id')<span class="{{ $err }}">{{ $message }}</span>@enderror</div>
                <div class="flex flex-col gap-[6px]"><label for="cq-led" class="{{ $lbl }}">{{ $issued ? 'হিসাব · Debit to' : 'হিসাব · Credit to' }}</label>
                    <select id="cq-led" wire:model="new.ledger_id" class="{{ $input }}"><option value="">Select</option>@foreach ($this->ledgers as $group => $items)<optgroup label="{{ $group }}">@foreach ($items as $a)<option value="{{ $a['id'] }}">{{ $a['name'] }}</option>@endforeach</optgroup>@endforeach</select>@error('new.ledger_id')<span class="{{ $err }}">{{ $message }}</span>@enderror</div>
                <div class="flex flex-col gap-[6px] sm:col-span-2"><label for="cq-note" class="{{ $lbl }}">Note</label><input id="cq-note" type="text" maxlength="255" wire:model="new.note" placeholder="optional" class="{{ $input }}"></div>
            </div>
            <div class="text-[12.5px] text-[#6b7280]">{{ $issued ? 'Release-এর ২ দিন আগে থেকে প্রতিদিন reminder যাবে।' : 'তারিখের আগের দিন reminder যাবে।' }} Clear না করা পর্যন্ত {{ $issued ? 'দেনা' : 'বাকি' }} কমবে না।</div>
            <x-slot:footer>
                <button type="button" wire:click="$set('showNew', false)" class="rounded-[9px] border border-[#e5e7eb] bg-white px-[16px] py-[10px] text-[14px] text-[#374151]">Cancel</button>
                <button type="button" wire:click="saveNew" wire:loading.attr="disabled" wire:target="saveNew" class="rounded-[9px] border-0 bg-[#4f46e5] px-[20px] py-[10px] text-[14px] font-bold text-white disabled:opacity-60">Save cheque</button>
            </x-slot:footer>
        </x-accounts.modal>
    @endif

    {{-- Row action --}}
    @if ($action && ($ac = $this->acting))
        <x-accounts.modal :title="$this::ACTIONS[$action] . ' · #' . $ac->cheque_no" close="closeAction">
            <div class="text-[13px] text-[#374151]">{{ $ac->partyName() }} · {{ $money($ac->amount) }} · {{ $ac->isReceived() ? 'পাওয়া' : 'দেওয়া' }} cheque · {{ $ac->due_date->format('d M Y') }}</div>

            @if ($action === 'clear')
                <div class="grid grid-cols-1 gap-[12px] sm:grid-cols-2">
                    <div class="flex flex-col gap-[6px]"><label for="ca-date" class="{{ $lbl }}">Clear-এর তারিখ</label><input id="ca-date" type="text" readonly placeholder="YYYY-MM-DD" wire:model="act.date" class="flatpickr-only-date cursor-pointer bg-white {{ $input }}">@error('act.date')<span class="{{ $err }}">{{ $message }}</span>@enderror</div>
                    <div class="flex flex-col gap-[6px]"><label for="ca-acc" class="{{ $lbl }}">{{ $ac->isReceived() ? 'জমা হলো' : 'কাটা হলো' }}</label>
                        <select id="ca-acc" wire:model="act.account_id" class="{{ $input }}">@foreach ($this->cashAccounts as $a)<option value="{{ $a['id'] }}">{{ $a['name'] }} · {{ $money($a['balance']) }}</option>@endforeach</select>@error('act.account_id')<span class="{{ $err }}">{{ $message }}</span>@enderror</div>
                </div>
                @if ($canPost)
                    <label class="flex items-center gap-[8px] text-[13px]"><input type="checkbox" wire:model="act.post" class="rounded border-[#d1d5db]"> এখনই post করুন (না হলে Posting Pending-এ যাবে)</label>
                @endif
                <div class="text-[12.5px] text-[#6b7280]">{{ $ac->isReceived() ? 'Receipt voucher (Dr bank / Cr ' . ($ac->ledger?->name ?? 'ledger') . ')' : 'Payment voucher (Dr ' . ($ac->ledger?->name ?? 'ledger') . ' / Cr bank)' }} হবে — post হলে {{ $ac->isReceived() ? 'বাকি' : 'দেনা' }} কমবে।</div>
            @elseif ($action === 'bounce')
                <div class="grid grid-cols-1 gap-[12px] sm:grid-cols-2">
                    <div class="flex flex-col gap-[6px]"><label for="ca-bdate" class="{{ $lbl }}">Bounce-এর তারিখ</label><input id="ca-bdate" type="text" readonly placeholder="YYYY-MM-DD" wire:model="act.date" class="flatpickr-only-date cursor-pointer bg-white {{ $input }}">@error('act.date')<span class="{{ $err }}">{{ $message }}</span>@enderror</div>
                    <div class="flex flex-col gap-[6px]"><label for="ca-why" class="{{ $lbl }}">কারণ</label><input id="ca-why" type="text" maxlength="200" wire:model="act.reason" placeholder="যেমন: Insufficient fund" class="{{ $input }}">@error('act.reason')<span class="{{ $err }}">{{ $message }}</span>@enderror</div>
                </div>
                <div class="grid grid-cols-1 gap-[12px] sm:grid-cols-2">
                    <div class="flex flex-col gap-[6px]"><label for="ca-charge" class="{{ $lbl }}">Bank charge (৳)</label><input id="ca-charge" type="number" min="0" step="0.01" wire:model="act.bank_charge" placeholder="0" class="{{ $input }}">
                        <span class="text-[11.5px] text-[#6b7280]">Bank যা কেটেছে — expense draft হবে</span>@error('act.bank_charge')<span class="{{ $err }}">{{ $message }}</span>@enderror</div>
                    @if ($ac->isReceived() && $ac->master_profile_id)
                        <div class="flex flex-col gap-[6px]"><label for="ca-fee" class="{{ $lbl }}">Customer-এর কাছে fee (৳)</label><input id="ca-fee" type="number" min="0" step="0.01" wire:model="act.fee" placeholder="0" class="{{ $input }}">
                            <span class="text-[11.5px] text-[#6b7280]">বাকিতে যোগ হবে — journal draft</span>@error('act.fee')<span class="{{ $err }}">{{ $message }}</span>@enderror</div>
                    @endif
                </div>
                <div class="text-[12.5px] text-[#6b7280]">{{ $ac->isReceived() ? 'Customer' : 'Supplier' }}-কে SMS / email আর owner / accountant-কে alert যাবে (Notification Center-এর rule অনুযায়ী)। বাকি / দেনা যেমন ছিল তেমনই থাকবে{{ $ac->order_payment_id ? '; order-এর payment failed হবে' : '' }}।</div>
            @elseif ($action === 'reschedule')
                <div class="flex flex-col gap-[6px]"><label for="ca-new" class="{{ $lbl }}">নতুন তারিখ</label><input id="ca-new" type="text" readonly placeholder="YYYY-MM-DD" wire:model="act.date" class="flatpickr-only-date cursor-pointer bg-white {{ $input }}">@error('act.date')<span class="{{ $err }}">{{ $message }}</span>@enderror</div>
                @if ($ac->status === 'bounced')<div class="text-[12.5px] text-[#6b7280]">Cheque আবার জমা দেওয়ার জন্য pending-এ ফিরবে।</div>@endif
            @elseif ($action === 'edit')
                <div class="grid grid-cols-1 gap-[12px] sm:grid-cols-2">
                    <div class="flex flex-col gap-[6px]"><label for="ce-no" class="{{ $lbl }}">Cheque no.</label><input id="ce-no" type="text" maxlength="60" wire:model="act.cheque_no" class="{{ $input }}">@error('act.cheque_no')<span class="{{ $err }}">{{ $message }}</span>@enderror</div>
                    <div class="flex flex-col gap-[6px]"><label for="ce-amt" class="{{ $lbl }}">Amount (৳)</label><input id="ce-amt" type="number" min="0" step="0.01" wire:model="act.amount" class="{{ $input }}">@error('act.amount')<span class="{{ $err }}">{{ $message }}</span>@enderror</div>
                    <div class="flex flex-col gap-[6px]"><label for="ce-bank" class="{{ $lbl }}">Bank</label><input id="ce-bank" type="text" maxlength="120" wire:model="act.bank_name" class="{{ $input }}"></div>
                    <div class="flex flex-col gap-[6px]"><label for="ce-date" class="{{ $lbl }}">Cheque-এর তারিখ</label><input id="ce-date" type="text" readonly placeholder="YYYY-MM-DD" wire:model="act.cheque_date" class="flatpickr-only-date cursor-pointer bg-white {{ $input }}">@error('act.cheque_date')<span class="{{ $err }}">{{ $message }}</span>@enderror</div>
                    <div class="flex flex-col gap-[6px] sm:col-span-2"><label for="ce-note" class="{{ $lbl }}">Note</label><input id="ce-note" type="text" maxlength="255" wire:model="act.note" class="{{ $input }}"></div>
                </div>
                <div class="text-[12.5px] text-[#6b7280]">তারিখ বদলালে reminder-এর তারিখও বদলাবে (আগে reschedule না করা থাকলে){{ $ac->order_payment_id ? '; order-এর pending payment-ও বদলাবে' : '' }}।</div>
            @else
                <div class="flex flex-col gap-[6px]"><label for="ca-cx" class="{{ $lbl }}">কারণ</label><input id="ca-cx" type="text" maxlength="200" wire:model="act.reason" placeholder="যেমন: ফেরত দেওয়া হয়েছে" class="{{ $input }}">@error('act.reason')<span class="{{ $err }}">{{ $message }}</span>@enderror</div>
            @endif

            <x-slot:footer>
                <button type="button" wire:click="closeAction" class="rounded-[9px] border border-[#e5e7eb] bg-white px-[16px] py-[10px] text-[14px] text-[#374151]">Cancel</button>
                <button type="button" wire:click="saveAction" wire:loading.attr="disabled" wire:target="saveAction" @class(['rounded-[9px] border-0 px-[20px] py-[10px] text-[14px] font-bold text-white disabled:opacity-60', 'bg-[#b91c1c]' => in_array($action, ['bounce', 'cancel'], true), 'bg-[#4f46e5]' => ! in_array($action, ['bounce', 'cancel'], true)])>Save</button>
            </x-slot:footer>
        </x-accounts.modal>
    @endif
</div>
