<x-app-layout>
    <x-slot name="header">
        <h2 class="mentea-page-title">Agendar con {{ $professional->name }}</h2>
    </x-slot>
    <div class="mentea-section">
        <div class="max-w-2xl mx-auto w-full space-y-6">
            <div class="mentea-card p-6">
                <div class="flex items-center gap-4">
                    <span class="mentea-avatar w-12 h-12 text-lg">{{ strtoupper(substr($professional->name, 0, 1)) }}</span>
                    <div class="min-w-0">
                        <p class="font-semibold text-slate-800">{{ $professional->specialty }}</p>
                        <p class="text-sm text-slate-500">{{ $professional->profession }}</p>
                    </div>
                </div>
                @if($professional->description)
                    <p class="mt-3 text-sm text-slate-600">{{ $professional->description }}</p>
                @endif
            </div>

            <form method="POST" action="{{ route('patient.appointments.store') }}" enctype="multipart/form-data" id="book-form" aria-label="Formulario de agendamiento de cita" class="mentea-card p-6 space-y-6">
                @csrf
                <input type="hidden" name="booking_token" value="{{ \Illuminate\Support\Str::uuid() }}">
                <input type="hidden" name="professional_id" value="{{ $professional->id }}">

                @if ($errors->any())
                    <div class="alert-error">
                        <ul class="list-disc list-inside space-y-0.5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif

                <div>
                    <label for="date" class="mentea-label">Fecha *</label>
                    <select name="date" id="date" required class="mentea-input">
                        <option value="">Selecciona una fecha</option>
                        @foreach($availableDates as $d)
                            <option value="{{ $d }}" @selected(old('date')===$d)>{{ \Carbon\Carbon::parse($d)->locale('es')->isoFormat('dddd D [de] MMMM') }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mentea-label">Hora *</label>
                    <div id="slots-container" class="grid grid-cols-3 sm:grid-cols-4 gap-2 min-h-[40px]">
                        <p class="col-span-full text-sm text-slate-400">Selecciona una fecha</p>
                    </div>
                    <input type="hidden" name="time" id="time" value="{{ old('time') }}" required>
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
                    <label for="reason" class="mentea-label">Motivo (opcional)</label>
                    <textarea name="reason" id="reason" rows="2" class="mentea-input">{{ old('reason') }}</textarea>
                </div>

                <div class="border-t border-slate-100 pt-5 space-y-4">
                    <p class="text-sm font-semibold text-slate-800">Documentos obligatorios (PDF o imagen)</p>
                    <div>
                        <label class="mentea-label">Orden *</label>
                        <input type="file" name="order_document" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*" required
                               class="block w-full text-sm text-slate-600 rounded-xl border border-slate-300 p-2 file:mr-3 file:rounded-lg file:border-0 file:bg-mentea-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-mentea-700 hover:file:bg-mentea-100">
                        <p class="text-xs text-slate-500 mt-1">PDF, JPG, PNG o WEBP · máx. 10 MB</p>
                        @error('order_document') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mentea-label">Autorización *</label>
                        <input type="file" name="authorization_document" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*" required
                               class="block w-full text-sm text-slate-600 rounded-xl border border-slate-300 p-2 file:mr-3 file:rounded-lg file:border-0 file:bg-mentea-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-mentea-700 hover:file:bg-mentea-100">
                        <p class="text-xs text-slate-500 mt-1">PDF, JPG, PNG o WEBP · máx. 10 MB</p>
                        @error('authorization_document') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('patient.appointments.create') }}" class="btn-mentea-outline">Volver</a>
                    <button type="submit" id="submit-btn" class="btn-mentea flex-1" disabled>
                        Confirmar cita
                    </button>
                </div>
            </form>
        </div>
    </div>
    <script>
        const dateSelect = document.getElementById('date');
        const slotsContainer = document.getElementById('slots-container');
        const timeInput = document.getElementById('time');
        const submitBtn = document.getElementById('submit-btn');
        const slotsUrl = @json(route('patient.appointments.slots', $professional));
        function updateSubmit() {
            submitBtn.disabled = !dateSelect.value || !timeInput.value;
        }
        dateSelect.addEventListener('change', async () => {
            timeInput.value = ''; updateSubmit();
            slotsContainer.innerHTML = '<p class="col-span-full text-sm text-slate-400">Cargando...</p>';
            if (!dateSelect.value) return;
            try {
                const res = await fetch(`${slotsUrl}?date=${dateSelect.value}`, { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                const slots = data.slots || [];
                if (!slots.length) {
                    slotsContainer.innerHTML = '<p class="col-span-full text-sm text-amber-600">Sin horarios disponibles.</p>';
                    return;
                }
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
                slotsContainer.innerHTML = '<p class="col-span-full text-sm text-red-600">Error al cargar horarios.</p>';
            }
        });
    </script>
</x-app-layout>
