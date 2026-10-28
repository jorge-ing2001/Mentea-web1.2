<x-app-layout>
    <x-slot name="header">
        <h2 class="mentea-page-title">Nuevo profesional</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('admin.professionals.store') }}" class="mentea-card p-6 space-y-4">
                @csrf
                @include('admin.professionals._form')
                <div class="flex gap-3 pt-2">
                    <a href="{{ route('admin.professionals.index') }}" class="btn-mentea-outline">Cancelar</a>
                    <button type="submit" class="btn-mentea flex-1">Crear</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
