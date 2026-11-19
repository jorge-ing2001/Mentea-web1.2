<x-app-layout>
    <x-slot name="header">
        <h2 class="mentea-page-title">Reprogramar cita</h2>
    </x-slot>
    <div class="mentea-section">
        <div class="max-w-2xl mx-auto w-full space-y-6">
            <div class="mentea-card p-6 text-sm text-slate-700 space-y-1">
                <p><strong class="text-slate-800">Profesional:</strong> {{ $appointment->professional->name }}</p>
                <p><strong class="text-slate-800">Actual:</strong> {{ $appointment->date->format('d/m/Y') }} · {{ \Carbon\Carbon::parse($appointment->time)->format('H:i') }}</p>
                <p class="text-amber-700 pt-1">Solo disponible en las primeras 24 horas desde que agendaste (o si eres admin).</p>
            </div>
            <form method="POST" action="{{ route('patient.appointments.update', $appointment) }}" id="reschedule-form" class="mentea-card p-6 space-y-6">
                @csrf
                @method('PUT')
                @if ($errors->any())
                    <div class="alert-error">
                        <ul class="list-disc list-inside space-y-0.5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif
                <div>
                    <label for="date" class="mentea-label">Nueva fecha</label>
                    <select name="date" id="date" required class="mentea-input">
                        <option value="">Selecciona</option>
                        @foreach($availableDates as $d)
                            <option value="{{ $d }}" @selected(old('date')===$d)>{{ \Carbon\Carbon::parse($d)->locale('es')->isoFormat('dddd D [de] MMMM') }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mentea-label">Nueva hora</label>
                    <div id="slots-container" class="grid grid-cols-3 sm:grid-cols-4 gap-2 min-h-[40px]">
                        <p class="col-span-full text-sm text-slate-400">Selecciona una fecha</p>
                    </div>
                    <input type="hidden" name="time" id="time" value="{{ old('time') }}" required>
                </div>
                <div>
                    <label class="mentea-label">Modalidad</label>
                    <div class="flex flex-wrap gap-4">
                        @foreach($modalities as $mod)
                            <label class="flex items-center gap-2 text-sm text-slate-700">
                                <input type="radio" name="modality" value="{{ $mod->value }}"
                                    @checked(old('modality', $appointment->modality->value) === $mod->value) class="text-mentea-600 focus:ring-mentea-500">
                                <span>{{ $mod->label() }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('patient.appointments.show', $appointment) }}" class="btn-mentea-outline">Cancelar</a>
                    <button type="submit" id="submit-btn" class="btn-mentea flex-1" disabled>Guardar nueva fecha</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        const dateSelect = document.getElementById('date');
        const slotsContainer = document.getElementById('slots-container');
        const timeInput = document.getElementById('time');
        const submitBtn = document.getElementById('submit-btn');
        const slotsUrl = @json(route('patient.appointments.slots', $appointment->professional));
        function updateSubmit() { submitBtn.disabled = !dateSelect.value || !timeInput.value; }
        dateSelect.addEventListener('change', async () => {
            timeInput.value = ''; updateSubmit();
            slotsContainer.innerHTML = '<p class="col-span-full text-sm text-slate-400">Cargando...</p>';
            if (!dateSelect.value) return;
            try {
                const res = await fetch(`${slotsUrl}?date=${dateSelect.value}`, { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                const slots = data.slots || [];
                if (!slots.length) { slotsContainer.innerHTML = '<p class="col-span-full text-sm text-amber-600">Sin horarios.</p>'; return; }
                slotsContainer.innerHTML = '';
                slots.forEach(slot => {
                    const btn = document.createElement('button');
                    btn.type = 'button'; btn.textContent = slot;
                    btn.className = 'px-3 py-2 rounded-xl border border-slate-200 text-sm font-medium text-slate-700 hover:border-mentea-500 hover:bg-mentea-50 focus:outline-none focus:ring-2 focus:ring-mentea-500';
                    btn.addEventListener('click', () => {
                        document.querySelectorAll('#slots-container button').forEach(b => b.classList.remove('bg-mentea-600','text-white','border-mentea-600'));
                        btn.classList.add('bg-mentea-600','text-white','border-mentea-600');
                        timeInput.value = slot; updateSubmit();
                    });
                    slotsContainer.appendChild(btn);
                });
            } catch (e) {
                slotsContainer.innerHTML = '<p class="col-span-full text-sm text-red-600">Error al cargar.</p>';
            }
        });
    </script>
</x-app-layout>
