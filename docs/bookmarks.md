# Bookmarks

Lets a signed-in user save a folio row (optionally into a flat, user-owned
folder) for later — implemented once here so every host app (zm, openfoto, …)
gets the same behavior instead of re-implementing it per app.

## Why this entity is different from every other folio-bundle entity

Every other entity this bundle ships (`Folio`, `Core`, `Row`, `Page`, `Claim`,
`Link`, `Str`, `TermSet`, …) is mapped into the bundle's own `folio` entity
manager — a dedicated DBAL connection pointed at a **per-folio SQLite file
that gets swapped at runtime** (`FolioConnectionWrapper::selectDatabase()`,
driven by `FolioService::context($folioCode)`). See `configuration.md`.

A bookmark is not folio content — it's a host-owned, cross-folio user record
("this user saved this row"). It has to live in the host app's own `default`
entity manager, alongside `App\Entity\User`, because:

1. A real foreign key to `User` only works if both sides share an entity
   manager/connection.
2. `Row`/folio content lives in the swapped-per-folio `folio` EM, so a
   bookmark can *never* hold a real ORM relation to the row it bookmarks —
   see "Row identity is stored as plain columns" below, same as it's always
   been in `App\Entity\Bookmark` before this bundle existed.

So this is the first case of the bundle shipping code meant to be mapped into
the *host's* EM rather than its own — worth flagging explicitly since it
breaks the "everything in `src/Entity` maps into the `folio` EM" assumption
that holds for the rest of the bundle.

## The MappedSuperclass split

