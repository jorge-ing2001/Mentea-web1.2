<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center flex-wrap gap-3">
            <h2 class="mentea-page-title">Hola, {{ Auth::user()->name }}</h2>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('patient.appointments.create') }}" class="btn-mentea">
                    <x-icon name="plus" class="w-4 h-4" /> Agendar cita
                </a>
                <a href="{{ route('patient.appointments.index') }}" class="btn-mentea-outline">Mis citas</a>
            </div>
        </div>
    </x-slot>
    <div class="mentea-section">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <a href="{{ route('patient.appointments.create') }}" class="mentea-card-hover p-5 flex items-start gap-4">
                <span class="mentea-avatar w-11 h-11"><x-icon name="calendar" class="w-5 h-5" /></span>
                <span>
                    <span class="block font-semibold text-slate-800">Agendar cita</span>
                    <span class="block text-sm text-slate-500 mt-0.5">Elige especialidad, profesional y horario</span>
                </span>
            </a>
            <a href="{{ route('patient.appointments.index') }}" class="mentea-card-hover p-5 flex items-start gap-4">
                <span class="mentea-avatar w-11 h-11"><x-icon name="list" class="w-5 h-5" /></span>
                <span>
                    <span class="block font-semibold text-slate-800">Mis citas</span>
                    <span class="block text-sm text-slate-500 mt-0.5">Consulta, reprograma o cancela</span>
                </span>
            </a>
            <a href="{{ route('patient.waitlist.create') }}" class="mentea-card-hover p-5 flex items-start gap-4">
                <span class="mentea-avatar w-11 h-11"><x-icon name="clock" class="w-5 h-5" /></span>
                <span>
                    <span class="block font-semibold text-slate-800">Lista de espera</span>
                    <span class="block text-sm text-slate-500 mt-0.5">Si no hay horario, te avisamos</span>
                </span>
            </a>
        </div>

        <div class="mentea-card p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-slate-800">Próximas citas</h3>
                <a href="{{ route('patient.appointments.index') }}" class="text-sm text-mentea-700 hover:underline">Ver todas</a>
            </div>
            <div class="divide-y divide-slate-100">
                @forelse($upcoming as $cita)
                    <a href="{{ route('patient.appointments.show', $cita) }}" class="flex justify-between items-center gap-3 py-3 -mx-2 px-2 rounded-lg hover:bg-slate-50 transition">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="mentea-avatar w-10 h-10">{{ strtoupper(substr($cita->professional->name ?? 'P', 0, 1)) }}</span>
                            <div class="min-w-0">
                                <div class="font-medium text-slate-800 truncate">{{ $cita->professional->name ?? 'Profesional' }}</div>
                                <div class="text-sm text-slate-500">
                                    {{ $cita->date->format('d/m/Y') }} · {{ \Carbon\Carbon::parse($cita->time)->format('H:i') }} · {{ $cita->modality->label() }}
                                </div>
                            </div>
                        </div>
                        <span class="shrink-0 px-2.5 py-0.5 text-xs font-medium rounded-full bg-{{ $cita->status->color() }}-100 text-{{ $cita->status->color() }}-800">
                            {{ $cita->status->label() }}
                        </span>
                    </a>
                @empty
                    <div class="mentea-empty-state">
                        <span class="mentea-avatar w-12 h-12"><x-icon name="calendar" class="w-6 h-6" /></span>
                        <p class="font-medium text-slate-600">No tienes citas próximas</p>
                        <a href="{{ route('patient.appointments.create') }}" class="btn-mentea mt-2">Agendar una cita</a>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
