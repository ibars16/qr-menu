<?php

namespace App\Command;

use App\Service\AI\AIProviderFactory;
use App\Service\AiProductTranslator;
use App\Service\RouterProductTranslator;
use App\Service\TranslationEval\PinnedProviderAiCaller;
use App\Service\TranslationEval\TranslationEvalStopped;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manual translation quality test outside any menu: translates one "tramo"
 * of tests/fixtures/translation_eval/platos_es.csv into en, fr, de and it
 * with REAL AI calls (cost, quota) — never part of the test suite, never
 * run automatically. Same spirit as app:menu:eval.
 *
 * Exactly the menu's process, not a copy of it: the menu's own
 * RouterProductTranslator (its prompts with TranslationGuidelines, its
 * JSON, its parsing and merge), the menu's batch size
 * (AiProductTranslator::BATCH), "seccion" as menu_section, source_locale
 * "es", translation pass then review pass. Only who answers changes:
 * PinnedProviderAiCaller, ONE provider with no fallback — a failure, an
 * exhausted quota, a cut-off or unreadable answer stops the run and says so.
 *
 * "trampa" and "significado" are reviewer notes: copied to the result file,
 * never sent to the AI.
 *
 * Results go to var/translation-eval/tramo-N.<proveedor>.json (var/ is not
 * in git), saved after every call: re-running the same command resumes
 * where it stopped and never repeats a call already done. The CSV's hash
 * and the prompts' hash are stored too — a resumed run whose CSV or prompts
 * changed refuses to go on (the results would mix two versions).
 */
#[AsCommand(
    name: 'app:translation:eval',
    description: 'Traduce un tramo de platos_es.csv con UN proveedor de IA real (sin respaldo) y guarda 1ª pasada + revisión — manual, cuesta llamadas reales',
)]
final class TranslationEvalCommand extends Command
{
    /** Target languages, in run order. */
    public const LOCALES = ['en', 'fr', 'de', 'it'];

    /** --proveedor → config/ai_providers.yaml id. */
    public const PROVIDERS = [
        'groq'   => 'groq-llama',
        'gemini' => 'gemini-flash-lite',
        'openai' => 'openai-mini',
    ];

    private const SOURCE_LOCALE = 'es';
    private const DEFAULT_CSV = 'tests/fixtures/translation_eval/platos_es.csv';
    private const DEFAULT_OUT_DIR = 'var/translation-eval';

    private const STATE_PENDING    = 'pendiente';
    private const STATE_TRANSLATED = 'traducida';
    private const STATE_REVIEWED   = 'revisada';

    /** 'traducir' | 'revisar' — which call beforeSend is guarding. */
    private string $currentKind = '';
    private string $currentLocale = '';

    /** @var array<string, mixed> the result file's content */
    private array $data = [];

