<?php

namespace App\Controller;

use App\Entity\Employee;
use App\Service\Inventory\EbpCatalog;
use App\Service\Inventory\EbpSynchronization;
use App\Service\Inventory\Inventory;
use App\Service\LeaveManager;
use App\Service\PaidLeaveAccrual;
use App\Service\WorkCalendar;
use App\Service\Workspace;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class WorkspaceController extends AbstractController
{
    private const int PAGE_SIZE = 20;

    public function __construct(
        private readonly Workspace $workspace,
        private readonly WorkCalendar $calendar,
        private readonly LeaveManager $leaves,
        private readonly PaidLeaveAccrual $accrual,
        private readonly EbpCatalog $catalog,
        private readonly EbpSynchronization $ebp,
        private readonly Inventory $inventory,
        private readonly Connection $db,
        private readonly UserPasswordHasherInterface $passwords,
    ) {
    }

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    #[Route('/mon-espace', name: 'employee_dashboard', methods: ['GET'])]
    #[Route('/admin/{section}', name: 'admin_section', requirements: ['section' => 'planning|demandes|collaborateurs|services|soldes|rapports|parametres|journal|personnalisation'], methods: ['GET'])]
    #[Route('/admin/inventaire/{section}', name: 'admin_inventory', requirements: ['section' => 'articles-ebp|connexion-bdd|assigner|etat|liste'], methods: ['GET'])]
    #[Route('/mon-espace/{section}', name: 'employee_section', requirements: ['section' => 'planning|demandes|soldes|profil|inventaires'], methods: ['GET'])]
    public function page(Request $request, #[CurrentUser] Employee $user, string $section = 'dashboard'): Response
    {
        $admin = str_starts_with($request->attributes->getString('_route'), 'admin_');
        $today = new DateTimeImmutable('today');
        $this->accrual->creditDue($today);
        $employee = $admin ? 0 : $user->id;
        $department = $admin ? $request->query->getInt('service') : 0;
        $type = $request->query->getInt('type');
        $months = $request->query->getInt('periode') === 3 ? 3 : 6;
        $status = $request->query->getString('statut');
        $search = trim($request->query->getString('q'));
        $assignment = in_array($section, ['liste', 'inventaires'], true) ? $request->query->getInt('affectation') : 0;
        $week = ($this->calendar->date($request->query->getString('semaine')) ?? $today)->modify('monday this week');
        $counts = $this->db->fetchOne("SELECT count(*) FROM leave_request WHERE status = 'pending'");
        $unread = $this->db->fetchOne('SELECT count(*) FROM notification WHERE employee_id = ? AND read_at IS NULL', [$user->id]);
        $data = [
            'admin' => $admin, 'section' => $section, 'today' => $today, 'user' => $user, 'pendingCount' => $admin ? $counts : 0,
            'unread' => $unread, 'department' => $department, 'type' => $type, 'months' => $months, 'status' => $status,
            'search' => $search, 'departments' => $admin ? $this->workspace->departments($section !== 'collaborateurs') : [],
            'titles' => [
                'dashboard' => $admin ? 'Tableau de bord' : 'Accueil', 'planning' => $admin ? 'Planning' : 'Mon calendrier',
                'demandes' => $admin ? 'Demandes' : 'Mes demandes', 'collaborateurs' => 'Collaborateurs',
                'services' => 'Services', 'soldes' => $admin ? 'Soldes et compteurs' : 'Mes soldes',
                'rapports' => 'Statistiques et exports', 'parametres' => 'Paramètres', 'journal' => 'Journal d’activité',
                'personnalisation' => 'Personnalisation', 'profil' => 'Mon profil',
                'articles-ebp' => 'BDD Articles EBP', 'connexion-bdd' => 'Connexion BDD',
                'assigner' => 'Assigner un inventaire', 'etat' => 'État inventaire', 'inventaires' => 'Mes inventaires', 'liste' => 'Liste des inventaires',
            ],
        ];
        $data += match ($section) {
            'dashboard' => $admin ? $this->workspace->dashboard($today, $months, $department) : [
                'balances' => $this->workspace->balances($employee),
                'inventorySummary' => $this->inventory->employeeSummary($employee),
                'recent' => array_slice($this->workspace->requests($employee), 0, 4),
                'upcoming' => $this->upcoming($employee, $today),
            ],
            'planning' => ['types' => $this->workspace->leaveTypes(true, !$admin)],
            'demandes' => [
                'rows' => $this->filterPeople($this->workspace->requests($employee, $department, $status, type: $type), $search),
                'types' => $this->workspace->leaveTypes(true, !$admin),
            ],
            'collaborateurs' => ['rows' => $this->filterPeople($this->workspace->people(department: $department, includeArchived: true), $search)],
            'services' => ['rows' => $this->workspace->departments()],
            'articles-ebp' => $this->catalog->browse($search, $request->query->getString('categorie'),
                $request->query->getString('marque'), $request->query->getInt('page', 1))
                + ['catalogueClearBlock' => $this->ebp->clearCatalogueBlock()],
            'connexion-bdd' => ['ebpStatus' => $this->ebp->status()],
            'assigner' => ['campaign' => $this->inventory->active()],
            'etat' => $this->inventory->adminView($request->query->getInt('inventaire'), $request->query->getInt('affectation'), $search,
                $request->query->getString('statut'), $request->query->getInt('page', 1)),
            'liste', 'inventaires' => $assignment
                ? $this->inventory->assignmentView($assignment, $admin ? null : $user->id, $search, $request->query->getInt('page', 1))
                : $this->inventory->assignmentList($admin ? null : $user->id, $search, $request->query->getInt('page', 1),
                    $status, $admin && $request->query->getBoolean('archives')),
            'soldes' => ['balances' => $this->filterPeople($this->workspace->balances($employee), $search)],
            'rapports' => $this->workspace->dashboard($today, $months, $department),
            'parametres' => [
                'types' => $this->workspace->leaveTypes(),
                'counters' => $this->workspace->counters(),
                'schedule' => $this->calendar->schedule(),
                'paidLeaveRule' => $this->accrual->rule(),
            ] + $this->holidaySettings($request, $today),
            default => [],
        };
        if (in_array($section, ['demandes', 'collaborateurs'], true)) {
            [$data['rows'], $data['pagination']] = $this->paginateRows($request, $data['rows']);
        }
        if ($section === 'collaborateurs') {
            $data['rows'] = array_map(fn (array $row): array => $row + ['deletionBlock' => $this->employeeDeletionBlock($row, $user->id)], $data['rows']);
        }
        if ($section === 'planning') {
            $people = array_filter($this->workspace->people(department: $department), static fn (array $person) =>
                $person['active'] && (!$employee || (int) $person['id'] === $employee));
            [$people, $data['pagination']] = $this->paginateRows($request, $this->filterPeople($people, $search));
            $data += $this->workspace->planning($week, $department, $type, $people, $employee);
        }
        if ($section === 'soldes') {
            if ($admin) {
                [$data['balances'], $data['pagination']] = $this->paginateRows($request, $data['balances']);
            }
            $history = $this->pagination($request, $this->workspace->movementCount($employee), 'mouvements');
            $data['movements'] = $this->workspace->movements($employee, $history['size'], $history['offset']);
            $data['movementPagination'] = $history;
        }
        if ($section === 'journal') {
            $pagination = $this->pagination($request, (int) $this->db->fetchOne('SELECT count(*) FROM audit_entry'));
            $data['rows'] = $this->db->fetchAllAssociative('SELECT a.*, e.first_name, e.last_name FROM audit_entry a
                JOIN employee e ON e.id = a.actor_id ORDER BY a.id DESC LIMIT ? OFFSET ?',
                [$pagination['size'], $pagination['offset']], [ParameterType::INTEGER, ParameterType::INTEGER]);
            $data['pagination'] = $pagination;
        }
        $template = $request->headers->get('X-Tempo-Fragment') === 'page' ? 'workspace/content.html.twig' : 'workspace/layout.html.twig';

        return $this->render($template, $data)->setPrivate()->setMaxAge(0);
    }

    private function holidaySettings(Request $request, DateTimeImmutable $today): array
    {
        $currentYear = (int) $today->format('Y');
        $years = array_map('intval', $this->db->fetchFirstColumn('SELECT DISTINCT substr(day, 1, 4) FROM holiday'));
        $years = array_unique([$currentYear, ...$years]);
        rsort($years);
        $year = $request->query->getInt('annee', $currentYear);
        if (!in_array($year, $years, true)) {
            $year = $currentYear;
        }

        return [
            'holidayYears' => $years,
            'holidayYear' => $year,
            'holidays' => $this->db->fetchAllAssociative('SELECT * FROM holiday WHERE day BETWEEN ? AND ? ORDER BY day',
                [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)]),
        ];
    }

    private function paginateRows(Request $request, array $rows): array
    {
        $pagination = $this->pagination($request, count($rows));

        return [array_slice($rows, $pagination['offset'], $pagination['size']), $pagination];
    }

    private function pagination(Request $request, int $total, string $parameter = 'page'): array
    {
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($pages, max(1, $request->query->getInt($parameter, 1)));

        return [
            'parameter' => $parameter, 'page' => $page, 'pages' => $pages, 'total' => $total,
            'size' => self::PAGE_SIZE, 'offset' => ($page - 1) * self::PAGE_SIZE,
        ];
    }

    #[Route('/duree', name: 'workspace_duration', methods: ['GET'])]
    public function duration(Request $request, #[CurrentUser] Employee $user): Response
    {
        $start = $this->calendar->date($request->query->getString('start'));
        $end = $this->calendar->date($request->query->getString('end'));
        $startSlot = $request->query->getString('startSlot') === 'afternoon' ? 1 : 0;
        $endSlot = $request->query->getString('endSlot') === 'morning' ? 0 : 1;
        if (!$start || !$end || $end < $start || $end > $start->modify('+1 year')
            || ($start == $end && $startSlot > $endSlot)) {
            return $this->json(['units' => null]);
        }
        $admin = $this->isGranted('ROLE_ADMIN');
        if ($request->query->has('type')) {
            $type = $this->leaves->availableType($request->query->getInt('type'), $admin);
            if (!$type || (!$type['allow_half_days'] && ($startSlot !== 0 || $endSlot !== 1))) {
                return $this->json(['units' => null]);
            }
        }
        $person = $admin ? $request->query->getInt('employee', $user->id) : $user->id;
        $available = $this->db->fetchOne('SELECT 1 FROM employee WHERE id = ? AND active = 1 AND archived = 0', [$person]);
        $units = $available ? $this->calendar->units($start, $end, $this->calendar->schedule()['days'], $startSlot, $endSlot) : null;

        return $this->json(['units' => $units]);
    }

    #[Route('/admin/conges-payes/estimation', name: 'admin_paid_leave_estimate', methods: ['GET'])]
    public function paidLeaveEstimate(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $hired = $this->calendar->date($request->query->getString('hired'));
        $rule = $this->accrual->rule();
        if (!$hired || $rule['counter_name'] === null) {
            return $this->json(['error' => 'Vérifiez la date d’embauche et le compteur dans les Paramètres.'], 422);
        }

        return $this->json($this->accrual->estimate($hired, new DateTimeImmutable('today'), $rule))->setPrivate()->setMaxAge(0);
    }

    #[Route('/actions/{action}/{id}', name: 'workspace_action', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function action(Request $request, #[CurrentUser] Employee $user, string $action, int $id = 0): Response
    {
        $admin = $this->isGranted('ROLE_ADMIN');
        if (!in_array($action, ['nouvelle', 'demande', 'notifications', 'profil', 'cloturer-affectation', 'rouvrir-affectation', 'terminer-reste-inventaire', 'article-inventorie'], true)) {
            $this->denyAccessUnlessGranted('ROLE_ADMIN');
        }
        $data = ['action' => $action, 'id' => $id, 'admin' => $admin, 'user' => $user];
        $attachment = null;
        try {
            $data += $this->actionData($action, $id, $user, $admin);
            if ($request->isMethod('POST')) {
                if (in_array($action, ['fiche-collaborateur', 'article-ebp', 'article-inventorie'], true)) {
                    throw new MethodNotAllowedHttpException(['GET']);
                }
                if (!$this->isCsrfTokenValid('action-'.$action.'-'.$id, $request->request->getString('_token'))) {
                    return $this->json(['error' => 'Votre session a expiré. Rouvrez la fenêtre puis réessayez.'], 403);
                }
                if ($action === 'vider-bdd-ebp') {
                    $this->db->transactional(function () use ($user): void {
                        $deleted = $this->ebp->clearCatalogue();
                        if ($deleted > 0) {
                            $this->leaves->audit($user->id, 'Catalogue EBP vidé ('.$deleted.' articles supprimés).');
                        }
                    });

                    return $this->json(['message' => 'La BDD Articles EBP a été vidée.']);
                }
                if ($action === 'supprimer-collaborateur') {
                    $this->deleteEmployee($id, $user);

                    return $this->json(['message' => 'Le collaborateur a été supprimé de la liste et son accès est désactivé.']);
                }
                $input = $request->request->all();
                $operation = $request->request->getString('operation');
                if ($action === 'assigner-inventaire') {
                    $parameters = $request->request;
                    $this->db->transactional(function () use ($parameters, $user): void {
                        $assignment = $this->inventory->assign($parameters->getInt('employee'), $parameters->getString('marque'),
                            $parameters->getString('categorie'), $parameters->getString('name'), $parameters->getInt('campaign'),
                            $parameters->getInt('available'));
                        $this->leaves->audit($user->id, 'Affectation d’inventaire n°'.$assignment.' créée.');
                    });

                    return $this->json(['message' => 'L’inventaire a été assigné.']);
                }
                if ($action === 'cloturer-affectation') {
                    $this->db->transactional(function () use ($id, $user, $admin): void {
                        if ($this->inventory->closeAssignment($id, $admin ? null : $user->id)) {
                            $this->leaves->audit($user->id, 'Affectation d’inventaire n°'.$id.' clôturée.');
                        }
                    });

                    return $this->json(['message' => 'L’affectation a été clôturée.']);
                }
                if ($action === 'rouvrir-affectation') {
                    $this->db->transactional(function () use ($id, $user, $admin): void {
                        if ($this->inventory->reopenAssignment($id, $admin ? null : $user->id)) {
                            $this->leaves->audit($user->id, 'Affectation d’inventaire n°'.$id.' rouverte.');
                        }
                    });

                    return $this->json(['message' => 'L’affectation a été rouverte.']);
                }
                if ($action === 'terminer-reste-inventaire') {
                    $revision = $request->request->getInt('revision', -1);
                    $updated = $this->db->transactional(function () use ($id, $user, $admin, $revision): int {
                        $updated = $this->inventory->zeroRemaining($id, $admin ? null : $user->id, $revision);
                        if ($updated > 0) {
                            $this->leaves->audit($user->id, 'Affectation d’inventaire n°'.$id.' : '.$updated.' article(s) terminé(s) à zéro.');
                        }

                        return $updated;
                    });

                    return $this->json(['message' => match ($updated) {
                        0 => 'Tous les articles sont déjà terminés.',
                        1 => 'Un article a été terminé à zéro.',
                        default => $updated.' articles ont été terminés à zéro.',
                    }]);
                }
                if ($action === 'cloturer-inventaire') {
                    $this->db->transactional(function () use ($id, $user): void {
                        if ($this->inventory->closeCampaign($id)) {
                            $this->leaves->audit($user->id, 'Inventaire n°'.$id.' clôturé.');
                        }
                    });

                    return $this->json(['message' => 'L’inventaire a été clôturé. Le CSV est disponible.']);
                }
                if ($action === 'annuler-delai-inventaire') {
                    $deadline = $request->request->getInt('delai');
                    $skipped = $this->db->transactional(function () use ($id, $user, $deadline): bool {
                        $skipped = $this->inventory->skipReviewDelay($id, $deadline);
                        if ($skipped) {
                            $this->leaves->audit($user->id, 'Délai de correction annulé pour l’inventaire n°'.$id.'.');
                        }

                        return $skipped;
                    });

                    return $this->json(['message' => $skipped ? 'Délai annulé. Vous pouvez clôturer l’inventaire.' : 'Le délai de correction est déjà terminé.']);
                }
                if ($action === 'annuler-affectation-inventaire') {
                    $this->db->transactional(function () use ($id, $user): void {
                        $this->inventory->cancelAssignment($id);
                        $this->leaves->audit($user->id, 'Affectation d’inventaire n°'.$id.' annulée.');
                    });

                    return $this->json(['message' => 'L’affectation a été supprimée. Ses articles peuvent être réattribués.']);
                }
                if (in_array($action, ['archiver-affectation-inventaire', 'supprimer-affectation-inventaire'], true)) {
                    $delete = $action === 'supprimer-affectation-inventaire';
                    $this->db->transactional(function () use ($delete, $id, $user): void {
                        if ($delete) {
                            $this->inventory->deleteArchivedAssignment($id);
                            $this->leaves->audit($user->id, 'Affectation d’inventaire n°'.$id.' supprimée définitivement.');
                        } elseif ($this->inventory->archiveAssignment($id)) {
                            $this->leaves->audit($user->id, 'Affectation d’inventaire n°'.$id.' archivée.');
                        }
                    });

                    return $this->json(['message' => $delete ? 'L’affectation a été supprimée.' : 'L’affectation est disponible dans les archives.']);
                }
                if ($action === 'annuler-inventaire') {
                    $this->db->transactional(function () use ($id, $user): void {
                        $this->inventory->cancelCampaign($id);
                        $this->leaves->audit($user->id, 'Inventaire n°'.$id.' annulé.');
                    });

                    return $this->json([
                        'message' => 'L’inventaire a été supprimé.',
                        'location' => $this->generateUrl('admin_inventory', ['section' => 'etat']),
                    ]);
                }
                if (in_array($action, ['nouvelle', 'demande', 'solde'], true)) {
                    $this->accrual->creditDue();
                }
                if ($action === 'compteur' && $operation === 'delete') {
                    if (!$id) {
                        throw $this->createNotFoundException();
                    }
                    $this->removeCounter($id, $user);

                    return $this->json(['message' => 'Le compteur a été supprimé.']);
                }
                if ($action === 'ferie' && $id && $operation === 'delete') {
                    $this->db->transactional(function () use ($id, $user) {
                        if ($this->db->delete('holiday', ['id' => $id])) {
                            $this->leaves->audit($user->id, 'Jour férié n°'.$id.' supprimé.');
                        }
                    });

                    return $this->json(['message' => 'Le jour férié a été supprimé.']);
                }
                if ($action === 'nouvelle') {
                    $attachment = $this->saveAttachment($request->files->get('attachment'));
                    $this->leaves->create($user, $input, $attachment);
                } elseif ($action === 'demande') {
                    $this->leaves->decide($user, $id, $request->request->getString('transition'), $request->request->getString('reason'));
                } elseif ($action === 'notifications') {
                    $this->db->executeStatement('UPDATE notification SET read_at = ? WHERE employee_id = ? AND read_at IS NULL', [date('Y-m-d H:i:s'), $user->id]);
                } elseif ($action === 'mot-de-passe-collaborateur') {
                    $this->resetEmployeePassword($request, $id, $user);
                } elseif ($action === 'solde') {
                    $units = $this->balanceUnits($request->request->getString('quantity'));
                    $this->leaves->adjust($user, $request->request->getInt('employee'), $request->request->getInt('counter'),
                        $operation, $units, $request->request->getString('reason'));
                } else {
                    $this->saveRecord($action, $id, $request, $user);
                }

                return $this->json(['message' => match ($action) {
                    'nouvelle' => 'La demande a été enregistrée.',
                    'demande' => 'La demande a été mise à jour.',
                    'solde' => 'Le solde a été mis à jour.',
                    'notifications' => 'Les notifications ont été marquées comme lues.',
                    'mot-de-passe-collaborateur' => 'Le mot de passe a été réinitialisé.',
                    default => 'Les modifications ont été enregistrées.',
                }]);
            }
        } catch (UniqueConstraintViolationException) {
            return $this->json(['error' => 'Cette valeur existe déjà. Vérifiez les champs uniques.'], 409);
        } catch (DomainException $error) {
            if ($attachment) {
                unlink($this->leaves->attachmentDirectory.'/'.$attachment['key']);
            }

            return $this->json(['error' => $error->getMessage()], 422);
        } catch (\Throwable $error) {
            if ($attachment) {
                unlink($this->leaves->attachmentDirectory.'/'.$attachment['key']);
            }
            if ($error instanceof \Doctrine\DBAL\Exception\RetryableException
                || ($error instanceof \Doctrine\DBAL\Exception && str_contains($error->getMessage(), 'database is locked'))) {
                return $this->json(['error' => 'Une autre modification est en cours. Réessayez dans un instant.'], 409);
            }
            throw $error;
        }

        return $this->render('workspace/modal.html.twig', $data)->setPrivate()->setMaxAge(0);
    }

    #[Route('/actions/demande/{id}/justificatif', name: 'workspace_attachment', methods: ['GET'])]
    public function attachment(int $id): Response
    {
        $row = $this->workspace->request($id);
        if (!$row || !$this->isGranted('LEAVE_VIEW', $row) || !$row['attachment']) {
            throw $this->createNotFoundException();
        }
        $path = $this->leaves->attachmentDirectory.'/'.$row['attachment'];
        if (!is_file($path)) {
            throw $this->createNotFoundException();
        }
        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $row['attachment_name'], 'justificatif');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/admin/export.csv', name: 'admin_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $months = $request->query->getInt('periode');
        $first = in_array($months, [3, 6], true)
            ? (new DateTimeImmutable('first day of this month'))->modify('-'.($months - 1).' months')->format('Y-m-d')
            : '';
        $last = $first ? (new DateTimeImmutable('last day of this month'))->format('Y-m-d') : '';
        $rows = $this->filterPeople($this->workspace->requests(
            department: $request->query->getInt('service'), status: $request->query->getString('statut'),
            min: $first, max: $last, type: $request->query->getInt('type'),
        ), trim($request->query->getString('q')));

        return new StreamedResponse(static function () use ($rows): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Collaborateur', 'Service', 'Type', 'Début', 'Fin', 'Jours', 'Statut'], ';', '"', '');
            $status = ['pending' => 'En attente', 'approved' => 'Acceptée', 'refused' => 'Refusée', 'cancelled' => 'Annulée'];
            foreach ($rows as $row) {
                $values = [$row['first_name'].' '.$row['last_name'], $row['department'], $row['type'], $row['start_on'],
                    $row['end_on'], str_replace('.', ',', (string) ($row['units'] / 2)), $status[$row['status']] ?? $row['status']];
                $values = array_map(static fn ($value) => preg_match('/^[\s]*[=+@-]/u', (string) $value) ? "'".$value : $value, $values);
                fputcsv($output, $values, ';', '"', '');
            }
            fclose($output);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="tempo-demandes.csv"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function actionData(string $action, int $id, Employee $user, bool $admin): array
    {
        return match ($action) {
            'nouvelle' => ['people' => $admin ? array_filter($this->workspace->people(), static fn (array $row) => $row['active']) : [],
                'types' => $this->workspace->leaveTypes(true, !$admin), 'today' => new DateTimeImmutable('today')],
            'demande' => ['row' => $this->viewableRequest($id)],
            'notifications' => ['rows' => $this->workspace->notifications($user->id)],
            'collaborateur' => ['row' => $id ? $this->employeeRecord($id) : [],
                'departments' => $this->workspace->departments(), 'paidLeaveRule' => $id ? null : $this->accrual->rule()],
            'regle-conges' => ['rule' => $this->accrual->rule(), 'counters' => $this->workspace->counters(true)],
            'fiche-collaborateur' => ['row' => $this->employeeRecord($id, includeDeleted: true), 'schedule' => $this->calendar->schedule()],
            'supprimer-collaborateur' => $this->employeeDeletionData($id, $user->id),
            'article-ebp' => ['row' => $this->catalog->article($id) ?: throw $this->createNotFoundException()],
            'article-inventorie' => ['row' => $this->inventory->assignedArticle($id, $admin ? null : $user->id)],
            'assigner-inventaire' => $this->inventory->scopeOptions()
                + ['people' => array_filter($this->workspace->people(), static fn (array $person) => $person['active'])],
            'cloturer-affectation', 'rouvrir-affectation' => ['assignment' => $this->inventory->assignment($id, $admin ? null : $user->id)],
            'terminer-reste-inventaire' => ['zeroPreview' => $this->inventory->zeroPreview($id, $admin ? null : $user->id)],
            'cloturer-inventaire' => ['campaign' => $this->inventory->campaign($id)],
            'annuler-delai-inventaire' => ['campaign' => $this->inventory->campaign($id), 'inventoryStats' => $this->inventory->stats($id)],
            'annuler-affectation-inventaire' => ['assignment' => $this->inventory->cancellableAssignment($id)],
            'archiver-affectation-inventaire', 'supprimer-affectation-inventaire' => ['assignment' => $this->inventory->assignment($id, null)],
            'annuler-inventaire' => ['campaign' => $this->inventory->campaign($id)],
            'vider-bdd-ebp' => $id ? throw $this->createNotFoundException()
                : ['storedArticles' => (int) $this->db->fetchOne('SELECT count(*) FROM ebp_article'), 'catalogueClearBlock' => $this->ebp->clearCatalogueBlock()],
            'mot-de-passe-collaborateur' => ['row' => $this->employeeRecord($id)],
            'service' => ['row' => $this->record('department', $id)],
            'solde' => ['people' => $this->workspace->people(), 'counters' => $this->workspace->counters(true),
                'balances' => $this->workspace->balances()],
            'type' => ['row' => $this->record('leave_type', $id), 'counters' => $this->workspace->counters()],
            'compteur' => ['row' => $this->record('counter_type', $id)],
            'horaire' => ['row' => $id ? $this->record('work_schedule', $id) : throw $this->createNotFoundException()],
            'ferie' => ['row' => $this->record('holiday', $id)],
            'profil' => ['row' => $this->workspace->person($user->id)],
            default => throw $this->createNotFoundException(),
        };
    }

    private function record(string $table, int $id): array
    {
        if (!$id) {
            return [];
        }

        $row = $this->db->fetchAssociative('SELECT * FROM '.$table.' WHERE id = ?', [$id]);
        if (!$row || ($table === 'counter_type' && !$row['listed'])) {
            throw $this->createNotFoundException();
        }

        return $row;
    }

    private function removeCounter(int $id, Employee $actor): void
    {
        $this->db->transactional(function () use ($id, $actor) {
            if (!$this->db->update('counter_type', ['active' => 0, 'listed' => null], ['id' => $id, 'listed' => 1])) {
                throw $this->createNotFoundException();
            }
            $this->db->update('leave_type', ['counter_type_id' => null], ['counter_type_id' => $id]);
            $this->db->update('paid_leave_rule', ['counter_type_id' => null, 'automatic' => 0], ['counter_type_id' => $id]);
            $this->leaves->audit($actor->id, 'Compteur n°'.$id.' supprimé.');
        });
    }

    private function employeeRecord(int $id, bool $includeDeleted = false): array
    {
        $row = $this->workspace->person($id);
        if (!$row || (!$includeDeleted && $row['deleted_at'] !== null)) {
            throw $this->createNotFoundException();
        }

        return $row;
    }

    private function employeeDeletionBlock(array $row, int $actor): ?string
    {
        return match (true) {
            (int) $row['id'] === $actor => 'Vous ne pouvez pas supprimer votre propre compte.',
            (bool) $row['has_inventory'] => 'Suppression bloquée par une affectation d’inventaire.',
            default => null,
        };
    }

    private function employeeDeletionData(int $id, int $actor): array
    {
        $row = $this->employeeRecord($id);

        return ['row' => $row, 'deletionBlock' => $this->employeeDeletionBlock($row, $actor)];
    }

    private function deleteEmployee(int $id, Employee $actor): void
    {
        $this->db->transactional(function () use ($id, $actor): void {
            // Serialize the check with inventory assignments and concurrent account updates.
            $this->db->executeStatement('UPDATE employee SET id = id WHERE 0');
            $administrator = $this->employeeRecord($actor->id);
            if (!$administrator['active'] || $administrator['archived'] || $administrator['role'] !== 'ROLE_ADMIN') {
                throw $this->createAccessDeniedException();
            }
            $data = $this->employeeDeletionData($id, $actor->id);
            if ($data['deletionBlock'] !== null) {
                throw new DomainException($data['deletionBlock']);
            }
            $this->db->update('employee', ['deleted_at' => date('Y-m-d H:i:s'), 'active' => 0, 'archived' => 1], ['id' => $id]);
            $this->leaves->audit($actor->id, 'Collaborateur n°'.$id.' supprimé de la liste.');
        });
    }

    private function viewableRequest(int $id): array
    {
        $row = $this->workspace->request($id);
        if (!$row || !$this->isGranted('LEAVE_VIEW', $row)) {
            throw $this->createNotFoundException();
        }

        return $row;
    }

    private function saveRecord(string $action, int $id, Request $request, Employee $actor): void
    {
        $values = $request->request;
        if ($action === 'profil') {
            $this->db->update('employee', ['phone' => mb_substr(trim($values->getString('phone')), 0, 40)], ['id' => $actor->id]);

            return;
        }
        if ($action === 'regle-conges') {
            $counter = $this->optionalRelatedId('counter_type', $values->getString('counter'), 'Sélectionnez un compteur valide.');
            $waitingMonths = $values->getString('waitingMonths');
            if (!preg_match('/^\d{1,3}$/', $waitingMonths)) {
                throw new DomainException('Saisissez un nombre entier de mois avant la disponibilité.');
            }
            $this->accrual->configure($actor->id, $counter, $this->balanceUnits($values->getString('monthlyDays')),
                (int) $waitingMonths, $values->getBoolean('automatic'));

            return;
        }
        $name = trim($values->getString('name'));
        $color = $values->getString('color', '#137b66');
        $active = $values->getBoolean('active') ? 1 : 0;
        $description = trim($values->getString('description'));
        if ((!in_array($action, ['collaborateur', 'horaire'], true) && ($name === '' || mb_strlen($name) > 100)) || !preg_match('/^#[a-f\d]{6}$/i', $color)
            || mb_strlen($description) > 255) {
            throw new DomainException('Vérifiez le nom, la couleur et la description.');
        }
        if ($action === 'type') {
            $attachmentPolicy = $values->getString('attachmentPolicy');
            if ($attachmentPolicy === '' && !$values->has('attachmentPolicy')) {
                $current = $this->record('leave_type', $id);
                $attachmentPolicy = $values->getBoolean('attachment')
                    ? 'required'
                    : (($current['allow_attachment'] ?? true) ? 'optional' : 'none');
            }
            if (!in_array($attachmentPolicy, ['none', 'optional', 'required'], true)) {
                throw new DomainException('Sélectionnez une règle de justificatif.');
            }
        }
        [$table, $data] = match ($action) {
            'collaborateur' => ['employee', $this->employeeData($request, $id, $actor)],
            'service' => ['department', ['name' => $name, 'active' => $active, 'color' => $color, 'description' => $description]],
            'compteur' => ['counter_type', ['name' => $name, 'active' => $active, 'color' => $color]],
            'horaire' => ['work_schedule', ['days' => $this->scheduleMask($values->all('days'))]],
            'ferie' => ['holiday', ['name' => $name, 'day' => $this->validDay($values->getString('day'))]],
            'type' => ['leave_type', [
                'name' => $name, 'active' => $active, 'description' => $description,
                'tone' => in_array($values->getString('tone'), ['mint', 'violet', 'coral', 'amber', 'blue'], true) ? $values->getString('tone') : 'mint',
                'icon' => 'calendar3',
                'counter_type_id' => $this->optionalRelatedId('counter_type', $values->getString('counter'), 'Sélectionnez un compteur valide.'),
                'requires_approval' => (int) $values->getBoolean('approval'),
                'allow_attachment' => (int) ($attachmentPolicy !== 'none'),
                'requires_attachment' => (int) ($attachmentPolicy === 'required'),
                'allow_half_days' => (int) $values->getBoolean('halfDays'), 'employee_visible' => (int) $values->getBoolean('visible'),
                'private_reason' => (int) $values->getBoolean('private'),
            ]],
            default => throw $this->createNotFoundException(),
        };
        $this->db->transactional(function () use ($table, $data, $id, $actor, $action, $values) {
            if ($action === 'type' && $data['counter_type_id'] !== null
                && !$this->db->executeStatement('UPDATE counter_type SET listed = listed WHERE id = ? AND listed = 1', [$data['counter_type_id']])) {
                throw new DomainException('Sélectionnez un compteur valide.');
            }
            if ($id && $action === 'compteur') {
                if (!$this->db->update($table, $data, ['id' => $id, 'listed' => 1])) {
                    throw $this->createNotFoundException();
                }
            } elseif ($id && $action === 'collaborateur') {
                if (!$this->db->update($table, $data, ['id' => $id, 'deleted_at' => null])) {
                    throw $this->createNotFoundException();
                }
            } elseif ($id) {
                $this->db->update($table, $data, ['id' => $id]);
            } else {
                if ($action === 'collaborateur') {
                    $data['code'] = $this->nextEmployeeCode();
                }
                $this->db->insert($table, $data);
                if ($action === 'collaborateur') {
                    $employee = (int) $this->db->lastInsertId();
                    $amount = $values->getBoolean('initialConfirmed') ? $this->balanceUnits($values->getString('initialBalance')) : null;
                    $this->accrual->openBalance($employee, $actor->id, new DateTimeImmutable($data['hired_on']),
                        new DateTimeImmutable('today'), $amount, $values->getString('initialEstimate'));
                }
            }
            $this->leaves->audit($actor->id, ucfirst($action).($id ? ' modifié.' : ' créé.'));
        });
    }

    private function employeeData(Request $request, int $id, Employee $actor): array
    {
        $values = $request->request;
        $first = trim($values->getString('firstName'));
        $last = trim($values->getString('lastName'));
        $email = mb_strtolower(trim($values->getString('email')));
        $job = trim($values->getString('job'));
        $phone = trim($values->getString('phone'));
        $hired = $values->getString('hired');
        $archived = $values->getString('state') === 'archived';
        $active = $values->getString('state') === 'active';
        $role = $values->getString('role') === 'ROLE_ADMIN' ? 'ROLE_ADMIN' : 'ROLE_USER';
        if (!$first || !$last || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || mb_strlen($first) > 100 || mb_strlen($last) > 100 || mb_strlen($email) > 180
            || mb_strlen($job) > 120 || mb_strlen($phone) > 40) {
            throw new DomainException('Complétez le prénom, le nom et une adresse e-mail valide.');
        }
        if (!$id && $hired === '') {
            throw new DomainException('Renseignez la date d’embauche pour calculer le solde initial.');
        }
        if ($id === $actor->id && (!$active || $role !== 'ROLE_ADMIN' || $email !== $actor->email)) {
            throw new DomainException('Votre compte administrateur doit rester actif, avec son adresse actuelle.');
        }
        $data = [
            'first_name' => $first, 'last_name' => $last, 'email' => $email, 'job' => $job, 'phone' => $phone,
            'department_id' => $this->optionalRelatedId('department', $values->getString('department'), 'Sélectionnez un service valide.'),
            'hired_on' => $hired ? $this->validDay($hired) : null, 'role' => $role, 'active' => (int) $active, 'archived' => (int) $archived,
        ];
        if ($id) {
            $code = trim($values->getString('code'));
            if ($code === '' || mb_strlen($code) > 30) {
                throw new DomainException('Saisissez un matricule de 1 à 30 caractères.');
            }
            $data['code'] = $code;
        }
        $password = $values->getString('password');
        if ($password !== '') {
            $data['password'] = $this->hashEmployeePassword($password);
        }
        if (!$id) {
            $data += ['password' => '!', 'created_at' => date('Y-m-d H:i:s')];
        }

        return $data;
    }

    private function nextEmployeeCode(): string
    {
        // Take the SQLite write lock before checking which candidate is free.
        $this->db->executeStatement('UPDATE employee SET code = code WHERE 0');
        $number = (int) $this->db->fetchOne("SELECT seq FROM sqlite_sequence WHERE name = 'employee'");
        do {
            $code = sprintf('TH-%03d', ++$number);
        } while ($this->db->fetchOne('SELECT 1 FROM employee WHERE code = ?', [$code]));

        return $code;
    }

    private function resetEmployeePassword(Request $request, int $id, Employee $actor): void
    {
        $password = $request->request->getString('password');
        if ($password !== $request->request->getString('passwordConfirmation')) {
            throw new DomainException('La confirmation ne correspond pas au nouveau mot de passe.');
        }
        $hash = $this->hashEmployeePassword($password);
        $this->db->transactional(function () use ($id, $actor, $hash) {
            if (!$this->db->update('employee', ['password' => $hash], ['id' => $id, 'deleted_at' => null])) {
                throw $this->createNotFoundException();
            }
            $this->leaves->audit($actor->id, 'Mot de passe réinitialisé pour le collaborateur n°'.$id.'.');
        });
    }

    private function hashEmployeePassword(#[\SensitiveParameter] string $password): string
    {
        if (mb_strlen($password) < 12 || mb_strlen($password) > 72) {
            throw new DomainException('Choisissez un mot de passe de 12 à 72 caractères.');
        }

        return $this->passwords->hashPassword(new Employee(), $password);
    }

    private function optionalRelatedId(string $table, string $value, string $invalidSelection): ?int
    {
        if ($value === '' || $value === '0') {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || !$this->db->fetchOne('SELECT 1 FROM '.$table.' WHERE id = ?', [$id])) {
            throw new DomainException($invalidSelection);
        }

        return $id;
    }

    private function validDay(string $day): string
    {
        return $this->calendar->date($day)?->format('Y-m-d') ?? throw new DomainException('Indiquez une date valide.');
    }

    private function balanceUnits(string $quantity): int
    {
        $quantity = str_replace(',', '.', $quantity);
        if (!preg_match('/^\d{1,4}(?:\.[05])?$/', $quantity)) {
            throw new DomainException('Indiquez un nombre de jours entier ou par demi-journée.');
        }
        [$whole, $fraction] = explode('.', $quantity) + [1 => '0'];

        return 2 * (int) $whole + (int) ($fraction === '5');
    }

    private function scheduleMask(array $days): int
    {
        $mask = 0;
        foreach ($days as $day) {
            if (filter_var($day, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 6]]) !== false) {
                $mask |= 1 << (int) $day;
            }
        }

        return $mask ?: throw new DomainException('Sélectionnez au moins un jour travaillé.');
    }

    private function saveAttachment(?UploadedFile $file): ?array
    {
        if (!$file) {
            return null;
        }
        $mime = $file->isValid() ? $file->getMimeType() : null;
        $extension = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? null;
        if (!$extension || $file->getSize() > 5 * 1024 * 1024) {
            throw new DomainException('Ajoutez un justificatif PDF, PNG ou JPEG de 5 Mo maximum.');
        }
        $key = bin2hex(random_bytes(24)).'.'.$extension;
        $file->move($this->leaves->attachmentDirectory, $key);

        return ['key' => $key, 'name' => mb_substr($file->getClientOriginalName(), 0, 180)];
    }

    private function filterPeople(array $rows, string $search): array
    {
        if ($search === '') {
            return $rows;
        }
        $search = $this->fold($search);

        return array_values(array_filter($rows, fn (array $row) => str_contains(
            $this->fold(implode(' ', array_filter([$row['first_name'] ?? '', $row['last_name'] ?? '', $row['department'] ?? '',
                $row['code'] ?? '', $row['email'] ?? '', $row['type'] ?? '']))), $search)));
    }

    private function fold(string $value): string
    {
        $normalized = \Normalizer::normalize(mb_strtolower($value), \Normalizer::FORM_D);

        return preg_replace('/\p{Mn}/u', '', $normalized ?: $value);
    }

    private function upcoming(int $employee, DateTimeImmutable $today): ?array
    {
        $rows = $this->workspace->requests($employee, status: 'approved', min: $today->format('Y-m-d'));
        usort($rows, static fn (array $a, array $b) => $a['start_on'] <=> $b['start_on']);

        return $rows[0] ?? null;
    }
}
