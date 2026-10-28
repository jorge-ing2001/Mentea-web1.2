<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
            <h2 class="mentea-page-title">Agenda</h2>
            @if($professional)
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('professional.appointments.index') }}" class="btn-mentea-outline text-sm">Todas mis citas</a>
                    <a href="{{ route('professional.calendar.index') }}" class="btn-mentea-outline text-sm">Calendario</a>
                </div>
            @endif
        </div>
    </x-slot>
    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if(!$professional)
                <div class="alert-warning">
                    Tu cuenta aún no está vinculada a un perfil profesional. Contacta al administrador.
                </div>
            @else
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="mentea-stat">
                        <div class="mentea-stat-label">Citas hoy</div>
                        <div class="mentea-stat-value">{{ $todayAppointments->count() }}</div>
                    </div>
                    <div class="mentea-stat">
                        <div class="mentea-stat-label">Esta semana</div>
                        <div class="mentea-stat-value">{{ $weekCount }}</div>
                    </div>
                    <div class="mentea-stat">
                        <div class="mentea-stat-label">Pendientes de confirmar</div>
                        <div class="mentea-stat-value text-amber-600">{{ $pendingCount }}</div>
                    </div>
                </div>

                <div class="mentea-card p-6">
                    <h3 class="font-semibold text-slate-800 mb-4">Citas de hoy · {{ $professional->name }}</h3>
                    <div class="divide-y divide-slate-100">
                        @forelse($todayAppointments as $cita)
                            <div class="py-3 flex justify-between items-center gap-3">
                                <div class="flex items-center gap-3 min-w-0">
                                    <span class="mentea-avatar shrink-0">{{ \Illuminate\Support\Str::of($cita->patient->name)->substr(0, 1)->upper() }}</span>
                                    <div class="min-w-0">
                                        <div class="font-medium text-slate-800 truncate">{{ $cita->patient->name }}</div>
                                        <div class="text-sm text-slate-500">
                                            {{ \Carbon\Carbon::parse($cita->time)->format('H:i') }} · {{ $cita->modality->label() }}
                                            @if($cita->patient->phone) · {{ $cita->patient->phone }} @endif
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3 shrink-0">
                                    <span class="px-2 py-0.5 text-xs rounded-full bg-{{ $cita->status->color() }}-100 text-{{ $cita->status->color() }}-800">
                                        {{ $cita->status->label() }}
                                    </span>
                                    <a href="{{ route('professional.appointments.show', $cita) }}" class="text-sm font-medium text-mentea-600 hover:underline">Ver</a>
                                </div>
                            </div>
                        @empty
                            <div class="mentea-empty-state">
                                <x-icon name="calendar" class="w-10 h-10 mx-auto text-slate-300 mb-2" />
                                <p>No tienes citas programadas para hoy.</p>
                            </div>
                        @endforelse
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
