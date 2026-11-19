<x-app-layout>
    <x-slot name="header">
        <h2 class="mentea-page-title">Editar · {{ $professional->name }}</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if(session('success'))
                <div class="alert-success">{{ session('success') }}</div>
            @endif
            <form method="POST" action="{{ route('admin.professionals.update', $professional) }}" class="mentea-card p-6 space-y-4">
                @csrf @method('PUT')
                @include('admin.professionals._form', ['professional' => $professional])
                <div class="flex flex-wrap gap-3 pt-2">
                    <a href="{{ route('admin.professionals.index') }}" class="btn-mentea-outline">Volver</a>
                    <a href="{{ route('admin.schedules.edit', $professional) }}" class="btn-mentea-outline">Horarios</a>
                    <button type="submit" class="btn-mentea flex-1">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
