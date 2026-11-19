<x-app-layout>
    <x-slot name="header">
        <h2 class="mentea-page-title">Agendar cita</h2>
    </x-slot>

    <div class="mentea-section">
        @if (session('success'))
            <div class="alert-success">{{ session('success') }}</div>
        @endif

        <div class="mentea-card p-5">
            <p class="text-sm text-slate-600 mb-3">Filtra por especialidad y elige al profesional con quien quieres agendar.</p>
            <!-- Filtro especialidades -->
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('patient.appointments.create') }}"
                   class="{{ !$specialty ? 'mentea-filter-chip-active' : 'mentea-filter-chip' }}">
                    Todas
                </a>
                @foreach($specialties as $s)
                    <a href="{{ route('patient.appointments.create', ['specialty' => $s]) }}"
                       class="{{ $specialty === $s ? 'mentea-filter-chip-active' : 'mentea-filter-chip' }}">
                        {{ $s }}
                    </a>
                @endforeach
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @forelse($professionals as $pro)
                <div class="mentea-card-hover p-6 flex flex-col">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="mentea-avatar w-14 h-14 text-xl">
                            {{ strtoupper(substr($pro->name, 0, 1)) }}
                        </div>
                        <div class="min-w-0">
                            <h3 class="font-semibold text-slate-900 truncate">{{ $pro->name }}</h3>
                            <p class="text-sm text-mentea-700">{{ $pro->specialty }}</p>
                        </div>
                    </div>
                    @if($pro->profession)
                        <p class="text-sm text-slate-500 mb-2">{{ $pro->profession }}</p>
                    @endif
                    @if($pro->description)
                        <p class="text-sm text-slate-600 mb-4 line-clamp-2">{{ $pro->description }}</p>
                    @endif
                    <div class="mt-auto">
                        <a href="{{ route('patient.appointments.schedule', $pro) }}"
                           class="btn-mentea w-full">
                            Elegir horario
                        </a>
                    </div>
                </div>
            @empty
                <div class="col-span-full mentea-card">
                    <div class="mentea-empty-state">
                        <span class="mentea-avatar w-12 h-12"><x-icon name="users" class="w-6 h-6" /></span>
                        <p class="font-medium text-slate-600">No hay profesionales disponibles en esta especialidad</p>
                        <a href="{{ route('patient.appointments.create') }}" class="btn-mentea-outline mt-2">Ver todas las especialidades</a>
                    </div>
                </div>
            @endforelse
        </div>
    </div>
</x-app-layout>
