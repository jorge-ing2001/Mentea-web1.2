<?php

namespace Tests\Feature\Chat;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cobertura de la ruta de renderizado Markdown del widget (única superficie
 * XSS nueva de esta feature). El renderizador vive en JS inline dentro del
 * partial, así que la suite PHP no puede ejecutarlo; en su lugar renderizamos
 * el partial y afirmamos, sobre el markup EMITIDO, los invariantes de
 * seguridad por construcción:
 *
 *   1. Los mensajes del asistente pasan SIEMPRE por renderMarkdown() (no hay
 *      una ruta de x-html/innerHTML crudo con el contenido del modelo).
 *   2. Los mensajes del usuario se pintan con x-text (texto plano escapado).
 *   3. renderMarkdown ESCAPA TODO el HTML primero (escapeHtml: &,<,>,",')
 *      y SOLO después aplica el subconjunto de Markdown sobre texto ya
 *      escapado (orden escape-primero).
 *   4. Los enlaces aceptan exclusivamente esquemas http(s) (no javascript:/
 *      data:) y la URL se re-escapa dentro del href.
 *
 * Así el test bloquea una regresión que, p. ej., mueva el formateo antes del
 * escape o introduzca un x-html sin sanitizar.
 */
class ChatWidgetMarkdownSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function widgetMarkup(): string
    {
        return view('partials.chat-widget')->render();
    }

    public function test_assistant_messages_render_through_the_sanitizer_not_raw_html(): void
    {
        $markup = $this->widgetMarkup();

        // Único x-html del partial: el que pasa por renderMarkdown().
        $this->assertStringContainsString('x-html="renderMarkdown(msg.content)"', $markup);

        // No existe ninguna otra ruta x-html que inyecte contenido sin sanear.
        $this->assertSame(
            1,
            substr_count($markup, 'x-html='),
            'Solo debe existir una ruta x-html, y debe pasar por renderMarkdown().'
        );

        // Los mensajes del usuario se pintan como texto plano (x-text).
        $this->assertStringContainsString('<span x-text="msg.content"></span>', $markup);
    }

    public function test_sanitizer_escapes_all_html_before_formatting(): void
    {
        $markup = $this->widgetMarkup();

        // escapeHtml neutraliza las cinco entidades peligrosas.
        $this->assertStringContainsString(".replace(/&/g, '&amp;')", $markup);
        $this->assertStringContainsString(".replace(/</g, '&lt;')", $markup);
        $this->assertStringContainsString(".replace(/>/g, '&gt;')", $markup);
        $this->assertStringContainsString(".replace(/\"/g, '&quot;')", $markup);
        $this->assertStringContainsString(".replace(/'/g, '&#39;')", $markup);

        // Orden escape-primero: renderMarkdown llama a escapeHtml antes de
        // cualquier reemplazo de formato.
        $renderPos = strpos($markup, 'renderMarkdown(raw)');
        $escapeCallPos = strpos($markup, 'this.escapeHtml(raw', $renderPos);
        $boldPos = strpos($markup, '<strong>$1</strong>', $renderPos);

        $this->assertNotFalse($renderPos);
        $this->assertNotFalse($escapeCallPos);
        $this->assertNotFalse($boldPos);
        $this->assertLessThan(
            $boldPos,
            $escapeCallPos,
            'El escape de HTML debe ocurrir ANTES de aplicar el formato Markdown.'
        );
    }

    public function test_links_only_accept_http_schemes_and_reescape_the_url(): void
    {
        $markup = $this->widgetMarkup();

        // El patrón de enlaces exige esquema http(s): javascript:/data: no casan.
        $this->assertStringContainsString('(https?:\/\/[^\s)]+)', $markup);

        // La URL del enlace se vuelve a escapar dentro del href.
        $this->assertStringContainsString('const safeUrl = this.escapeHtml(url);', $markup);
        $this->assertStringContainsString('rel="noopener noreferrer"', $markup);
    }
}
