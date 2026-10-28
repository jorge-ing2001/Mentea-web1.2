<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="mentea-page-title">Mis citas</h2>
            <a href="{{ route('professional.dashboard') }}" class="text-sm font-medium text-mentea-600 hover:underline">Dashboard</a>
        </div>
    </x-slot>
    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <form method="GET" class="mentea-card p-4 flex flex-wrap items-end gap-3">
                <div>
                    <label for="date" class="mentea-label">Fecha</label>
                    <input type="date" id="date" name="date" value="{{ request('date') }}" class="mentea-input">
                </div>
                <div>
                    <label for="status" class="mentea-label">Estado</label>
                    <select id="status" name="status" class="mentea-input">
                        <option value="">Todos los estados</option>
                        @foreach($statuses as $st)
                            <option value="{{ $st->value }}" @selected(request('status')===$st->value)>{{ $st->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn-mentea">Filtrar</button>
            </form>

            <div class="mentea-card overflow-x-auto">
                <table class="mentea-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Paciente</th>
                            <th>Modalidad</th>
                            <th>Estado</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($appointments as $cita)
                            <tr>
                                <td>{{ $cita->date->format('d/m/Y') }} <span class="text-slate-500">{{ \Carbon\Carbon::parse($cita->time)->format('H:i') }}</span></td>
                                <td>
                                    <div class="font-medium text-slate-800">{{ $cita->patient->name ?? '—' }}</div>
                                    <div class="text-xs text-slate-500">{{ $cita->patient->document_number ?? '' }}</div>
                                </td>
                                <td>{{ $cita->modality->label() }}</td>
                                <td>
                                    <span class="px-2 py-0.5 text-xs rounded-full bg-{{ $cita->status->color() }}-100 text-{{ $cita->status->color() }}-800">{{ $cita->status->label() }}</span>
                                </td>
                                <td class="text-right">
                                    <a href="{{ route('professional.appointments.show', $cita) }}" class="font-medium text-mentea-600 hover:underline">Gestionar</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5">
                                <div class="mentea-empty-state">
                                    <x-icon name="calendar" class="w-10 h-10 mx-auto text-slate-300 mb-2" />
                                    <p>No hay citas.</p>
                                </div>
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $appointments->links() }}</div>
        </div>
    </div>
</x-app-layout>
