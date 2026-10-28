<x-app-layout>
    <x-slot name="header">
        <h2 class="mentea-page-title">Asignar cita desde lista de espera</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="mentea-card p-5 text-sm space-y-1">
                <p><strong class="text-slate-800">Paciente:</strong> {{ $waitlist->patient->name ?? '—' }} ({{ $waitlist->patient->email ?? '' }})</p>
                <p><strong class="text-slate-800">Preferencia:</strong> {{ $waitlist->specialty ?? 'Cualquiera' }}
                    @if($waitlist->professional) · {{ $waitlist->professional->name }} @endif
                </p>
                @if($waitlist->notes)
                    <p class="text-slate-500">{{ $waitlist->notes }}</p>
                @endif
            </div>

            <form method="POST" action="{{ route('admin.waitlist.assign.store', $waitlist) }}" id="assign-form" class="mentea-card p-6 space-y-4">
                @csrf
                @if ($errors->any())
                    <div class="alert-error">
                        <ul class="list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif
                <div>
                    <label for="professional_id" class="mentea-label">Profesional *</label>
                    <select name="professional_id" id="professional_id" required class="mentea-input">
                        <option value="">Selecciona</option>
                        @foreach($professionals as $pro)
                            <option value="{{ $pro->id }}" @selected(old('professional_id', $professional?->id) == $pro->id)>
                                {{ $pro->name }} — {{ $pro->specialty }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="date" class="mentea-label">Fecha *</label>
                    <input type="date" name="date" id="date" value="{{ old('date') }}" min="{{ date('Y-m-d') }}" required class="mentea-input">
                </div>
                <div>
                    <label class="mentea-label">Hora disponible *</label>
                    <div id="slots-container" class="grid grid-cols-3 sm:grid-cols-4 gap-2 min-h-[40px]">
                        <p class="col-span-full text-sm text-slate-400">Selecciona profesional y fecha</p>
                    </div>
                    <input type="hidden" name="time" id="time" value="{{ old('time') }}" required>
                </div>
                <div>
                    <label class="mentea-label">Modalidad *</label>
                    <div class="flex gap-4">
                        <label class="flex items-center gap-2"><input type="radio" name="modality" value="presencial" checked class="text-mentea-600 focus:ring-mentea-500"> Presencial</label>
                        <label class="flex items-center gap-2"><input type="radio" name="modality" value="virtual" class="text-mentea-600 focus:ring-mentea-500"> Virtual</label>
                    </div>
                </div>
                <div>
                    <label for="reason" class="mentea-label">Motivo / notas</label>
                    <textarea name="reason" id="reason" rows="2" class="mentea-input">{{ old('reason') }}</textarea>
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('admin.waitlist.index') }}" class="btn-mentea-outline">Cancelar</a>
                    <button type="submit" id="submit-btn" class="btn-mentea flex-1 disabled:opacity-50" disabled>
                        Asignar y crear cita
                    </button>
                </div>
            </form>
        </div>
    </div>
    <script>
        const proSelect = document.getElementById('professional_id');
        const dateInput = document.getElementById('date');
        const slotsContainer = document.getElementById('slots-container');
        const timeInput = document.getElementById('time');
        const submitBtn = document.getElementById('submit-btn');
        const slotsUrl = @json(route('admin.waitlist.slots'));
        function updateSubmit() { submitBtn.disabled = !proSelect.value || !dateInput.value || !timeInput.value; }
        async function loadSlots() {
            timeInput.value = ''; updateSubmit();
            if (!proSelect.value || !dateInput.value) {
                slotsContainer.innerHTML = '<p class="col-span-full text-sm text-slate-400">Selecciona profesional y fecha</p>';
                return;
            }
            slotsContainer.innerHTML = '<p class="col-span-full text-sm text-slate-400">Cargando...</p>';
            try {
                const url = `${slotsUrl}?professional_id=${proSelect.value}&date=${dateInput.value}`;
                const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                const slots = data.slots || [];
                if (!slots.length) {
                    slotsContainer.innerHTML = '<p class="col-span-full text-sm text-amber-600">Sin horarios libres ese día.</p>';
                    return;
                }
                slotsContainer.innerHTML = '';
                slots.forEach(slot => {
                    const btn = document.createElement('button');
                    btn.type = 'button'; btn.textContent = slot;
                    btn.className = 'px-3 py-2 rounded-lg border text-sm hover:border-mentea-500 hover:bg-mentea-50';
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
        }
        proSelect.addEventListener('change', loadSlots);
        dateInput.addEventListener('change', loadSlots);
    </script>
</x-app-layout>
