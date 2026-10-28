{{--
    Panel de accesibilidad de Mentea.

    Botón flotante (abajo-izquierda, para NO solaparse con la burbuja del chat
    que vive abajo-derecha) que abre un diálogo con controles de:
      · Tema: Claro / Oscuro / Sistema
      · Tamaño de fuente: A- / A / A+
      · Alto contraste (toggle)
      · Reducir movimiento (toggle)
      · Restablecer

    Toda la lógica va inline con Alpine (x-data) para no depender del orden del
    bundle de Vite, igual que el widget de chat. Las preferencias se guardan en
    localStorage (claves mentea_theme / mentea_font / mentea_contrast /
    mentea_reduce_motion) y se aplican alternando clases/atributos en <html>.
    Persistencia 100% en el cliente: no se escribe ningún campo del backend.
--}}
<div
    x-data="menteaA11y()"
    x-cloak
    class="mentea-a11y-widget"
    @keydown.escape.window="close()"
>
    {{-- Panel de ajustes --}}
    <div
        x-show="open"
        x-transition.opacity.duration.200ms
        class="mentea-a11y-panel"
        role="dialog"
        aria-modal="true"
        aria-label="Opciones de accesibilidad"
        x-ref="panel"
        tabindex="-1"
    >
        <div class="mentea-a11y-header">
            <span class="mentea-a11y-title">Accesibilidad</span>
            <button type="button" class="mentea-a11y-close" @click="close()" aria-label="Cerrar opciones de accesibilidad">&times;</button>
        </div>

        <div class="mentea-a11y-body">
            {{-- Tema --}}
            <fieldset class="mentea-a11y-group">
                <legend class="mentea-a11y-legend">Tema</legend>
                <div class="mentea-a11y-options" role="group" aria-label="Tema de color">
                    <button type="button" class="mentea-a11y-opt" :class="{ 'mentea-a11y-opt-active': theme === 'light' }" :aria-pressed="(theme === 'light').toString()" @click="setTheme('light')">Claro</button>
                    <button type="button" class="mentea-a11y-opt" :class="{ 'mentea-a11y-opt-active': theme === 'dark' }" :aria-pressed="(theme === 'dark').toString()" @click="setTheme('dark')">Oscuro</button>
                    <button type="button" class="mentea-a11y-opt" :class="{ 'mentea-a11y-opt-active': theme === 'system' }" :aria-pressed="(theme === 'system').toString()" @click="setTheme('system')">Sistema</button>
                </div>
            </fieldset>

            {{-- Tamaño de fuente --}}
            <fieldset class="mentea-a11y-group">
                <legend class="mentea-a11y-legend">Tamaño de fuente</legend>
                <div class="mentea-a11y-options" role="group" aria-label="Tamaño de fuente">
                    <button type="button" class="mentea-a11y-opt" :class="{ 'mentea-a11y-opt-active': font === 'sm' }" :aria-pressed="(font === 'sm').toString()" @click="setFont('sm')" aria-label="Fuente pequeña">A-</button>
                    <button type="button" class="mentea-a11y-opt" :class="{ 'mentea-a11y-opt-active': font === 'normal' }" :aria-pressed="(font === 'normal').toString()" @click="setFont('normal')" aria-label="Fuente normal">A</button>
                    <button type="button" class="mentea-a11y-opt" :class="{ 'mentea-a11y-opt-active': font === 'lg' }" :aria-pressed="(font === 'lg').toString()" @click="setFont('lg')" aria-label="Fuente grande">A+</button>
                </div>
            </fieldset>

            {{-- Alto contraste --}}
            <div class="mentea-a11y-row">
                <label class="mentea-a11y-switch-label" for="mentea-a11y-contrast">Alto contraste</label>
                <button type="button" id="mentea-a11y-contrast" class="mentea-a11y-switch" :class="{ 'mentea-a11y-switch-on': contrast }" role="switch" :aria-checked="contrast.toString()" @click="toggleContrast()">
                    <span class="mentea-a11y-switch-knob" aria-hidden="true"></span>
                </button>
            </div>

            {{-- Reducir movimiento --}}
            <div class="mentea-a11y-row">
                <label class="mentea-a11y-switch-label" for="mentea-a11y-motion">Reducir movimiento</label>
                <button type="button" id="mentea-a11y-motion" class="mentea-a11y-switch" :class="{ 'mentea-a11y-switch-on': reduceMotion }" role="switch" :aria-checked="reduceMotion.toString()" @click="toggleReduceMotion()">
                    <span class="mentea-a11y-switch-knob" aria-hidden="true"></span>
                </button>
            </div>

            {{-- Restablecer --}}
            <button type="button" class="mentea-a11y-reset" @click="reset()">Restablecer</button>
        </div>
    </div>

    {{-- Botón flotante --}}
    <button
        type="button"
        class="mentea-a11y-button"
        @click="toggle()"
        :aria-expanded="open.toString()"
        aria-label="Abrir opciones de accesibilidad"
        x-ref="button"
    >
        <svg class="mentea-a11y-glyph" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">
            <circle cx="12" cy="3.6" r="2"/>
            <path d="M3.5 7.3a1 1 0 0 1 .5-.1c1.9.5 4.1.9 8 .9s6.1-.4 8-.9a1 1 0 1 1 .5 1.95c-1.6.42-3.4.73-6 .86v2.1l2.1 7.2a1 1 0 1 1-1.92.56L12.5 14h-1l-2.18 6.73a1 1 0 1 1-1.92-.56l2.1-7.2v-2.1c-2.6-.13-4.4-.44-6-.86a1 1 0 0 1-.5-1.9z"/>
        </svg>
    </button>
