<?php

declare(strict_types=1);
namespace Survos\FolioBundle\Service;

use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Survos\FolioBundle\Entity\Folio;
use Survos\FolioBundle\Entity\FolioProperty;
use Survos\Folio\PropertyStore;

/** Registered on the folio EM only. All state belongs to the entity, never the switching connection. */
final class FolioPropertyListener
{
    public function postLoad(PostLoadEventArgs $event): void
    {
        if (($folio = $event->getObject()) instanceof Folio) {
            $folio->loadProperties((new PropertyStore($event->getObjectManager()->getConnection()->getNativeConnection()))->read());
        }
    }

    public function preFlush(PreFlushEventArgs $event): void
    {
        $em = $event->getObjectManager();
        $uow = $em->getUnitOfWork();
        $folios = $uow->getIdentityMap()[Folio::class] ?? [];
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Folio) { $folios[$entity->code] = $entity; }
        }
        foreach ($folios as $folio) {
            foreach ($folio->pendingProperties() as $key => $value) {
                $property = $em->find(FolioProperty::class, $key);
                if ($value === null) {
                    if ($property !== null) { $em->remove($property); }
                } elseif ($property === null) {
                    $em->persist(new FolioProperty($key, $value));
                } else {
                    $property->assign($value);
                }
            }
            // Keep pending changes until postFlush: a failed transaction must not forget them.
        }
    }

    public function postFlush(\Doctrine\ORM\Event\PostFlushEventArgs $event): void
    {
        foreach ($event->getObjectManager()->getUnitOfWork()->getIdentityMap()[Folio::class] ?? [] as $folio) {
            $folio->markPropertiesSaved();
        }
    }
}
