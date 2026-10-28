<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center flex-wrap gap-3">
            <h2 class="mentea-page-title">Mis citas</h2>
            <a href="{{ route('patient.appointments.create') }}" class="btn-mentea">
                <x-icon name="plus" class="w-4 h-4" /> Agendar cita
            </a>
        </div>
    </x-slot>

    <div class="mentea-section">
        @if (session('success'))
            <div class="alert-success">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="alert-error">{{ session('error') }}</div>
        @endif

        <div class="mentea-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="mentea-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Profesional</th>
                            <th>Modalidad</th>
                            <th>Estado</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($appointments as $cita)
                            <tr>
                                <td class="whitespace-nowrap">
                                    <span class="font-medium text-slate-800">{{ $cita->date->format('d/m/Y') }}</span>
                                    <span class="text-slate-500">{{ \Carbon\Carbon::parse($cita->time)->format('H:i') }}</span>
                                </td>
                                <td>
                                    <div class="font-medium text-slate-800">{{ $cita->professional->name ?? '—' }}</div>
                                    <div class="text-slate-500 text-xs">{{ $cita->professional->specialty ?? '' }}</div>
                                </td>
                                <td>{{ $cita->modality->label() }}</td>
                                <td>
                                    <span class="inline-flex items-center px-2.5 py-0.5 text-xs font-medium rounded-full bg-{{ $cita->status->color() }}-100 text-{{ $cita->status->color() }}-800">
                                        {{ $cita->status->label() }}
                                    </span>
                                </td>
                                <td class="text-right">
                                    <a href="{{ route('patient.appointments.show', $cita) }}" class="text-mentea-700 font-medium hover:underline">Ver</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <div class="mentea-empty-state">
                                        <span class="mentea-avatar w-12 h-12"><x-icon name="calendar" class="w-6 h-6" /></span>
                                        <p class="font-medium text-slate-600">No tienes citas registradas</p>
                                        <a href="{{ route('patient.appointments.create') }}" class="btn-mentea mt-2">Agendar la primera</a>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div>{{ $appointments->links() }}</div>
    </div>
</x-app-layout>