    public function __construct(
        private readonly AIProviderFactory       $providerFactory,
        private readonly RouterProductTranslator $translator,
        private readonly ClockInterface          $clock,
        private readonly string                  $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tramo', null, InputOption::VALUE_REQUIRED, 'Tramo del CSV a traducir (1, 2…)')
            ->addOption('proveedor', null, InputOption::VALUE_REQUIRED, 'groq, gemini u openai — sin respaldo')
            ->addOption('pausa', null, InputOption::VALUE_REQUIRED, 'Segundos de pausa entre llamadas', '20')
            ->addOption('csv', null, InputOption::VALUE_REQUIRED, 'CSV de platos', self::DEFAULT_CSV)
            ->addOption('salida', null, InputOption::VALUE_REQUIRED, 'Carpeta de resultados', self::DEFAULT_OUT_DIR);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tramo = (string) $input->getOption('tramo');
        if (!ctype_digit($tramo) || (int) $tramo < 1) {
            $io->error('--tramo es obligatorio: 1, 2…');

            return Command::INVALID;
        }
        $providerName = (string) $input->getOption('proveedor');
        if (!isset(self::PROVIDERS[$providerName])) {
            $io->error(sprintf('--proveedor debe ser uno de: %s', implode(', ', array_keys(self::PROVIDERS))));

            return Command::INVALID;
        }
        $pause = (string) $input->getOption('pausa');
        if (!ctype_digit($pause)) {
            $io->error('--pausa: segundos, entero ≥ 0');

            return Command::INVALID;
        }
        $pause = (int) $pause;

        $providerId = self::PROVIDERS[$providerName];
        $provider   = $this->providerFactory->getProviderById($providerId);
        if ($provider === null) {
            $io->error(sprintf('El proveedor %s (%s) no está disponible: desactivado o sin clave configurada. No se ha hecho ninguna llamada.', $providerName, $providerId));

            return Command::FAILURE;
        }

        $csvPath = $this->path((string) $input->getOption('csv'));
        if (!is_file($csvPath)) {
            $io->error("No existe el CSV: {$csvPath}");

            return Command::FAILURE;
        }
        $dishes = $this->readTramo($csvPath, (int) $tramo);
        if ($dishes === []) {
            $io->error("El tramo {$tramo} no tiene platos en {$csvPath}");

            return Command::FAILURE;
        }

        $outDir  = $this->path((string) $input->getOption('salida'));
        $outFile = sprintf('%s/tramo-%d.%s.json', $outDir, (int) $tramo, $providerName);
        $csvHash = hash_file('sha256', $csvPath);

        if (is_file($outFile)) {
            $this->data = json_decode((string) file_get_contents($outFile), true, 512, JSON_THROW_ON_ERROR);
            if (($this->data['csv_sha256'] ?? null) !== $csvHash) {
                $io->error("El CSV ha cambiado desde que empezó este tramo ({$outFile}). Borra o renombra ese archivo para empezar de nuevo.");

                return Command::FAILURE;
            }
            $io->note("Sigo donde se quedó: {$outFile}");
        } else {
            $this->data = $this->freshData((int) $tramo, $providerName, $providerId, $csvHash, $pause, $dishes);
        }
        $this->data['pausa_s'] = $pause;

        $caller = new PinnedProviderAiCaller($provider);
        $caller->setBeforeSend($this->promptGuard(...));
        $translator = $this->translator->withCaller($caller);

        $batches = array_chunk($dishes, AiProductTranslator::BATCH, true);
        $calls   = 0;

        try {
            foreach (self::LOCALES as $locale) {
                $this->currentLocale = $locale;
                foreach ($batches as $n => $batch) {
                    $key   = sprintf('%s:%d', $locale, $n + 1);
                    $state = $this->data['tandas'][$key] ?? self::STATE_PENDING;
                    if ($state === self::STATE_REVIEWED) {
                        continue;
                    }

                    [$source, $sourceLocales] = $this->sourceFor($batch);

                    if ($state === self::STATE_PENDING) {
                        $this->pauseBefore($calls++, $pause);
                        $this->currentKind = 'traducir';
                        $first = $translator->firstPass($source, $locale, $sourceLocales);
                        if ($first === null) {
                            throw new TranslationEvalStopped("Tanda {$key}: la 1ª pasada no devolvió ningún plato legible.");
                        }
                        $by = $this->who($caller);
                        foreach ($batch as $id => $_) {
                            $this->data['platos'][(string) $id][$locale] = [
                                'primera'         => $first['translated'][$id] ?? null,
                                'revisada'        => null,
                                'revision_cambio' => null,
                                'traducido_por'   => $by,
                                'revisado_por'    => null,
                            ];
                        }
                        $this->data['tandas'][$key] = self::STATE_TRANSLATED;
                        $this->save($outFile);
                        $io->writeln("{$key}: traducida");
                    }

                    $translated = [];
                    foreach ($batch as $id => $_) {
                        $pair = $this->data['platos'][(string) $id][$locale]['primera'] ?? null;
                        if ($pair !== null) {
                            $translated[$id] = ['name' => $pair['name'], 'description' => $pair['description']];
                        }
                    }
                    $translatedBy = $this->data['platos'][(string) array_key_first($batch)][$locale]['traducido_por']['provider'] ?? $providerId;

                    $this->pauseBefore($calls++, $pause);
                    $this->currentKind = 'revisar';
                    $review = $translator->review($translated, $source, $locale, $sourceLocales, $translatedBy);
                    if (!$review['readable']) {
                        throw new TranslationEvalStopped("Tanda {$key}: la revisión no devolvió un JSON legible (no se apunta como «sin cambios»).");
                    }
                    $by = $this->who($caller);
                    foreach ($batch as $id => $_) {
                        $entry = &$this->data['platos'][(string) $id][$locale];
                        if ($entry['primera'] !== null) {
                            $entry['revisada']        = $review['merged'][$id];
                            $entry['revision_cambio'] = $entry['revisada'] !== $entry['primera'];
                            $entry['revisado_por']    = $by;
                        }
                        unset($entry);
                    }
                    $this->data['tandas'][$key] = self::STATE_REVIEWED;
                    $this->save($outFile);
                    $io->writeln("{$key}: revisada");
                }
            }
        } catch (TranslationEvalStopped $e) {
            $io->error([
                'Parado: ' . $e->getMessage(),
                "Lo hecho está guardado en {$outFile}. Relanza el mismo comando para seguir (no repite llamadas hechas).",
            ]);

            return Command::FAILURE;
        }

        $this->summary($io, $outFile, $calls);

        return Command::SUCCESS;
    }

