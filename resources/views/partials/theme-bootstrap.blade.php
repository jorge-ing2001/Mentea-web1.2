{{--
    Bootstrap de preferencias de accesibilidad / tema — SIN flash.

    Se ejecuta en el <head> ANTES de que se pinte la página y de cargar el CSS,
    de modo que el tema (claro/oscuro), el tamaño de fuente, el alto contraste y
    la reducción de movimiento se apliquen antes del primer render.

    Persistencia 100% en el cliente (localStorage). Claves:
      mentea_theme          -> 'light' | 'dark' | 'system'
      mentea_font           -> 'sm' | 'normal' | 'lg'
      mentea_contrast       -> '1' (activo) | ausente
      mentea_reduce_motion  -> '1' (activo) | ausente

    Si NO hay override en localStorage, se respeta el valor renderizado por el
    servidor en el atributo data-theme (derivado de preferred_theme). No se
    escribe ningún campo del backend desde aquí.
--}}
<script>
(function () {
    var el = document.documentElement;
    var store;
    try { store = window.localStorage; } catch (e) { store = null; }

    function get(key) {
        try { return store ? store.getItem(key) : null; } catch (e) { return null; }
    }

    // —— Tema —— localStorage tiene prioridad; si no, el data-theme del servidor.
    var theme = get('mentea_theme') || el.getAttribute('data-theme') || 'system';
    el.setAttribute('data-theme', theme);

    var prefersDark = false;
    try {
        prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    } catch (e) { prefersDark = false; }

    if (theme === 'dark' || (theme === 'system' && prefersDark)) {
        el.classList.add('dark');
    } else {
        el.classList.remove('dark');
    }

    // —— Tamaño de fuente ——
    var font = get('mentea_font') || 'normal';
    if (font === 'sm' || font === 'lg') {
        el.setAttribute('data-font', font);
    } else {
        el.removeAttribute('data-font');
    }

    // —— Alto contraste ——
    if (get('mentea_contrast') === '1') {
        el.classList.add('mentea-contrast');
    }

    // —— Reducir movimiento ——
    if (get('mentea_reduce_motion') === '1') {
        el.classList.add('mentea-reduce-motion');
    }
})();
</script>
