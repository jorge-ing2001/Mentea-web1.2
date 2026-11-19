<x-app-layout>
    <x-slot name="header"><h2 class="mentea-page-title">Bienvenido a Mentea</h2></x-slot>
    <div class="mentea-section">
        <div class="max-w-xl mx-auto w-full">
            <div class="mentea-card p-6 space-y-6">
                <ol class="flex items-center gap-2 text-xs font-medium text-mentea-700">
                    <li class="flex-1 text-center rounded-full bg-mentea-50 border border-mentea-100 py-1.5">1. Identidad</li>
                    <li class="flex-1 text-center rounded-full bg-mentea-50 border border-mentea-100 py-1.5">2. Contacto</li>
                    <li class="flex-1 text-center rounded-full bg-mentea-50 border border-mentea-100 py-1.5">3. Consentimientos</li>
                </ol>
                <p class="text-sm text-slate-600">Completa estos datos una sola vez para agendar con seguridad.</p>
                <form method="POST" action="{{ route('patient.onboarding.store') }}" class="space-y-4" aria-label="Onboarding paciente">
                    @csrf
                    <fieldset class="space-y-3">
                        <legend class="font-semibold text-slate-800">Paso 1 — Identidad</legend>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="mentea-label">Tipo documento</label>
                                <select name="document_type" class="mentea-input" required>
                                    @foreach(['CC','CE','TI','PA','RC','OTRO'] as $t)
                                        <option value="{{ $t }}" @selected(old('document_type', $user->document_type)===$t)>{{ $t }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mentea-label">Número</label>
                                <input name="document_number" class="mentea-input" required value="{{ old('document_number', $user->document_number) }}">
                            </div>
                        </div>
                    </fieldset>
                    <fieldset class="space-y-3">
                        <legend class="font-semibold text-slate-800">Paso 2 — Contacto y EPS</legend>
                        <div>
                            <label class="mentea-label">Teléfono</label>
                            <input name="phone" class="mentea-input" required value="{{ old('phone', $user->phone) }}">
                        </div>
                        <div>
                            <label class="mentea-label">EPS</label>
                            <input name="eps" class="mentea-input" value="{{ old('eps', $user->eps) }}">
                        </div>
                        <div>
                            <label class="mentea-label">Contacto de emergencia</label>
                            <input name="emergency_contact_name" class="mentea-input" required value="{{ old('emergency_contact_name', $user->emergency_contact_name) }}">
                        </div>
                        <div>
                            <label class="mentea-label">Tel. emergencia</label>
                            <input name="emergency_contact_phone" class="mentea-input" required value="{{ old('emergency_contact_phone', $user->emergency_contact_phone) }}">
                        </div>
                        <div>
                            <label class="mentea-label">Tema de la interfaz</label>
                            <select name="preferred_theme" class="mentea-input">
                                <option value="system">Sistema</option>
                                <option value="light">Claro</option>
                                <option value="dark">Oscuro</option>
                            </select>
                        </div>
                    </fieldset>
                    <fieldset class="space-y-2">
                        <legend class="font-semibold text-slate-800">Paso 3 — Consentimientos</legend>
                        <label class="flex gap-2 text-sm text-slate-700"><input type="checkbox" name="consent_informed" value="1" required class="mt-0.5 rounded text-mentea-600 focus:ring-mentea-500"> Consentimiento informado *</label>
                        <label class="flex gap-2 text-sm text-slate-700"><input type="checkbox" name="consent_data" value="1" required class="mt-0.5 rounded text-mentea-600 focus:ring-mentea-500"> Tratamiento de datos (Ley 1581) *</label>
                    </fieldset>
                    <button type="submit" class="btn-mentea w-full">Finalizar y entrar a Mentea</button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