    /**
     * Before each call: stops if its system prompt (with the target language
     * taken out) isn't the one this tramo's file started with.
     */
    private function promptGuard(string $systemPrompt): void
    {
        $normalized = str_replace('"' . $this->currentLocale . '"', '"{locale}"', $systemPrompt);
        $hash       = hash('sha256', $normalized);
        $stored     = $this->data['prompts_sha256'][$this->currentKind] ?? null;
        if ($stored !== null && $stored !== $hash) {
            throw new TranslationEvalStopped(sprintf(
                'Las instrucciones de «%s» han cambiado desde que empezó este tramo: los resultados mezclarían dos versiones. Borra o renombra el archivo de resultados para empezar de nuevo.',
                $this->currentKind,
            ));
        }
        $this->data['prompts_sha256'][$this->currentKind] = $hash;
    }

    private function pauseBefore(int $callsSoFar, int $pause): void
    {
        if ($callsSoFar > 0 && $pause > 0) {
            $this->clock->sleep($pause);
        }
    }

    /** @return array{provider: string, model: string} */
    private function who(PinnedProviderAiCaller $caller): array
    {
        $response = $caller->lastResponse();

        return ['provider' => $response?->providerId ?? '', 'model' => $response?->model ?? ''];
    }

    /**
     * The batch as the menu sends it: id → name/description/section, and
     * id → source locale. Never trampa/significado.
     *
     * @param  array<int, array<string, string>> $batch
     * @return array{0: array<int, array{name: string, description: ?string, section: string}>, 1: array<int, string>}
     */
    private function sourceFor(array $batch): array
    {
        $source  = [];
        $locales = [];
        foreach ($batch as $id => $dish) {
            $source[$id] = [
                'name'        => $dish['nombre'],
                'description' => $dish['descripcion'] !== '' ? $dish['descripcion'] : null,
                'section'     => $dish['seccion'],
            ];
            $locales[$id] = self::SOURCE_LOCALE;
        }

        return [$source, $locales];
    }

    /** @return array<int, array<string, string>> id → CSV row, the tramo's dishes in CSV order */
    private function readTramo(string $csvPath, int $tramo): array
    {
        $handle = fopen($csvPath, 'r');
        $header = fgetcsv($handle, escape: '');
        $dishes = [];
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            if ($row === [null]) {
                continue;
            }
            $dish = array_combine($header, array_pad($row, count($header), ''));
            if ((int) $dish['tramo'] === $tramo) {
                $dishes[(int) $dish['id']] = array_map('trim', $dish);
            }
        }
        fclose($handle);

        return $dishes;
    }

    /** @param array<int, array<string, string>> $dishes */
    private function freshData(int $tramo, string $providerName, string $providerId, string $csvHash, int $pause, array $dishes): array
    {
        $platos = [];
        foreach ($dishes as $id => $dish) {
            $platos[(string) $id] = [
                'seccion'     => $dish['seccion'],
                'nombre'      => $dish['nombre'],
                'descripcion' => $dish['descripcion'],
                'trampa'      => $dish['trampa'] ?? '',
                'significado' => $dish['significado'] ?? '',
            ];
        }

        return [
            'tramo'          => $tramo,
            'proveedor'      => $providerName,
            'provider_id'    => $providerId,
            'csv_sha256'     => $csvHash,
            'prompts_sha256' => [],
            'pausa_s'        => $pause,
            'tandas'         => [],
            'platos'         => $platos,
        ];
    }

    /** Atomic: a run killed mid-write never leaves a half file. */
    private function save(string $outFile): void
    {
        $dir = dirname($outFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $outFile . '.tmp';
        file_put_contents($tmp, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        rename($tmp, $outFile);
    }

    private function summary(SymfonyStyle $io, string $outFile, int $calls): void
    {
        $rows = [];
        foreach (self::LOCALES as $locale) {
            $changed = $missing = 0;
            foreach ($this->data['platos'] as $plato) {
                $entry = $plato[$locale] ?? null;
                if ($entry === null || $entry['primera'] === null) {
                    $missing++;
                } elseif ($entry['revision_cambio']) {
                    $changed++;
                }
            }
            $rows[] = [$locale, $changed, $missing];
        }
        $io->table(['idioma', 'cambiados por la revisión', 'sin traducción'], $rows);
        $io->success(sprintf('Tramo completo (%d llamadas en esta ejecución). Resultados: %s', $calls, $outFile));
    }

    private function path(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->projectDir . '/' . $path;
    }
}
