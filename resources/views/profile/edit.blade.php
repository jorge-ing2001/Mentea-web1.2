<x-app-layout>
    <x-slot name="header">
        <h2 class="mentea-page-title">Mi cuenta</h2>
    </x-slot>

    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
        <div class="mentea-card p-6 sm:p-8">
            @include('profile.partials.update-profile-information-form')
        </div>

        <div class="mentea-card p-6 sm:p-8">
            @include('profile.partials.update-password-form')
        </div>

        <div class="mentea-card border-red-200 p-6 sm:p-8">
            @include('profile.partials.delete-user-form')
        </div>
    </div>
</x-app-layout>
