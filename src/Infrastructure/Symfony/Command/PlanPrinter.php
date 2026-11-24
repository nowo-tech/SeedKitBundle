<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Command;

use Nowo\SeedKitBundle\Domain\Plan\ChangeOp;
use Nowo\SeedKitBundle\Domain\Plan\FieldValueView;
use Nowo\SeedKitBundle\Domain\Plan\RowChange;
use Nowo\SeedKitBundle\Domain\Plan\SeedPlan;
use Symfony\Component\Console\Output\OutputInterface;

use function count;
use function json_encode;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Renders a plan for the console: text (counts per provider, the preview rows with their changed fields, the plan
 * hash and warnings) or JSON (the same data, machine-readable). Hidden values print as `(hidden)`.
 */
final class PlanPrinter
{
    /** Symbols of the operations. */
    private const SYMBOL = ['create' => '+', 'update' => '~', 'unchanged' => '=', 'delete' => '-'];

    /**
     * Text output.
     */
    public static function text(OutputInterface $output, SeedPlan $plan, int $maxRows): void
    {
        foreach ($plan->documents as $document) {
            $output->writeln(sprintf(
                '<info>%s</info> v%d: %d to create, %d to update, %d unchanged, %d to delete',
                $document->provider->value,
                $document->version,
                $document->count(ChangeOp::Create),
                $document->count(ChangeOp::Update),
                $document->count(ChangeOp::Unchanged),
                $document->count(ChangeOp::Delete),
            ));
            $shown = 0;
            foreach ($document->preview as $change) {
                if ($change->op === ChangeOp::Unchanged) {
                    continue;
                }
                if ($shown++ >= $maxRows) {
                    $output->writeln('  …');

                    break;
                }
                $output->writeln(sprintf('  %s %s', self::SYMBOL[$change->op->value], $change->key->canonical));
                foreach ($change->fields as $field) {
                    $output->writeln(sprintf('      %s: %s -> %s', $field->path, self::view($field->before), self::view($field->after)));
                }
            }
        }
        foreach ($plan->warnings as $warning) {
            $output->writeln(sprintf('<comment>warning: %s</comment>', $warning));
        }
        $output->writeln(sprintf('Plan hash: %s (%d change(s) in %d batch(es))', $plan->hash->value, $plan->changes(), $plan->writingBatches()));
    }

    /**
     * JSON output.
     */
    public static function json(OutputInterface $output, SeedPlan $plan, int $maxRows): void
    {
        $documents = [];
        foreach ($plan->documents as $document) {
            $rows = [];
            foreach ($document->preview as $change) {
                if ($change->op !== ChangeOp::Unchanged && count($rows) < $maxRows) {
                    $rows[] = self::row($change);
                }
            }
            $documents[] = ['provider' => $document->provider->value, 'version' => $document->version, 'counts' => $document->counts, 'rows' => $rows];
        }
        $output->writeln((string) json_encode([
            'scope'     => $plan->scope->key(),
            'prune'     => $plan->prune,
            'planHash'  => $plan->hash->value,
            'changes'   => $plan->changes(),
            'warnings'  => $plan->warnings,
            'documents' => $documents,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * One preview row.
     *
     * @return array<string, mixed>
     */
    private static function row(RowChange $change): array
    {
        $fields = [];
        foreach ($change->fields as $field) {
            $fields[] = ['path' => $field->path, 'before' => self::jsonView($field->before), 'after' => self::jsonView($field->after)];
        }

        return ['op' => $change->op->value, 'key' => $change->key->components, 'index' => $change->index, 'fields' => $fields];
    }

    /**
     * A view as text.
     */
    private static function view(FieldValueView $view): string
    {
        return match ($view->kind) {
            FieldValueView::VALUE  => CommandSupport::display($view->value),
            FieldValueView::HIDDEN => '(hidden)',
            default                => '(absent)',
        };
    }

    /**
     * A view as JSON data.
     *
     * @return array{kind: string, value?: mixed}
     */
    private static function jsonView(FieldValueView $view): array
    {
        return $view->kind === FieldValueView::VALUE ? ['kind' => $view->kind, 'value' => $view->value] : ['kind' => $view->kind];
    }
}
