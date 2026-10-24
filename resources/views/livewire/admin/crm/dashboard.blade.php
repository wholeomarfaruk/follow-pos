<div x-data x-init="$store.pageName = { name: 'CRM', slug: 'crm-dashboard' }">

    @include('livewire.admin.crm.partials.tabs')

    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div class="flex rounded-lg border border-gray-300 overflow-hidden text-sm bg-white">
            @foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '1 year'] as $d => $label)
                <button type="button" wire:click="$set('days', {{ $d }})" class="px-3 py-2 {{ ! $loop->first ? 'border-l border-gray-300' : '' }} {{ $days === $d ? 'bg-indigo-600 text-white' : 'text-gray-600 hover:bg-gray-50' }}">{{ $label }}</button>
            @endforeach
        </div>
        <a href="{{ route('admin.crm.leads', ['new' => 1]) }}" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 shadow-sm">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
            Add lead
        </a>
    </div>

    {{-- KPIs --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
            <p class="text-xs text-gray-400">Open leads</p>
            <p class="text-2xl font-semibold text-gray-800 mt-0.5">{{ number_format($openCount) }}</p>
            <p class="text-xs text-gray-500 mt-1">৳{{ number_format($pipelineValue) }} pipeline · ৳{{ number_format($weightedValue) }} weighted</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
            <p class="text-xs text-gray-400">New in {{ $days }} days</p>
            <p class="text-2xl font-semibold text-gray-800 mt-0.5">{{ number_format($created) }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ $converted }} converted · {{ $lost }} lost</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
            <p class="text-xs text-gray-400">Win rate</p>
            <p class="text-2xl font-semibold text-emerald-600 mt-0.5">{{ $conversionRate === null ? '—' : $conversionRate . '%' }}</p>
            <p class="text-xs text-gray-500 mt-1">converted ÷ (converted + lost)</p>
        </div>
        <a href="{{ route('admin.crm.followups', ['tab' => $overdue ? 'overdue' : 'today']) }}" class="bg-white rounded-xl border {{ $overdue ? 'border-rose-200' : 'border-gray-200' }} px-4 py-3 hover:border-indigo-300 transition">
            <p class="text-xs text-gray-400">Follow-ups</p>
            <p class="text-2xl font-semibold mt-0.5 {{ $overdue ? 'text-rose-600' : 'text-gray-800' }}">{{ $dueToday }} <span class="text-sm font-normal text-gray-500">today</span></p>
            <p class="text-xs mt-1 {{ $overdue ? 'text-rose-600' : 'text-gray-500' }}">{{ $overdue }} overdue · {{ $noFollowUp }} open leads with none</p>
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {{-- Pipeline funnel --}}
        <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-gray-900">Pipeline (open leads)</h3>
                <a href="{{ route('admin.crm.leads', ['view' => 'board']) }}" class="text-xs font-medium text-indigo-600 hover:text-indigo-800">Open board →</a>
            </div>
            @php $max = max(1, $stageCounts->max() ?? 1); @endphp
            <div class="space-y-3">
                @foreach ($stages->reject->isClosed() as $s)
                    @php $n = $stageCounts[$s->id] ?? 0; @endphp
                    <a href="{{ route('admin.crm.leads', ['stage' => $s->id]) }}" class="block group">
                        <div class="flex items-center justify-between text-sm mb-1">
                            <span class="text-gray-700 group-hover:text-indigo-600">{{ $s->name }} <span class="text-xs text-gray-400">· {{ $s->probability }}%</span></span>
                            <span class="text-gray-500">{{ $n }} · ৳{{ number_format($stageValues[$s->id] ?? 0) }}</span>
                        </div>
                        <div class="h-2.5 rounded-full bg-gray-100 overflow-hidden">
                            <div class="h-full rounded-full {{ $s->dotClass() }}" style="width: {{ $n ? max(3, round($n / $max * 100)) : 0 }}%"></div>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>

        {{-- Lost reasons --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-gray-900 mb-4">Why leads were lost</h3>
            @forelse ($lostReasons as $r)
                <div class="flex items-center justify-between text-sm py-1.5">
                    <span class="text-gray-700 truncate">{{ $r->lost_reason }}</span>
                    <span class="text-gray-500">{{ $r->n }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-400">No lost leads in this period.</p>
            @endforelse
        </div>

        {{-- Sources --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-gray-900 mb-4">Where leads come from</h3>
            <table class="w-full text-sm">
                <thead><tr class="text-xs text-gray-400"><th class="text-left font-medium pb-2">Source</th><th class="text-right font-medium pb-2">Leads</th><th class="text-right font-medium pb-2">Customers</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($bySource as $row)
                        <tr>
                            <td class="py-1.5 text-gray-700">{{ \App\Models\CrmLead::SOURCES[$row->source] ?? ucfirst($row->source) }}</td>
                            <td class="py-1.5 text-right text-gray-600">{{ $row->total }}</td>
                            <td class="py-1.5 text-right text-emerald-600">{{ $row->won }}{{ $row->total ? ' (' . round($row->won / $row->total * 100) . '%)' : '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-3 text-gray-400">No leads in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- People --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-gray-900 mb-4">By assignee</h3>
            <table class="w-full text-sm">
                <thead><tr class="text-xs text-gray-400"><th class="text-left font-medium pb-2">Person</th><th class="text-right font-medium pb-2">Leads</th><th class="text-right font-medium pb-2">Converted</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($byUser as $row)
                        <tr>
                            <td class="py-1.5 text-gray-700">{{ $userNames[$row->assigned_to] ?? '#' . $row->assigned_to }}</td>
                            <td class="py-1.5 text-right text-gray-600">{{ $row->total }}</td>
                            <td class="py-1.5 text-right text-emerald-600">{{ $row->won }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-3 text-gray-400">No assigned leads in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Recent activity --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-gray-900 mb-4">Recent activity</h3>
            <div class="space-y-3">
                @forelse ($recent as $a)
                    <div class="text-sm">
                        <p class="text-gray-700">
                            <span class="font-medium">{{ $a->typeLabel() }}</span> ·
                            <a href="{{ route('admin.crm.leads.show', $a->lead_id) }}" class="text-indigo-600 hover:text-indigo-800">{{ $a->lead?->name }}</a>
                        </p>
                        @if ($a->body)<p class="text-xs text-gray-500 truncate">{{ $a->body }}</p>@endif
                        <p class="text-[11px] text-gray-400">{{ local_time($a->created_at)->diffForHumans() }}{{ $a->user ? ' · ' . $a->user->name : '' }}</p>
                    </div>
                @empty
                    <p class="text-sm text-gray-400">Nothing yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
