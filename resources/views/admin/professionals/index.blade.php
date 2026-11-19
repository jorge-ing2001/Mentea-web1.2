<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center flex-wrap gap-2">
            <h2 class="mentea-page-title">Profesionales</h2>
            <a href="{{ route('admin.professionals.create') }}" class="btn-mentea text-sm">
                <x-icon name="plus" class="w-4 h-4" /> Nuevo
            </a>
        </div>
    </x-slot>
    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if(session('success'))
                <div class="alert-success">{{ session('success') }}</div>
            @endif
            <form method="GET" class="mentea-card p-4 flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[12rem]">
                    <label for="q" class="mentea-label">Buscar</label>
                    <input type="search" id="q" name="q" value="{{ request('q') }}" placeholder="Nombre, email o especialidad" class="mentea-input">
                </div>
                <div>
                    <label for="specialty" class="mentea-label">Especialidad</label>
                    <select id="specialty" name="specialty" class="mentea-input">
                        <option value="">Todas las especialidades</option>
                        @foreach($specialties as $s)
                            <option value="{{ $s }}" @selected(request('specialty')===$s)>{{ $s }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="status" class="mentea-label">Estado</label>
                    <select id="status" name="status" class="mentea-input">
                        <option value="">Todos los estados</option>
                        <option value="active" @selected(request('status')==='active')>Activos</option>
                        <option value="inactive" @selected(request('status')==='inactive')>Inactivos</option>
                    </select>
                </div>
                <button class="btn-mentea">Filtrar</button>
            </form>

            <div class="mentea-card overflow-x-auto">
                <table class="mentea-table">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Especialidad</th>
                            <th>Estado</th>
                            <th>Citas</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($professionals as $pro)
                            <tr class="{{ $pro->is_available ? '' : 'opacity-70' }}">
                                <td>
                                    <div class="flex items-center gap-3">
                                        <span class="mentea-avatar shrink-0">{{ \Illuminate\Support\Str::of($pro->name)->substr(0, 1)->upper() }}</span>
                                        <div>
                                            <div class="font-medium text-slate-800">{{ $pro->name }}</div>
                                            <div class="text-xs text-slate-500">{{ $pro->email }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td>{{ $pro->specialty }}</td>
                                <td>
                                    @if($pro->is_available)
                                        <span class="badge-active">Activo</span>
                                    @else
                                        <span class="badge-inactive">Inactivo</span>
                                    @endif
                                </td>
                                <td>{{ $pro->appointments_count }}</td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-3">
                                        <a href="{{ route('admin.professionals.edit', $pro) }}" class="font-medium text-mentea-600 hover:underline">Editar</a>
                                        <a href="{{ route('admin.schedules.edit', $pro) }}" class="font-medium text-mentea-600 hover:underline">Horarios</a>
                                        <form method="POST" action="{{ route('admin.professionals.toggle', $pro) }}" class="inline"
                                              onsubmit="return confirm('¿{{ $pro->is_available ? 'Inactivar' : 'Activar' }} a {{ $pro->name }}?');">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="font-medium {{ $pro->is_available ? 'text-amber-600' : 'text-green-600' }} hover:underline">
                                                {{ $pro->is_available ? 'Inactivar' : 'Activar' }}
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5">
                                <div class="mentea-empty-state">
                                    <x-icon name="users" class="w-10 h-10 mx-auto text-slate-300 mb-2" />
                                    <p>Sin profesionales.</p>
                                </div>
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $professionals->links() }}</div>
        </div>
    </div>
</x-app-layout>
