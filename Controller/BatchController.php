<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Mautic\CoreBundle\Service\FlashBag;
use Mautic\CoreBundle\Translation\Translator;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageLot;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageQueue;
use MauticPlugin\MauticBpMessageBundle\Model\BpMessageModel;
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
    private UrlGeneratorInterface $urlGenerator;

    public function __construct(
        ManagerRegistry $doctrine,
        Translator $translator,
        FlashBag $flashBag,
        Environment $twig,
        BpMessageModel $bpMessageModel,
        UrlGeneratorInterface $urlGenerator,
    ) {
        $this->doctrine       = $doctrine;
        $this->translator     = $translator;
        $this->flashBag       = $flashBag;
        $this->twig           = $twig;
        $this->bpMessageModel = $bpMessageModel;
        $this->urlGenerator   = $urlGenerator;
    }

    /**
     * List all lots.
     */
    public function indexAction(Request $request, int $page = 1): Response
    {
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        // Get entity manager
        $em = $this->doctrine->getManager();

        // Get lots with pagination
        $qb = $em->createQueryBuilder();
        $qb->select('l')
            ->from(BpMessageLot::class, 'l')
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $lots = $qb->getQuery()->getResult();

        // Get total count
        $countQb = $em->createQueryBuilder();
        $countQb->select('COUNT(l.id)')
            ->from(BpMessageLot::class, 'l');
        $totalCount = (int) $countQb->getQuery()->getSingleScalarResult();

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
            'lots'          => $lots,
            'lotStats'      => $lotStats,
            'page'          => $page,
            'totalPages'    => $totalPages,
            'totalCount'    => $totalCount,
            'activeLink'    => '#mautic_bpmessage_lot_index',
            'mauticContent' => 'bpmessageLot',
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
            $this->flashBag->add('mautic.bpmessage.lot.error.notfound', FlashBag::LEVEL_ERROR);

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

        $content = $this->twig->render('@MauticBpMessage/Batch/view.html.twig', [
            'lot'           => $lot,
            'messages'      => $messages,
            'statistics'    => $statistics,
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

            $this->flashBag->add('mautic.bpmessage.lot.error.notfound', FlashBag::LEVEL_ERROR);

            return new RedirectResponse($this->urlGenerator->generate('mautic_bpmessage_lot_index'));
        }

        // Reset lot and messages to PENDING
        $em->createQueryBuilder()
            ->update(BpMessageQueue::class, 'q')
            ->set('q.status', ':pending')
            ->set('q.retryCount', '0')
            ->set('q.errorMessage', 'NULL')
            ->where('q.lot = :lot')
            ->andWhere('q.status IN (:statuses)')
            ->setParameter('pending', 'PENDING')
            ->setParameter('lot', $lot)
            ->setParameter('statuses', ['FAILED'])
            ->getQuery()
            ->execute();

        // Reset lot status
        if ('FAILED' === $lot->getStatus() || 'FINISHED' === $lot->getStatus()) {
            $lot->setStatus('OPEN');
            $lot->setErrorMessage(null);
            $lot->setFinishedAt(null);
        }

        $em->persist($lot);
        $em->flush();

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'success' => true,
                'message' => $this->translator->trans('mautic.bpmessage.lot.reprocessed'),
            ]);
        }

        $this->flashBag->add('mautic.bpmessage.lot.reprocessed', FlashBag::LEVEL_SUCCESS);

        return new RedirectResponse($this->urlGenerator->generate('mautic_bpmessage_lot_view', ['id' => $id]));
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

            $this->flashBag->add('mautic.bpmessage.lot.error.notfound', FlashBag::LEVEL_ERROR);

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

            $this->flashBag->add('mautic.bpmessage.lot.error.cannot_cancel', FlashBag::LEVEL_ERROR);

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

        $this->flashBag->add('mautic.bpmessage.lot.cancelled', FlashBag::LEVEL_SUCCESS);

        return new RedirectResponse($this->urlGenerator->generate('mautic_bpmessage_lot_view', ['id' => $id]));
    }

    /**
     * Process pending lots manually.
     */
    public function processAction(Request $request): Response
    {
        $limit = (int) $request->query->get('limit', 10);

        // Use BpMessageModel to process lots
        $stats = $this->bpMessageModel->processPendingLots($limit);

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
            $this->translator->trans('mautic.bpmessage.lot.processed', [
                '%succeeded%' => $stats['succeeded'],
                '%failed%'    => $stats['failed'],
            ]),
            $flashType
        );

        return new RedirectResponse($this->urlGenerator->generate('mautic_bpmessage_lot_index'));
    }
}
