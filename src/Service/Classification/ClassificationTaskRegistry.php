<?php

namespace App\Service\Classification;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Resolves a ClassificationTaskInterface by its getName() — every task
 * implementation is auto-tagged (see the #[AutoconfigureTag] on the
 * interface) and collected here, so the classify:* commands can operate on
 * "whichever task the operator named on the CLI" without knowing the
 * concrete list in advance.
 */
final class ClassificationTaskRegistry
{
    /** @var array<string, ClassificationTaskInterface> */
    private array $tasksByName = [];

    /** @param iterable<ClassificationTaskInterface> $tasks */
    public function __construct(#[AutowireIterator('app.classification_task')] iterable $tasks)
    {
        foreach ($tasks as $task) {
            $this->tasksByName[$task->getName()] = $task;
        }
    }

    public function get(string $name): ClassificationTaskInterface
    {
        if (!isset($this->tasksByName[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'No classification task named "%s". Available: %s',
                $name,
                implode(', ', array_keys($this->tasksByName)) ?: '(none registered)'
            ));
        }

        return $this->tasksByName[$name];
    }

    /** @return string[] */
    public function getNames(): array
    {
        return array_keys($this->tasksByName);
    }
}
