<?php

namespace App\Command;

use App\Service\SmartWaiterConversationStore;
use App\Service\SmartWaiterModelComparison;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Manual tool for trying Smart Waiter with a list of questions instead of
 * typing them in the bubble: reads a CSV (conversacion;pregunta;idioma) and
 * sends each question to the very endpoint the bubble uses — POST
 * /r/{slug}/chat, same JSON body as the browser — then writes every reply
 * to an Excel-friendly CSV (";" separator, UTF-8 with BOM) — by default
 * always the same file, var/smart-waiter-ask/respuestas.csv, overwritten on
 * every run (--out for another one). Its "modelo" column says which
 * provider and model answered each question (and whether it took a second
 * try), read — SELECT only — from smart_waiter_exchange_log by the
 * conversation id the server returned.
 *
 * The same letter in "conversacion" = the same conversation (the id the
 * server returns is reused, like the bubble's sessionStorage); a new letter
 * = a new conversation, like opening a new tab.
 *
 * It goes through the real chat, so every question is a REAL AI call: it is
 * never run by the test suite (SmartWaiterAskCommandTest fakes the HTTP).
 * Refuses any URL that isn't localhost. Default URL is the app's own port
 * INSIDE the qr-menu-php container (8000; the host sees it as 8083).
 *
 * --modelos=groq-llama,gemini-flash,… (config/ai_providers.yaml ids)
 * compares models instead: no HTTP — SmartWaiterModelComparison asks EACH
 * model every question with exactly what the chat would send (same request
 * builder, same history rules), with no fallback (a failure goes to that
 * model's error column), each model with its own conversations, kept in
 * memory only. Nothing is logged to smart_waiter_exchange_log and no alert
 * email is sent. --espera is each call's timeout (60 s; the chat's stays
 * 20 s); --pause is then the minimum time between two calls to the SAME
 * model (60 s by default, for Groq's free plan), so a slow model's wait
 * counts towards the others' pause. Output: conversacion;pregunta;idioma
 * then "<id> respuesta";"<id> error" per model, in --modelos order. Only
 * this console command can pin a model — the chat has no way to.
 */
#[AsCommand(
    name: 'app:smart-waiter:ask',
    description: 'Manda las preguntas de un CSV al camarero (POST /r/{slug}/chat en localhost) y guarda las respuestas en otro CSV — usa la IA real',
)]
final class SmartWaiterAskCommand extends Command
{
    public const DEFAULT_CSV = 'tests/fixtures/smart_waiter_ask/preguntas_ejemplo.csv';
    /** One file, overwritten on every run. */
    public const DEFAULT_OUT = 'var/smart-waiter-ask/respuestas.csv';
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];
    private const BOM = "\xEF\xBB\xBF";
    /** One wait-and-retry when the chat's own 20-per-minute limit answers 429. */
    private const RATE_LIMIT_WAIT_SECONDS = 61;
    /** Seconds between questions over HTTP (the chat's own limit is 20 per minute). */
    private const DEFAULT_PAUSE = 4;
    /** With --modelos: minimum seconds between two calls to the same model — Groq's free plan allows roughly one Smart Waiter question per minute. */
    private const DEFAULT_MODEL_PAUSE = 60;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ClockInterface $clock,
        private readonly Connection $connection,
        private readonly SmartWaiterModelComparison $comparison,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('csv', null, InputOption::VALUE_REQUIRED, 'CSV de preguntas (conversacion;pregunta;idioma)', self::DEFAULT_CSV)
            ->addOption('slug', null, InputOption::VALUE_REQUIRED, 'Restaurante (/r/{slug})', 'david-test')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Dónde está la app — solo localhost (dentro del contenedor: 8000; desde el host: 8083)', 'http://localhost:8000')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'CSV de respuestas (se sobrescribe en cada ejecución)', self::DEFAULT_OUT)
            ->addOption('pause', null, InputOption::VALUE_REQUIRED, sprintf('Segundos entre preguntas (el chat admite 20 por minuto; por defecto %d). Con --modelos: mínimo entre dos llamadas al MISMO modelo (por defecto %d, para el plan gratis de Groq)', self::DEFAULT_PAUSE, self::DEFAULT_MODEL_PAUSE))
            ->addOption('modelos', null, InputOption::VALUE_REQUIRED, 'Comparar modelos sin HTTP: ids de config/ai_providers.yaml separados por comas (p. ej. groq-llama,gemini-flash,gemini-flash-lite) — cada uno sin respaldo y con sus propias conversaciones')
            ->addOption('espera', null, InputOption::VALUE_REQUIRED, sprintf('Con --modelos: segundos de espera de cada llamada antes de darla por perdida (por defecto %d; el chat sigue con 20)', (int) SmartWaiterModelComparison::DEFAULT_TIMEOUT));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $models = $input->getOption('modelos');

        $baseUrl = rtrim((string) $input->getOption('url'), '/');
        $parts = parse_url($baseUrl);
        if ($models === null && (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || !in_array(strtolower($parts['host'] ?? ''), self::LOCAL_HOSTS, true))) {
            $io->error(sprintf('Solo funciona contra localhost; "%s" no lo es.', $baseUrl));

            return Command::FAILURE;
        }

        $slug = (string) $input->getOption('slug');
        if (preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
            $io->error(sprintf('Slug no válido: "%s".', $slug));

            return Command::FAILURE;
        }

        $pause = (float) ($input->getOption('pause') ?? ($models === null ? self::DEFAULT_PAUSE : self::DEFAULT_MODEL_PAUSE));
        if ($pause < 0) {
            $io->error('--pause no puede ser negativo.');

            return Command::FAILURE;
        }

        $csvPath = $this->path((string) $input->getOption('csv'));
        try {
            $questions = $this->readQuestions($csvPath);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        if ($questions === []) {
            $io->warning('El CSV no tiene preguntas.');

            return Command::SUCCESS;
        }

        $outPath = $this->path((string) $input->getOption('out'));
        if ($models !== null) {
            return $this->compare($io, (string) $models, (string) ($input->getOption('espera') ?? SmartWaiterModelComparison::DEFAULT_TIMEOUT), $slug, $pause, $questions, $outPath);
        }

        $out = $this->openOutput($io, $outPath, ['conversacion', 'pregunta', 'idioma', 'respuesta', 'modelo', 'error']);
        if ($out === null) {
            return Command::FAILURE;
        }

        $endpoint = sprintf('%s/r/%s/chat', $baseUrl, $slug);
        $io->writeln(sprintf('%d preguntas → %s (pausa de %s s entre preguntas)', count($questions), $endpoint, $pause));

        /** @var array<string, string> $conversationIds letter => id the server returned */
        $conversationIds = [];
        /** @var array<string, int> $lastLogIds conversation id => last exchange-log row already reported */
        $lastLogIds = [];
        $errors = 0;
        foreach ($questions as $i => $q) {
            if ($i > 0 && $pause > 0) {
                $this->clock->sleep($pause);
            }

            [$reply, $error, $newId] = $this->ask($endpoint, $q['pregunta'], $q['idioma'], $conversationIds[$q['conversacion']] ?? null, $io);
            $model = '';
            if ($newId !== null) {
                $conversationIds[$q['conversacion']] = $newId;
                $model = $this->whoAnswered($newId, $lastLogIds);
            }
            if ($error !== '') {
                $errors++;
            }

            fputcsv($out, [$q['conversacion'], $q['pregunta'], $q['idioma'], $reply, $model, $error], ';', '"', '');
            fflush($out); // a long run interrupted halfway keeps what it already has
            $io->writeln(sprintf('  [%s] %s → %s%s', $q['conversacion'], $q['pregunta'], $error !== '' ? 'ERROR: ' . $error : 'ok', $model !== '' ? ' — ' . $model : ''));
        }
        fclose($out);

        $io->success(sprintf('%d respuestas (%d con error) en %s', count($questions), $errors, $outPath));

        return Command::SUCCESS;
    }

    /**
     * --modelos: every question to every model, no HTTP, no fallback — see
     * the class docblock. Refuses before the first call if the restaurant
     * or any model isn't there.
     *
     * @param list<array{conversacion: string, pregunta: string, idioma: string}> $questions
     */
    private function compare(SymfonyStyle $io, string $models, string $timeout, string $slug, float $pause, array $questions, string $outPath): int
    {
        $ids = array_values(array_unique(array_filter(array_map('trim', explode(',', $models)), static fn (string $id) => $id !== '')));
        if ($ids === []) {
            $io->error('--modelos necesita al menos un id de config/ai_providers.yaml, p. ej. --modelos=groq-llama,gemini-flash-lite');

            return Command::FAILURE;
        }
        if (!is_numeric($timeout) || (float) $timeout <= 0) {
            $io->error('--espera: segundos, mayor que 0.');

            return Command::FAILURE;
        }
        $timeout = (float) $timeout;

        $restaurant = $this->comparison->findRestaurant($slug);
        if ($restaurant === null) {
            $io->error(sprintf('No existe el restaurante "%s". No se ha hecho ninguna llamada.', $slug));

            return Command::FAILURE;
        }
        if ($restaurant->isExpired()) {
            $io->error(sprintf('El restaurante "%s" está caducado: su chat no responde. No se ha hecho ninguna llamada.', $slug));

            return Command::FAILURE;
        }

        $providers = [];
        $missing = [];
        foreach ($ids as $id) {
            $provider = $this->comparison->provider($id);
            if ($provider === null) {
                $missing[] = $id;
            } else {
                $providers[$id] = $provider;
            }
        }
        if ($missing !== []) {
            $io->error(sprintf('No disponible (no existe en config/ai_providers.yaml, está desactivado o no tiene clave): %s. No se ha hecho ninguna llamada.', implode(', ', $missing)));

            return Command::FAILURE;
        }

        $header = ['conversacion', 'pregunta', 'idioma'];
        foreach ($ids as $id) {
            $header[] = $id . ' respuesta';
            $header[] = $id . ' error';
        }
        $out = $this->openOutput($io, $outPath, $header);
        if ($out === null) {
            return Command::FAILURE;
        }

        $io->writeln(sprintf('%d preguntas × %d modelos, sin respaldo (espera de %s s por llamada; al menos %s s entre dos llamadas al mismo modelo):', count($questions), count($ids), $timeout, $pause));
        foreach ($providers as $id => $provider) {
            $io->writeln(sprintf('  %s = %s', $id, $provider->getModel()));
        }

        /** @var array<string, SmartWaiterConversationStore> $stores model id => its own conversations */
        $stores = [];
        foreach ($ids as $id) {
            $stores[$id] = $this->comparison->newConversationStore();
        }
        /** @var array<string, array<string, string>> $conversationIds model id => letter => its conversation id */
        $conversationIds = [];
        /** @var array<string, \DateTimeImmutable> $lastCallAt model id => when it was last called */
        $lastCallAt = [];
        $errors = 0;

        foreach ($questions as $q) {
            $io->writeln(sprintf('  [%s] %s', $q['conversacion'], $q['pregunta']));
            $locale = $this->comparison->locale($restaurant, $q['idioma']);
            $row = [$q['conversacion'], $q['pregunta'], $q['idioma']];

            foreach ($providers as $id => $provider) {
                if (isset($lastCallAt[$id])) {
                    $elapsed = (float) $this->clock->now()->format('U.u') - (float) $lastCallAt[$id]->format('U.u');
                    if ($elapsed < $pause) {
                        $this->clock->sleep($pause - $elapsed);
                    }
                }
                $lastCallAt[$id] = $this->clock->now();

                $conversationId = $conversationIds[$id][$q['conversacion']] ??= $stores[$id]->newConversationId();
                $result = $this->comparison->ask($provider, $stores[$id], $conversationId, $restaurant, $locale, $q['pregunta'], $timeout);
                if ($result['error'] !== '') {
                    $errors++;
                }
                $row[] = $result['reply'];
                $row[] = $result['error'];
                $io->writeln(sprintf('      %s → %s', $id, $result['error'] !== '' ? 'ERROR: ' . $result['error'] : 'ok'));
            }

            fputcsv($out, $row, ';', '"', '');
            fflush($out); // a long run interrupted halfway keeps what it already has
        }
        fclose($out);

        $io->success(sprintf('%d preguntas × %d modelos (%d con error) en %s', count($questions), count($ids), $errors, $outPath));

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $header
     *
     * @return resource|null the output CSV, BOM and header written; null (error shown) if it can't be written
     */
    private function openOutput(SymfonyStyle $io, string $outPath, array $header)
    {
        if (!is_dir(dirname($outPath)) && !mkdir(dirname($outPath), 0777, true) && !is_dir(dirname($outPath))) {
            $io->error(sprintf('No se puede crear la carpeta de "%s".', $outPath));

            return null;
        }
        $out = fopen($outPath, 'w');
        if ($out === false) {
            $io->error(sprintf('No se puede escribir "%s".', $outPath));

            return null;
        }
        fwrite($out, self::BOM);
        fputcsv($out, $header, ';', '"', '');

        return $out;
    }

    /** @return array{0: string, 1: string, 2: ?string} [reply, error ('' if none), conversation id the server returned] */
    private function ask(string $endpoint, string $message, string $locale, ?string $conversationId, SymfonyStyle $io): array
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->httpClient->request('POST', $endpoint, [
                    // Same body as the bubble (_smart_waiter.html.twig's swSend()).
                    'json' => ['message' => $message, 'conversationId' => $conversationId, 'locale' => $locale],
                    // Bare language, no region: the price currency falls back to the restaurant's own.
                    'headers' => ['Accept-Language' => $locale],
                    'timeout' => 180,
                ]);
                $status = $response->getStatusCode();
                $body = $response->toArray(false);
            } catch (HttpExceptionInterface $e) {
                return ['', 'no se pudo conectar: ' . $e->getMessage(), null];
            }

            if ($status === 429 && $attempt === 1) {
                $io->writeln(sprintf('  límite de 20 por minuto alcanzado: espero %d s y reintento', self::RATE_LIMIT_WAIT_SECONDS));
                $this->clock->sleep(self::RATE_LIMIT_WAIT_SECONDS);
                continue;
            }
            if ($status !== 200) {
                return ['', sprintf('HTTP %d %s', $status, is_string($body['error'] ?? null) ? $body['error'] : ''), null];
            }

            $newId = is_string($body['conversationId'] ?? null) ? $body['conversationId'] : null;
            if (($body['error'] ?? false) === true || !is_string($body['reply'] ?? null)) {
                return ['', 'sin respuesta (todos los proveedores de IA fallaron o cortaron)', $newId];
            }

            return [$body['reply'], '', $newId];
        }
    }

    /**
     * The exchange-log row this question just wrote (the newest one of the
     * conversation past the last one already reported — questions go one at
     * a time), as "provider (model)", plus "— Nº intento" when an earlier
     * provider failed or was cut off, or "sin respuesta (reason)". '' when
     * there's no new row (the question never reached the AI). SELECT only.
     *
     * @param array<string, int> $lastLogIds updated in place
     */
    private function whoAnswered(string $conversationId, array &$lastLogIds): string
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, provider_id, model, success, failure_reason, attempts_count
             FROM smart_waiter_exchange_log
             WHERE conversation_id = ? AND id > ?
             ORDER BY id DESC
             LIMIT 1',
            [$conversationId, $lastLogIds[$conversationId] ?? 0],
        );
        if ($row === false) {
            return '';
        }
        $lastLogIds[$conversationId] = (int) $row['id'];

        if (!in_array($row['success'], [true, 1, '1', 't', 'true'], true)) {
            return sprintf('sin respuesta (%s)', $row['failure_reason'] ?? 'motivo desconocido');
        }

        $attempts = (int) $row['attempts_count'];

        return sprintf('%s (%s)', $row['provider_id'], $row['model']) . ($attempts > 1 ? sprintf(' — %dº intento', $attempts) : '');
    }

    /** @return list<array{conversacion: string, pregunta: string, idioma: string}> */
    private function readQuestions(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('No existe el CSV "%s".', $path));
        }
        $content = (string) file_get_contents($path);
        if (str_starts_with($content, self::BOM)) {
            $content = substr($content, strlen(self::BOM));
        }
        $lines = preg_split('/\r\n|\n|\r/', $content);
        $headerLine = array_shift($lines) ?? '';
        $separator = str_contains($headerLine, ';') ? ';' : ',';
        $header = array_map(static fn (string $h) => mb_strtolower(trim($h)), str_getcsv($headerLine, $separator, '"', ''));

        $col = array_flip($header);
        if (!isset($col['conversacion'], $col['pregunta'])) {
            throw new \RuntimeException('El CSV necesita las columnas "conversacion" y "pregunta" (e "idioma", opcional), separadas por ";".');
        }

        $questions = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = str_getcsv($line, $separator, '"', '');
            $conversation = trim($row[$col['conversacion']] ?? '');
            $question = trim($row[$col['pregunta']] ?? '');
            if ($conversation === '' || $question === '') {
                continue;
            }
            $locale = isset($col['idioma']) ? trim($row[$col['idioma']] ?? '') : '';
            $questions[] = ['conversacion' => $conversation, 'pregunta' => $question, 'idioma' => $locale !== '' ? $locale : 'es'];
        }

        return $questions;
    }

    private function path(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->projectDir . '/' . $path;
    }
}
