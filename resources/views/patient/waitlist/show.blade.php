<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center flex-wrap gap-3">
            <h2 class="mentea-page-title">Solicitud #{{ $waitlist->id }}</h2>
            <a href="{{ route('patient.waitlist.index') }}" class="btn-mentea-outline">Volver al listado</a>
        </div>
    </x-slot>
    <div class="mentea-section">
        <div class="max-w-2xl mx-auto w-full space-y-4">
            @if($errors->any())
                <div class="alert-error">{{ $errors->first() }}</div>
            @endif

            <div class="mentea-card p-6 space-y-4 text-sm">
                <div class="flex justify-between items-start gap-3">
                    <div class="font-semibold text-base text-slate-800">{{ $waitlist->specialty }}</div>
                    <span class="shrink-0 px-2.5 py-1 text-xs font-medium rounded-full bg-{{ $waitlist->status->color() }}-100 text-{{ $waitlist->status->color() }}-800">
                        {{ $waitlist->status->emoji() }} {{ $waitlist->status->label() }}
                    </span>
                </div>
                <div class="grid grid-cols-2 gap-4 border-t border-slate-100 pt-4">
                    <div>
                        <div class="text-slate-500">Profesional</div>
                        <div class="text-slate-800">{{ $waitlist->professional?->name ?? 'Cualquier profesional' }}</div>
                    </div>
                    <div>
                        <div class="text-slate-500">Modalidad</div>
                        <div class="text-slate-800">{{ $waitlist->modality?->label() ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-slate-500">Rango de fechas</div>
                        <div class="text-slate-800">
                            @if($waitlist->date_from && $waitlist->date_to)
                                {{ $waitlist->date_from->format('d/m/Y') }} – {{ $waitlist->date_to->format('d/m/Y') }}
                            @else — @endif
                        </div>
                    </div>
                    <div>
                        <div class="text-slate-500">Horario preferido</div>
                        <div class="text-slate-800">
                            @if($waitlist->time_from && $waitlist->time_to)
                                {{ \Carbon\Carbon::parse($waitlist->time_from)->format('H:i') }} – {{ \Carbon\Carbon::parse($waitlist->time_to)->format('H:i') }}
                            @else Cualquiera @endif
                        </div>
                    </div>
                    <div>
                        <div class="text-slate-500">Creada</div>
                        <div class="text-slate-800">{{ $waitlist->created_at->format('d/m/Y H:i') }}</div>
                    </div>
                    @if($waitlist->cancelled_at)
                        <div><div class="text-slate-500">Cancelada</div><div class="text-slate-800">{{ $waitlist->cancelled_at->format('d/m/Y H:i') }}</div></div>
                    @endif
                    @if($waitlist->expired_at)
                        <div><div class="text-slate-500">Vencida</div><div class="text-slate-800">{{ $waitlist->expired_at->format('d/m/Y H:i') }}</div></div>
                    @endif
                    @if($waitlist->confirmed_at)
                        <div><div class="text-slate-500">Confirmada</div><div class="text-slate-800">{{ $waitlist->confirmed_at->format('d/m/Y H:i') }}</div></div>
                    @endif
                </div>
                @if($waitlist->notes)
                    <div class="text-slate-500 border-t border-slate-100 pt-4">Notas: <span class="text-slate-800">{{ $waitlist->notes }}</span></div>
                @endif
            </div>

            @if($waitlist->isOfferActive())
                <div class="bg-blue-50 border border-blue-200 rounded-2xl p-6 space-y-4">
                    <h3 class="font-semibold text-blue-900">Disponibilidad encontrada</h3>
                    <div class="text-sm text-blue-900 space-y-1">
                        <p><strong>Profesional:</strong> {{ $waitlist->offeredProfessional?->name }}</p>
                        <p><strong>Fecha:</strong> {{ $waitlist->offered_date?->format('d/m/Y') }} a las {{ \Carbon\Carbon::parse($waitlist->offered_time)->format('H:i') }}</p>
                        <p><strong>Modalidad:</strong> {{ $waitlist->offered_modality?->label() }}</p>
                    </div>
                    <p class="text-sm text-blue-800">
                        Disponibilidad reservada durante
                        <span class="font-mono font-bold text-lg countdown" data-expires="{{ $waitlist->offer_expires_at->toIso8601String() }}">--:--</span>
                        minutos.
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <form method="POST" action="{{ route('patient.waitlist.confirm', $waitlist) }}">
                            @csrf
                            <button type="submit" class="btn-mentea">Confirmar cita</button>
                        </form>
                        <form method="POST" action="{{ route('patient.waitlist.reject', $waitlist) }}" onsubmit="return confirm('¿Rechazar esta disponibilidad?');">
                            @csrf
                            <button type="submit" class="btn-mentea-outline">Rechazar</button>
                        </form>
                    </div>
                </div>
            @endif

            @if($waitlist->status->value === 'waiting')
                <form method="POST" action="{{ route('patient.waitlist.cancel', $waitlist) }}"
                      onsubmit="return confirm('¿Estás seguro de que deseas retirar esta solicitud de la lista de espera?');">
                    @csrf
                    <button type="submit" class="inline-flex items-center justify-center w-full px-4 py-2.5 rounded-xl border border-red-200 text-red-700 font-medium text-sm hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-400 focus:ring-offset-2 transition">
                        Salir de lista de espera
                    </button>
                </form>
            @endif

            @if($waitlist->assigned_appointment_id)
                <a href="{{ route('patient.appointments.show', $waitlist->assigned_appointment_id) }}" class="block text-center text-mentea-700 hover:underline text-sm">
                    Ver cita generada →
                </a>
            @endif
        </div>
    </div>
    <script>
        function tick() {
            document.querySelectorAll('.countdown').forEach(el => {
                const exp = new Date(el.dataset.expires).getTime();
                const diff = Math.max(0, Math.floor((exp - Date.now()) / 1000));
                const m = String(Math.floor(diff / 60)).padStart(2, '0');
                const s = String(diff % 60).padStart(2, '0');
                el.textContent = m + ':' + s;
                if (diff === 0) el.closest('.bg-blue-50')?.classList.add('opacity-50');
            });
        }
        tick(); setInterval(tick, 1000);
    </script>
</x-app-layout>
