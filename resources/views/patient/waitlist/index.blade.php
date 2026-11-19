<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center flex-wrap gap-3">
            <h2 class="mentea-page-title">Lista de espera</h2>
            <a href="{{ route('patient.waitlist.create') }}" class="btn-mentea">
                <x-icon name="plus" class="w-4 h-4" /> Nueva solicitud
            </a>
        </div>
    </x-slot>
    <div class="mentea-section">
        @if(session('success'))
            <div class="alert-success">{{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div class="alert-error">{{ $errors->first() }}</div>
        @endif

        <section>
            <h3 class="font-semibold text-slate-800 mb-3">Solicitudes activas</h3>
            <div class="space-y-3">
                @forelse($active as $e)
                    <a href="{{ route('patient.waitlist.show', $e) }}" class="block mentea-card-hover p-4">
                        <div class="flex flex-wrap justify-between gap-2 items-start">
                            <div>
                                <div class="font-medium text-slate-800">{{ $e->specialty }}</div>
                                <div class="text-sm text-slate-500 mt-1">{{ $e->preferenceSummary() }}</div>
                                <div class="text-xs text-slate-400 mt-1">Solicitada {{ $e->created_at->format('d/m/Y H:i') }}</div>
                            </div>
                            <span class="px-2.5 py-1 text-xs font-medium rounded-full bg-{{ $e->status->color() }}-100 text-{{ $e->status->color() }}-800">
                                {{ $e->status->emoji() }} {{ $e->status->label() }}
                            </span>
                        </div>
                        @if($e->isOfferActive())
                            <div class="mt-3 p-2.5 rounded-xl bg-blue-50 border border-blue-100 text-sm text-blue-800">
                                Oferta disponible · expira en <span class="font-mono font-semibold countdown" data-expires="{{ $e->offer_expires_at->toIso8601String() }}">--:--</span>
                            </div>
                        @endif
                    </a>
                @empty
                    <div class="mentea-card">
                        <div class="mentea-empty-state">
                            <span class="mentea-avatar w-12 h-12"><x-icon name="clock" class="w-6 h-6" /></span>
                            <p class="font-medium text-slate-600">No tienes solicitudes activas</p>
                            <a href="{{ route('patient.waitlist.create') }}" class="btn-mentea mt-2">Crear una</a>
                        </div>
                    </div>
                @endforelse
            </div>
        </section>

        <section>
            <h3 class="font-semibold text-slate-800 mb-3">Historial</h3>
            <div class="mentea-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="mentea-table">
                        <thead>
                            <tr>
                                <th>Especialidad</th>
                                <th>Preferencia</th>
                                <th>Estado</th>
                                <th>Fecha solicitud</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($history as $e)
                                <tr>
                                    <td>
                                        <a href="{{ route('patient.waitlist.show', $e) }}" class="text-mentea-700 font-medium hover:underline">{{ $e->specialty }}</a>
                                    </td>
                                    <td class="text-slate-600">{{ $e->preferenceSummary() }}</td>
                                    <td>
                                        <span class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full bg-{{ $e->status->color() }}-100 text-{{ $e->status->color() }}-800">
                                            {{ $e->status->emoji() }} {{ $e->status->label() }}
                                        </span>
                                    </td>
                                    <td class="text-slate-500">{{ $e->created_at->format('d/m/Y') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-slate-500 py-6">Sin historial.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="mt-3">{{ $history->links() }}</div>
        </section>
    </div>
    <script>
        function tick() {
            document.querySelectorAll('.countdown').forEach(el => {
                const exp = new Date(el.dataset.expires).getTime();
                const diff = Math.max(0, Math.floor((exp - Date.now()) / 1000));
                const m = String(Math.floor(diff / 60)).padStart(2, '0');
                const s = String(diff % 60).padStart(2, '0');
                el.textContent = m + ':' + s;
            });
        }
        tick(); setInterval(tick, 1000);
    </script>
</x-app-layout>
