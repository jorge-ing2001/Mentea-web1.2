<?php

namespace App\Services\Chat;

use Illuminate\Support\Collection;

/**
 * Recuperación ligera (RAG) sobre la base de conocimiento institucional en
 * docs/agente/base-conocimiento.csv. SOLO LECTURA.
 *
 * Carga el CSV en tiempo de ejecución (editable sin tocar código) y devuelve
 * entre MIN_FRAGMENTS y MAX_FRAGMENTS fragmentos relevantes por consulta,
 * puntuando por coincidencia de palabras clave y categoría sobre
 * pregunta + respuesta_oficial + categoria (normalizado, insensible a tildes
 * como ChatTools::normalize).
 *
 * Garantías:
 *   - NUNCA inyecta el CSV completo al modelo (recorta a MAX_FRAGMENTS).
 *   - Solo expone entradas con estado utilizable ("Aprobado"); las marcadas
 *     para revisión humana ("No se sabe / escalar") no se ofrecen como dato
 *     verificado.
 *   - No inventa: si no hay coincidencia, devuelve una colección vacía.
 */
class KnowledgeBase
{
    /** Tope de fragmentos devueltos por consulta (regla 3-6, nunca el CSV completo). */
    public const MAX_FRAGMENTS = 6;

    /** Piso de fragmentos cuando hay al menos una coincidencia relevante. */
    public const MIN_FRAGMENTS = 3;

    /** Cache por request de las filas parseadas del CSV. */
    private ?Collection $rows = null;

    /**
     * Devuelve los fragmentos más relevantes para la consulta (entre
     * MIN_FRAGMENTS y MAX_FRAGMENTS cuando hay coincidencias; vacío si no hay
     * ninguna). Cada fragmento es un array con id, categoria, pregunta,
     * respuesta_oficial y fuente.
     *
     * @return array<int,array{id:string,categoria:string,pregunta:string,respuesta_oficial:string,fuente:string}>
     */
    public function retrieve(string $query): array
    {
        $needle = $this->normalize($query);

        if ($needle === '') {
            return [];
        }

        $tokens = $this->tokenize($needle);

        if ($tokens === []) {
            return [];
        }

        $scored = $this->usableRows()
            ->map(function (array $row) use ($tokens) {
                $row['_score'] = $this->score($row, $tokens);

                return $row;
            })
            ->filter(fn (array $row) => $row['_score'] > 0)
            ->sortByDesc('_score')
            ->values();

        if ($scored->isEmpty()) {
            return [];
        }

        return $scored
            ->take(self::MAX_FRAGMENTS)
            ->map(fn (array $row) => [
                'id' => $row['id'],
                'categoria' => $row['categoria'],
                'pregunta' => $row['pregunta'],
                'respuesta_oficial' => $row['respuesta_oficial'],
                'fuente' => $row['fuente'],
            ])
            ->all();
    }

    /**
     * Construye un bloque de texto "conocimiento verificado" para inyectar en
     * el prompt del sistema. Devuelve cadena vacía si no hay coincidencias
     * (para que el agente no reciba ruido ni invente). Nunca excede
     * MAX_FRAGMENTS entradas.
     */
    public function contextBlock(string $query): string
    {
        $fragments = $this->retrieve($query);

        if ($fragments === []) {
            return '';
        }

        $lines = collect($fragments)->map(
            fn (array $f) => "- [{$f['categoria']}] {$f['pregunta']} {$f['respuesta_oficial']}"
        )->implode("\n");

        return "Conocimiento verificado de Mentea (úsalo como única fuente; no inventes nada fuera de esto):\n"
            .$lines;
    }

    /**
     * Filas del CSV con estado utilizable (contenido aprobado). Las entradas
     * marcadas para revisión humana no se devuelven como dato verificado.
     *
     * @return Collection<int,array<string,string>>
     */
    private function usableRows(): Collection
    {
        return $this->allRows()->filter(function (array $row) {
            $estado = $this->normalize($row['estado'] ?? '');

            return str_contains($estado, 'aprobado');
        })->values();
    }

    /**
     * Parsea el CSV (UTF-8, cabecera id,categoria,pregunta,respuesta_oficial,
     * fuente,estado). Cachea el resultado dentro del request.
     *
     * @return Collection<int,array<string,string>>
     */
    private function allRows(): Collection
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $path = $this->csvPath();

        if (! is_file($path) || ! is_readable($path)) {
            return $this->rows = collect();
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            return $this->rows = collect();
        }

        $header = fgetcsv($handle);

        if ($header === false || $header === null) {
            fclose($handle);

            return $this->rows = collect();
        }

        $header = array_map(fn ($h) => trim((string) $h), $header);
        $rows = collect();

        while (($data = fgetcsv($handle)) !== false) {
            if ($data === [null] || $data === []) {
                continue;
            }

            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = isset($data[$i]) ? (string) $data[$i] : '';
            }

            if (($row['id'] ?? '') === '' && ($row['pregunta'] ?? '') === '') {
                continue;
            }

            $rows->push($row);
        }

        fclose($handle);

        return $this->rows = $rows;
    }

    /**
     * Ruta del CSV. Configurable vía config('chat.knowledge_base_path') para
     * poder apuntar a otro archivo editable sin cambiar código.
     */
    private function csvPath(): string
    {
        $configured = config('chat.knowledge_base_path');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return base_path('docs/agente/base-conocimiento.csv');
    }

    /**
     * Puntúa una fila contra los tokens de la consulta. La pregunta y la
     * categoría pesan más que la respuesta. Un match de categoría completa da
     * un bono adicional.
     *
     * @param  array<string,string>  $row
     * @param  array<int,string>  $tokens
     */
    private function score(array $row, array $tokens): int
    {
        $pregunta = $this->normalize($row['pregunta'] ?? '');
        $respuesta = $this->normalize($row['respuesta_oficial'] ?? '');
        $categoria = $this->normalize($row['categoria'] ?? '');

        $score = 0;

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            if (str_contains($categoria, $token)) {
                $score += 5;
            }

            if (str_contains($pregunta, $token)) {
                $score += 3;
            }

            if (str_contains($respuesta, $token)) {
                $score += 1;
            }
        }

        return $score;
    }

    /**
     * Divide un texto normalizado en tokens significativos (>= 4 chars) para
     * reducir ruido de palabras vacías ("para", "como", "que").
     *
     * @return array<int,string>
     */
    private function tokenize(string $normalized): array
    {
        $parts = preg_split('/[^a-z0-9]+/', $normalized) ?: [];

        return collect($parts)
            ->filter(fn (string $t) => mb_strlen($t) >= 4)
            ->unique()
            ->values()
            ->all();
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $map = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u'];

        return strtr($value, $map);
    }
}
