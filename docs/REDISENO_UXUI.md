# Rediseño integral UX/UI de Mentea

> Documento de estrategia del rediseño de interfaz y experiencia de usuario de la plataforma Mentea. Describe el análisis del proyecto, el sistema de diseño unificado, el catálogo de componentes, las mejoras de navegación por rol, la estrategia responsive, los objetivos de accesibilidad y el plan de ejecución por áreas.
>
> Regla fundamental: este es un rediseño de la **capa de presentación**. No se modifica backend, rutas, controladores, validaciones, permisos ni integraciones. Todas las funcionalidades existentes se conservan al 100 %.

## 1. Análisis del proyecto

### 1.1 Stack tecnológico

- **Backend:** Laravel 13, PHP 8.3.
- **Autenticación:** Laravel Breeze.
- **Autorización:** spatie/laravel-permission (roles y permisos) + enum `Role` propio.
- **Vistas:** Blade renderizado en servidor.
- **Interactividad:** Alpine.js 3 (menús, modales, navegación móvil, widget de chat).
- **Estilos:** Tailwind CSS 3 (sintaxis v3; `tailwind.config.js` y `postcss.config.js` con plugin `tailwindcss`). Compilación con Vite.
- **CSS plano de respaldo:** `public/css/mentea.css`, cargado por `<link>` (no pasa por Vite). Refleja el sistema, aporta modo oscuro, accesibilidad, animaciones de iconos y el widget de chat flotante.
- **Base de datos:** SQLite en desarrollo, PostgreSQL en producción (Render).
- **Integraciones:** agente conversacional (OpenRouter vía `/chat`), disponibilidad tipo Cal.com, Google Meet, notificaciones por correo y en la aplicación.

### 1.2 Módulos por rol

La plataforma organiza la experiencia alrededor de cuatro roles principales más un rol de superadministración:

- **Paciente (`patient`):** agendar citas, ver y gestionar sus citas, lista de espera, encuestas, onboarding, tickets de soporte.
- **Profesional (`professional`):** ver sus citas, calendario, evoluciones clínicas.
- **Administrador (`admin`):** calendario y citas globales, profesionales y sus horarios, lista de espera, diligenciamientos (eventos), excepciones de agenda, encuestas, reportes.
- **Superadministrador (`super`):** panel, configuración del sistema, tickets, auditoría, roles y usuarios.
- **Común a todos:** notificaciones, perfil (incluye preferencia de tema), widget de chat.

### 1.3 Inventario de vistas y componentes

- **~90 vistas Blade** distribuidas en: `layouts/` (app, guest, navigation), `partials/` (brand-head, chat-widget), `auth/` (6), `patient/` (14), `professional/` (4), `admin/` (17), `super-admin/` (12), `profile/` (edit + 3 parciales), `emails/` (6), `errors/` (404/500/503), `notifications/`, `welcome.blade.php`.
- **16 componentes reutilizables** en `resources/views/components/`: `application-logo`, `icon`, `nav-icon`, `nav-link`, `responsive-nav-link`, `dropdown`, `dropdown-link`, `modal`, `primary-button`, `secondary-button`, `danger-button`, `text-input`, `input-label`, `input-error`, `auth-session-status`, `appointment-documents`.

### 1.4 Diagnóstico

El sistema de diseño **ya existe** y está bien aplicado en los layouts, los componentes y el panel de superadministración (`super-admin/dashboard.blade.php`, usado como referencia dorada). El problema es la **deriva**: muchas vistas por rol usan Tailwind ad-hoc (`bg-teal-600`, `rounded-xl border`, `bg-gray-50`, botones en línea) que se desvía de las clases canónicas `mentea-*`. Además existe un **bug de navegación**: el menú móvil tiene un bloque de superadministración duplicado y enlaces móviles que no coinciden con los del escritorio por rol.

La estrategia no es reconstruir, sino **unificar y extender** el sistema existente y **migrar** las vistas a los componentes/clases canónicas sin tocar el marcado funcional.

## 2. Sistema de diseño unificado

### 2.1 Tokens de color

Paleta `mentea` (teal/green/navy), definida en `tailwind.config.js` y reflejada como variables CSS en `public/css/mentea.css`:

| Token | Valor | Uso |
| --- | --- | --- |
| `mentea-50` | `#F0FDFA` | fondos muy suaves, hover sutil |
| `mentea-100` | `#CCFBF1` | fondos de chips e iconos |
| `mentea-200` / `mint` | `#A7F3D0` | bordes suaves, acentos |
| `mentea-300` | `#5EEAD4` | gradientes |
| `mentea-400` / `teal` | `#2DD4BF` | foco de inputs, acentos vivos |
| `mentea-500` | `#14B8A6` | color de marca principal |
| `mentea-600` | `#0F766E` | botones primarios, enlaces activos |
| `mentea-700` | `#0D9488` | hover de botones, foco accesible |
| `mentea-800` | `#115E59` | texto fuerte |
| `mentea-900` / `navy` | `#053B50` | títulos, texto de máximo contraste |
| `green` | `#10B981` | estado positivo, corazón |

