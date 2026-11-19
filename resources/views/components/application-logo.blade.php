@props(['variant' => 'symbol'])

@if($variant === 'horizontal')
    {{-- Para superficies claras: logo horizontal de tinta oscura --}}
    <img src="{{ asset('images/brand/logo-horizontal-claro.png') }}" alt="Mentea" {{ $attributes->merge(['class' => 'h-10 w-auto object-contain']) }}>
@elseif($variant === 'horizontal-dark')
    {{-- Para superficies oscuras: logo horizontal de tinta clara --}}
    <img src="{{ asset('images/brand/logo-horizontal-oscuro.png') }}" alt="Mentea" {{ $attributes->merge(['class' => 'h-10 w-auto object-contain']) }}>
@elseif($variant === 'dark')
    {{-- Logo principal de tinta clara: legible sobre fondo oscuro --}}
    <img src="{{ asset('images/brand/logo-principal-claro.png') }}" alt="Mentea" {{ $attributes->merge(['class' => 'h-16 w-auto object-contain']) }}>
@elseif($variant === 'full')
    {{-- Logo principal de tinta oscura: legible sobre fondo claro (auth/guest) --}}
    <img src="{{ asset('images/brand/logo-principal-oscuro.png') }}" alt="Mentea — Conecta con tu bienestar mental" {{ $attributes->merge(['class' => 'h-20 w-auto object-contain']) }}>
@else
    <img src="{{ asset('images/brand/logo-symbol.svg') }}" alt="Mentea" {{ $attributes->merge(['class' => 'h-9 w-9 object-contain']) }}
         onerror="this.src='{{ asset('images/brand/logo-symbol.png') }}'">
@endif
