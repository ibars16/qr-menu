<?php

namespace App\Controller\Admin;

use App\Entity\ProductTag;
use App\Entity\ProductTagTranslation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_USER')]
class TagsController extends AbstractController
{
    private function restaurant(): \App\Entity\Restaurant
    {
        $r = $this->getUser()->getRestaurant();
        if (!$r) throw $this->createAccessDeniedException();
        return $r;
    }

    #[Route('/tags', name: 'tags')]
    public function index(): Response
    {
        $restaurant = $this->restaurant();
        $tags       = $restaurant->getProductTags()->toArray();
        usort($tags, fn($a, $b) => $a->getPosition() <=> $b->getPosition());

        return $this->render('admin/tags.html.twig', [
            'restaurant' => $restaurant,
            'tags'       => $tags,
            'locale'     => $restaurant->getDefaultLanguage(),
        ]);
    }

    #[Route('/tags/create', name: 'tag_create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $restaurant = $this->restaurant();
        $data       = json_decode($request->getContent(), true);
        $name       = trim($data['name'] ?? '');

        if (!$name) {
            return $this->json(['error' => 'El nombre es obligatorio.'], 400);
        }

        $tag = new ProductTag();
        $tag->setRestaurant($restaurant);
        $tag->setCode(strtolower(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name))));
        $tag->setIcon(trim($data['icon'] ?? '🏷️') ?: '🏷️');
        $tag->setColor($data['color'] ?? '#666666');
        $tag->setPosition($restaurant->getProductTags()->count());

        $t = new ProductTagTranslation();
        $t->setTag($tag);
        $t->setLocale($restaurant->getDefaultLanguage());
        $t->setName($name);

        $em->persist($tag);
        $em->persist($t);
        $em->flush();

        return $this->json([
            'id'       => $tag->getId(),
            'name'     => $name,
            'icon'     => $tag->getIcon(),
            'color'    => $tag->getColor(),
            'products' => 0,
        ]);
    }

    #[Route('/tags/{id}/edit', name: 'tag_edit', methods: ['POST'])]
    public function edit(ProductTag $tag, Request $request, EntityManagerInterface $em): JsonResponse
    {
        if ($tag->getRestaurant() !== $this->restaurant()) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $data  = json_decode($request->getContent(), true);
        $name  = trim($data['name'] ?? '');

        if (!$name) {
            return $this->json(['error' => 'El nombre es obligatorio.'], 400);
        }

        $tag->setIcon(trim($data['icon'] ?? $tag->getIcon()) ?: $tag->getIcon());
        $tag->setColor($data['color'] ?? $tag->getColor());

        $locale = $tag->getRestaurant()->getDefaultLanguage();
        $t      = $tag->getTranslation($locale);

        if (!$t) {
            $t = new ProductTagTranslation();
            $t->setTag($tag);
            $t->setLocale($locale);
            $em->persist($t);
        }
        $t->setName($name);
        $em->flush();

        return $this->json([
            'id'    => $tag->getId(),
            'name'  => $name,
            'icon'  => $tag->getIcon(),
            'color' => $tag->getColor(),
        ]);
    }

    #[Route('/tags/{id}/delete', name: 'tag_delete', methods: ['POST'])]
    public function delete(ProductTag $tag, EntityManagerInterface $em): JsonResponse
    {
        if ($tag->getRestaurant() !== $this->restaurant()) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $em->remove($tag);
        $em->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('/tags/reorder', name: 'tags_reorder', methods: ['POST'])]
    public function reorder(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $restaurant = $this->restaurant();
        $ids        = json_decode($request->getContent(), true)['ids'] ?? [];

        foreach ($ids as $position => $id) {
            $tag = $em->getRepository(ProductTag::class)->find($id);
            if ($tag && $tag->getRestaurant() === $restaurant) {
                $tag->setPosition($position);
            }
        }
        $em->flush();

        return $this->json(['ok' => true]);
    }
}
