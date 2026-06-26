<?php

namespace App\Controller\Admin;

use App\Entity\Table;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_USER')]
class TablesController extends AbstractController
{
    private function restaurant(): \App\Entity\Restaurant
    {
        $r = $this->getUser()->getRestaurant();
        if (!$r) throw $this->createAccessDeniedException();
        return $r;
    }

    #[Route('/tables', name: 'tables')]
    public function index(): Response
    {
        $restaurant = $this->restaurant();
        $tables     = $restaurant->getTables()->toArray();
        usort($tables, fn($a, $b) => strnatcmp($a->getNumber(), $b->getNumber()));

        $menuBase = $this->generateUrl('menu_show', ['slug' => $restaurant->getSlug()]);

        return $this->render('admin/tables.html.twig', [
            'restaurant' => $restaurant,
            'tables'     => $tables,
            'menuBase'   => $menuBase,
        ]);
    }

    #[Route('/tables/create', name: 'table_create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $restaurant = $this->restaurant();
        $data       = json_decode($request->getContent(), true);
        $number     = trim($data['number'] ?? '');

        if (!$number) {
            return $this->json(['error' => 'El número de mesa es obligatorio.'], 400);
        }

        foreach ($restaurant->getTables() as $t) {
            if ($t->getNumber() === $number) {
                return $this->json(['error' => 'Ya existe una mesa con ese número.'], 400);
            }
        }

        $table = new Table();
        $table->setRestaurant($restaurant);
        $table->setNumber($number);
        $table->setQrToken(bin2hex(random_bytes(16)));
        $table->setActive(true);

        $em->persist($table);
        $em->flush();

        $menuUrl = $this->generateUrl('menu_show_table', [
            'slug'     => $restaurant->getSlug(),
            'qrToken'  => $table->getQrToken(),
        ], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->json([
            'id'      => $table->getId(),
            'number'  => $table->getNumber(),
            'qrToken' => $table->getQrToken(),
            'active'  => $table->isActive(),
            'url'     => $menuUrl,
        ]);
    }

    #[Route('/tables/{id}/edit', name: 'table_edit', methods: ['POST'])]
    public function edit(Table $table, Request $request, EntityManagerInterface $em): JsonResponse
    {
        if ($table->getRestaurant() !== $this->restaurant()) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $data   = json_decode($request->getContent(), true);
        $number = trim($data['number'] ?? '');

        if (!$number) {
            return $this->json(['error' => 'El número de mesa es obligatorio.'], 400);
        }

        foreach ($table->getRestaurant()->getTables() as $t) {
            if ($t->getNumber() === $number && $t->getId() !== $table->getId()) {
                return $this->json(['error' => 'Ya existe una mesa con ese número.'], 400);
            }
        }

        $table->setNumber($number);
        $em->flush();

        return $this->json(['id' => $table->getId(), 'number' => $table->getNumber()]);
    }

    #[Route('/tables/{id}/toggle', name: 'table_toggle', methods: ['POST'])]
    public function toggle(Table $table, EntityManagerInterface $em): JsonResponse
    {
        if ($table->getRestaurant() !== $this->restaurant()) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $table->setActive(!$table->isActive());
        $em->flush();

        return $this->json(['active' => $table->isActive()]);
    }

    #[Route('/tables/{id}/delete', name: 'table_delete', methods: ['POST'])]
    public function delete(Table $table, EntityManagerInterface $em): JsonResponse
    {
        if ($table->getRestaurant() !== $this->restaurant()) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $em->remove($table);
        $em->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('/tables/{id}/regenerate-qr', name: 'table_regenerate_qr', methods: ['POST'])]
    public function regenerateQr(Table $table, EntityManagerInterface $em): JsonResponse
    {
        if ($table->getRestaurant() !== $this->restaurant()) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $table->setQrToken(bin2hex(random_bytes(16)));
        $em->flush();

        $menuUrl = $this->generateUrl('menu_show_table', [
            'slug'    => $table->getRestaurant()->getSlug(),
            'qrToken' => $table->getQrToken(),
        ], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->json([
            'qrToken' => $table->getQrToken(),
            'url'     => $menuUrl,
        ]);
    }
}
