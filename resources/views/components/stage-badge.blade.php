@props(['stage'])
<span class="badge stage-{{ strtolower($stage->value) }}">{{ $stage->label() }}</span>
