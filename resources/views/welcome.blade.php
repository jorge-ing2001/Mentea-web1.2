<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Mentea — Conecta con tu bienestar mental</title>
    @include('partials.brand-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('css/mentea.css') }}">
</head>
<body class="font-sans antialiased bg-slate-50 text-slate-800">
    <a href="#main-content" class="mentea-skip-link">Saltar al contenido</a>

    <header class="mentea-header">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
            <a href="/" class="flex items-center gap-2 group" aria-label="Mentea — inicio">
                <img src="{{ asset('images/brand/logo-symbol.svg') }}" alt="Mentea" class="h-10 w-10"
                     onerror="this.src='{{ asset('images/brand/logo-symbol.png') }}'">
                <div class="leading-tight">
                    <div class="mentea-brand-wordmark text-lg" style="font-size:1.15rem;">MENTEA</div>
                    <div class="text-[10px] text-mentea-600 hidden sm:block">Conecta con tu bienestar mental</div>
                </div>
            </a>
            <nav class="flex items-center gap-2 text-sm" aria-label="Acceso">
                @auth
                    <a href="{{ route('dashboard') }}" class="btn-mentea">Mi panel</a>
                @else
                    <a href="{{ route('login') }}" class="btn-mentea-ghost">Iniciar sesión</a>
                    <a href="{{ route('register') }}" class="btn-mentea">Registrarse</a>
                @endauth
            </nav>
        </div>
    </header>

    <main id="main-content">
        <section class="relative overflow-hidden">
            <div class="absolute inset-0 bg-mentea-gradient"></div>
            <div class="relative max-w-6xl mx-auto px-4 sm:px-6 py-16 md:py-24 text-white grid md:grid-cols-2 gap-10 lg:gap-14 items-center">
                <div>
                    <p class="inline-flex items-center gap-2 text-mentea-mint text-xs sm:text-sm font-semibold tracking-wide uppercase mb-4">
                        <span class="inline-block w-8 h-px bg-mentea-mint/70"></span>
                        Salud mental accesible
                    </p>
                    <h1 class="font-display text-4xl md:text-5xl font-bold leading-tight text-balance">
                        Conecta con tu bienestar mental
                    </h1>
                    <p class="mt-5 text-lg text-white/90 max-w-xl leading-relaxed">
                        Agenda citas con psicólogos, psiquiatras y neuropsicólogos. Atención presencial o virtual, con recordatorios y acompañamiento.
                    </p>
                    <p class="mt-3 text-sm text-white/70">Conecta · Exprésate · Cuida tu mente</p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        @auth
                            <a href="{{ route('dashboard') }}" class="inline-flex items-center justify-center px-6 py-3 rounded-xl bg-white text-mentea-800 font-semibold hover:bg-mentea-50 transition shadow-sm focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-mentea-700">Ir al panel</a>
                        @else
                            <a href="{{ route('register') }}" class="inline-flex items-center justify-center px-6 py-3 rounded-xl bg-white text-mentea-800 font-semibold hover:bg-mentea-50 transition shadow-sm focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-mentea-700">Crear cuenta gratis</a>
                            <a href="{{ route('login') }}" class="inline-flex items-center justify-center px-6 py-3 rounded-xl border border-white/40 text-white font-medium hover:bg-white/10 transition focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-mentea-700">Ya tengo cuenta</a>
                        @endauth
                    </div>
                </div>
                <div class="hidden md:flex justify-center">
                    <img src="{{ asset('images/brand/logo-principal-oscuro.png') }}"
                         alt="Mentea logo"
                         class="max-h-72 w-auto object-contain drop-shadow-xl rounded-2xl">
                </div>
            </div>
        </section>

        <section class="max-w-6xl mx-auto px-4 sm:px-6 py-16 md:py-20">
            <div class="text-center max-w-2xl mx-auto mb-12">
                <h2 class="font-display text-2xl sm:text-3xl font-bold text-mentea-900">¿Cómo funciona?</h2>
                <p class="mt-3 text-slate-600">Tres pasos simples para empezar a cuidar tu salud mental.</p>
            </div>
            <div class="grid md:grid-cols-3 gap-6">
                @foreach([
                    ['1', 'Regístrate', 'Crea tu cuenta en minutos con tu documento e email.'],
                    ['2', 'Elige profesional y horario', 'Filtra por especialidad y reserva un espacio disponible en tiempo real.'],
                    ['3', 'Asiste y cuida tu bienestar', 'Recibe recordatorios y, si es virtual, el enlace de Meet automáticamente.'],
                ] as [$n, $t, $d])
                <div class="mentea-card-hover p-6 flex flex-col">
                    <div class="w-11 h-11 rounded-full bg-mentea-100 text-mentea-700 flex items-center justify-center font-bold text-lg mb-4">{{ $n }}</div>
                    <h3 class="font-semibold text-lg text-slate-900 mb-2">{{ $t }}</h3>
                    <p class="text-slate-600 text-sm leading-relaxed">{{ $d }}</p>
                </div>
                @endforeach
            </div>
        </section>

        <section class="bg-white border-y border-slate-100">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 py-16 md:py-20">
                <div class="text-center max-w-2xl mx-auto mb-12">
                    <h2 class="font-display text-2xl sm:text-3xl font-bold text-mentea-900">Especialidades</h2>
                    <p class="mt-3 text-slate-600">Profesionales certificados para cada necesidad.</p>
                </div>
                <div class="grid sm:grid-cols-3 gap-6">
                    <div class="rounded-2xl bg-gradient-to-br from-teal-50 to-emerald-50 p-6 border border-teal-100 transition hover:shadow-soft">
                        <h3 class="font-semibold text-lg text-teal-900">Psicología</h3>
                        <p class="mt-2 text-sm text-slate-600">Terapia y acompañamiento emocional.</p>
                    </div>
                    <div class="rounded-2xl bg-gradient-to-br from-cyan-50 to-teal-50 p-6 border border-cyan-100 transition hover:shadow-soft">
                        <h3 class="font-semibold text-lg text-teal-900">Psiquiatría</h3>
                        <p class="mt-2 text-sm text-slate-600">Evaluación y tratamiento médico especializado.</p>
                    </div>
                    <div class="rounded-2xl bg-gradient-to-br from-emerald-50 to-green-50 p-6 border border-emerald-100 transition hover:shadow-soft">
                        <h3 class="font-semibold text-lg text-teal-900">Neuropsicología</h3>
                        <p class="mt-2 text-sm text-slate-600">Evaluación y rehabilitación cognitiva.</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="max-w-6xl mx-auto px-4 sm:px-6 py-16 md:py-20 text-center">
            <img src="{{ asset('images/brand/favicon-heart.png') }}" alt="" class="h-12 w-12 mx-auto mb-4 object-contain opacity-90">
            <h2 class="font-display text-2xl sm:text-3xl font-bold text-mentea-900 mb-3">¿Listo para dar el primer paso?</h2>
            <p class="text-slate-600 mb-7 max-w-md mx-auto">Únete a Mentea y agenda tu cita cuando te convenga.</p>
            @guest
                <a href="{{ route('register') }}" class="btn-mentea px-8 py-3 text-base">Comenzar ahora</a>
            @else
                <a href="{{ route('dashboard') }}" class="btn-mentea px-8 py-3 text-base">Ir a mi panel</a>
            @endguest
        </section>
    </main>

    <footer class="border-t border-slate-200 py-10 text-center text-sm text-slate-500">
        <div class="mentea-brand-wordmark text-mentea-800 mb-1" style="font-size:1rem;">MENTEA</div>
        <p class="text-mentea-600 text-xs mb-2">Conecta con tu bienestar mental.</p>
        <p class="text-xs text-slate-400">© {{ date('Y') }} Mentea — Plataforma de agendamiento de salud mental</p>
    </footer>
    @include('partials.chat-widget')
</body>
</html>
