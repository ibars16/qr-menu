<?php

namespace App\Command;

use App\Entity\Restaurant;
use App\Entity\User;
use App\Service\AccountMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\RequestContext;

/**
 * Daily: emails a restaurant's owners (ROLE_OWNER, not staff)
 *  - "Tu prueba gratis acaba mañana" the day before the trial's last day
 *    (Restaurant::isTrialEndingNoticeDue());
 *  - "Tu carta está pausada" once its own period has run out
 *    (Restaurant::isPausedNoticeDue()).
 * Each goes out once per paidUntil; a restaurant is marked only when at
 * least one owner got it, so a mail failure is retried on the next run
 * (while still due). Safe to run by hand and again.
 *
 * The links in these emails come from DEFAULT_URI (framework.router.
 * default_uri): there is no request to take the address from. In prod an
 * empty or localhost DEFAULT_URI would send owners to a dead link, so
 * then nothing is sent and the run fails, saying so on stdout (cron.log)
 * and in the log.
 *
 * Nothing schedules it on its own: deploy/crontab runs it every morning.
 */
#[AsCommand(
    name: 'app:billing:send-notices',
    description: 'Emails owners "tu prueba gratis acaba mañana" and "tu carta está pausada".',
)]
class SendBillingNoticesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountMailer $mailer,
        private readonly RequestContext $requestContext,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show who would get an email.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->environment === 'prod' && $this->isLocalHost($this->requestContext->getHost())) {
            $output->writeln(sprintf('[%s] ERROR: DEFAULT_URI vacía o localhost: los enlaces de los emails no llevarían a ningún sitio. No se envía nada (ver deploy/PRODUCCION.md, sección 4).', date('Y-m-d H:i:s')));
            $this->logger->error('Billing notices not sent: DEFAULT_URI is empty or localhost', ['host' => $this->requestContext->getHost()]);

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $today = new \DateTimeImmutable('today');
        $failed = 0;

        // Only restaurants whose paidUntil is near today can be due: tomorrow
        // (trial ending) or within the paused window behind it.
        $from = $today->modify('-' . (Restaurant::PAUSED_NOTICE_WINDOW_DAYS + Restaurant::CARD_RENEWAL_GRACE_DAYS) . ' days');
        $restaurants = $this->em->getRepository(Restaurant::class)->createQueryBuilder('r')
            ->where('r.paidUntil BETWEEN :from AND :to')
            ->setParameter('from', $from, 'date_immutable')
            ->setParameter('to', $today->modify('+1 day'), 'date_immutable')
            ->orderBy('r.id', 'ASC')
            ->getQuery()->getResult();

        foreach ($restaurants as $restaurant) {
            if ($restaurant->isTrialEndingNoticeDue($today)) {
                $kind = 'prueba acaba mañana';
                $send = fn (User $owner): bool => $this->mailer->sendTrialEndingNotice($owner, $restaurant);
                $mark = $restaurant->markTrialEndingNoticeSent(...);
            } elseif ($restaurant->isPausedNoticeDue($today)) {
                $kind = 'carta pausada';
                $send = fn (User $owner): bool => $this->mailer->sendPausedNotice($owner, $restaurant);
                $mark = $restaurant->markPausedNoticeSent(...);
            } else {
                continue;
            }

            $owners = array_filter(
                $restaurant->getUsers()->toArray(),
                static fn (User $user): bool => in_array(User::ROLE_OWNER, $user->getRoles(), true),
            );
            if ($owners === []) {
                continue;
            }

            if ($dryRun) {
                $output->writeln(sprintf('%s · %s: %d propietario(s) (simulado)', $restaurant->getName(), $kind, count($owners)));
                continue;
            }

            $sent = 0;
            foreach ($owners as $owner) {
                $sent += $send($owner) ? 1 : 0;
            }
            if ($sent > 0) {
                $mark();
                $this->em->flush();
            }
            if ($sent < count($owners)) {
                $failed++;
            }
            $output->writeln(sprintf('%s · %s: %d/%d enviados', $restaurant->getName(), $kind, $sent, count($owners)));
        }

        $output->writeln($failed > 0 ? sprintf('Terminado con %d restaurantes con errores (ver el log).', $failed) : 'Terminado.');

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** DEFAULT_URI empty also ends up as "localhost" (Symfony's default RequestContext). */
    private function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        return $host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || $host === '::1' || str_starts_with($host, '127.');
    }
}
