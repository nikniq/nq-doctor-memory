<span class="player">
    @if ($user?->avatar_url)
        <img src="{{ $user->avatar_url }}" alt="" class="player__avatar" referrerpolicy="no-referrer" loading="lazy">
    @else
        <span class="player__avatar player__avatar--initial" aria-hidden="true">{{ mb_strtoupper(mb_substr($user?->name ?? '?', 0, 1)) }}</span>
    @endif
    <span class="player__name">{{ $user?->name ?? 'Deleted player' }}</span>
</span>
