@extends('layouts.app')

@use('App\Domain\VendorFilters')

@section('title', ($selected ? $selected->name.' · ' : '').'Vendor Onboarding')

@section('content')
    <div class="app">
        <header class="topbar">
            <h1>Vendor Onboarding</h1>
            <div class="topbar-right">
                <span class="muted">Signed in as <strong>{{ auth()->user()->display_name }}</strong></span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit">Log out</button>
                </form>
            </div>
        </header>

        <nav class="pipeline" aria-label="Vendors per stage">
            @foreach ($pipeline as $step)
                @php($active = $filters->stage === $step['stage'])
                <a href="{{ $filterUrl($filters->withStage($active ? null : $step['stage'])) }}"
                   @class(['pipeline-step', 'active' => $active])
                   @if ($active) aria-current="true" @endif>
                    <span class="pipeline-label">{{ $step['stage']->label() }}</span>
                    <span class="pipeline-count">{{ $step['count'] }}</span>
                    @if ($step['stuck'] > 0)
                        <span class="stuck-pill">{{ $step['stuck'] }} stuck</span>
                    @endif
                </a>
            @endforeach
        </nav>

        <form class="toolbar" method="GET" action="{{ $filterUrl(new VendorFilters) }}">
            @if ($filters->stage)
                <input type="hidden" name="stage" value="{{ $filters->stage->value }}">
            @endif
            <input type="search" name="q" value="{{ $filters->search }}"
                   placeholder="Search vendor or region" aria-label="Search vendor or region">
            <label class="check">
                <input type="checkbox" name="stuck" value="1" @checked($filters->stuckOnly) data-autosubmit>
                Stuck only ({{ $stuckCount }})
            </label>
            <label class="check">
                <input type="checkbox" name="mine" value="1" @checked($filters->mineOnly) data-autosubmit>
                My vendors
            </label>
            <noscript><button type="submit">Apply</button></noscript>
            @if ($filters->isActive())
                <a class="link" href="{{ $filterUrl(new VendorFilters) }}">Clear filters</a>
            @endif
            <span class="spacer"></span>
            <span class="muted small">
                Stuck = more than {{ $stuckAfterDays }} days in a stage · as of <x-local-time :at="$now" />
            </span>
            <a class="button" href="{{ request()->fullUrl() }}">Refresh</a>
        </form>

        <div class="layout">
            <div class="card table-wrap">
                <table class="vendors">
                    <thead>
                        <tr>
                            <th>Vendor</th>
                            <th>Region</th>
                            <th>Stage</th>
                            <th>Time in stage</th>
                            <th>Coordinator</th>
                            <th>Last change</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($vendors as $vendor)
                            @php($url = route('vendors.show', ['vendor' => $vendor->id] + $filters->toQuery()))
                            <tr @class(['stuck' => $vendor->isStuck, 'selected' => $selected?->id === $vendor->id])
                                data-href="{{ $url }}">
                                <td class="strong"><a href="{{ $url }}">{{ $vendor->name }}</a></td>
                                <td>{{ $vendor->region }}</td>
                                <td><x-stage-badge :stage="$vendor->stage" /></td>
                                <td>
                                    {{ $vendor->timeInStage() }}
                                    @if ($vendor->isStuck)
                                        <span class="stuck-pill">Stuck</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($vendor->coordinatorName)
                                        {{ $vendor->coordinatorName }}
                                    @else
                                        <span class="warn">Unassigned</span>
                                    @endif
                                </td>
                                <td class="last-change">
                                    <span>{{ $vendor->lastChangedByName ?? 'Sales' }}</span>
                                    @if ($vendor->lastNote)
                                        <span class="muted note-preview"> · {{ $vendor->lastNote }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="muted centered">No vendors match these filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($selected)
                @include('vendors.partials.panel')
            @else
                <aside class="card panel placeholder muted">
                    Select a vendor to see its history or change its stage.
                </aside>
            @endif
        </div>
    </div>
@endsection
