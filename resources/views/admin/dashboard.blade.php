<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center flex-wrap gap-3">
            <h2 class="mentea-page-title">Panel Administrador</h2>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.professionals.index') }}" class="btn-mentea-outline text-sm">Profesionales</a>
                <a href="{{ route('admin.appointments.index') }}" class="btn-mentea-outline text-sm">Citas</a>
                <a href="{{ route('admin.calendar.index') }}" class="btn-mentea-outline text-sm">Calendario</a>
                <a href="{{ route('admin.waitlist.index') }}" class="btn-mentea-outline text-sm">Lista de espera</a>
            </div>
        </div>
    </x-slot>
    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="mentea-stat">
                    <div class="mentea-stat-label">Pacientes</div>
                    <div class="mentea-stat-value">{{ $totalPatients }}</div>
                </div>
                <div class="mentea-stat">
                    <div class="mentea-stat-label">Profesionales activos</div>
                    <div class="mentea-stat-value">{{ $totalProfessionals }}</div>
                </div>
                <div class="mentea-stat">
                    <div class="mentea-stat-label">Total citas</div>
                    <div class="mentea-stat-value">{{ $totalAppointments }}</div>
                </div>
                <div class="mentea-stat">
                    <div class="mentea-stat-label">Citas hoy</div>
                    <div class="mentea-stat-value">{{ $appointmentsToday }}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="mentea-card p-5">
                    <h3 class="font-semibold text-slate-800 mb-3">Por estado</h3>
                    <ul class="space-y-2 text-sm">
                        @foreach(['pending'=>'Pendiente','confirmed'=>'Confirmada','cancelled'=>'Cancelada','completed'=>'Completada','no_show'=>'No asistió'] as $key => $label)
                            <li class="flex justify-between items-center py-1">
                                <span class="text-slate-600">{{ $label }}</span>
                                <span class="font-semibold text-slate-800">{{ $byStatus[$key] ?? 0 }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="lg:col-span-2 mentea-card p-5">
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="font-semibold text-slate-800">Próximas citas</h3>
                        <a href="{{ route('admin.appointments.index') }}" class="text-sm font-medium text-mentea-600 hover:underline">Ver todas</a>
                    </div>
                    <div class="divide-y divide-slate-100">
                        @forelse($upcoming as $cita)
                            <a href="{{ route('admin.appointments.show', $cita) }}" class="py-2.5 flex justify-between items-center gap-3 text-sm hover:bg-slate-50 -mx-2 px-2 rounded-lg transition">
                                <div class="min-w-0">
                                    <span class="font-medium text-slate-800">{{ $cita->patient->name ?? '—' }}</span>
                                    <span class="text-slate-500"> · {{ $cita->professional->name ?? '' }}</span>
                                </div>
                                <div class="text-slate-600 shrink-0">
                                    {{ $cita->date->format('d/m') }} {{ \Carbon\Carbon::parse($cita->time)->format('H:i') }}
                                </div>
                            </a>
                        @empty
                            <div class="mentea-empty-state">
                                <p>No hay citas próximas. <a href="{{ route('admin.professionals.index') }}" class="text-mentea-600 font-medium hover:underline">Gestionar profesionales</a></p>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
