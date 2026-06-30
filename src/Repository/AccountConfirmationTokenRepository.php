<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountConfirmationToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountConfirmationToken>
 */
class AccountConfirmationTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountConfirmationToken::class);
    }

    /**
     * Active = not consumed AND not expired. Used by the issuer when
     * rotating tokens (invalidate before issuing a new one).
     *
     * @return list<AccountConfirmationToken>
     */
    public function findActiveForUser(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.consumedAt IS NULL')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }
}