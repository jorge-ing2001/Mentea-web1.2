<x-app-layout>
    <x-slot name="header">
        <h2 class="mentea-page-title">Permisos: {{ $role->name }}</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('super.roles.update', $role) }}" class="mentea-card p-6 space-y-6">
                @csrf
                @method('PUT')
                @foreach($permissions as $group => $perms)
                    <fieldset>
                        <legend class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">{{ $group }}</legend>
                        <div class="grid sm:grid-cols-2 gap-2">
                            @foreach($perms as $perm)
                                <label class="flex items-center gap-2.5 text-sm text-slate-700 rounded-lg px-3 py-2 hover:bg-slate-50 transition cursor-pointer">
                                    <input type="checkbox" name="permissions[]" value="{{ $perm->name }}"
                                        class="rounded border-slate-300 text-mentea-600 focus:ring-mentea-500"
                                        @checked($role->hasPermissionTo($perm->name))>
                                    {{ $perm->name }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
                <div class="flex gap-3">
                    <button type="submit" class="btn-mentea">Guardar permisos</button>
                    <a href="{{ route('super.roles.index') }}" class="btn-mentea-ghost">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