</div>

<script>
function menteaA11y() {
    return {
        open: false,
        theme: 'system',
        font: 'normal',
        contrast: false,
        reduceMotion: false,

        init() {
            var el = document.documentElement;
            // Lee el estado ya aplicado por el bootstrap de <head> / localStorage.
            this.theme = this.read('mentea_theme') || el.getAttribute('data-theme') || 'system';
            this.font = this.read('mentea_font') || 'normal';
            this.contrast = this.read('mentea_contrast') === '1';
            this.reduceMotion = this.read('mentea_reduce_motion') === '1';
            this.applyTheme();
            this.applyFont();
            this.applyContrast();
            this.applyReduceMotion();
        },

        read(key) {
            try { return window.localStorage.getItem(key); } catch (e) { return null; }
        },
        write(key, value) {
            try {
                if (value === null) { window.localStorage.removeItem(key); }
                else { window.localStorage.setItem(key, value); }
            } catch (e) { /* almacenamiento no disponible: la sesión sigue funcionando */ }
        },

        toggle() {
            this.open ? this.close() : this.openPanel();
        },
        openPanel() {
            this.open = true;
            this.$nextTick(function () {
                if (this.$refs.panel) { this.$refs.panel.focus(); }
            }.bind(this));
        },
        close() {
            if (!this.open) { return; }
            this.open = false;
            this.$nextTick(function () {
                if (this.$refs.button) { this.$refs.button.focus(); }
            }.bind(this));
        },

        prefersDark() {
            try { return window.matchMedia('(prefers-color-scheme: dark)').matches; }
            catch (e) { return false; }
        },
        applyTheme() {
            var el = document.documentElement;
            el.setAttribute('data-theme', this.theme);
            var dark = this.theme === 'dark' || (this.theme === 'system' && this.prefersDark());
            el.classList.toggle('dark', dark);
        },
        setTheme(value) {
            this.theme = value;
            this.write('mentea_theme', value);
            this.applyTheme();
        },

        applyFont() {
            var el = document.documentElement;
            if (this.font === 'sm' || this.font === 'lg') { el.setAttribute('data-font', this.font); }
            else { el.removeAttribute('data-font'); }
        },
        setFont(value) {
            this.font = value;
            if (value === 'normal') { this.write('mentea_font', null); }
            else { this.write('mentea_font', value); }
            this.applyFont();
        },

        applyContrast() {
            document.documentElement.classList.toggle('mentea-contrast', this.contrast);
        },
        toggleContrast() {
            this.contrast = !this.contrast;
            this.write('mentea_contrast', this.contrast ? '1' : null);
            this.applyContrast();
        },

        applyReduceMotion() {
            document.documentElement.classList.toggle('mentea-reduce-motion', this.reduceMotion);
        },
        toggleReduceMotion() {
            this.reduceMotion = !this.reduceMotion;
            this.write('mentea_reduce_motion', this.reduceMotion ? '1' : null);
            this.applyReduceMotion();
        },

        reset() {
            this.write('mentea_theme', null);
            this.write('mentea_font', null);
            this.write('mentea_contrast', null);
            this.write('mentea_reduce_motion', null);
            this.theme = 'system';
            this.font = 'normal';
            this.contrast = false;
            this.reduceMotion = false;
            this.applyTheme();
            this.applyFont();
            this.applyContrast();
            this.applyReduceMotion();
        },
    };
}
</script>
