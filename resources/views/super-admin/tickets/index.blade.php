<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center flex-wrap gap-2">
            <h2 class="mentea-page-title">Tickets y diligenciamientos</h2>
            <a href="{{ route('super.tickets.create') }}" class="btn-mentea text-sm">+ Evento plataforma</a>
        </div>
    </x-slot>
    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if(session('success'))
                <div class="alert-success">{{ session('success') }}</div>
            @endif
            <p class="text-sm text-slate-600">
                <strong>Soporte:</strong> solicitudes de usuarios.
                <strong>Diligenciamiento:</strong> eventos documentados por el equipo sobre la plataforma.
            </p>
            <form method="GET" class="flex gap-2 flex-wrap">
                <select name="type" class="mentea-input w-auto" aria-label="Filtrar por tipo" onchange="this.form.submit()">
                    <option value="">Todos los tipos</option>
                    <option value="support" @selected(($type ?? '')==='support')>Soporte usuario</option>
                    <option value="platform_event" @selected(($type ?? '')==='platform_event')>Diligenciamiento / evento</option>
                </select>
                <select name="status" class="mentea-input w-auto" aria-label="Filtrar por estado" onchange="this.form.submit()">
                    <option value="">Todos los estados</option>
                    @foreach(['open'=>'Abierto','in_progress'=>'En progreso','resolved'=>'Resuelto','closed'=>'Cerrado'] as $v=>$l)
                        <option value="{{ $v }}" @selected(($status ?? '')===$v)>{{ $l }}</option>
                    @endforeach
                </select>
            </form>
            <div class="mentea-card overflow-hidden">
                <div class="overflow-x-auto">
                <table class="mentea-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Tipo</th>
                            <th>Asunto</th>
                            <th>Usuario</th>
                            <th>Prioridad</th>
                            <th>Estado</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($tickets as $t)
                            <tr>
                                <td>{{ $t->id }}</td>
                                <td>
                                    <span class="badge {{ $t->isPlatformEvent() ? 'bg-amber-100 text-amber-800' : 'bg-mentea-50 text-mentea-700' }}">
                                        {{ $t->typeLabel() }}
                                    </span>
                                </td>
                                <td><a href="{{ route('super.tickets.show', $t) }}" class="font-medium text-mentea-700 hover:underline">{{ $t->subject }}</a></td>
                                <td>{{ $t->user?->name }}</td>
                                <td>{{ $t->priorityLabel() }}</td>
                                <td>
                                    @php
                                        $statusBadge = match($t->status) {
                                            'open' => 'bg-amber-100 text-amber-800',
                                            'in_progress' => 'bg-blue-100 text-blue-800',
                                            'resolved' => 'bg-emerald-100 text-emerald-800',
                                            'closed' => 'bg-slate-200 text-slate-700',
                                            default => 'bg-slate-100 text-slate-700',
                                        };
                                    @endphp
                                    <span class="badge {{ $statusBadge }}">{{ $t->statusLabel() }}</span>
                                </td>
                                <td class="text-slate-500 whitespace-nowrap">{{ $t->created_at->format('d/m/Y H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <div class="mentea-empty-state">
                                        <x-icon name="chat" class="w-8 h-8 text-slate-300" />
                                        <p>No hay registros.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
            {{ $tickets->links() }}
        </div>
    </div>
</x-app-layout>
