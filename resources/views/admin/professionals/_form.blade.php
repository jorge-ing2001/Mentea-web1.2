@php $p = $professional ?? null; @endphp
<div>
    <label for="name" class="mentea-label">Nombre *</label>
    <input type="text" id="name" name="name" value="{{ old('name', $p->name ?? '') }}" required class="mentea-input">
    @error('name') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
</div>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <label for="specialty" class="mentea-label">Especialidad *</label>
        <input type="text" id="specialty" name="specialty" value="{{ old('specialty', $p->specialty ?? '') }}" required list="specialties" class="mentea-input">
        <datalist id="specialties">
            <option value="Psicología"><option value="Psiquiatría"><option value="Neuropsicología">
        </datalist>
        @error('specialty') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="profession" class="mentea-label">Profesión</label>
        <input type="text" id="profession" name="profession" value="{{ old('profession', $p->profession ?? '') }}" class="mentea-input">
    </div>
</div>
<div>
    <label for="email" class="mentea-label">Email *</label>
    <input type="email" id="email" name="email" value="{{ old('email', $p->email ?? '') }}" required class="mentea-input">
    @error('email') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
</div>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <label for="phone" class="mentea-label">Teléfono</label>
        <input type="text" id="phone" name="phone" value="{{ old('phone', $p->phone ?? '') }}" class="mentea-input">
    </div>
    <div>
        <label for="appointment_duration_minutes" class="mentea-label">Duración cita (min) *</label>
        <input type="number" id="appointment_duration_minutes" name="appointment_duration_minutes" value="{{ old('appointment_duration_minutes', $p->appointment_duration_minutes ?? 45) }}" min="15" max="180" required class="mentea-input">
    </div>
</div>
<div>
    <label for="description" class="mentea-label">Descripción</label>
    <textarea id="description" name="description" rows="3" class="mentea-input">{{ old('description', $p->description ?? '') }}</textarea>
</div>
<div class="flex items-center gap-2">
    <input type="checkbox" name="is_available" value="1" id="is_available"
           @checked(old('is_available', $p->is_available ?? true)) class="rounded border-slate-300 text-mentea-600 focus:ring-mentea-500">
    <label for="is_available" class="text-sm text-slate-700">Disponible para agendar</label>
</div>
