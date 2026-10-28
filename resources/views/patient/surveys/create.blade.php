<x-app-layout>
    <x-slot name="header">
        <h2 class="mentea-page-title">Valora tu cita</h2>
    </x-slot>
    <div class="mentea-section">
        <div class="max-w-lg mx-auto w-full space-y-4">
            <div class="mentea-card p-5 text-sm text-slate-600">
                Cita con <strong class="text-slate-800">{{ $appointment->professional->name }}</strong>
                el {{ $appointment->date->format('d/m/Y') }} a las {{ \Carbon\Carbon::parse($appointment->time)->format('H:i') }}
            </div>
            <form method="POST" action="{{ route('patient.surveys.store', $appointment) }}" class="mentea-card p-6 space-y-5">
                @csrf
                <div>
                    <label class="mentea-label">¿Cómo calificarías la atención? *</label>
                    <div class="flex flex-wrap gap-2 mt-1">
                        @for($i = 1; $i <= 5; $i++)
                            <label class="cursor-pointer">
                                <input type="radio" name="rating" value="{{ $i }}" required class="peer sr-only" @checked(old('rating')==$i)>
                                <span class="flex items-center justify-center w-12 h-12 rounded-xl border border-slate-200 text-base font-semibold text-slate-600 transition hover:border-mentea-400 hover:bg-mentea-50 peer-checked:bg-mentea-600 peer-checked:text-white peer-checked:border-mentea-600 peer-focus-visible:ring-2 peer-focus-visible:ring-mentea-500 peer-focus-visible:ring-offset-2">{{ $i }}</span>
                            </label>
                        @endfor
                    </div>
                    <div class="flex justify-between text-xs text-slate-400 mt-1 max-w-[19rem]">
                        <span>Deficiente</span>
                        <span>Excelente</span>
                    </div>
                    @error('rating') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="comment" class="mentea-label">Comentario (opcional)</label>
                    <textarea name="comment" id="comment" rows="3" class="mentea-input">{{ old('comment') }}</textarea>
                </div>
                <button type="submit" class="btn-mentea w-full">Enviar valoración</button>
            </form>
        </div>
    </div>
</x-app-layout>
