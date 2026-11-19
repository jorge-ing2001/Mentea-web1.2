<?php

namespace Tests\Feature\Chat;

use App\Services\Chat\KnowledgeBase;
use Tests\TestCase;

/**
 * RAG sobre docs/agente/base-conocimiento.csv: recuperación de 3-6 fragmentos
 * reales, matching insensible a tildes, sin inventar y sin volcar el CSV.
 */
class KnowledgeBaseTest extends TestCase
{
    private function kb(): KnowledgeBase
    {
        return app(KnowledgeBase::class);
    }

    public function test_services_query_returns_csv_grounded_fragments(): void
    {
        $fragments = $this->kb()->retrieve('¿Qué servicios ofrece Mentea?');

        $this->assertNotEmpty($fragments);
        $this->assertLessThanOrEqual(KnowledgeBase::MAX_FRAGMENTS, count($fragments));

        $blob = collect($fragments)->pluck('respuesta_oficial')->implode(' ');
        $this->assertStringContainsString('Hospital Especializado Granja Integral', $blob);
    }

    public function test_query_returns_between_three_and_six_fragments_for_common_topic(): void
    {
        // "horario" y "cita" son términos muy presentes en el CSV.
        $fragments = $this->kb()->retrieve('horario de atencion para mi cita');

        $this->assertGreaterThanOrEqual(KnowledgeBase::MIN_FRAGMENTS, count($fragments));
        $this->assertLessThanOrEqual(KnowledgeBase::MAX_FRAGMENTS, count($fragments));
    }

    public function test_accent_insensitive_matching(): void
    {
        $withAccents = $this->kb()->retrieve('¿Puedo reprogramar mi cita con anticipación?');
        $withoutAccents = $this->kb()->retrieve('puedo reprogramar mi cita con anticipacion');

        $this->assertNotEmpty($withAccents);
        $this->assertNotEmpty($withoutAccents);

        $idsA = collect($withAccents)->pluck('id')->sort()->values()->all();
        $idsB = collect($withoutAccents)->pluck('id')->sort()->values()->all();

        $this->assertSame($idsA, $idsB, 'El matching debe ser idéntico con o sin tildes');
    }

    public function test_unrelated_query_returns_empty_without_inventing(): void
    {
        $fragments = $this->kb()->retrieve('recetas de cocina italiana pizza napolitana');

        $this->assertSame([], $fragments);
    }

    public function test_never_dumps_whole_csv(): void
    {
        // Una consulta amplia que toca muchas categorías NO debe devolver las 45
        // filas: el tope es MAX_FRAGMENTS.
        $fragments = $this->kb()->retrieve('cita agendar horario servicio profesional modalidad documentos');

        $this->assertLessThanOrEqual(KnowledgeBase::MAX_FRAGMENTS, count($fragments));
    }

    public function test_context_block_is_bounded_and_empty_for_no_match(): void
    {
        $block = $this->kb()->contextBlock('servicios de salud mental');
        $this->assertNotSame('', $block);
        // Como máximo MAX_FRAGMENTS líneas de fragmento (cada una empieza por "- ").
        $this->assertLessThanOrEqual(KnowledgeBase::MAX_FRAGMENTS, substr_count($block, "\n- "));

        $empty = $this->kb()->contextBlock('recetas de cocina italiana pizza napolitana');
        $this->assertSame('', $empty);
    }

    public function test_only_approved_entries_are_returned(): void
    {
        // "precio" apunta a filas marcadas para revisión humana (estado no
        // aprobado); no deben ofrecerse como dato verificado.
        $fragments = $this->kb()->retrieve('cuanto cuesta el precio de la consulta');

        foreach ($fragments as $fragment) {
            $this->assertStringNotContainsStringIgnoringCase(
                'No disponible',
                $fragment['fuente'],
                'No deben devolverse entradas marcadas para revisión humana'
            );
        }
    }
}
