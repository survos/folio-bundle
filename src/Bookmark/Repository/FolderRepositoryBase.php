<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Bookmark\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * Base for the host app's App\Repository\FolderRepository (extends this,
 * with a trivial `parent::__construct($registry, Folder::class)`). $user is
 * typed `object` here since this class never knows the host's concrete User
 * class — see docs/bookmarks.md.
 *
 * @template T of object
 * @extends ServiceEntityRepository<T>
 */
abstract class FolderRepositoryBase extends ServiceEntityRepository
{
    public function findOneForUserBySlug(object $user, string $slug): ?object
    {
        return $this->findOneBy(['user' => $user, 'slug' => $slug]);
    }

    /** @return list<object> */
    public function findForUser(object $user): array
    {
        return $this->findBy(['user' => $user], ['name' => 'ASC']);
    }
}
