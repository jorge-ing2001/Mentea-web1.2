<x-app-layout>
    <x-slot name="header">
        <h2 class="mentea-page-title">Lista de espera</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if(session('success'))
                <div class="alert-success">{{ session('success') }}</div>
            @endif
            <form method="GET" class="mentea-card p-4 flex flex-wrap items-end gap-3">
                <div>
                    <label for="status" class="mentea-label">Estado</label>
                    <select id="status" name="status" onchange="this.form.submit()" class="mentea-input">
                        <option value="">Todos</option>
                        @foreach(['waiting','notified','assigned','cancelled'] as $st)
                            <option value="{{ $st }}" @selected(request('status')===$st)>{{ $st }}</option>
                        @endforeach
                    </select>
                </div>
            </form>

            <div class="mentea-card overflow-x-auto">
                <table class="mentea-table">
                    <thead>
                        <tr>
                            <th>Paciente</th>
                            <th>Preferencia</th>
                            <th>Fecha pref.</th>
                            <th>Estado</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($entries as $e)
                            <tr>
                                <td>
                                    <div class="font-medium text-slate-800">{{ $e->patient->name ?? '—' }}</div>
                                    <div class="text-xs text-slate-500">{{ $e->patient->email ?? '' }}</div>
                                </td>
                                <td>
                                    {{ $e->specialty ?? '—' }}
                                    @if($e->professional)<br><span class="text-xs text-slate-500">{{ $e->professional->name }}</span>@endif
                                </td>
                                <td>{{ $e->preferred_date?->format('d/m/Y') ?? '—' }}</td>
                                <td>
                                    <span class="mentea-chip">{{ $e->status }}</span>
                                    @if($e->assigned_at)
                                        <div class="text-xs text-slate-500 mt-1">Asignada {{ $e->assigned_at->format('d/m/Y H:i') }}</div>
                                    @endif
                                </td>
                                <td class="text-right">
                                    <div class="flex flex-col items-end gap-2">
                                        <div class="flex items-center gap-3">
                                            @if(in_array($e->status, ['waiting', 'notified']))
                                                <a href="{{ route('admin.waitlist.assign', $e) }}" class="text-mentea-600 hover:underline font-medium">Asignar cita</a>
                                            @endif
                                            @if($e->assigned_appointment_id)
                                                <a href="{{ route('admin.appointments.show', $e->assigned_appointment_id) }}" class="text-slate-600 hover:underline">Ver cita</a>
                                            @endif
                                        </div>
                                        <form method="POST" action="{{ route('admin.waitlist.status', $e) }}" class="inline-flex gap-1 items-center">
                                            @csrf @method('PATCH')
                                            <select name="status" class="mentea-input text-xs py-1 w-auto">
                                                @foreach(['waiting','notified','assigned','cancelled'] as $st)
                                                    <option value="{{ $st }}" @selected($e->status===$st)>{{ $st }}</option>
                                                @endforeach
                                            </select>
                                            <button class="text-mentea-600 text-xs font-medium hover:underline">OK</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5">
                                <div class="mentea-empty-state">
                                    <x-icon name="clock" class="w-10 h-10 mx-auto text-slate-300 mb-2" />
                                    <p>Sin solicitudes.</p>
                                </div>
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $entries->links() }}</div>
        </div>
    </div>
</x-app-layout>
