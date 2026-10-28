<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Página no encontrada — Mentea</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/mentea.css') }}">
    <style>
        body { font-family: Poppins, system-ui, sans-serif; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem;
            background: linear-gradient(160deg, #f0fdfa 0%, #f8fafc 50%, #ecfdf5 100%); color: #0f172a; }
        .card { max-width: 30rem; width: 100%; text-align: center; padding: 3rem 2rem; background: #fff; border: 1px solid rgba(203,213,225,0.6);
            border-radius: 1.5rem; box-shadow: 0 10px 30px -12px rgba(15,118,110,0.18); }
        .logo { height: 44px; margin: 0 auto 1.5rem; display: block; }
        .code { font-family: 'Playfair Display', Georgia, serif; font-size: 4.5rem; font-weight: 600; color: #99f6e4; line-height: 1; margin-bottom: 0.5rem; }
        h1 { font-family: 'Playfair Display', Georgia, serif; font-size: 1.6rem; margin: 0 0 0.5rem; color: #0f766e; }
        p { color: #64748b; font-size: 0.95rem; margin: 0 0 1.75rem; line-height: 1.6; }
        .actions { display: flex; flex-wrap: wrap; gap: 0.75rem; justify-content: center; }
        a.btn { display: inline-flex; align-items: center; background: linear-gradient(135deg, #14b8a6, #0f766e); color: #fff; text-decoration: none;
            padding: 0.7rem 1.4rem; border-radius: 0.85rem; font-weight: 600; font-size: 0.9rem; transition: filter .15s ease; }
        a.btn:hover { filter: brightness(1.05); }
        a.sec { display: inline-flex; align-items: center; color: #0f766e; font-size: 0.9rem; font-weight: 500; text-decoration: none;
            padding: 0.7rem 1.4rem; border-radius: 0.85rem; border: 1px solid #99f6e4; transition: background .15s ease; }
        a.sec:hover { background: #f0fdfa; }
    </style>
</head>
<body>
    <div class="card">
        <img src="{{ asset('images/brand/logo-symbol.svg') }}" alt="Mentea" class="logo" onerror="this.style.display='none'">
        <div class="code" aria-hidden="true">404</div>
        <h1>Página no encontrada</h1>
        <p>No encontramos lo que buscas. Puede que el enlace esté roto o la página ya no exista.</p>
        <div class="actions">
            <a class="btn" href="{{ url('/') }}">Ir al inicio</a>
            @auth
                <a class="sec" href="{{ url('/dashboard') }}">Ir a mi panel</a>
            @endauth
        </div>
    </div>
</body>
</html>
