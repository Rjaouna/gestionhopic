<?php

namespace App\Controller;

use App\Service\Inventory\EbpSynchronization;
use App\Service\Inventory\EbpUnavailable;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class InventoryConnectionController extends AbstractController
{
    #[Route('/admin/inventaire/synchronisation', name: 'admin_ebp_sync', methods: ['GET', 'POST'])]
    public function synchronization(Request $request, EbpSynchronization $sync): Response
    {
        if ($request->isMethod('GET')) {
            return $this->json($sync->status());
        }
        if (!$this->isCsrfTokenValid('ebp-sync', $request->request->getString('_token'))) {
            return $this->json(['error' => 'Votre session a expiré. Rechargez la page.'], 403);
        }
        $operation = $request->request->getString('operation');
        if (in_array($operation, ['run', 'test'], true)) {
            $request->getSession()->save();
        }
        try {
            return match ($operation) {
                'save' => $this->json($sync->configure($request->request->getString('sourceUrl'), $request->request->getString('photoPath'))),
                'test' => $this->json($sync->test($request->request->getString('sourceUrl'))),
                'enqueue' => $this->json($sync->enqueue()),
                'run' => $this->run($sync),
                default => $this->json(['error' => 'Action de synchronisation inconnue.'], 400),
            };
        } catch (EbpUnavailable $error) {
            return $this->json(['error' => $error->getMessage()], 503);
        } catch (DomainException $error) {
            return $this->json(['error' => $error->getMessage()], 422);
        }
    }

    private function run(EbpSynchronization $sync): Response
    {
        ignore_user_abort(true);

        return $this->json($sync->run());
    }
}