`Survos\FolioBundle\Bookmark\Entity\BookmarkBase` and `FolderBase` are
[Doctrine mapped superclasses](https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/inheritance-mapping.html#mapped-superclasses) —
modeled on this bundle's own `Str extends Survos\BabelBundle\Entity\Base\StrBase`
pattern (`src/Entity/Str.php`), but with one important difference: **the base
classes here hold only scalar fields and computed (non-persisted) properties —
no relations at all.**

`BookmarkBase` has: `id`, `created`, `provider`, `dataset`, `coreCode`,
`localId`, `dtoType`, `label`, plus computed `folioCode`/`rowRouteParams`.
It does **not** declare `$user` or `$folder`.

`FolderBase` has: `id`, `created`, `name`, `slug`, `visibility`, plus computed
`isPrivate`. It does **not** declare `$user`, `$bookmarks`, or
`$bookmarkCount` (the latter needs the `$bookmarks` relation).

Every association — `Bookmark::$user` (`ManyToOne`), `Bookmark::$folder`
(`ManyToOne`), `Folder::$user` (`ManyToOne`), `Folder::$bookmarks`
(`OneToMany`), plus `User::$bookmarks`/`User::$folders` (`OneToMany`) — is
declared entirely by the **host app's own concrete classes**
(`App\Entity\Bookmark`, `App\Entity\Folder`, `App\Entity\User`).

Why not put the relations in the base classes? A Doctrine association needs
a single resolvable `targetEntity` FQCN at mapping time. `App\Entity\User`
differs per host app (zm's is a rich entity with many relations; openfoto's
is a bare auth-only entity) — even though both currently happen to share the
literal class name `App\Entity\User`, relying on that string match would be
an undocumented, fragile coupling, not a contract. Keeping every relation
100% host-owned avoids it entirely, and needs no `resolve_target_entities`
indirection (that mechanism resolves an *interface* to a concrete class
within a single entity manager's metadata — it doesn't help here, since the
association itself, not just its target type, needs to be declared
somewhere with a concrete class in scope).

## Row identity is stored as plain columns, not a relation

`BookmarkBase` stores `provider` + `dataset` (`= folioCode`) + `coreCode` +
`localId` as plain scalar columns, mirroring
`Survos\FolioBundle\Entity\Row::id()`/`getRp()`. This is not new to this
bundle — it's the same design `App\Entity\Bookmark` already used before this
refactor — but the reasoning is worth restating here since it's now
load-bearing bundle design, not just an app convention: `Row` lives in the
swapped-per-folio `folio` EM, so a real FK from the host's `default` EM is
never possible. `dtoType`/`label` are a display cache snapshotted at bookmark
time (for rendering the bookmarks list without a cross-EM lookup per row),
not part of row identity.

## The directory-sweep-up gotcha

`BookmarkBase`/`FolderBase` live under `src/Bookmark/Entity/` — a sibling of
`src/Entity/`, not a subdirectory of it. This is deliberate. Doctrine's
simplified/attribute mapping driver (`ColocatedMappingDriver`) recursively
scans **every** `.php` file under a mapping's configured `dir` — there's no
`exclude_paths` option in DoctrineBundle's mapping config. The `folio` EM's
existing `SurvosFolioBundle` mapping points at `dir: .../folio-bundle/src/Entity`,
so anything placed under `src/Entity/**` (including a new `src/Entity/Base/`)
would get swept into the `folio` EM — wrong EM entirely.

Doctrine resolves `MappedSuperclass` parents by walking a concrete entity's
class hierarchy via reflection (`AbstractClassMetadataFactory::getParentClasses()`),
**independent of whether the parent class's file lives inside any mapped
`dir`**. This is exactly how `Str extends StrBase` already works today —
babel-bundle's `src/Entity/Base/` is never scanned by any EM mapping in
zm/openfoto's `doctrine.yaml`, yet the inheritance resolves fine because the
concrete `Str` class (which *is* inside a scanned dir) pulls its parent in
via reflection, not directory scanning.

**Net effect: no `doctrine.yaml` changes are needed in either EM, in either
host app.** The `folio` EM never sees `src/Bookmark/**` (nothing scans that
directory), and the `default` EM doesn't need any new mapping entry either —
its existing `App: {dir: src/Entity, prefix: App\Entity}` mapping already
covers `App\Entity\Bookmark`/`App\Entity\Folder`, and Doctrine pulls in
`BookmarkBase`/`FolderBase` automatically via inheritance. Do not move these
files back under `src/Entity/` — that would silently break by mapping them
into the wrong entity manager.

## Setting it up in a host app

1. Add the concrete entities (fields/relations shown are the full contract —
   copy zm's `App\Entity\Bookmark`/`App\Entity\Folder` as the reference
   implementation):

   ```php
   // src/Entity/Bookmark.php
   namespace App\Entity;

   use Survos\FolioBundle\Bookmark\Entity\BookmarkBase;

   #[ORM\Entity(repositoryClass: BookmarkRepository::class)]
   #[ORM\UniqueConstraint(name: 'uniq_bookmark_row', fields: ['user', 'provider', 'dataset', 'coreCode', 'localId'])]
   class Bookmark extends BookmarkBase
   {
       public function __construct(
           #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'bookmarks')]
           #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
           public User $user,
           string $provider,
           string $dataset,
           string $coreCode,
           string $localId,
           #[ORM\ManyToOne(targetEntity: Folder::class, inversedBy: 'bookmarks')]
           #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
           public ?Folder $folder = null,
           ?string $dtoType = null,
           ?string $label = null,
       ) {
           $this->id = new Ulid();
           $this->created = new \DateTimeImmutable();
           $this->provider = $provider;
           $this->dataset = $dataset;
           $this->coreCode = $coreCode;
           $this->localId = $localId;
           $this->dtoType = $dtoType;
           $this->label = $label;
       }
   }
   ```

   Note the inherited scalar params (`provider`, `dataset`, `coreCode`,
   `localId`, `dtoType`, `label`) can't use constructor property promotion —
   PHP can't promote-and-assign a property declared on a parent class — so
   they're plain params assigned in the constructor body. Callers are
   unaffected: `new Bookmark(user: $u, provider: $p, ...)` works identically
   whether or not the target params are promoted.

   `App\Entity\Folder extends FolderBase` similarly adds `$user`
   (`ManyToOne`), `$bookmarks` (`OneToMany`, initialized in the constructor),
   and `$bookmarkCount` (computed from `$bookmarks`).

2. Add the inverse side to `App\Entity\User`:

   ```php
   #[ORM\OneToMany(targetEntity: Folder::class, mappedBy: 'user', orphanRemoval: true)]
   public private(set) Collection $folders;

   #[ORM\OneToMany(targetEntity: Bookmark::class, mappedBy: 'user', orphanRemoval: true)]
   public private(set) Collection $bookmarks;
   ```

   (initialized with `new ArrayCollection()` in the constructor). Optionally
   `implements Survos\FolioBundle\Bookmark\Contract\BookmarkOwnerInterface`.

3. Add trivial repository subclasses:

   ```php
   // src/Repository/BookmarkRepository.php
   class BookmarkRepository extends Survos\FolioBundle\Bookmark\Repository\BookmarkRepositoryBase
   {
       public function __construct(ManagerRegistry $registry)
       {
           parent::__construct($registry, Bookmark::class);
       }
   }
   ```

   (same shape for `FolderRepository` / `FolderRepositoryBase`.)

4. Configure the bundle:

   ```yaml
   survos_folio:
     bookmark_class: App\Entity\Bookmark
     folder_class: App\Entity\Folder
   ```

   This registers `Survos\FolioBundle\Bookmark\Service\BookmarkManager` —
   autowire it wherever you need `isBookmarked()`/`add()`/`remove()`/
   `toggle()`/`move()`/`createFolder()`/`renameFolder()`/`deleteFolder()`.
   Both `bookmark_class` and `folder_class` must be set together; if either
   is left null, `BookmarkManager` simply isn't registered (same
   "optional collaborator" pattern the bundle uses elsewhere, e.g.
   `FolioSlugResolverInterface`) rather than failing container compilation.

5. **The constructor contract `BookmarkManager` relies on.** PHP can't
   enforce a constructor shape via an interface, so this is a documented
   convention, not a type-checked one: your `$bookmarkClass` must accept
   named constructor parameters `user, provider, dataset, coreCode, localId,
   folder (nullable), dtoType (nullable), label (nullable)`; your
   `$folderClass` must accept `user, name, slug, visibility`.
   `BookmarkManager` calls `new $class(user: ..., provider: ..., ...)`
   internally.

6. Run `bin/console doctrine:migrations:diff` to generate the `bookmark`/
   `folder` tables (green-field apps), or verify it produces an *empty* diff
   (apps migrating existing hand-rolled `Bookmark`/`Folder` entities to this
   base-class split — the column shapes are unchanged, only the inheritance
   is new).

7. Controllers, the API Platform layer (create/patch/delete operations,
   input DTOs, ownership-scoped query extensions), Twig templates, and
   frontend JS are **not** provided by this bundle — unlike `FolioController`
   (which only ever touches this bundle's own folio-EM entities and so can
   be identically bundle-provided for every consumer), a bookmarks
   controller/API layer is saturated with host-specific types
   (`App\Entity\User`, `App\Entity\Bookmark`) at nearly every line. Copy
   zm's `App\Controller\BookmarkController` and
   `App\ApiPlatform\{Dto,State}\{Bookmark,Folder}*` classes as the reference
   implementation and adapt.
