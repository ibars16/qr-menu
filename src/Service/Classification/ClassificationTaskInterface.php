<?php

namespace App\Service\Classification;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Plugin contract for one kind of AI-assisted data enrichment — e.g.
 * "which of the 14 EU allergens does this Global Ingredient carry".
 *
 * Everything task-specific lives behind this interface (which subjects need
 * classifying, how to describe them and the label vocabulary to the AI,
 * whether a proposed label is even valid, and how an approved proposal gets
 * permanently persisted). Everything generic — logging every proposal,
 * threshold-based auto-apply vs. review routing, the CSV/JSON review file,
 * status reporting — lives in ClassificationRunner and the classify:*
 * commands and is shared by every task. Adding a new kind of classification
 * later (dietary tags, ingredient categories, synonyms, nutrition facts) is
 * a new class implementing this interface, tagged in services.yaml — no
 * change to the runner or commands.
 */
#[AutoconfigureTag('app.classification_task')]
interface ClassificationTaskInterface
{
    /** Unique key used on the CLI and stored as ClassificationLog::classificationType, e.g. "global_ingredient_allergens". */
    public function getName(): string;

    /** Stored as ClassificationLog::subjectType, e.g. "global_ingredient". */
    public function getSubjectType(): string;

    /**
     * Subjects that still need this classification: must never return a
     * subject that already has a real domain-table mapping (manual or
     * previously applied) or an existing ClassificationLog row for this
     * task — that exclusion is what makes re-running this safe and it is
     * the task's job to enforce it, since only the task knows where the
     * real domain-table mappings live.
     *
     * @return array<int, object> subjectId => subject entity
     */
    public function findUnclassified(int $limit): array;

    /** Cheap count of the same set findUnclassified() draws from — for status reporting, without hydrating every remaining subject. */
    public function countUnclassified(): int;

    /** A stable human-readable identifier for CLI/review-file output, e.g. the ingredient's code slug. */
    public function getSubjectCode(object $subject): string;

    /** The text actually sent to the AI to describe this subject. */
    public function getSubjectDisplayText(object $subject): string;

    /** Domain instructions: what the labels mean, any extra attributes to request, and worked examples. */
    public function getInstructions(): string;

    /** @return string[] the only labels the AI may return, or [] for an open-ended label (e.g. a generated synonym string). */
    public function getLabelVocabulary(): array;

    /**
     * Validates and normalizes one raw proposed label for one subject.
     * This is the "never invent" safety gate — e.g. for a closed
     * vocabulary, reject anything not in getLabelVocabulary() outright.
     * Return null to silently discard the proposal (it will not be logged
     * at all).
     *
     * @param  array<string, mixed> $attributes raw extra fields the AI returned alongside the label
     * @return array<string, mixed>|null normalized attributes to persist in ClassificationLog, or null to discard
     */
    public function validateProposal(object $subject, string $label, array $attributes): ?array;

    /**
     * Persists one approved proposal permanently into the real domain
     * tables (e.g. a GlobalIngredientAllergen row). Called for both
     * auto-applied (confidence met the threshold) and human-approved
     * (via classify:import) proposals — must be idempotent, since a
     * subject could in principle already have this exact mapping.
     *
     * @param array<string, mixed> $attributes the normalized attributes returned by validateProposal()
     */
    public function apply(object $subject, string $label, array $attributes): void;

    /** Fetches one subject by id for the export/import commands, which only carry IDs, not hydrated entities. */
    public function getSubjectById(int $id): ?object;
}
