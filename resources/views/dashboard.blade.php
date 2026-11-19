<x-app-layout>
    <x-slot name="header">
        <h2 class="mentea-page-title">{{ __('Dashboard') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="mentea-card p-6 text-slate-700">
                {{ __("You're logged in!") }}
            </div>
        </div>
    </div>
</x-app-layout>
