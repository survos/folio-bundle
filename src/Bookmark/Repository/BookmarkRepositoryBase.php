<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Bookmark\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * Base for the host app's App\Repository\BookmarkRepository (extends this,
 * with a trivial `parent::__construct($registry, Bookmark::class)`). $user/
 * $folder are typed `object` here since this class never knows the host's
 * concrete User/Folder classes — see docs/bookmarks.md.
 *
 * @template T of object
 * @extends ServiceEntityRepository<T>
 */
abstract class BookmarkRepositoryBase extends ServiceEntityRepository
{
    public function findOneByRow(object $user, string $provider, string $dataset, string $coreCode, string $localId): ?object
    {
        return $this->findOneBy([
            'user' => $user,
            'provider' => $provider,
            'dataset' => $dataset,
            'coreCode' => $coreCode,
            'localId' => $localId,
        ]);
    }

    /** @return list<object> */
    public function findForUser(object $user, ?object $folder = null): array
    {
        $criteria = ['user' => $user];
        if ($folder !== null) {
            $criteria['folder'] = $folder;
        }

        return $this->findBy($criteria, ['created' => 'DESC']);
    }
}