Los **colores de estado** (badges de citas y lista de espera) son dinámicos: las vistas generan `bg-{{ $status->color() }}-100 text-{{ $status->color() }}-800`. Los valores posibles provienen de `AppointmentStatus::color()` (`yellow`, `blue`, `red`, `green`, `gray`) y `WaitlistStatus::color()` (`amber`, `blue`, `green`, `red`, `gray`). El `safelist` de Tailwind debe cubrir todas estas combinaciones para que el purgado no elimine las clases.

### 2.2 Tipografía

- **Cuerpo:** `Poppins` (sans), con la pila de sistema como respaldo.
- **Títulos / marca:** `Playfair Display` (display/serif), con `Georgia` de respaldo. Clase utilitaria `font-display`.
- Jerarquía: `mentea-page-title` para títulos de página; pesos `font-semibold`/`font-bold`; `tracking-tight` en titulares para una sensación moderna y compacta.

### 2.3 Sombras, gradientes y radios

- **Sombras:** `shadow-card` (elevación de tarjeta discreta) y `shadow-soft` (elevación de hover/foco con tinte teal).
- **Gradientes:** `mentea-gradient` (navy → teal → green, para marca y burbuja de chat) y `mentea-soft` (fondo claro de pantallas de autenticación).
- **Radios:** sistema coherente de `rounded-xl` (botones, inputs, chips) y `rounded-2xl` (tarjetas). En CSS plano: `--radius` (0.75rem) y `--radius-lg` (1rem).

### 2.4 Estados de interacción

Todos los elementos interactivos declaran estados claros y accesibles: `hover`, `focus`/`focus-visible` (anillo teal), `active` y `disabled`. El foco visible usa un contorno teal de alto contraste (`#0D9488`) definido una sola vez para teclado.

## 3. Catálogo de clases y componentes canónicos

### 3.1 Clases de componente (`resources/css/app.css` + espejo en `public/css/mentea.css`)

- **Layout:** `.mentea-page`, `.mentea-header`, `.mentea-page-title`, `.mentea-section` (bloque con separación vertical consistente).
- **Tarjetas:** `.mentea-card`, `.mentea-card-hover`.
- **Botones:** `.btn-mentea` (primario), `.btn-mentea-outline` (secundario), `.btn-mentea-ghost` (terciario/sutil).
- **Formularios:** `.mentea-input`, `.mentea-label`.
- **Badges de estado:** `.badge`, `.badge-pending`, `.badge-confirmed`, `.badge-completed`, `.badge-cancelled`, `.badge-active`, `.badge-inactive`.
- **Tablas:** `.mentea-table` (con `thead`, `th`, `td`, `tbody tr`).
- **Navegación:** `.mentea-nav-link`, `.mentea-nav-link-active`.
- **Tarjetas de estadística:** `.mentea-stat`, `.mentea-stat-label`, `.mentea-stat-value`.
- **Alertas:** `.alert-success`, `.alert-error`, `.alert-info`, `.alert-warning`.
- **Utilidades nuevas (para reutilizar en features posteriores):**
  - `.mentea-empty-state`: estado vacío centrado y accesible para listas/tablas sin datos.
  - `.mentea-chip` y `.mentea-filter-chip` (+ `.mentea-filter-chip-active`): filtros tipo pill con estado activo.
  - `.mentea-avatar`: círculo con iniciales/acento de marca.

> Compatibilidad: ninguna clase existente se renombra. Las utilidades nuevas solo se añaden porque se usarán en la migración de vistas por rol (FEAT-002..005).

### 3.2 Componentes Blade a reutilizar

| Componente | Reutilizar para |
| --- | --- |
| `x-application-logo` | logotipo de marca (variantes symbol/horizontal/full/dark) |
| `x-icon` | set de iconos SVG de marca (outline/solid) |
| `x-nav-icon` | pill de icono en navegación |
| `x-nav-link` / `x-responsive-nav-link` | enlaces de navegación escritorio/móvil |
| `x-dropdown` / `x-dropdown-link` | menús desplegables (usuario, notificaciones) |
| `x-modal` | diálogos con foco atrapado y eventos Alpine |
| `x-primary-button` / `x-secondary-button` / `x-danger-button` | acciones primaria/secundaria/destructiva |
| `x-text-input` / `x-input-label` / `x-input-error` | campos de formulario |
| `x-auth-session-status` | mensajes de sesión en autenticación |
| `x-appointment-documents` | adjuntos de orden/autorización de cita |

La regla de migración: reemplazar el marcado ad-hoc por estos componentes y clases, **sin cambiar** `action`, `method`, `name`, `@csrf`, `@method`, rutas ni validaciones.

