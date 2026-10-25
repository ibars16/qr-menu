<?php

namespace App\Service\AllergenBackfill;

/**
 * What IngredientAllergenBackfill does with one ingredient, and why.
 *
 * Only APPLIED and NO_ALLERGENS write anything (source/reviewed_by 'ai').
 * REVIEW and SUGGESTION go to the human review list and leave the
 * ingredient exactly as it was — still "never reviewed" for REVIEW, still
 * its YAML/hand-set links for SUGGESTION. FAILED means the model's answer
 * for it was missing or unreadable; nothing is written and the next run
 * retries it.
 */
final readonly class AllergenDecision
{
    public const APPLIED = 'applied';
    public const NO_ALLERGENS = 'no_allergens';
    public const REVIEW = 'review';
    public const SUGGESTION = 'suggestion';
    public const UNCHANGED = 'unchanged';
    public const FAILED = 'failed';

    private const VALID_PRESENCES = ['contains', 'may_contain'];

    /**
     * @param array<string, string> $allergens final proposal (second pass), code => presence
     * @param list<string>          $reasons   human-readable, Spanish — they go straight into the review file
     */
    public function __construct(
        public string $outcome,
        public array $allergens,
        public array $reasons,
    ) {}

    /**
     * @param array{allergens: array<string, string>, unsure: bool}|null $first
     * @param array{allergens: array<string, string>, unsure: bool}|null $second
     * @param array{expected: list<string>, forbidden: list<string>, review?: list<string>, processed?: ?string} $rules
     * @param list<string>                                                $validCodes the 14 allergen codes
     * @param array<string, string>|null $protectedCurrent the ingredient's current links when it is protected (YAML/hand-set), null when it isn't
     */
    public static function decide(?array $first, ?array $second, array $rules, array $validCodes, ?array $protectedCurrent): self
    {
        if ($first === null || $second === null) {
            return new self(self::FAILED, [], ['la IA no devolvió una respuesta válida para este ingrediente']);
        }

        $final = $second['allergens'];
        $reasons = [];

        foreach ([$first['allergens'], $final] as $set) {
            foreach ($set as $code => $presence) {
                if (!in_array($code, $validCodes, true) || !in_array($presence, self::VALID_PRESENCES, true)) {
                    $reasons[] = sprintf('respuesta no válida de la IA ("%s": "%s")', $code, $presence);
                }
            }
        }

        if ($first['allergens'] != $final) {
            $reasons[] = sprintf('la 1ª pasada y la revisión no coinciden (1ª: %s; revisión: %s)', self::format($first['allergens']), self::format($final));
        }
        if ($first['unsure'] || $second['unsure']) {
            $reasons[] = 'la IA lo marca como dudoso';
        }
        if (in_array('may_contain', $first['allergens'], true) || in_array('may_contain', $final, true)) {
            $reasons[] = '"puede contener"';
        }
        foreach ($rules['expected'] as $code) {
            if (!isset($final[$code])) {
                $reasons[] = sprintf('por el nombre debería llevar %s y la IA no lo pone', $code);
            }
        }
        foreach ($rules['forbidden'] as $code) {
            if (isset($final[$code])) {
                $reasons[] = sprintf('por el nombre no debería llevar %s y la IA lo pone', $code);
            }
        }
        foreach ($rules['review'] ?? [] as $reason) {
            $reasons[] = $reason;
        }
        if ($final === [] && ($rules['processed'] ?? null) !== null) {
            $reasons[] = sprintf('producto procesado («%s») y la IA dice "ninguno": puede llevar alérgenos ocultos', $rules['processed']);
        }

        if ($protectedCurrent !== null) {
            $diff = [];
            foreach ($final as $code => $presence) {
                if (!isset($protectedCurrent[$code])) {
                    $diff[] = "añadir {$code} ({$presence})";
                } elseif ($protectedCurrent[$code] !== $presence) {
                    $diff[] = "cambiar {$code} de {$protectedCurrent[$code]} a {$presence}";
                }
            }
            foreach ($protectedCurrent as $code => $presence) {
                if (!isset($final[$code])) {
                    $diff[] = "quitar {$code}";
                }
            }
            if ($diff === []) {
                return new self(self::UNCHANGED, $final, []);
            }

            return new self(self::SUGGESTION, $final, array_merge(
                ['SOLO SUGERENCIA — datos del YAML o puestos a mano, no se han tocado: ' . implode(', ', $diff)],
                $reasons,
            ));
        }

        if ($reasons !== []) {
            return new self(self::REVIEW, $final, $reasons);
        }

        return new self($final === [] ? self::NO_ALLERGENS : self::APPLIED, $final, []);
    }

    /**
     * The reverse of format(), for the review files: "ninguno" => [], "—" (no answer) => null.
     *
     * @return array<string, string>|null
     */
    public static function parse(string $formatted): ?array
    {
        $formatted = trim($formatted);
        if ($formatted === '' || $formatted === '—') {
            return null;
        }
        if ($formatted === 'ninguno') {
            return [];
        }

        $allergens = [];
        foreach (explode(',', $formatted) as $part) {
            preg_match('/^\s*([a-z_]+)(?:\s*\(([a-z_]+)\))?\s*$/', $part, $m);
            $allergens[$m[1]] = $m[2] ?? 'contains';
        }
        ksort($allergens);

        return $allergens;
    }

    /** @param array<string, string> $allergens */
    public static function format(array $allergens): string
    {
        if ($allergens === []) {
            return 'ninguno';
        }

        $parts = [];
        foreach ($allergens as $code => $presence) {
            $parts[] = $presence === 'contains' ? $code : "{$code} ({$presence})";
        }

        return implode(', ', $parts);
    }
}
