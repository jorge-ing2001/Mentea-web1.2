<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center flex-wrap gap-3">
            <h2 class="mentea-page-title">Soporte</h2>
            <a href="{{ route('patient.tickets.create') }}" class="btn-mentea">
                <x-icon name="plus" class="w-4 h-4" /> Nueva solicitud
            </a>
        </div>
    </x-slot>
    <div class="mentea-section">
        <div class="max-w-3xl mx-auto w-full space-y-4">
            @if(session('success'))
                <div class="alert-success">{{ session('success') }}</div>
            @endif
            <div class="mentea-card divide-y divide-slate-100">
                @forelse($tickets as $t)
                    <div class="p-4 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-medium text-slate-800 truncate">{{ $t->subject }}</div>
                            <div class="text-sm text-slate-500 mt-1">{{ $t->created_at->format('d/m/Y') }}</div>
                        </div>
                        <span class="shrink-0 mentea-chip">{{ $t->statusLabel() }}</span>
                    </div>
                @empty
                    <div class="mentea-empty-state">
                        <span class="mentea-avatar w-12 h-12"><x-icon name="chat" class="w-6 h-6" /></span>
                        <p class="font-medium text-slate-600">No has enviado solicitudes de soporte</p>
                        <a href="{{ route('patient.tickets.create') }}" class="btn-mentea mt-2">Crear una solicitud</a>
                    </div>
                @endforelse
            </div>
            <div>{{ $tickets->links() }}</div>
        </div>
    </div>
</x-app-layout>
