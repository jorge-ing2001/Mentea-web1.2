@props(['appointment'])
@if($appointment->order_document || $appointment->authorization_document)
<div class="mentea-card p-4 space-y-3 bg-slate-50">
    <h4 class="font-medium text-sm text-slate-800">Documentos adjuntos</h4>
    <div class="grid sm:grid-cols-2 gap-3">
        <div class="bg-white border border-slate-200 rounded-xl p-3">
            <div class="text-xs font-semibold text-mentea-700 uppercase tracking-wide mb-2">Orden</div>
            @if($appointment->order_document)
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('documents.view', ['appointment' => $appointment, 'type' => 'order']) }}"
                       target="_blank" rel="noopener"
                       class="btn-mentea text-sm px-3 py-1.5">
                        Ver documento
                    </a>
                    <a href="{{ route('documents.download', ['appointment' => $appointment, 'type' => 'order']) }}"
                       class="btn-mentea-outline text-sm px-3 py-1.5">
                        Descargar
                    </a>
                </div>
            @else
                <p class="text-sm text-slate-400">No adjuntada</p>
            @endif
        </div>
        <div class="bg-white border border-slate-200 rounded-xl p-3">
            <div class="text-xs font-semibold text-emerald-700 uppercase tracking-wide mb-2">Autorización</div>
            @if($appointment->authorization_document)
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('documents.view', ['appointment' => $appointment, 'type' => 'authorization']) }}"
                       target="_blank" rel="noopener"
                       class="btn-mentea text-sm px-3 py-1.5">
                        Ver documento
                    </a>
                    <a href="{{ route('documents.download', ['appointment' => $appointment, 'type' => 'authorization']) }}"
                       class="btn-mentea-outline text-sm px-3 py-1.5">
                        Descargar
                    </a>
                </div>
            @else
                <p class="text-sm text-slate-400">No adjuntada</p>
            @endif
        </div>
    </div>
</div>
@endif
