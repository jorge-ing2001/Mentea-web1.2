<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center flex-wrap gap-2">
            <h2 class="mentea-page-title">Notificaciones</h2>
            @if(Auth::user()->unreadNotifications->isNotEmpty())
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <button type="submit" class="btn-mentea-ghost">Marcar todas como leídas</button>
                </form>
            @endif
        </div>
    </x-slot>
    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
        @if(session('success'))
            <div class="alert-success text-sm">{{ session('success') }}</div>
        @endif

        <div class="mentea-card divide-y divide-slate-100 overflow-hidden">
            @forelse($notifications as $n)
                @php $data = $n->data; @endphp
                <a href="{{ route('notifications.read', $n->id) }}"
                   class="block px-5 py-4 transition hover:bg-slate-50 focus:outline-none focus:bg-mentea-50 {{ $n->read_at ? '' : 'bg-mentea-50/50' }}">
                    <div class="flex justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-medium text-sm {{ $n->read_at ? 'text-slate-700' : 'text-slate-900' }}">{{ $data['title'] ?? 'Aviso' }}</div>
                            <div class="text-sm text-slate-600 mt-0.5">{{ $data['body'] ?? '' }}</div>
                            <div class="text-xs text-slate-400 mt-1">{{ $n->created_at->diffForHumans() }}</div>
                        </div>
                        @unless($n->read_at)
                            <span class="shrink-0 w-2.5 h-2.5 mt-1.5 rounded-full bg-mentea-500" aria-label="No leída"></span>
                        @endunless
                    </div>
                </a>
            @empty
                <div class="mentea-empty-state">
                    <x-icon name="bell" class="w-10 h-10 text-slate-300" />
                    <p class="text-sm">No tienes notificaciones.</p>
                </div>
            @endforelse
        </div>

        <div>{{ $notifications->links() }}</div>
    </div>
</x-app-layout>
