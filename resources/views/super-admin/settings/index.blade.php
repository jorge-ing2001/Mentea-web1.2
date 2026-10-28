<x-app-layout>
    <x-slot name="header">
        <h2 class="mentea-page-title">Configuración del sistema</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="alert-success mb-4">{{ session('success') }}</div>
            @endif
            <form method="POST" action="{{ route('super.settings.update') }}" class="mentea-card p-6 sm:p-8 space-y-8">
                @csrf
                @method('PUT')
                @php
                    $groupLabels = [
                        'general' => 'General',
                        'citas' => 'Citas',
                        'lista_espera' => 'Lista de espera',
                        'notificaciones' => 'Notificaciones',
                    ];
                    $grouped = collect($defs)->groupBy(fn ($meta) => $meta['group']);
                @endphp
                @foreach($grouped as $group => $items)
                    <fieldset class="space-y-4">
                        <legend class="text-sm font-semibold text-slate-800 uppercase tracking-wide">
                            {{ $groupLabels[$group] ?? ucfirst($group) }}
                        </legend>
                        <div class="grid sm:grid-cols-2 gap-5">
                            @foreach($items as $key => $meta)
                                <div>
                                    <label class="mentea-label" for="{{ $key }}">{{ $meta['label'] }}</label>
                                    @if($meta['type'] === 'boolean')
                                        <select name="{{ $key }}" id="{{ $key }}" class="mentea-input">
                                            <option value="1" @selected((string)$values[$key] === '1')>Sí</option>
                                            <option value="0" @selected((string)$values[$key] === '0')>No</option>
                                        </select>
                                    @else
                                        <input type="{{ $meta['type'] === 'integer' ? 'number' : 'text' }}"
                                               name="{{ $key }}" id="{{ $key }}"
                                               value="{{ old($key, $values[$key]) }}"
                                               class="mentea-input">
                                    @endif
                                    <p class="text-xs text-slate-500 mt-1.5">{{ $meta['help'] }}</p>
                                    @error($key)<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
                                </div>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
                <div class="pt-2 border-t border-slate-100">
                    <button type="submit" class="btn-mentea">Guardar cambios</button>
                </div>
            </form>
            <form method="POST" action="{{ route('super.mail.test') }}" class="mt-4">
                @csrf
                <button type="submit" class="btn-mentea-outline">Enviar correo de prueba SMTP</button>
                @error('mail')<p class="text-sm text-red-600 mt-2">{{ $message }}</p>@enderror
            </form>
        </div>
    </div>
</x-app-layout>
