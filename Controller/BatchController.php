<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Mautic\CoreBundle\Service\FlashBag;
use Mautic\CoreBundle\Translation\Translator;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageLot;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageQueue;
use MauticPlugin\MauticBpMessageBundle\Model\BpMessageEmailModel;
use MauticPlugin\MauticBpMessageBundle\Model\BpMessageModel;
use MauticPlugin\MauticBpMessageBundle\Service\LotManager;
use MauticPlugin\MauticBpMessageBundle\Service\RoutesService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Controller for managing BpMessage batches/lots
 * Compatible with Mautic 5+ / 7.0.
 */
class BatchController
{
    private ManagerRegistry $doctrine;
    private Translator $translator;
    private FlashBag $flashBag;
    private Environment $twig;
    private BpMessageModel $bpMessageModel;
    private BpMessageEmailModel $bpMessageEmailModel;
    private UrlGeneratorInterface $urlGenerator;
    private LotManager $lotManager;
    private RoutesService $routesService;

    public function __construct(
        ManagerRegistry $doctrine,
        Translator $translator,
        FlashBag $flashBag,
        Environment $twig,
        BpMessageModel $bpMessageModel,
        BpMessageEmailModel $bpMessageEmailModel,
        UrlGeneratorInterface $urlGenerator,
        LotManager $lotManager,
        RoutesService $routesService,
    ) {
        $this->doctrine            = $doctrine;
        $this->translator          = $translator;
        $this->flashBag            = $flashBag;
        $this->twig                = $twig;
        $this->bpMessageModel      = $bpMessageModel;
        $this->bpMessageEmailModel = $bpMessageEmailModel;
        $this->urlGenerator        = $urlGenerator;
        $this->lotManager          = $lotManager;
        $this->routesService       = $routesService;
    }

