<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Every run starts from a known test database: whatever earlier runs left
// behind is wiped (a test that errors halfway through setUp() never reaches
// its own cleanup, which is how ~64 restaurants piled up), then the allergen
// taxonomy — the one piece of shared reference data the suite expects to be
// there, normally seeded by app:allergens:seed — is put back. Only ever on
// a *_test database, and never under paratest (TEST_TOKEN), where another
// process may already be running tests against it.
(static function (): void {
    if (($_SERVER['APP_ENV'] ?? null) !== 'test' || ($_SERVER['TEST_TOKEN'] ?? '') !== '') {
        return;
    }

    $kernel = new App\Kernel('test', false);
    $kernel->boot();
    $container = $kernel->getContainer();
    $connection = $container->get('doctrine')->getConnection();

    $database = (string) $connection->getDatabase();
    if (!str_ends_with($database, '_test')) {
        throw new RuntimeException(sprintf('Refusing to reset "%s": not a *_test database.', $database));
    }

    $tables = $connection->fetchFirstColumn(
        "SELECT quote_ident(tablename) FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'doctrine_migration_versions'"
    );
    if ($tables !== []) {
        $connection->executeStatement('TRUNCATE ' . implode(', ', $tables) . ' CASCADE');
    }
    $container->get('test.service_container')->get(App\Service\AllergenSeeder::class)->seed();

    $kernel->shutdown();
})();
