<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * Repository for BpMessageQueue entity
 */
class BpMessageQueueRepository extends EntityRepository
{
    /**
     * Find pending messages for a lot
     *
     * @param int $lotId
     * @param int|null $limit
     * @return BpMessageQueue[]
     */
    public function findPendingForLot(int $lotId, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('q')
            ->where('q.lot = :lotId')
            ->andWhere('q.status = :status')
            ->setParameter('lotId', $lotId)
            ->setParameter('status', 'PENDING')
            ->orderBy('q.createdAt', 'ASC');

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find failed messages that can be retried
     *
     * @param int $maxRetries
     * @param int|null $limit
     * @return BpMessageQueue[]
     */
    public function findFailedForRetry(int $maxRetries = 3, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('q')
            ->where('q.status = :status')
            ->andWhere('q.retryCount < :maxRetries')
            ->setParameter('status', 'FAILED')
            ->setParameter('maxRetries', $maxRetries)
            ->orderBy('q.createdAt', 'ASC');

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Count messages by status for a lot
     *
     * @param int $lotId
     * @param string $status
     * @return int
     */
    public function countByStatus(int $lotId, string $status): int
    {
        return (int) $this->createQueryBuilder('q')
            ->select('COUNT(q.id)')
            ->where('q.lot = :lotId')
            ->andWhere('q.status = :status')
            ->setParameter('lotId', $lotId)
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get all messages for a lot
     *
     * @param int $lotId
     * @return BpMessageQueue[]
     */
    public function findAllForLot(int $lotId): array
    {
        return $this->createQueryBuilder('q')
            ->where('q.lot = :lotId')
            ->setParameter('lotId', $lotId)
            ->orderBy('q.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Delete messages for old lots
     *
     * @param int $days
     * @return int Number of deleted messages
     */
    public function deleteOldMessages(int $days = 30): int
    {
        $threshold = new \DateTime();
        $threshold->modify("-{$days} days");

        $qb = $this->createQueryBuilder('q')
            ->delete()
            ->where('q.status = :status')
            ->andWhere('q.sentAt < :threshold')
            ->setParameter('status', 'SENT')
            ->setParameter('threshold', $threshold);

        return $qb->getQuery()->execute();
    }

    /**
     * Mark multiple messages as sent
     *
     * @param array $ids
     * @return int
     */
    public function markAsSent(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $now = new \DateTime();

        return $this->createQueryBuilder('q')
            ->update()
            ->set('q.status', ':status')
            ->set('q.sentAt', ':sentAt')
            ->where('q.id IN (:ids)')
            ->setParameter('status', 'SENT')
            ->setParameter('sentAt', $now)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    /**
     * Reset failed messages to pending for retry
     *
     * @param array $ids
     * @return int
     */
    public function resetToPending(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        return $this->createQueryBuilder('q')
            ->update()
            ->set('q.status', ':status')
            ->where('q.id IN (:ids)')
            ->setParameter('status', 'PENDING')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    /**
     * Check if a lead is already queued for a lot
     *
     * @param int $lotId
     * @param int $leadId
     * @return bool
     */
    public function isLeadQueued(int $lotId, int $leadId): bool
    {
        $count = $this->createQueryBuilder('q')
            ->select('COUNT(q.id)')
            ->where('q.lot = :lotId')
            ->andWhere('q.lead = :leadId')
            ->setParameter('lotId', $lotId)
            ->setParameter('leadId', $leadId)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
