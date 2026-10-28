<x-app-layout>
    <x-slot name="header">
        <h2 class="mentea-page-title">Cita #{{ $appointment->id }}</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if(session('success'))
                <div class="alert-success">{{ session('success') }}</div>
            @endif
            <div class="mentea-card p-6 space-y-4 text-sm">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <div class="mentea-label">Paciente</div>
                        <div class="font-medium text-base text-slate-800">{{ $appointment->patient->name }}</div>
                        <div class="text-xs text-slate-500">{{ $appointment->patient->email }}</div>
                        @if($appointment->patient->phone)
                            <div class="text-xs text-slate-500">{{ $appointment->patient->phone }}</div>
                        @endif
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
                        <div class="mentea-label">Estado</div>
                        <span class="px-2 py-0.5 text-xs rounded-full bg-{{ $appointment->status->color() }}-100 text-{{ $appointment->status->color() }}-800">{{ $appointment->status->label() }}</span>
                    </div>
                </div>
                @if($appointment->reason)
                    <div class="pt-2 border-t border-slate-100"><span class="text-slate-500">Motivo:</span> <span class="text-slate-700">{{ $appointment->reason }}</span></div>
                @endif
                @if($appointment->meet_link)
                    <div class="p-3 bg-blue-50 rounded-lg">
                        <a href="{{ $appointment->meet_link }}" target="_blank" class="text-mentea-600 break-all hover:underline">{{ $appointment->meet_link }}</a>
                    </div>
                @endif
            </div>

            <x-appointment-documents :appointment="$appointment" />

            @if(in_array($appointment->status->value, ['pending', 'confirmed']))
                <form method="POST" action="{{ route('professional.appointments.status', $appointment) }}" class="mentea-card p-6 space-y-4">
                    @csrf @method('PATCH')
                    <h3 class="font-semibold text-slate-800">Actualizar estado</h3>
                    <div>
                        <label for="status" class="mentea-label">Nuevo estado</label>
                        <select id="status" name="status" required class="mentea-input">
                            <option value="confirmed" @selected($appointment->status->value==='confirmed')>Confirmada</option>
                            <option value="completed">Completada</option>
                            <option value="no_show">No asistió</option>
                        </select>
                    </div>
                    <div>
                        <label for="notes" class="mentea-label">Notas clínicas (internas)</label>
                        <textarea id="notes" name="notes" rows="3" class="mentea-input">{{ old('notes', $appointment->notes) }}</textarea>
                    </div>
                    <div class="flex gap-3">
                        <a href="{{ route('professional.appointments.index') }}" class="btn-mentea-outline">Volver</a>
                        <button type="submit" class="btn-mentea flex-1">Guardar</button>
                    </div>
                </form>
            @else
                <a href="{{ route('professional.appointments.index') }}" class="btn-mentea-outline inline-block">Volver al listado</a>
            @endif
        </div>
    </div>
</x-app-layout>

{{-- Historia clínica mínima (solo profesional / staff) --}}
@can('viewClinicalNotes', $appointment)
<div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
    <div class="mentea-card p-6 mt-6">
        <h3 class="font-semibold text-slate-800 mb-1">Historia clínica (notas confidenciales)</h3>
        <p class="text-xs text-slate-500 mb-3">Visible solo para el profesional asignado y administradores.</p>
        @if($appointment->clinical_notes)
            <div class="text-sm whitespace-pre-wrap bg-slate-50 rounded-lg p-3 mb-3">{{ $appointment->clinical_notes }}</div>
            <p class="text-xs text-slate-400">Actualizado {{ $appointment->clinical_notes_updated_at?->format('d/m/Y H:i') }}</p>
        @endif
        <form method="POST" action="{{ route('professional.clinical.store', $appointment) }}" class="space-y-3 mt-3">
            @csrf

            <input type="hidden" name="status" value="{{ $appointment->status->value }}">
            <label class="mentea-label" for="clinical_notes">Notas clínicas</label>
            <textarea id="clinical_notes" name="clinical_notes" rows="5" class="mentea-input" aria-label="Notas clínicas confidenciales">{{ old('clinical_notes', $appointment->clinical_notes) }}</textarea>
            <button type="submit" class="btn-mentea text-sm">Guardar notas</button>
        </form>
    </div>
</div>
@endcan
