<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mantenimiento — Mentea</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/mentea.css') }}">
    <style>
        body { font-family: Poppins, system-ui, sans-serif; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem;
            background: linear-gradient(160deg, #042f2e 0%, #0f766e 45%, #134e4a 100%); color: #f0fdfa; }
        .card { max-width: 30rem; width: 100%; text-align: center; padding: 3rem 2rem;
            background: rgba(255,255,255,0.06); border: 1px solid rgba(153,246,228,0.22); border-radius: 1.5rem; backdrop-filter: blur(6px); }
        .logo { height: 44px; margin: 0 auto 1.5rem; display: block; filter: brightness(0) invert(1); opacity: 0.95; }
        .code { font-family: 'Playfair Display', Georgia, serif; font-size: 4rem; font-weight: 600; color: rgba(255,255,255,0.28); line-height: 1; margin-bottom: 0.5rem; }
        h1 { font-family: 'Playfair Display', Georgia, serif; font-size: 1.6rem; margin: 0 0 0.5rem; }
        p { color: #99f6e4; font-size: 0.95rem; margin: 0 0 0.5rem; line-height: 1.6; }
        .hint { color: rgba(240,253,250,0.7); font-size: 0.8rem; margin-top: 1.5rem; }
    </style>
</head>
<body>
    <div class="card">
        <img src="{{ asset('images/brand/logo-symbol.svg') }}" alt="Mentea" class="logo" onerror="this.style.display='none'">
        <div class="code" aria-hidden="true">503</div>
        <h1>Estamos en mantenimiento</h1>
        <p>Mentea no está disponible por un momento. Volveremos muy pronto.</p>
        <p class="hint">Si el problema persiste, escribe a soporte.</p>
    </div>
</body>
</html>
