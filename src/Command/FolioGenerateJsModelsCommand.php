<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Doctrine\ORM\Mapping\ClassMetadata;
use Survos\FolioBundle\Entity\{Claim,Core,Doc,Folio,Link,LinkType,Page,Row,SchemaProperty,SchemaTable,Str,StrTranslation,Term,TermSet};
use Survos\FolioBundle\Service\FolioService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prototype (survos-sites/sos, 2026-07-29): generate thin JS active-record classes from the SAME
 * Doctrine metadata folio:migrate/FolioSchemaManager already read to build a folio's SQL schema —
 * so a client-side sqlite-wasm consumer can write `Row.where({core_id}).all(db)` instead of
 * hand-written SQL strings, and the JS model can never structurally drift from the real folio
 * schema (same source of truth, not a hand-maintained duplicate).
 *
 * Deliberately NOT wrapping Knex/Bookshelf/Sequelize (none have real sqlite-wasm support -- Knex's
 * own "should support sql.js" issue has sat open since 2018) and NOT wrapping Drizzle/SQLocal
 * either (Worker+promise-based, fighting sqlite-wasm's synchronous main-thread API for no benefit
 * here). Generated classes extend the small hand-written FolioModel.js runtime instead.
 *
 * Scope for now: Row, Core, SchemaTable -- to see how the shape feels before generating all 14
 * FolioSchemaManager entities. Needs a real, already-built folio to read metadata from (metadata
 * itself is schema-only -- it doesn't depend on that folio's actual row DATA).
 */
#[AsCommand('folio:generate-js-models', 'Generate JS active-record classes from folio entity metadata (prototype)')]
final class FolioGenerateJsModelsCommand
{
    /** @var list<class-string> Prototype scope. The full FolioSchemaManager list is the eventual target. */
    private const array ENTITIES = [Row::class, Core::class, SchemaTable::class];

