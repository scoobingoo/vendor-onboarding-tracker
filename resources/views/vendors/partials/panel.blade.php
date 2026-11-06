@use('App\Domain\Stage')
@use('App\Domain\Workflow')

@php
    // Without JS the form still shows the right label for the preselected option;
    // public/js/app.js keeps it in sync when the selection changes.
    $chosen = old('to_stage', $targets[0]->stage->value ?? null);
    $backward = collect($targets)->first(fn ($t) => $t->stage->value === $chosen)?->isBackward() ?? false;
@endphp

<aside class="card panel" aria-label="{{ $selected->name }} details">
    <div class="panel-head">
        <div>
            <h2>{{ $selected->name }}</h2>
            <p class="muted">{{ $selected->region }} · Coordinator: {{ $selected->coordinatorName ?? 'Unassigned' }}</p>
        </div>
        <a class="link" href="{{ route('vendors.index', $filters->toQuery()) }}" aria-label="Close panel">✕</a>
    </div>

    <ol class="stepper">
        @foreach (Stage::cases() as $stage)
            <li @class([
                'done' => $stage->index() < $selected->stage->index(),
                'current' => $stage === $selected->stage,
            ])>{{ $stage->label() }}</li>
        @endforeach
    </ol>

    <p>
        In <x-stage-badge :stage="$selected->stage" /> for <strong>{{ $selected->timeInStage() }}</strong>
        <span class="muted small">(since <x-local-time :at="$selected->stageEnteredAt" />)</span>
        @if ($selected->isStuck)
            <span class="stuck-pill">Stuck</span>
        @endif
    </p>

    @session('success')
        <p class="success" role="status">{{ $value }}</p>
    @endsession
    @session('warning')
        <p class="warning" role="alert">{{ $value }}</p>
    @endsession

    <form class="stage-form" method="POST" data-stage-form
          action="{{ route('vendors.transitions.store', ['vendor' => $selected->id] + $filters->toQuery()) }}">
        @csrf
        <input type="hidden" name="expected_from_stage" value="{{ $selected->stage->value }}">

        <label>
            Move to
            <select name="to_stage">
                @foreach ($targets as $target)
                    <option value="{{ $target->stage->value }}" data-direction="{{ $target->direction->value }}"
                            @selected($target->stage->value === $chosen)>{{ $target->label() }}</option>
                @endforeach
            </select>
        </label>

        <label>
            <span data-note-label>{{ $backward ? 'Reason (required)' : 'Note (optional)' }}</span>
            <textarea name="note" rows="2" maxlength="{{ Workflow::MAX_NOTE_LENGTH }}" @required($backward)
                      data-placeholder-forward="e.g. Contract countersigned via email"
                      data-placeholder-backward="Why is this vendor going back? e.g. Compliance rejected the business license"
                      placeholder="{{ $backward ? 'Why is this vendor going back? e.g. Compliance rejected the business license' : 'e.g. Contract countersigned via email' }}">{{ old('note') }}</textarea>
        </label>

        <p class="warning small" data-backward-warning @if (! $backward) hidden @endif>
            Moving backwards is for corrections and rejections. It resets the time-in-stage clock.
        </p>

        @foreach (['transition', 'to_stage', 'expected_from_stage', 'note'] as $field)
            @error($field)
                <p class="error" role="alert">{{ $message }}</p>
            @enderror
        @endforeach

        <button type="submit" class="primary">Update stage</button>
    </form>

    <h3>History</h3>
    <ol class="history">
        @foreach ($history as $transition)
            <li @class(['backward' => $transition->isBackward()])>
                <div>
                    <strong>{{ $transition->changedBy?->display_name ?? 'Sales' }}</strong>
                    @if ($transition->from_stage)
                        moved <x-stage-badge :stage="$transition->from_stage" /> →
                        <x-stage-badge :stage="$transition->to_stage" />
                    @else
                        added vendor at <x-stage-badge :stage="$transition->to_stage" />
                    @endif
                </div>
                <div class="muted small"><x-local-time :at="$transition->changed_at" /></div>
                @if ($transition->note)
                    <div class="note">“{{ $transition->note }}”</div>
                @endif
            </li>
        @endforeach
    </ol>
</aside>
