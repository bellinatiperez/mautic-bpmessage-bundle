<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * Repository for BpMessageLot entity.
 */
class BpMessageLotRepository extends EntityRepository
{
    /**
     * Find an open lot for a campaign.
     */
    public function findOpenLotForCampaign(int $campaignId): ?BpMessageLot
    {
        return $this->createQueryBuilder('l')
            ->where('l.campaignId = :campaignId')
            ->andWhere('l.status IN (:statuses)')
            ->setParameter('campaignId', $campaignId)
            ->setParameter('statuses', ['CREATING', 'OPEN'])
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all open lots that should be closed (time window expired or batch size reached).
     *
     * @return BpMessageLot[]
     */
    public function findLotsToClose(): array
    {
        $qb = $this->createQueryBuilder('l')
            ->where('l.status = :status')
            ->setParameter('status', 'OPEN')
            ->orderBy('l.createdAt', 'ASC');

        $lots = $qb->getQuery()->getResult();

        // Filter lots that should be closed
        return array_filter($lots, function (BpMessageLot $lot) {
            return $lot->shouldCloseByTime() || $lot->shouldCloseByCount();
        });
    }

    /**
     * Find lots that are stuck in CREATING status for too long.
     *
     * @return BpMessageLot[]
     */
    public function findStuckCreatingLots(int $minutes = 30): array
    {
        $threshold = new \DateTime();
        $threshold->modify("-{$minutes} minutes");

        return $this->createQueryBuilder('l')
            ->where('l.status = :status')
            ->andWhere('l.createdAt < :threshold')
            ->setParameter('status', 'CREATING')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find lots with SENDING status.
     *
     * @return BpMessageLot[]
     */
    public function findSendingLots(): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.status = :status')
            ->setParameter('status', 'SENDING')
            ->orderBy('l.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count messages by status for a lot.
     */
    public function getMessageCountsByStatus(int $lotId): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql  = 'SELECT status, COUNT(*) as count
                FROM bpmessage_queue
                WHERE lot_id = :lotId
                GROUP BY status';

        $stmt = $conn->prepare($sql);
        $stmt->execute(['lotId' => $lotId]);
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $counts = [
            'PENDING' => 0,
            'SENT'    => 0,
            'FAILED'  => 0,
        ];

        foreach ($results as $row) {
            $counts[$row['status']] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Get statistics for a campaign.
     */
    public function getCampaignStats(int $campaignId): array
    {
        $qb = $this->createQueryBuilder('l')
            ->select('l.status, COUNT(l.id) as lot_count, SUM(l.messagesCount) as total_messages')
            ->where('l.campaignId = :campaignId')
            ->groupBy('l.status')
            ->setParameter('campaignId', $campaignId);

        return $qb->getQuery()->getArrayResult();
    }

    /**
     * Delete old finished lots.
     *
     * @return int Number of deleted lots
     */
    public function deleteOldFinishedLots(int $days = 30): int
    {
        $threshold = new \DateTime();
        $threshold->modify("-{$days} days");

        return $this->createQueryBuilder('l')
            ->delete()
            ->where('l.status = :status')
            ->andWhere('l.finishedAt < :threshold')
            ->setParameter('status', 'FINISHED')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();
    }

    /**
     * Find lot by external lot ID.
     */
    public function findByExternalLotId(string $externalLotId): ?BpMessageLot
    {
        return $this->findOneBy(['externalLotId' => $externalLotId]);
    }
}