    /**
     * List all lots with filters.
     */
    public function indexAction(Request $request, int $page = 1): Response
    {
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        // Get filter parameters
        $filterStatus   = $request->query->get('status', '');
        $filterType     = $request->query->get('type', '');      // 'email', 'sms', 'whatsapp', 'rcs' or ''
        $filterCampaign = $request->query->get('campaign_id', '');
        $filterDays     = (int) $request->query->get('days', 0);  // 0 = all, 7, 14, 30, 60, 90

        // Get entity manager
        $em = $this->doctrine->getManager();

        // Get lots with pagination and filters
        $qb = $em->createQueryBuilder();
        $qb->select('l')
            ->from(BpMessageLot::class, 'l')
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        // Apply filters
        if (!empty($filterStatus)) {
            $qb->andWhere('l.status = :status')
                ->setParameter('status', $filterStatus);
        }

        if (!empty($filterType)) {
            if ('email' === $filterType) {
                // Email lots have idQuotaSettings = 0
                $qb->andWhere('l.idQuotaSettings = 0');
            } elseif ('whatsapp' === $filterType) {
                // WhatsApp = serviceType 1
                $qb->andWhere('l.serviceType = 1');
            } elseif ('sms' === $filterType) {
                // SMS = serviceType 2
                $qb->andWhere('l.serviceType = 2');
            } elseif ('rcs' === $filterType) {
                // RCS = serviceType 4
                $qb->andWhere('l.serviceType = 4');
            }
        }

        if (!empty($filterCampaign)) {
            $qb->andWhere('l.campaignId = :campaignId')
                ->setParameter('campaignId', (int) $filterCampaign);
        }

        if ($filterDays > 0) {
            $dateFrom = new \DateTime("-{$filterDays} days");
            $qb->andWhere('l.createdAt >= :dateFrom')
                ->setParameter('dateFrom', $dateFrom);
        }

        $lots = $qb->getQuery()->getResult();

        // Get total count with same filters
        $countQb = $em->createQueryBuilder();
        $countQb->select('COUNT(l.id)')
            ->from(BpMessageLot::class, 'l');

        // Apply same filters to count query
        if (!empty($filterStatus)) {
            $countQb->andWhere('l.status = :status')
                ->setParameter('status', $filterStatus);
        }

        if (!empty($filterType)) {
            if ('email' === $filterType) {
                $countQb->andWhere('l.idQuotaSettings = 0');
            } elseif ('whatsapp' === $filterType) {
                $countQb->andWhere('l.serviceType = 1');
            } elseif ('sms' === $filterType) {
                $countQb->andWhere('l.serviceType = 2');
            } elseif ('rcs' === $filterType) {
                $countQb->andWhere('l.serviceType = 4');
            }
        }

        if (!empty($filterCampaign)) {
            $countQb->andWhere('l.campaignId = :campaignId')
                ->setParameter('campaignId', (int) $filterCampaign);
        }

        if ($filterDays > 0) {
            $dateFrom = new \DateTime("-{$filterDays} days");
            $countQb->andWhere('l.createdAt >= :dateFrom')
                ->setParameter('dateFrom', $dateFrom);
        }

        $totalCount = (int) $countQb->getQuery()->getSingleScalarResult();

        // Get available campaigns for filter dropdown
        $campaignsQb = $em->createQueryBuilder();
        $campaignsQb->select('DISTINCT l.campaignId')
            ->from(BpMessageLot::class, 'l')
            ->where('l.campaignId IS NOT NULL')
            ->orderBy('l.campaignId', 'ASC');
        $campaignIds = array_column($campaignsQb->getQuery()->getArrayResult(), 'campaignId');

        // Get campaign names from campaigns table
        $campaigns = [];
        if (!empty($campaignIds)) {
            $conn = $em->getConnection();
            $campaignRows = $conn->fetchAllAssociative(
                'SELECT id, name FROM campaigns WHERE id IN ('.implode(',', $campaignIds).')'
            );
            foreach ($campaignRows as $row) {
                $campaigns[$row['id']] = $row['name'];
            }
        }

        // Get statistics summary
        $statsQb = $em->createQueryBuilder();
        $statsQb->select(
            'l.status',
            'COUNT(l.id) as count',
            'SUM(CASE WHEN l.idQuotaSettings = 0 THEN 1 ELSE 0 END) as emailCount',
            'SUM(CASE WHEN l.serviceType = 1 THEN 1 ELSE 0 END) as whatsappCount',
            'SUM(CASE WHEN l.serviceType = 2 THEN 1 ELSE 0 END) as smsCount',
            'SUM(CASE WHEN l.serviceType = 4 THEN 1 ELSE 0 END) as rcsCount'
        )
            ->from(BpMessageLot::class, 'l')
            ->groupBy('l.status');

        $statsResults = $statsQb->getQuery()->getResult();
        $statistics   = [
            'total'     => 0,
            'creating'  => 0,
            'open'      => 0,
            'finished'  => 0,
            'failed'    => 0,
            'email'     => 0,
            'whatsapp'  => 0,
            'sms'       => 0,
            'rcs'       => 0,
        ];

        foreach ($statsResults as $stat) {
            $statistics['total'] += $stat['count'];
            $statistics[strtolower($stat['status'])] = $stat['count'];
            $statistics['email'] += (int) $stat['emailCount'];
            $statistics['whatsapp'] += (int) $stat['whatsappCount'];
            $statistics['sms'] += (int) $stat['smsCount'];
            $statistics['rcs'] += (int) $stat['rcsCount'];
        }

        $totalPages = (int) ceil($totalCount / $limit);

        // Get message counts for each lot
        $lotStats = [];
        foreach ($lots as $lot) {
            $stats = $em->createQueryBuilder()
                ->select('q.status', 'COUNT(q.id) as count')
                ->from(BpMessageQueue::class, 'q')
                ->where('q.lot = :lot')
                ->setParameter('lot', $lot)
                ->groupBy('q.status')
                ->getQuery()
                ->getResult();

            $lotStats[$lot->getId()] = [
                'total'   => 0,
                'pending' => 0,
                'sent'    => 0,
                'failed'  => 0,
            ];

            foreach ($stats as $stat) {
                $lotStats[$lot->getId()]['total'] += $stat['count'];
                $status                           = strtolower($stat['status']);
                $lotStats[$lot->getId()][$status] = $stat['count'];
            }
        }

        $content = $this->twig->render('@MauticBpMessage/Batch/list.html.twig', [
            'lots'           => $lots,
            'lotStats'       => $lotStats,
            'page'           => $page,
            'totalPages'     => $totalPages,
            'totalCount'     => $totalCount,
            'statistics'     => $statistics,
            'campaigns'      => $campaigns,
            'filterStatus'   => $filterStatus,
            'filterType'     => $filterType,
            'filterCampaign' => $filterCampaign,
            'filterDays'     => $filterDays,
            'activeLink'     => '#mautic_bpmessage_lot_index',
            'mauticContent'  => 'bpmessageLot',
        ]);

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'newContent'    => $content,
                'route'         => $this->urlGenerator->generate('mautic_bpmessage_lot_index', ['page' => $page]),
                'mauticContent' => 'bpmessageLot',
            ]);
        }

        return new Response($content);
    }

    /**
     * View lot details.
     */
    public function viewAction(Request $request, int $id): Response
    {
        $em = $this->doctrine->getManager();

        $lot = $em->getRepository(BpMessageLot::class)->find($id);

        if (!$lot) {
            $this->flashBag->add('mautic.bpmessage.lot.error.notfound', [], FlashBag::LEVEL_ERROR);

            return new RedirectResponse($this->urlGenerator->generate('mautic_bpmessage_lot_index'));
        }

        // Get lot messages
        $messages = $em->createQueryBuilder()
            ->select('q', 'l')
            ->from(BpMessageQueue::class, 'q')
            ->join('q.lead', 'l')
            ->where('q.lot = :lot')
            ->setParameter('lot', $lot)
            ->orderBy('q.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        // Get statistics
        $stats = $em->createQueryBuilder()
            ->select('q.status', 'COUNT(q.id) as count')
            ->from(BpMessageQueue::class, 'q')
            ->where('q.lot = :lot')
            ->setParameter('lot', $lot)
            ->groupBy('q.status')
            ->getQuery()
            ->getResult();

        $statistics = [
            'total'   => 0,
            'pending' => 0,
            'sent'    => 0,
            'failed'  => 0,
        ];

        foreach ($stats as $stat) {
            $statistics['total'] += $stat['count'];
            $status              = strtolower($stat['status']);
            $statistics[$status] = $stat['count'];
        }

        // Get route name from payload if available
        $routeName = null;
        $payload = $lot->getCreateLotPayload();
        if ($payload && !empty($payload['bookBusinessForeignId']) && !empty($payload['crmId'])) {
            $routeName = $this->routesService->getRouteNameByIdServiceSettings(
                $lot->getIdServiceSettings(),
                (int) $payload['bookBusinessForeignId'],
                (int) $payload['crmId'],
                $lot->getServiceType() ?? 1
            );
        }

        $content = $this->twig->render('@MauticBpMessage/Batch/view.html.twig', [
            'lot'           => $lot,
            'messages'      => $messages,
            'statistics'    => $statistics,
            'routeName'     => $routeName,
            'activeLink'    => '#mautic_bpmessage_lot_index',
            'mauticContent' => 'bpmessageLot',
        ]);

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'newContent'    => $content,
                'route'         => $this->urlGenerator->generate('mautic_bpmessage_lot_view', ['id' => $id]),
                'mauticContent' => 'bpmessageLot',
            ]);
        }

        return new Response($content);
    }

    /**
     * Reprocess a failed lot.
     */
    public function reprocessAction(Request $request, int $id): Response
    {
        $em = $this->doctrine->getManager();

        $lot = $em->getRepository(BpMessageLot::class)->find($id);

        if (!$lot) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => 'Lot not found'], 404);
            }

            $this->flashBag->add('mautic.bpmessage.lot.error.notfound', [], FlashBag::LEVEL_ERROR);

            return new RedirectResponse($this->urlGenerator->generate('mautic_bpmessage_lot_index'));
        }

        try {
            // Reset lot and messages to PENDING
            // IMPORTANT: Do NOT reset messages that failed due to missing phone/email
            // These contacts cannot be sent and should remain as FAILED
            $em->createQueryBuilder()
                ->update(BpMessageQueue::class, 'q')
                ->set('q.status', ':pending')
                ->set('q.retryCount', '0')
                ->set('q.errorMessage', 'NULL')
                ->where('q.lot = :lot')
                ->andWhere('q.status IN (:statuses)')
                ->andWhere('q.errorMessage NOT IN (:permanent_errors) OR q.errorMessage IS NULL')
                ->setParameter('pending', 'PENDING')
                ->setParameter('lot', $lot)
                ->setParameter('statuses', ['FAILED'])
                ->setParameter('permanent_errors', ['Contato sem telefone', 'Contato sem email'])
                ->getQuery()
                ->execute();

            // Check if lot dates are expired - update them for valid range
            // This is critical for CREATING lots that need to call BpMessage API again
            // Using America/Sao_Paulo timezone to match LotManager behavior
            $localTimezone = new \DateTimeZone('America/Sao_Paulo');
            $now           = new \DateTime('now', $localTimezone);

            // Convert lot end date to local timezone for proper comparison
            $lotEndDate = clone $lot->getEndDate();
            $lotEndDate->setTimezone($localTimezone);

            $datesUpdated = false;
            // Always update dates for CREATING lots to ensure they're in the future
            if ($lotEndDate < $now) {
                $timeWindow   = $lot->getTimeWindow(); // in seconds
                $newStartDate = new \DateTime('now', $localTimezone);
                $newEndDate   = (clone $newStartDate)->modify("+{$timeWindow} seconds");

                $lot->setStartDate($newStartDate);
                $lot->setEndDate($newEndDate);

                // Also update the createLotPayload with new dates if it exists
                $payload = $lot->getCreateLotPayload();
                if ($payload) {
                    $payload['startDate'] = $newStartDate->format('Y-m-d\TH:i:s.vP');
                    $payload['endDate']   = $newEndDate->format('Y-m-d\TH:i:s.vP');
                    $lot->setCreateLotPayload($payload);
                }

                $datesUpdated = true;
            }

            // Reset lot status
            // If lot has no externalLotId, it needs to go back to CREATING to call API
            // If lot has externalLotId, it can go to OPEN to just send messages
            $needsApiRegistration = false;
            if ('FAILED' === $lot->getStatus() || 'FINISHED' === $lot->getStatus() || 'CREATING' === $lot->getStatus() || 'FAILED_CREATION' === $lot->getStatus()) {
                if (empty($lot->getExternalLotId())) {
                    $lot->setStatus('CREATING');
                    $needsApiRegistration = true;
                } else {
                    $lot->setStatus('OPEN');
                }
                $lot->setErrorMessage(null);
                $lot->setFinishedAt(null);
            }

            $em->persist($lot);
            $em->flush();

            // Force update with SQL to ensure persistence
            $payload    = $lot->getCreateLotPayload();
            $connection = $em->getConnection();
            $connection->executeStatement(
                'UPDATE bpmessage_lot SET status = ?, start_date = ?, end_date = ?, create_lot_payload = ?, error_message = NULL, finished_at = NULL WHERE id = ?',
                [
                    $lot->getStatus(),
                    $lot->getStartDate()->format('Y-m-d H:i:s'),
                    $lot->getEndDate()->format('Y-m-d H:i:s'),
                    $payload ? json_encode($payload) : null,
                    $lot->getId(),
                ]
            );

            // For CREATING lots, immediately try to register in the API
            if ($needsApiRegistration) {
                $em->refresh($lot);
                $registered = $this->lotManager->registerLotInApi($lot);

                if (!$registered) {
                    // Error already saved by registerLotInApi
                    $em->refresh($lot);

                    if ($request->isXmlHttpRequest()) {
                        return new JsonResponse([
                            'success' => false,
                            'message' => $lot->getErrorMessage() ?: 'Failed to register lot in API',
                        ], 400);
                    }

                    // No flash message - error is already shown in lot details panel
                    return new RedirectResponse($this->urlGenerator->generate('mautic_bpmessage_lot_view', ['id' => $id]));
                }
            }

            if ($request->isXmlHttpRequest()) {
                return new JsonResponse([
                    'success' => true,
                    'message' => $this->translator->trans('mautic.bpmessage.lot.reprocessed'),
                ]);
            }

            $this->flashBag->add('mautic.bpmessage.lot.reprocessed', [], FlashBag::LEVEL_SUCCESS);
        } catch (\Exception $e) {
            // Save the error to the lot (no prefix, clean message)
            $errorMessage = $e->getMessage();
            $lot->setErrorMessage($errorMessage);
            $lot->setStatus('FAILED');
            $em->persist($lot);
            $em->flush();

            // Force SQL update
            $connection = $em->getConnection();
            $connection->executeStatement(
                'UPDATE bpmessage_lot SET status = ?, error_message = ? WHERE id = ?',
                ['FAILED', $errorMessage, $id]
            );

            if ($request->isXmlHttpRequest()) {
                return new JsonResponse([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 500);
            }

            // No flash message - error is already shown in lot details panel
        }

        // Redirect back to list if came from list, otherwise to lot view
        return $this->redirectBackToReferer($request, $id);
    }

    /**
     * Process a specific lot (force close and send).
     */
    public function processLotAction(Request $request, int $id): Response
    {
        $em = $this->doctrine->getManager();

        $lot = $em->getRepository(BpMessageLot::class)->find($id);

        if (!$lot) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => 'Lot not found'], 404);
            }

            $this->flashBag->add('mautic.bpmessage.lot.error.notfound', [], FlashBag::LEVEL_ERROR);

            return new RedirectResponse($this->urlGenerator->generate('mautic_bpmessage_lot_index'));
        }

        // Only allow processing lots that are OPEN or CREATING
        if (!in_array($lot->getStatus(), ['CREATING', 'OPEN'])) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse([
                    'success' => false,
                    'message' => $this->translator->trans('mautic.bpmessage.lot.error.cannot_process'),
                ], 400);
            }

            $this->flashBag->add('mautic.bpmessage.lot.error.cannot_process', [], FlashBag::LEVEL_ERROR);

            return new RedirectResponse($this->urlGenerator->generate('mautic_bpmessage_lot_view', ['id' => $id]));
        }

        // If lot dates are expired, update them before processing
        // This is important for CREATING lots that failed previously and need valid date ranges
        // Using America/Sao_Paulo timezone to match LotManager behavior
        $localTimezone = new \DateTimeZone('America/Sao_Paulo');
        $now           = new \DateTime('now', $localTimezone);

        // Convert lot end date to local timezone for proper comparison
        $lotEndDate = clone $lot->getEndDate();
        $lotEndDate->setTimezone($localTimezone);

        if ($lotEndDate < $now) {
            $timeWindow   = $lot->getTimeWindow(); // in seconds
            $newStartDate = new \DateTime('now', $localTimezone);
            $newEndDate   = (clone $newStartDate)->modify("+{$timeWindow} seconds");

            $lot->setStartDate($newStartDate);
            $lot->setEndDate($newEndDate);

            // Also update the createLotPayload with new dates if it exists
            $payload = $lot->getCreateLotPayload();
            if ($payload) {
                $payload['startDate'] = $newStartDate->format('Y-m-d\TH:i:s.vP');
                $payload['endDate']   = $newEndDate->format('Y-m-d\TH:i:s.vP');
                $lot->setCreateLotPayload($payload);
            }

            $em->persist($lot);
            $em->flush();

            // Force update with SQL to ensure persistence
            $connection = $em->getConnection();
            $connection->executeStatement(
                'UPDATE bpmessage_lot SET start_date = ?, end_date = ?, create_lot_payload = ? WHERE id = ?',
                [
                    $newStartDate->format('Y-m-d H:i:s'),
                    $newEndDate->format('Y-m-d H:i:s'),
                    $payload ? json_encode($payload) : null,
                    $lot->getId(),
                ]
            );

            // Refresh lot to get updated data
            $em->refresh($lot);
        }

        try {
            // For CREATING lots without externalLotId, first register in API
            if ('CREATING' === $lot->getStatus() && empty($lot->getExternalLotId())) {
                $registered = $this->lotManager->registerLotInApi($lot);

                if (!$registered) {
                    // Error is already saved to lot by registerLotInApi
                    $em->refresh($lot);

                    if ($request->isXmlHttpRequest()) {
                        return new JsonResponse([
                            'success' => false,
                            'message' => $lot->getErrorMessage() ?: $this->translator->trans('mautic.bpmessage.lot.process.failed'),
                        ], 400);
                    }

                    // No flash message - error is already shown in lot details panel
                    return new RedirectResponse($this->urlGenerator->generate('mautic_bpmessage_lot_view', ['id' => $id]));
                }

                // Refresh lot to get updated status (should be OPEN now)
                $em->refresh($lot);
            }

            // Use forceCloseLot which processes and finishes the lot
            // Check if this is an email lot (idQuotaSettings = 0) or SMS lot (idQuotaSettings > 0)
            if ($lot->isEmailLot()) {
                $success = $this->bpMessageEmailModel->forceCloseLot($id);
            } else {
                $success = $this->bpMessageModel->forceCloseLot($id);
            }

            if ($success) {
                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse([
                        'success' => true,
                        'message' => $this->translator->trans('mautic.bpmessage.lot.process.success'),
                    ]);
                }

                $this->flashBag->add('mautic.bpmessage.lot.process.success', [], FlashBag::LEVEL_SUCCESS);
            } else {
                // Refresh lot to get error message
                $em->refresh($lot);

                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => $lot->getErrorMessage() ?: $this->translator->trans('mautic.bpmessage.lot.process.failed'),
                    ]);
                }

                // No flash message - error is already shown in lot details panel
            }
        } catch (\Exception $e) {
            // Save the error to the lot (no prefix, clean message)
            $errorMessage = $e->getMessage();
            $lot->setErrorMessage($errorMessage);
            $lot->setStatus('FAILED');
            $em->persist($lot);
            $em->flush();

            // Also force update via SQL to ensure persistence
            $connection = $em->getConnection();
            $connection->executeStatement(
                'UPDATE bpmessage_lot SET status = ?, error_message = ? WHERE id = ?',
                ['FAILED', $errorMessage, $id]
            );

            if ($request->isXmlHttpRequest()) {
                return new JsonResponse([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 500);
            }

            // No flash message - error is already shown in lot details panel
        }

        // Redirect back to list if came from list, otherwise to lot view
        return $this->redirectBackToReferer($request, $id);
    }

    /**
     * Cancel/fail a lot manually.
     */
    public function cancelAction(Request $request, int $id): Response
    {
        $em = $this->doctrine->getManager();

        $lot = $em->getRepository(BpMessageLot::class)->find($id);

        if (!$lot) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => 'Lot not found'], 404);
            }

            $this->flashBag->add('mautic.bpmessage.lot.error.notfound', [], FlashBag::LEVEL_ERROR);

            return new RedirectResponse($this->urlGenerator->generate('mautic_bpmessage_lot_index'));
        }

        // Only allow canceling lots that are not already FINISHED or FAILED
        if (!in_array($lot->getStatus(), ['CREATING', 'OPEN', 'SENDING'])) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse([
                    'success' => false,
                    'message' => $this->translator->trans('mautic.bpmessage.lot.error.cannot_cancel'),
                ], 400);
            }

            $this->flashBag->add('mautic.bpmessage.lot.error.cannot_cancel', [], FlashBag::LEVEL_ERROR);

            return new RedirectResponse($this->urlGenerator->generate('mautic_bpmessage_lot_view', ['id' => $id]));
        }

        // Get reason from request
        $reason = $request->request->get('reason', 'Manually cancelled by user');

        // Update lot status
        $lot->setStatus('FAILED');
        $lot->setErrorMessage('Cancelled: '.$reason);
        $em->persist($lot);
        $em->flush();

        // Force update with SQL to ensure persistence
        $connection = $em->getConnection();
        $connection->executeStatement(
            'UPDATE bpmessage_lot SET status = ?, error_message = ? WHERE id = ?',
            ['FAILED', 'Cancelled: '.$reason, $lot->getId()]
        );

        // Mark pending messages as failed
        $connection->executeStatement(
            'UPDATE bpmessage_queue SET status = ?, error_message = ? WHERE lot_id = ? AND status = ?',
            ['FAILED', 'Lot cancelled', $lot->getId(), 'PENDING']
        );

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'success' => true,
                'message' => $this->translator->trans('mautic.bpmessage.lot.cancelled'),
            ]);
        }

        $this->flashBag->add('mautic.bpmessage.lot.cancelled', [], FlashBag::LEVEL_SUCCESS);

        // Redirect back to list if came from list, otherwise to lot view
        return $this->redirectBackToReferer($request, $id);
    }

    /**
     * Process pending lots manually.
     * Processes both SMS/WhatsApp lots and Email lots using the correct model for each.
     */
    public function processAction(Request $request): Response
    {
        $limit = (int) $request->query->get('limit', 10);

        // Process SMS/WhatsApp lots
        $smsStats = $this->bpMessageModel->processOpenLots(false);

        // Process Email lots
        $emailStats = $this->bpMessageEmailModel->processOpenLots(false);

        // Combine stats
        $stats = [
            'processed' => $smsStats['processed'] + $emailStats['processed'],
            'succeeded' => $smsStats['succeeded'] + $emailStats['succeeded'],
            'failed'    => $smsStats['failed'] + $emailStats['failed'],
        ];

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'success' => 0 === $stats['failed'],
                'stats'   => $stats,
                'message' => $this->translator->trans('mautic.bpmessage.lot.processed', [
                    '%succeeded%' => $stats['succeeded'],
                    '%failed%'    => $stats['failed'],
                ]),
            ]);
        }

        $flashType = $stats['failed'] > 0 ? FlashBag::LEVEL_WARNING : FlashBag::LEVEL_SUCCESS;
        $this->flashBag->add(
            'mautic.bpmessage.lot.processed',
            [
                '%succeeded%' => $stats['succeeded'],
                '%failed%'    => $stats['failed'],
            ],
            $flashType
        );

        return new RedirectResponse($this->urlGenerator->generate('mautic_bpmessage_lot_index'));
    }

    /**
     * Redirect back to the referer page (list or lot view).
     * If referer contains '/lots' (list page), redirect to list. Otherwise, redirect to lot view.
     */
    private function redirectBackToReferer(Request $request, int $lotId): RedirectResponse
    {
        $referer = $request->headers->get('referer');

        // Check if came from list page (URL ends with /lots or /lots/{page})
        if ($referer && preg_match('#/bpmessage/lots(?:/\d+)?(?:\?|$)#', $referer)) {
            return new RedirectResponse($this->urlGenerator->generate('mautic_bpmessage_lot_index'));
        }

        // Default: redirect to lot view
        return new RedirectResponse($this->urlGenerator->generate('mautic_bpmessage_lot_view', ['id' => $lotId]));
    }
}