    public function __construct(
        private readonly FolioService $folios,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('A real, already-built folio to read entity metadata from (e.g. curatescape/scioto) -- metadata is schema-only, not data-specific')]
        string $folio,
        #[Option('Directory to write generated .js files into')]
        string $output = __DIR__ . '/../../assets/models',
    ): int {
        $ctx = $this->folios->context($folio);
        $mf = $ctx->em->getMetadataFactory();

        /** @var array<class-string, ClassMetadata> $metas */
        $metas = [];
        foreach (self::ENTITIES as $class) {
            $metas[$class] = $mf->getMetadataFor($class);
        }

        // Synthesize inverse hasMany relations: Core.php has no `rows` Collection property (only
        // Row.php declares the owning `core` ManyToOne), so a hasMany on Core can't come from
        // Core's own metadata -- it has to come from scanning every OTHER entity's ManyToOne
        // mappings whose target is Core. targetClass => list<{via: fkColumn, from: ownerClass}>.
        $inverseHasMany = [];
        foreach ($metas as $ownerClass => $meta) {
            foreach ($meta->getAssociationMappings() as $field => $mapping) {
                if (!$meta->isSingleValuedAssociation($field)) {
                    continue;
                }
                $targetClass = $mapping['targetEntity'];
                $joinColumns = $mapping['joinColumns'] ?? [];
                $fkColumn = $joinColumns[0]['name'] ?? null;
                if ($fkColumn === null) {
                    continue;
                }
                $inverseHasMany[$targetClass][] = ['via' => $fkColumn, 'from' => $ownerClass];
            }
        }

        if (!is_dir($output) && !mkdir($output, 0775, true) && !is_dir($output)) {
            $io->error(sprintf('Unable to create output directory "%s".', $output));
            return Command::FAILURE;
        }

        foreach ($metas as $class => $meta) {
            $js = $this->generateClass($meta, $class, $metas, $inverseHasMany[$class] ?? []);
            $file = $output . '/' . $this->shortName($class) . '.js';
            file_put_contents($file, $js);
            $io->writeln(sprintf('  %s → %s', $class, $file));
        }

        $io->success(sprintf('Generated %d JS model(s) in %s', count($metas), $output));
        return Command::SUCCESS;
    }

    /**
     * @param array<class-string, ClassMetadata> $metas All generated classes -- used to resolve a
     *   ManyToOne's target class to its generated short name/import.
     * @param list<array{via:string,from:class-string}> $inverseHasMany
     */
    private function generateClass(ClassMetadata $meta, string $class, array $metas, array $inverseHasMany): string
    {
        $short = $this->shortName($class);
        $tableName = $meta->getTableName();

        // getFieldNames() only covers scalar fields -- a ManyToOne's FK column (e.g. Row's
        // core_id) is an association mapping, not a field mapping, so it's added separately
        // below as each association is walked (keeps column order: scalars first, then FKs).
        $columns = [];
        foreach ($meta->getFieldNames() as $field) {
            $columns[] = $meta->getColumnName($field);
        }

        // belongsTo: this entity's own single-valued (ManyToOne) associations.
        $belongsTo = [];
        $imports = [];
        foreach ($meta->getAssociationMappings() as $field => $mapping) {
            if (!$meta->isSingleValuedAssociation($field)) {
                continue;
            }
            $targetClass = $mapping['targetEntity'];
            $fkColumn = $mapping['joinColumns'][0]['name'] ?? null;
            if ($fkColumn === null) {
                continue;
            }
            $columns[] = $fkColumn;
            if (!isset($metas[$targetClass])) {
                continue; // target not in this generation run (prototype scope) -- skip, don't emit a broken import
            }
            $targetShort = $this->shortName($targetClass);
            $imports[$targetShort] = true;
            $belongsTo[] = ['method' => $field, 'target' => $targetShort, 'fk' => $fkColumn];
        }

        // hasMany: synthesized inverse relations pointing AT this entity.
        $hasMany = [];
        foreach ($inverseHasMany as $rel) {
            if (!isset($metas[$rel['from']])) {
                continue;
            }
            $fromShort = $this->shortName($rel['from']);
            $imports[$fromShort] = true;
            // Pluralized method name: "rows" for Row, "cores" for Core, etc. -- simple 's' suffix
            // is fine for this entity set (no irregular plurals among Row/Core/Page/Term/...).
            $method = lcfirst($fromShort) . 's';
            $hasMany[] = ['method' => $method, 'target' => $fromShort, 'fk' => $rel['via']];
        }

        $importLines = array_map(
            static fn (string $name): string => "import { {$name} } from './{$name}.js';",
            array_keys($imports),
        );

        $methods = [];
        foreach ($belongsTo as $rel) {
            $methods[] = <<<JS

                    /** @returns {{$rel['target']}|null} belongsTo {$rel['target']} via {$rel['fk']} */
                    {$rel['method']}(db) {
                        return {$rel['target']}.find(db, this.{$rel['fk']});
                    }
                JS;
        }
        foreach ($hasMany as $rel) {
            $methods[] = <<<JS

                    /** @returns {{$rel['target']}[]} hasMany {$rel['target']} via {$rel['fk']} */
                    {$rel['method']}(db) {
                        return {$rel['target']}.where({ {$rel['fk']}: this.id }).all(db);
                    }
                JS;
        }

        $columnsJs = implode(', ', array_map(static fn (string $c): string => "'{$c}'", $columns));
        $importBlock = $importLines === [] ? '' : implode("\n", $importLines) . "\n";
        $methodsBlock = implode("\n", $methods);

        return <<<JS
            // AUTO-GENERATED by folio:generate-js-models from {$class} -- do not edit by hand.
            // Regenerate: bin/console folio:generate-js-models <folio>
            import { FolioModel } from './FolioModel.js';
            {$importBlock}
            export class {$short} extends FolioModel {
                static tableName = '{$tableName}';
                static columns = [{$columnsJs}];
            {$methodsBlock}
            }

            JS;
    }

    /** @param class-string $class */
    private function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
