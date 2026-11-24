<?php

namespace App\Controller;

use App\Entity\Employee;
use App\Service\Inventory\Inventory;
use App\Service\LeaveManager;
use Doctrine\DBAL\Connection;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class InventoryController extends AbstractController
{
    #[Route('/admin/inventaire/perimetre', name: 'admin_inventory_preview', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function preview(Request $request, Inventory $inventory): Response
    {
        try {
            return $this->json($inventory->preview($request->query->getString('marque'), $request->query->getString('categorie')));
        } catch (DomainException $error) {
            return $this->json(['error' => $error->getMessage()], 422);
        }
    }

    #[Route('/mon-espace/inventaire/{assignment}/articles/{line}', name: 'inventory_save_count', requirements: ['assignment' => '\d+', 'line' => '\d+'], methods: ['POST'])]
    #[Route('/admin/inventaire/{assignment}/articles/{line}', name: 'admin_inventory_save_count', requirements: ['assignment' => '\d+', 'line' => '\d+'], methods: ['POST'])]
    public function count(Request $request, #[CurrentUser] Employee $user, Inventory $inventory, Connection $db, LeaveManager $leaves, int $assignment, int $line): Response
    {
        $admin = $request->attributes->get('_route') === 'admin_inventory_save_count';
        if ($admin) {
            $this->denyAccessUnlessGranted('ROLE_ADMIN');
        }
        $token = ($admin ? 'inventory-admin-count-' : 'inventory-count-').$assignment;
        if (!$this->isCsrfTokenValid($token, $request->request->getString('_token'))) {
            return $this->json(['error' => 'Votre session a expiré. Actualisez la page.'], 403);
        }
        try {
            $state = $db->transactional(function () use ($request, $user, $inventory, $leaves, $admin, $assignment, $line): array {
                $state = $inventory->saveCount($admin ? null : $user->id, $assignment, $line, $request->request->getString('quantity'),
                    $request->request->getBoolean('completed'), $request->request->getInt('version', -1));
                if ($admin) {
                    $leaves->audit($user->id, 'Comptage administrateur · affectation n°'.$assignment.', article n°'.$line
                        .', quantité '.$state['quantity'].($state['completed'] ? ' (terminé).' : ' (en cours).'));
                }

                return $state;
            });

            return $this->json($state);
        } catch (DomainException $error) {
            return $this->json(['error' => $error->getMessage()], 422);
        } catch (HttpExceptionInterface $error) {
            return $this->json(['error' => $error->getMessage()], $error->getStatusCode());
        } catch (\Doctrine\DBAL\Exception\RetryableException) {
            return $this->json(['error' => 'Un autre enregistrement est en cours. Réessayez dans un instant.'], 409);
        }
    }

    #[Route('/admin/inventaire/{id}/export', name: 'admin_inventory_export', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function export(int $id, Inventory $inventory): Response
    {
        if (!$inventory->campaign($id)['closed_at']) {
            return new Response('Clôturez l’inventaire avant de l’exporter.', 409);
        }
        $response = new StreamedResponse(static function () use ($inventory, $id): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['UniqueId', 'RealStock'], ';', '"', '');
            foreach ($inventory->exportRows($id) as $row) {
                fputcsv($output, [$row['unique_id'], $row['counted_quantity']], ';', '"', '');
            }
            fclose($output);
        }, headers: ['Content-Type' => 'text/csv; charset=UTF-8']);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'inventaire-ebp-'.$id.'.csv'));

        return $response;
    }
}
