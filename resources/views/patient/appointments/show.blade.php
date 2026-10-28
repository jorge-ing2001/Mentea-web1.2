<x-app-layout>
    <x-slot name="header">
        <h2 class="mentea-page-title">Detalle de cita</h2>
    </x-slot>

    <div class="mentea-section">
        <div class="max-w-2xl mx-auto w-full space-y-4">
            @if (session('success'))
                <div class="alert-success">{{ session('success') }}</div>
            @endif

            <div class="mentea-card p-6 space-y-5">
                <div class="flex justify-between items-start gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="mentea-avatar w-12 h-12 text-lg">{{ strtoupper(substr($appointment->professional->name ?? 'P', 0, 1)) }}</span>
                        <div class="min-w-0">
                            <h3 class="text-lg font-semibold text-slate-900 truncate">{{ $appointment->professional->name }}</h3>
                            <p class="text-sm text-mentea-700">{{ $appointment->professional->specialty }}</p>
                        </div>
                    </div>
                    <span class="shrink-0 px-3 py-1 text-sm font-medium rounded-full bg-{{ $appointment->status->color() }}-100 text-{{ $appointment->status->color() }}-800">
                        {{ $appointment->status->label() }}
                    </span>
                </div>

                <dl class="grid grid-cols-2 gap-4 text-sm border-t border-slate-100 pt-5">
                    <div>
                        <dt class="text-slate-500">Fecha</dt>
                        <dd class="font-medium text-slate-800">{{ $appointment->date->format('d/m/Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Hora</dt>
                        <dd class="font-medium text-slate-800">{{ \Carbon\Carbon::parse($appointment->time)->format('H:i') }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Modalidad</dt>
                        <dd class="font-medium text-slate-800">{{ $appointment->modality->label() }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Duración</dt>
                        <dd class="font-medium text-slate-800">{{ $appointment->professional->appointment_duration_minutes }} min</dd>
                    </div>
                </dl>

                @if($appointment->reason)
                    <div class="text-sm">
                        <dt class="text-slate-500">Motivo</dt>
                        <dd class="text-slate-800 mt-0.5">{{ $appointment->reason }}</dd>
                    </div>
                @endif

                @if($appointment->meet_link)
                    <div class="p-3 rounded-xl bg-mentea-50 border border-mentea-100">
                        <p class="text-sm text-mentea-800 font-medium">Enlace de videollamada</p>
                        <a href="{{ $appointment->meet_link }}" target="_blank" rel="noopener" class="text-mentea-700 text-sm break-all hover:underline">
                            {{ $appointment->meet_link }}
                        </a>
                    </div>
                @endif

                <div class="flex flex-wrap gap-3 pt-5 border-t border-slate-100">
                    <a href="{{ route('patient.appointments.index') }}" class="btn-mentea-outline">Volver al listado</a>

                    @if($appointment->status->value === 'completed' && !$appointment->survey)
                        <a href="{{ route('patient.surveys.create', $appointment) }}"
                           class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl font-semibold text-sm text-white bg-amber-500 hover:bg-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-400 focus:ring-offset-2 shadow-sm transition">
                            Valorar esta cita
                        </a>
                    @endif

                    <x-appointment-documents :appointment="$appointment" />

                    @can('reschedule', $appointment)
                        <a href="{{ route('patient.appointments.edit', $appointment) }}" class="btn-mentea">
                            Reprogramar
                        </a>
                    @endcan

                    @can('cancel', $appointment)
                        <form method="POST" action="{{ route('patient.appointments.cancel', $appointment) }}"
                              onsubmit="return confirm('¿Seguro que deseas cancelar esta cita?')">
                            @csrf
                            <x-danger-button type="submit">Cancelar cita</x-danger-button>
                        </form>
                    @endcan
                </div>

                @if(!$appointment->canBeCancelledByPatient() && $appointment->status->canBeCancelled())
                    <p class="text-xs text-amber-600">
                        Solo puedes cancelar con al menos 5 días de anticipación.
                    </p>
                @endif

                @if($appointment->modality?->value === 'virtual')
                    <div class="text-sm text-slate-600 rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <strong>Videollamada:</strong>
                        @if($appointment->meet_link)
                            <a href="{{ $appointment->meet_link }}" target="_blank" rel="noopener" class="text-mentea-700 underline">{{ $appointment->meet_link }}</a>
                        @else
                            El enlace se compartirá antes de la cita (correo o panel). No se generan salas ficticias en producción.
                        @endif
                    </div>
                @endif

                @if(in_array($appointment->status->value, ['pending','confirmed'], true) && !$appointment->patient_confirmed_at)
                    <form method="POST" action="{{ route('patient.appointments.confirm', $appointment) }}" class="pt-1">
                        @csrf
                        <button type="submit" class="btn-mentea">Confirmar asistencia</button>
                    </form>
                @elseif($appointment->patient_confirmed_at)
                    <p class="text-sm text-mentea-700">Confirmaste asistencia el {{ $appointment->patient_confirmed_at->format('d/m/Y H:i') }}.</p>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
