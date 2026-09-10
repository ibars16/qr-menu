<?php

namespace App\Tests\Controller;

use App\Controller\Admin\MenuAdminController;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Exercises the dev-only guard in MenuAdminController::deleteAllProducts()
 * without booting the kernel — this sandbox's WebTestCase::createClient()
 * fails with "Booting the kernel before calling createClient() is not
 * supported" (pre-existing, unrelated to this change; see
 * MenuAdminMissingPhotoNoticeTest for the same failure), so a real HTTP
 * functional test can't run here. This test instead calls the controller
 * method directly with the private $kernelEnvironment property set via
 * reflection, which still exercises the actual guard code, not a
 * reimplementation of it.
 */
class MenuAdminDeleteAllProductsGuardTest extends TestCase
{
    private function makeController(string $kernelEnvironment): MenuAdminController
    {
        $controller = (new \ReflectionClass(MenuAdminController::class))->newInstanceWithoutConstructor();

        $prop = new \ReflectionProperty(MenuAdminController::class, 'kernelEnvironment');
        $prop->setAccessible(true);
        $prop->setValue($controller, $kernelEnvironment);

        return $controller;
    }

    public function testReturns404OutsideDevBeforeTouchingTheEntityManager(): void
    {
        $controller = $this->makeController('prod');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $this->expectException(NotFoundHttpException::class);
        $controller->deleteAllProducts($em);
    }

    public function testGuardOnlyBlocksNonDevEnvironments(): void
    {
        $controller = $this->makeController('dev');

        // Past the guard, the real code needs $this->getUser() (no security
        // token in this unit test) — that's the *next* line, proving the
        // guard itself let a dev request through instead of 404-ing.
        $this->expectException(\Error::class);
        $controller->deleteAllProducts($this->createStub(EntityManagerInterface::class));
    }
}
