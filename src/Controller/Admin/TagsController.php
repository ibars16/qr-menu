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
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_STAFF')]
class TagsController extends AbstractController
{
    private function restaurant(): \App\Entity\Restaurant
    {
        $r = $this->getUser()->getRestaurant();
        if (!$r) throw $this->createAccessDeniedException();
        return $r;
    }

    /**
     * Disambiguates a freshly-slugified code against every code this
     * restaurant already uses — system tag or custom — so two tags can
     * never silently share a code (also enforced at the DB level by a
     * unique constraint on (restaurant_id, code); this just avoids ever
     * hitting it). Without this, an owner naming their own tag "Recommended"
     * would collide with the system tag of the same code, and code that
     * trusts "code = 'recommended'" to mean the real system tag would have
     * no way to tell them apart.
     */
    private function uniqueCodeFor(\App\Entity\Restaurant $restaurant, string $baseCode): string
    {
        $existing = array_map(fn ($tag) => $tag->getCode(), $restaurant->getProductTags()->toArray());

        if (!in_array($baseCode, $existing, true)) {
            return $baseCode;
        }

        for ($suffix = 2; ; $suffix++) {
            $candidate = $baseCode . '-' . $suffix;
            if (!in_array($candidate, $existing, true)) {
                return $candidate;
            }
        }
    }

    #[Route('/tags', name: 'tags')]
    public function index(): Response
    {
        // Screen hidden from the admin (2026-09-28, owner decision): no nav
        // link and 404 by URL. Only this page — the POST endpoints below stay
        // live because the Carta screen and the dish editor still use them.
        // The rest of this method is kept as-is so re-enabling is one line.
        throw $this->createNotFoundException();

        $restaurant = $this->restaurant();
        $tags       = $restaurant->getProductTags()->toArray();
        usort($tags, fn($a, $b) => $a->getPosition() <=> $b->getPosition());

        $customTagsCount = count(array_filter($tags, fn($t) => !$t->isSystem()));

        return $this->render('admin/tags.html.twig', [
            'restaurant'      => $restaurant,
            'tags'            => $tags,
            'locale'          => $restaurant->getDefaultLanguage(),
            'customTagsCount' => $customTagsCount,
        ]);
    }

    #[Route('/tags/create', name: 'tag_create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em, TranslatorInterface $translator): JsonResponse
    {
        $restaurant = $this->restaurant();
        $data       = json_decode($request->getContent(), true);
        $name       = trim($data['name'] ?? '');

        if (!$name) {
            return $this->json(['error' => $translator->trans('error.name_required', domain: 'admin_tags')], 400);
        }

        $code = $this->uniqueCodeFor($restaurant, strtolower(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name))));

        $tag = new ProductTag($restaurant, $code);
        $tag->setIcon(mb_substr(trim($data['icon'] ?? '🏷️'), 0, 10) ?: '🏷️'); // product_tag.icon is VARCHAR(50); 10 covers any emoji sequence, same as the inputs' maxlength
        $tag->setColor($data['color'] ?? '#666666');
        $tag->setPosition($restaurant->getProductTags()->count());

        $t = new ProductTagTranslation();
        $t->setTag($tag);
        $t->setLocale($restaurant->getDefaultLanguage());
        $t->setName($name);

        $em->persist($tag);
        $em->persist($t);
        $restaurant->bumpMenuContentVersion();
        $em->flush();

        // Translations into other locales are generated on the first visit in
        // each language, behind the menu's loading screen (warm-translations).

        return $this->json([
            'id'       => $tag->getId(),
            'name'     => $name,
            'icon'     => $tag->getIcon(),
            'color'    => $tag->getColor(),
            'products' => 0,
        ]);
    }

    #[Route('/tags/{id}/edit', name: 'tag_edit', methods: ['POST'])]
    public function edit(ProductTag $tag, Request $request, EntityManagerInterface $em, TranslatorInterface $translator): JsonResponse
    {
        if ($tag->getRestaurant() !== $this->restaurant()) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $data  = json_decode($request->getContent(), true);
        $name  = trim($data['name'] ?? '');

        if (!$name) {
            return $this->json(['error' => $translator->trans('error.name_required', domain: 'admin_tags')], 400);
        }

        $tag->setIcon(mb_substr(trim($data['icon'] ?? $tag->getIcon()), 0, 10) ?: $tag->getIcon());
        $tag->setColor($data['color'] ?? $tag->getColor());

        $locale = $tag->getRestaurant()->getDefaultLanguage();
        $t      = $tag->getTranslation($locale);
        $renamed = $t === null || $t->getName() !== $name;

        if (!$t) {
            $t = new ProductTagTranslation();
            $t->setTag($tag);
            $t->setLocale($locale);
            $em->persist($t);
        }
        $t->setName($name);

        // A real rename makes every generated translation (AI, or the preset
        // wording of a system tag) describe the old name: drop them so the
        // next loading screen in each language regenerates them from this
        // one. The owner's own ('human') rows — this one included — stay.
        if ($renamed) {
            $t->setSource(ProductTagTranslation::SOURCE_HUMAN);
            foreach ($tag->getTranslations()->toArray() as $translation) {
                if (in_array($translation->getSource(), [ProductTagTranslation::SOURCE_AI, ProductTagTranslation::SOURCE_PRESET], true)) {
                    $tag->removeTranslation($translation);
                    $em->remove($translation);
                }
            }
        }
        $tag->getRestaurant()->bumpMenuContentVersion();
        $em->flush();

        return $this->json([
            'id'    => $tag->getId(),
            'name'  => $name,
            'icon'  => $tag->getIcon(),
            'color' => $tag->getColor(),
        ]);
    }

    #[Route('/tags/{id}/delete', name: 'tag_delete', methods: ['POST'])]
    public function delete(ProductTag $tag, EntityManagerInterface $em, TranslatorInterface $translator): JsonResponse
    {
        if ($tag->getRestaurant() !== $this->restaurant()) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        if ($tag->isSystem()) {
            return $this->json(['error' => $translator->trans('error.system_tag_cannot_delete', domain: 'admin_tags')], 403);
        }

        $tag->getRestaurant()->bumpMenuContentVersion();
        $em->remove($tag);
        $em->flush();

        return $this->json(['ok' => true]);
    }

    /** Deletes every non-system tag — system tags stay protected, same as delete() above. */
    #[Route('/tags/delete-all', name: 'tags_delete_all', methods: ['POST'])]
    public function deleteAll(EntityManagerInterface $em): JsonResponse
    {
        $restaurant = $this->restaurant();

        foreach ($restaurant->getProductTags() as $tag) {
            if (!$tag->isSystem()) {
                $em->remove($tag);
            }
        }
        $restaurant->bumpMenuContentVersion();
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
        $restaurant->bumpMenuContentVersion();
        $em->flush();

        return $this->json(['ok' => true]);
    }
}
