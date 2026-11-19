<x-app-layout>
    <x-slot name="header">
        <h2 class="mentea-page-title">Horarios · {{ $professional->name }}</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="alert-success mb-4">{{ session('success') }}</div>
            @endif
            <form method="POST" action="{{ route('admin.schedules.update', $professional) }}" class="mentea-card p-6 space-y-4">
                @csrf @method('PUT')
                <p class="text-sm text-slate-500">Define los bloques horarios. Se eliminarán los actuales y se crearán los nuevos.</p>
                @php
                    $existing = $professional->schedules->groupBy('day_of_week');
                    $idx = 0;
                @endphp
                @foreach($days as $num => $label)
                    @php $daySchedules = $existing->get($num, collect()); @endphp
                    <div class="border border-slate-200 rounded-xl p-4">
                        <h4 class="font-semibold text-slate-800 mb-3">{{ $label }}</h4>
                        @forelse($daySchedules as $sch)
                            <div class="flex flex-wrap gap-2 items-center mb-2">
                                <input type="hidden" name="schedules[{{ $idx }}][day_of_week]" value="{{ $num }}">
                                <input type="time" name="schedules[{{ $idx }}][start_time]" value="{{ \Carbon\Carbon::parse($sch->start_time)->format('H:i') }}" class="mentea-input w-auto">
                                <span class="text-slate-400">—</span>
                                <input type="time" name="schedules[{{ $idx }}][end_time]" value="{{ \Carbon\Carbon::parse($sch->end_time)->format('H:i') }}" class="mentea-input w-auto">
                                <label class="flex items-center gap-1.5 text-sm text-slate-700">
                                    <input type="checkbox" name="schedules[{{ $idx }}][is_active]" value="1" @checked($sch->is_active) class="rounded border-slate-300 text-mentea-600 focus:ring-mentea-500">
                                    Activo
                                </label>
                            </div>
                            @php $idx++; @endphp
                        @empty
                            <div class="flex flex-wrap gap-2 items-center mb-2">
                                <input type="hidden" name="schedules[{{ $idx }}][day_of_week]" value="{{ $num }}">
                                <input type="time" name="schedules[{{ $idx }}][start_time]" class="mentea-input w-auto">
                                <span class="text-slate-400">—</span>
                                <input type="time" name="schedules[{{ $idx }}][end_time]" class="mentea-input w-auto">
                                <label class="flex items-center gap-1.5 text-sm text-slate-700">
                                    <input type="checkbox" name="schedules[{{ $idx }}][is_active]" value="1" checked class="rounded border-slate-300 text-mentea-600 focus:ring-mentea-500">
                                    Activo
                                </label>
                            </div>
                            @php $idx++; @endphp
                        @endforelse
                    </div>
                @endforeach
                <div class="flex gap-3 pt-2">
                    <a href="{{ route('admin.professionals.edit', $professional) }}" class="btn-mentea-outline">Volver</a>
                    <button type="submit" class="btn-mentea flex-1">Guardar horarios</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
