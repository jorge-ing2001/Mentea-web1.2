<x-app-layout>
    <x-slot name="header">
        <h2 class="mentea-page-title">Gestionar cita #{{ $appointment->id }}</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if(session('success'))
                <div class="alert-success">{{ session('success') }}</div>
            @endif
            <div class="mentea-card p-6 space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div>
                        <div class="mentea-label">Paciente</div>
                        <div class="font-medium text-slate-800">{{ $appointment->patient->name }}</div>
                        <div class="text-xs text-slate-500">{{ $appointment->patient->email }} · {{ $appointment->patient->document_number }}</div>
                    </div>
                    <div>
                        <div class="mentea-label">Profesional</div>
                        <div class="font-medium text-slate-800">{{ $appointment->professional->name }}</div>
                        <div class="text-xs text-slate-500">{{ $appointment->professional->specialty }}</div>
                    </div>
                    <div>
                        <div class="mentea-label">Fecha y hora</div>
                        <div class="font-medium text-slate-800">{{ $appointment->date->format('d/m/Y') }} · {{ \Carbon\Carbon::parse($appointment->time)->format('H:i') }}</div>
                    </div>
                    <div>
                        <div class="mentea-label">Modalidad</div>
                        <div class="font-medium text-slate-800">{{ $appointment->modality->label() }}</div>
                    </div>
                    <div>
                        <div class="mentea-label">Estado actual</div>
                        <span class="px-2 py-0.5 text-xs rounded-full bg-{{ $appointment->status->color() }}-100 text-{{ $appointment->status->color() }}-800">
                            {{ $appointment->status->label() }}
                        </span>
                    </div>
                    @if($appointment->meet_link)
                        <div>
                            <div class="mentea-label">Meet</div>
                            <a href="{{ $appointment->meet_link }}" target="_blank" class="text-mentea-600 text-sm break-all hover:underline">{{ $appointment->meet_link }}</a>
                        </div>
                    @endif
                </div>
                @if($appointment->reason)
                    <div class="text-sm pt-2 border-t border-slate-100"><span class="text-slate-500">Motivo:</span> <span class="text-slate-700">{{ $appointment->reason }}</span></div>
                @endif
                <x-appointment-documents :appointment="$appointment" />

                @if($appointment->notes)
                    <div class="text-sm"><span class="text-slate-500">Notas:</span> <span class="text-slate-700">{{ $appointment->notes }}</span></div>
                @endif
            </div>

            <form method="POST" action="{{ route('admin.appointments.status', $appointment) }}" class="mentea-card p-6 space-y-4">
                @csrf @method('PATCH')
                <h3 class="font-semibold text-slate-800">Cambiar estado</h3>
                <div>
                    <label for="status" class="mentea-label">Nuevo estado</label>
                    <select id="status" name="status" required class="mentea-input">
                        @foreach(\App\Enums\AppointmentStatus::cases() as $st)
                            <option value="{{ $st->value }}" @selected($appointment->status === $st)>{{ $st->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="notes" class="mentea-label">Notas internas</label>
                    <textarea id="notes" name="notes" rows="2" class="mentea-input">{{ old('notes', $appointment->notes) }}</textarea>
                </div>
                <div>
                    <label for="cancellation_reason" class="mentea-label">Motivo de cancelación (si aplica)</label>
                    <input type="text" id="cancellation_reason" name="cancellation_reason" value="{{ old('cancellation_reason') }}" class="mentea-input">
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('admin.appointments.index') }}" class="btn-mentea-outline">Volver</a>
                    <button type="submit" class="btn-mentea flex-1">Actualizar</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
