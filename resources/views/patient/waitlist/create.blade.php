<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center flex-wrap gap-3">
            <h2 class="mentea-page-title">Nueva solicitud · Lista de espera</h2>
            <a href="{{ route('patient.waitlist.index') }}" class="btn-mentea-outline">Mis solicitudes</a>
        </div>
    </x-slot>
    <div class="mentea-section">
        <div class="max-w-2xl mx-auto w-full">
            <form method="POST" action="{{ route('patient.waitlist.store') }}" class="mentea-card p-6 space-y-5" id="waitlist-form">
                @csrf
                <div class="rounded-xl bg-mentea-50 border border-mentea-100 p-4 text-sm text-mentea-800">
                    Indica tus preferencias. Te avisaremos cuando haya un horario compatible (prioridad por orden de llegada).
                </div>

                @if ($errors->any())
                    <div class="alert-error">
                        <ul class="list-disc list-inside space-y-0.5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif

                <div>
                    <label for="specialty" class="mentea-label">Especialidad *</label>
                    <select name="specialty" id="specialty" required class="mentea-input">
                        <option value="">Selecciona</option>
                        @foreach($specialties as $s)
                            <option value="{{ $s }}" @selected(old('specialty')===$s)>{{ $s }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="professional_id" class="mentea-label">Profesional</label>
                    <select name="professional_id" id="professional_id" class="mentea-input">
                        <option value="">Cualquier profesional</option>
                        @foreach($professionals as $specialty => $pros)
                            @foreach($pros as $pro)
                                <option value="{{ $pro->id }}" data-specialty="{{ $pro->specialty }}" @selected(old('professional_id')==$pro->id)>
                                    {{ $pro->name }} — {{ $pro->specialty }}
                                </option>
                            @endforeach
                        @endforeach
                    </select>
                    <p class="text-xs text-slate-500 mt-1">Si eliges uno específico, solo se mostrarán los de la especialidad seleccionada.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="mentea-label">Fecha inicial *</label>
                        <input type="date" name="date_from" value="{{ old('date_from') }}" min="{{ date('Y-m-d') }}" required class="mentea-input">
                    </div>
                    <div>
                        <label class="mentea-label">Fecha final *</label>
                        <input type="date" name="date_to" value="{{ old('date_to') }}" min="{{ date('Y-m-d') }}" required class="mentea-input">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="mentea-label">Hora desde</label>
                        <input type="time" name="time_from" value="{{ old('time_from', '08:00') }}" class="mentea-input">
                    </div>
                    <div>
                        <label class="mentea-label">Hora hasta</label>
                        <input type="time" name="time_to" value="{{ old('time_to', '12:00') }}" class="mentea-input">
                    </div>
                </div>

                <div>
                    <label class="mentea-label">Modalidad *</label>
                    <div class="flex flex-wrap gap-4">
                        @foreach($modalities as $mod)
                            <label class="flex items-center gap-2 text-sm text-slate-700">
                                <input type="radio" name="modality" value="{{ $mod->value }}" required
                                    @checked(old('modality', 'presencial') === $mod->value) class="text-mentea-600 focus:ring-mentea-500">
                                <span>{{ $mod->label() }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div>
                    <label for="notes" class="mentea-label">Notas (opcional)</label>
                    <textarea name="notes" id="notes" rows="2" class="mentea-input">{{ old('notes') }}</textarea>
                </div>

                <button type="submit" class="btn-mentea w-full">
                    Confirmar lista de espera
                </button>
            </form>
        </div>
    </div>
    <script>
        const specialty = document.getElementById('specialty');
        const proSelect = document.getElementById('professional_id');
        function filterPros() {
            const s = specialty.value;
            Array.from(proSelect.options).forEach((opt, i) => {
                if (i === 0) return; // Cualquiera
                const match = !s || opt.dataset.specialty === s;
                opt.hidden = !match;
                if (!match && opt.selected) proSelect.value = '';
            });
        }
        specialty.addEventListener('change', filterPros);
        filterPros();
    </script>
</x-app-layout>