## 4. Navegación e arquitectura de la información por rol

### 4.1 Corrección del bug de navegación móvil

El menú móvil tenía un bloque de superadministración **duplicado** (copiado del escritorio con `x-nav-link` y pills, que no corresponde al patrón móvil) y faltaban enlaces por rol respecto al escritorio. Se reconcilia para que el menú móvil ofrezca **exactamente los mismos destinos** (mismas rutas con nombre) que el escritorio para cada rol, usando `x-responsive-nav-link` de forma consistente y con estado activo:

- **Superadministrador (móvil):** Panel, Configuración, Tickets, Auditoría, Roles, Usuarios (antes faltaban Roles y Usuarios, y había un bloque duplicado).
- **Administrador (móvil):** Citas, Profesionales, Lista de espera, Diligenciamientos, Excepciones, Encuestas, Reportes (antes faltaban Diligenciamientos, Excepciones, Encuestas y Reportes).
- **Paciente (móvil):** Agendar, Mis citas, Lista de espera, Soporte (antes faltaba Soporte).
- **Profesional (móvil):** Mis citas, Calendario.

Se conservan intactos: la campana de notificaciones (contador `unreadNotifications`, dropdown, formulario POST de marcar-todas-como-leídas con `@csrf`), el dropdown de usuario (formulario de logout con `@csrf`) y todas las llamadas `route()`.

### 4.2 Accesibilidad del menú

El botón hamburguesa expone `aria-expanded`, `aria-controls` y `aria-label`, y el panel móvil tiene `id` para la relación `aria-controls`. El estado activo en móvil se calcula con `request()->routeIs()` igual que en el escritorio.

## 5. Estrategia responsive

- **Enfoque mobile-first** con los breakpoints de Tailwind: `sm` (640px), `md` (768px), `lg` (1024px).
- **Móvil (< 640px):** una columna, navegación por menú hamburguesa Alpine, tarjetas apiladas, tablas que pasan a vista de tarjeta/scroll horizontal controlado, botones a ancho completo donde aporte.
- **Tableta (640–1024px):** rejillas de 2 columnas para estadísticas y tarjetas, navegación de escritorio empieza a mostrarse en `sm`.
- **Escritorio (≥ 1024px):** rejillas de 3–4 columnas, contenedor `max-w-7xl` centrado, navegación horizontal completa.
- El widget de chat flotante limita su ancho con `max-width: calc(100vw - 2.5rem)` para no desbordar en móvil.

## 6. Objetivos de accesibilidad

- **Contraste AA:** combinaciones de texto/fondo que cumplen el ratio mínimo; labels con color de alto contraste.
- **Foco visible:** contorno teal de 3px (`:focus-visible`) para navegación por teclado, sin eliminar el foco.
- **ARIA:** `aria-expanded`/`aria-controls`/`aria-label` en el toggle de navegación; roles y etiquetas en elementos interactivos.
- **Navegación por teclado:** modales con foco atrapado (Alpine), cierre con `Escape`, tabulación cíclica; menús operables con teclado.
- **Skip-link:** `.mentea-skip-link` a `#main-content` como primer elemento enfocable.
- **Movimiento reducido:** las animaciones de iconos respetan `prefers-reduced-motion: reduce`.
- **Modo oscuro:** `html.dark` con superposiciones de color legibles, conservado intacto.

## 7. Plan de ejecución por áreas

El rediseño se entrega en features incrementales. Esta feature (FEAT-001) establece la **fundación compartida**; las siguientes migran cada área de rol reutilizando el sistema.

| Feature | Alcance |
| --- | --- |
| **FEAT-001** (esta) | Documento de estrategia + consolidar/extender tokens (`tailwind.config.js`, `app.css`, `mentea.css`) + refactor de la capa compartida: `layouts/app`, `layouts/guest`, `layouts/navigation` (corrección del bug móvil) y los 16 componentes reutilizables. |
| **FEAT-002** | Área de autenticación (`auth/*`) y `profile/*`: login, registro, recuperación, verificación, confirmación y edición de perfil sobre el shell `guest` y los componentes de formulario. |
| **FEAT-003** | Área de paciente (`patient/*`): dashboard, agendamiento, citas, lista de espera, encuestas, onboarding y tickets. |
| **FEAT-004** | Área de profesional (`professional/*`) y de administrador (`admin/*`): dashboards, calendarios, tablas de citas/profesionales, excepciones, encuestas y reportes migrados a `mentea-table`/`mentea-stat`/`mentea-card`. |
| **FEAT-005** | Superadministración (`super-admin/*`, ya cercano al estándar), notificaciones, errores y `welcome`: pulido final y verificación de consistencia global. |

> Cada feature posterior preserva el marcado funcional (rutas, `name`, `@csrf`, `@method`, validaciones, permisos e integraciones) y verifica que la suite de pruebas se mantenga en el baseline esperado.
