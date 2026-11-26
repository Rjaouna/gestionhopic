<?php

namespace App\Controller;

use App\Entity\Employee;
use App\Service\AppearanceSettings;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception\RetryableException;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AppearanceController extends AbstractController
{
    public function __construct(private readonly AppearanceSettings $appearance)
    {
    }

    #[Route('/admin/personnalisation', name: 'admin_update_appearance', methods: ['POST'])]
    public function update(Request $request, #[CurrentUser] Employee $user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $values = $request->request;
        if (!$this->isCsrfTokenValid('app-appearance', $values->getString('_token'))) {
            return $this->json(['error' => 'Votre session a expiré. Rechargez la page puis réessayez.'], 403);
        }

        $logo = $request->files->get('logo');
        if ($logo !== null && !$logo instanceof UploadedFile) {
            return $this->json(['error' => 'Sélectionnez une seule image pour le logo.'], 422);
        }
        try {
            $this->appearance->save($user, $values->getString('name'), $values->getString('suffix'),
                $values->getString('theme'), $logo, $values->getBoolean('resetLogo'));
        } catch (DomainException $error) {
            return $this->json(['error' => $error->getMessage()], 422);
        } catch (Exception $error) {
            if ($error instanceof RetryableException || str_contains($error->getMessage(), 'database is locked')) {
                return $this->json(['error' => 'Une modification est en cours. Réessayez dans un instant.'], 409);
            }
            throw $error;
        }

        return $this->json(['message' => 'Personnalisation enregistrée.', 'appearance' => $this->appearance->current()]);
    }

    #[Route('/identite/logo', name: 'appearance_logo', methods: ['GET'])]
    public function logo(): Response
    {
        $logo = $this->appearance->logo();
        if (!$logo) {
            throw $this->createNotFoundException();
        }

        return new Response($logo['content'], 200, ['Content-Type' => $logo['mime']]);
    }

    #[Route('/manifest.webmanifest', name: 'appearance_manifest', methods: ['GET'])]
    public function manifest(): Response
    {
        $appearance = $this->appearance->current();
        $icons = [
            ['src' => '/tempo.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/tempo-large.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ];
        if ($appearance['logoUrl']) {
            $icons[] = ['src' => $appearance['logoUrl'], 'sizes' => 'any', 'purpose' => 'any'];
        }
        $response = $this->json([
            'name' => $appearance['title'],
            'short_name' => mb_substr($appearance['name'], 0, 24),
            'description' => 'Congés et absences',
            'lang' => 'fr',
            // Preserve the identity of installations made with the former start URL.
            'id' => $this->generateUrl('employee_dashboard'),
            'start_url' => $this->generateUrl('app_home'),
            'scope' => $this->generateUrl('app_home'),
            'display' => 'standalone',
            'background_color' => $appearance['backgroundColor'],
            'theme_color' => $appearance['themeColor'],
            'icons' => $icons,
        ]);
        $response->headers->set('Content-Type', 'application/manifest+json');

        return $response;
    }
}
