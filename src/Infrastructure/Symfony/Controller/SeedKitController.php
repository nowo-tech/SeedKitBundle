<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\SeedKitBundle\Application\RunView;
use Nowo\SeedKitBundle\Application\SeedOperations;
use Nowo\SeedKitBundle\Application\SeedRuns;
use Nowo\SeedKitBundle\Domain\Document\DocumentFormat;
use Nowo\SeedKitBundle\Domain\Document\DocumentSet;
use Nowo\SeedKitBundle\Domain\Document\ExportMode;
use Nowo\SeedKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\SeedKitBundle\Domain\Exception\ApplyInterruptedException;
use Nowo\SeedKitBundle\Domain\Exception\DocumentInvalidException;
use Nowo\SeedKitBundle\Domain\Exception\DocumentReadException;
use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Nowo\SeedKitBundle\Domain\Exception\LeaseHeldException;
use Nowo\SeedKitBundle\Domain\Exception\PlanTooLargeException;
use Nowo\SeedKitBundle\Domain\Exception\PruneRefusedException;
use Nowo\SeedKitBundle\Domain\Exception\RunNotAvailableException;
use Nowo\SeedKitBundle\Domain\Exception\ScopeNotFoundException;
use Nowo\SeedKitBundle\Domain\Exception\SeedKitException;
use Nowo\SeedKitBundle\Domain\Exception\StalePlanException;
use Nowo\SeedKitBundle\Domain\Exception\UnknownProviderException;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\ScopeId;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Plan\ChangeOp;
use Nowo\SeedKitBundle\Domain\Plan\FieldValueView;
use Nowo\SeedKitBundle\Domain\Plan\SeedPlan;
use Nowo\SeedKitBundle\Domain\Port\SeedActorResolverInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedCurrentScopeInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedProviderInterface;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Domain\Run\RunRecord;
use Nowo\SeedKitBundle\Domain\Run\RunStatus;
use Nowo\SeedKitBundle\Domain\Run\SeedAction;
use Nowo\SeedKitBundle\Domain\Support\CanonicalJson;
use Nowo\SeedKitBundle\Infrastructure\Reader\DocumentReader;
use Nowo\SeedKitBundle\Infrastructure\Reader\ReaderLimits;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Form\FormProfile;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Form\SeedActionType;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Form\SeedApplyType;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Form\SeedUploadType;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Routing\SeedKitRouteLoader;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Security\SeedKitGuard;
use Nowo\TransactionContract\ConcurrencyConflict;
use Nowo\UiKitBundle\Dialog\RemoteDialog;
use Nowo\UiKitBundle\Dialog\RemoteDialogResponder;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\ConfirmPage;
use Nowo\UiKitBundle\View\Crumb;
use Nowo\UiKitBundle\View\DetailItem;
use Nowo\UiKitBundle\View\DetailSection;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\PageStatus;
use Nowo\UiKitBundle\View\ShowPage;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException as SecurityAccessDenied;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function array_keys;
use function array_map;
use function count;
use function fclose;
use function fopen;
use function fpassthru;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_strlen;
use function mb_substr;
use function rewind;
use function sprintf;
use function str_ends_with;
use function strtolower;
use function substr;
use function trim;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The opt-in UI (contract section 12.5; REQ-UI-002 / REQ-UI-003): per mounted scope kind, the Import / export page
 * (providers the viewer may use, downloads, upload), the upload dialog (UiKit remote form dialog, loaded when it opens;
 * the same URL is the no-JavaScript page), the run page (diff preview, apply, discard, progress), the runs list.
 *
 * Every action starts with the guard: no user ⇒ the firewall's entry point (302 to the login page / 401) unless
 * `allow_unauthenticated`; the `system` actor, an unknown or invisible scope ⇒ 404 / 403 through
 * {@see SeedOperations::resolveScope()} (unknown or not visible = 404, so ids cannot be probed); no area access ⇒
 * 403; a provider the viewer may not use is not listed and its URLs answer 404. Every POST is a FormKit form with
 * the `nowo_seed_kit` CSRF token. Errors carry static translated messages, never a submitted value. Stateless.
 */
final readonly class SeedKitController
{
    /**
     * The UiKit action kinds of the pages. No action carries an explicit icon: the host maps each kind once in
     * `nowo_ui_kit.action_icons` (else UiKit's default of the kind); docs/TEMPLATES.md lists them.
     */
    public const ACTION_KINDS = ['view', 'download', 'import', 'run', 'cancel', 'prev'];

    /** Dialog id of the upload dialog. */
    public const UPLOAD_DIALOG = 'nowo-seed-kit-upload';

    /** Rows of a preview table per document. */
    private const PREVIEW_CHARS = 200;

    /**
     * Builds the controller.
     */
    public function __construct(
        private SeedOperations $operations,
        private SeedRuns $runs,
        private SeedKitGuard $guard,
        private SeedActorResolverInterface $actors,
        private DocumentReader $reader,
        private ReaderLimits $limits,
        private Environment $twig,
        private FormFactoryInterface $forms,
        private UrlGeneratorInterface $urls,
        private RemoteDialogResponder $dialog,
        private UiOptions $options,
        private ?SeedCurrentScopeInterface $current = null,
        private ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * `GET <mount>/`: the providers the viewer may use, with their downloads, and the upload / history actions.
     */
    public function index(Request $request): Response
    {
        return $this->handle($request, function (SeedScope $scope, ActorRef $actor, string $kind) use ($request): Response {
            $rows    = [];
            $actions = [];
            $apply   = false;
            foreach ($this->operations->providers($scope) as $provider) {
                $name   = $provider->name();
                $export = $this->operations->isGranted(SeedAction::Export, $name, $scope, Channel::Http, $actor);
                $write  = $this->operations->isGranted(SeedAction::Apply, $name, $scope, Channel::Http, $actor);
                if (!$export && !$write) {
                    continue;
                }
                $apply      = $apply || $write;
                $schema     = $provider->schema();
                $key        = $name->value;
                $rows[$key] = [
                    'name'       => $key,
                    'title'      => $schema->title($key),
                    'version'    => $provider->version(),
                    'key'        => implode(', ', $schema->keyProperties()),
                    'depends'    => implode(', ', array_map(static fn (ProviderName $dependency): string => $dependency->value, $provider->dependsOn())),
                    'personal'   => $schema->personalDataProperties() !== [],
                    'secret'     => $schema->secretProperties() !== [],
                    'can_export' => $export,
                    'can_apply'  => $write,
                ];
                $actions[$key] = $export ? $this->downloadActions($provider, $kind, $request) : [];
            }
            $header = [new Action('view', $this->label('seed_kit.ui.runs.title'), $this->url($kind, 'runs', $request), attributes: ['data-testid' => 'seed-kit-history'])];
            if ($apply) {
                $header[] = $this->uploadAction($kind, $request);
            }

            return $this->render('@NowoSeedKitBundle/index.html.twig', [
                'page' => new ListPage(
                    $this->header('seed_kit.ui.index.title', 'seed_kit.ui.index.intro', $header, $this->crumbs($kind, $request)),
                    [
                        new Column('name', $this->label('seed_kit.ui.index.provider'), required: true),
                        new Column('title', $this->label('seed_kit.ui.index.description'), priority: 2),
                        new Column('key', $this->label('seed_kit.ui.index.key'), priority: 3),
                        new Column('depends', $this->label('seed_kit.ui.index.depends'), priority: 3),
                        new Column('flags', $this->label('seed_kit.ui.index.data')),
                    ],
                    $rows,
                    rowActions: static fn (mixed $row, int|string $key): array => $actions[(string) $key] ?? [],
                    empty: new EmptyState($this->label('seed_kit.ui.index.empty_title'), $this->label('seed_kit.ui.index.empty')),
                    caption: $this->label('seed_kit.ui.index.caption'),
                    bundle: FormProfile::DOMAIN,
                    layout: $this->options->layout,
                    attributes: ['data-testid' => 'seed-kit-index', 'data-scope-kind' => $kind],
                ),
                'upload_dialog' => $apply ? self::UPLOAD_DIALOG : null,
            ]);
        });
    }

    /**
     * `GET|POST <mount>/providers/{provider}/export?mode=empty|current&format=yaml|json|jsonl`: a download. A current
     * export of a provider with personal-data or secret fields is a POST from the confirmation (GET shows it).
     */
    public function export(Request $request, string $provider): Response
    {
        return $this->handle($request, function (SeedScope $scope, ActorRef $actor, string $kind) use ($request, $provider): Response {
            $item   = $this->provider($provider, $scope, $actor, [SeedAction::Export]);
            $mode   = ExportMode::tryFrom((string) $request->query->get('mode', 'empty')) ?? throw new NotFoundHttpException();
            $format = DocumentFormat::tryFrom((string) $request->query->get('format', 'yaml')) ?? throw new NotFoundHttpException();
            if ($mode === ExportMode::Current && $item->schema()->isSensitive()) {
                $form = $this->forms->create(SeedActionType::class);
                $form->handleRequest($request);
                if (!$form->isSubmitted() || !$form->isValid()) {
                    return $this->exportConfirmation($item, $kind, $request, $format, $form, $request->isMethod('POST') ? Response::HTTP_FORBIDDEN : Response::HTTP_OK);
                }
            } elseif ($request->isMethod('POST')) {
                throw new NotFoundHttpException();
            }
            // On a refusal the temp stream is released with the request (nothing written to it yet).
            $stream = fopen('php://temp/maxmemory:2097152', 'w+') ?: throw new NotFoundHttpException();
            $this->operations->export($item->name(), $scope, $mode, $format, $actor, Channel::Http, $stream);
            rewind($stream);
            $filename = sprintf('%s.%s.%s.seed.%s', $item->name()->value, $scope->kind->value, (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Ymd-His'), $format->extension());

            return new StreamedResponse(static function () use ($stream): void {
                try {
                    fpassthru($stream);
                } finally {
                    fclose($stream);
                }
            }, Response::HTTP_OK, $this->downloadHeaders($format->mediaType(), $filename));
        });
    }

    /**
     * `GET <mount>/providers/{provider}/schema.json`: the document schema (for editors and AI tools).
     */
    public function schema(Request $request, string $provider): Response
    {
        return $this->handle($request, function (SeedScope $scope, ActorRef $actor) use ($provider): Response {
            $item = $this->provider($provider, $scope, $actor, [SeedAction::Export, SeedAction::Apply]);
            $json = json_encode($this->operations->schema($item->name()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            return new Response($json . "\n", Response::HTTP_OK, $this->downloadHeaders('application/schema+json', sprintf('%s.v%d.schema.json', $item->name()->value, $item->version())));
        });
    }

    /**
     * `GET|POST <mount>/upload`: the upload form (page, or the fragment of the upload dialog); a valid file is
     * dry-run and stored as a planned run, then the run page opens.
     */
    public function upload(Request $request): Response
    {
        return $this->handle($request, function (SeedScope $scope, ActorRef $actor, string $kind) use ($request): Response {
            $apply = false;
            $prune = false;
            foreach ($this->operations->providers($scope) as $provider) {
                $apply = $apply || $this->operations->isGranted(SeedAction::Apply, $provider->name(), $scope, Channel::Http, $actor);
                $prune = $prune || $this->operations->isGranted(SeedAction::Prune, $provider->name(), $scope, Channel::Http, $actor);
            }
            if (!$apply) {
                throw new AccessDeniedHttpException();
            }
            $form = $this->forms->create(SeedUploadType::class, null, ['max_bytes' => $this->options->maxUploadBytes, 'allow_prune' => $prune]);
            $form->handleRequest($request);
            $errors = [];
            if ($form->isSubmitted() && $form->isValid()) {
                $data = $form->getData();
                $file = is_array($data) ? ($data['file'] ?? null) : null;
                try {
                    // NotNull + File: a valid form always carries an uploaded file.
                    $run = $this->runs->upload($this->read($file instanceof UploadedFile ? $file : throw new AccessDeniedHttpException()), $scope, $actor, $prune && ($data['prune'] ?? false) === true);

                    return $this->dialog->redirect($this->url($kind, 'run', $request, ['run' => $run->id->value]), Response::HTTP_SEE_OTHER, $request);
                } catch (DocumentInvalidException $e) {
                    $errors = $this->rowErrors($e);
                    $form->addError(new FormError($this->trans($e->messageKey())));
                } catch (SeedKitException $e) {
                    // Unreadable file, plain secret, scope kind, unknown provider, a provider the viewer may not apply,
                    // a prune guard: a static message on the form, never a value.
                    $form->addError(new FormError($this->trans($e->messageKey())));
                }
            }
            $status = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
            $page   = new FormPage(
                $this->header('seed_kit.ui.upload.title', 'seed_kit.ui.upload.intro', [], $this->crumbs($kind, $request, [new Crumb($this->label('seed_kit.ui.upload.title'))])),
                $form->createView(),
                $this->label('seed_kit.ui.upload.submit'),
                $this->url($kind, 'index', $request),
                bundle: FormProfile::DOMAIN,
                layout: $this->options->layout,
                attributes: ['data-testid' => 'seed-kit-upload'],
                formAttributes: ['data-testid' => 'seed-kit-upload-form'],
            );
            $template = $this->dialog->template('@NowoSeedKitBundle/upload.html.twig', '@NowoSeedKitBundle/upload_fragment.html.twig', $request);

            return RemoteDialog::vary($this->render($template, ['page' => $page, 'errors' => $errors, 'max_bytes' => $this->options->maxUploadBytes], $status), $this->dialog->headers());
        });
    }

    /**
     * `GET <mount>/runs`: the runs of the scope (all of them with `SEED_APPLY` on every provider, else the viewer's
     * own), newest first, keyset pager (`?before=<run id>`).
     */
    public function runs(Request $request): Response
    {
        return $this->handle($request, function (SeedScope $scope, ActorRef $actor, string $kind) use ($request): Response {
            $before = $request->query->get('before');
            $runs   = $this->runs->history($scope, $actor, $this->options->runsPerPage + 1, is_string($before) && $before !== '' ? new RunId($before) : null);
            $more   = isset($runs[$this->options->runsPerPage]);
            $rows   = [];
            foreach ($runs as $i => $run) {
                if ($i >= $this->options->runsPerPage) {
                    break;
                }
                $rows[$run->id->value] = $this->runRow($run, $kind, $request);
            }
            $last = $rows === [] ? null : array_keys($rows)[count($rows) - 1];

            return $this->render('@NowoSeedKitBundle/runs.html.twig', [
                'page' => new ListPage(
                    $this->header('seed_kit.ui.runs.title', 'seed_kit.ui.runs.intro', [], $this->crumbs($kind, $request, [new Crumb($this->label('seed_kit.ui.runs.title'))])),
                    [
                        new Column('created', $this->label('seed_kit.ui.runs.created'), required: true),
                        new Column('status', $this->label('seed_kit.ui.runs.status'), required: true),
                        new Column('providers', $this->label('seed_kit.ui.runs.providers'), priority: 2),
                        new Column('changes', $this->label('seed_kit.ui.runs.changes'), align: 'end', priority: 2),
                        new Column('actor', $this->label('seed_kit.ui.runs.actor'), priority: 3),
                        new Column('channel', $this->label('seed_kit.ui.runs.channel'), priority: 3),
                    ],
                    $rows,
                    rowActions: static fn (mixed $row, int|string $key): array => [new Action('view', null, is_array($row) && is_string($row['href'] ?? null) ? $row['href'] : null)],
                    empty: new EmptyState($this->label('seed_kit.ui.runs.empty_title'), $this->label('seed_kit.ui.runs.empty')),
                    caption: $this->label('seed_kit.ui.runs.caption'),
                    bundle: FormProfile::DOMAIN,
                    layout: $this->options->layout,
                    attributes: ['data-testid' => 'seed-kit-runs'],
                ),
                'next_href' => $more && $last !== null ? $this->url($kind, 'runs', $request, [], ['before' => $last]) : null,
            ]);
        });
    }

    /**
     * `GET <mount>/runs/{run}`: the run page.
     */
    public function run(Request $request, string $run): Response
    {
        return $this->handle($request, function (SeedScope $scope, ActorRef $actor, string $kind) use ($request, $run): Response {
            return $this->runPage($this->runs->view(new RunId($run), $scope, $actor), $kind, $request);
        });
    }

    /**
     * `POST <mount>/runs/{run}/apply`: applies (or queues) the run; then the run page.
     */
    public function apply(Request $request, string $run): Response
    {
        return $this->handle($request, function (SeedScope $scope, ActorRef $actor, string $kind) use ($request, $run): Response {
            $id     = new RunId($run);
            $record = $this->runs->progress($id, $scope, $actor);
            $form   = $this->forms->create(SeedApplyType::class, null, ['mass_prune' => $record->isMassPrune()]);
            $form->handleRequest($request);
            $data = $form->getData();
            if (!$form->isSubmitted() || !$form->isValid() || !is_array($data) || !is_string($data['plan_hash'] ?? null)) {
                return $this->runPage($this->runs->view($id, $scope, $actor), $kind, $request, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $typed     = $data['confirm_deletes'] ?? null;
            $confirmed = $record->isMassPrune() && is_string($typed) && trim($typed) === (string) $record->planned(ChangeOp::Delete->value);
            if ($record->isMassPrune() && !$confirmed) {
                $form->get('confirm_deletes')->addError(new FormError($this->trans('seed_kit.ui.run.confirm_deletes_wrong')));

                return $this->runPage($this->runs->view($id, $scope, $actor), $kind, $request, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->runs->apply($id, new PlanHash($data['plan_hash']), $scope, $actor, $confirmed);

            return new RedirectResponse($this->url($kind, 'run', $request, ['run' => $id->value]), Response::HTTP_SEE_OTHER);
        });
    }

    /**
     * `POST <mount>/runs/{run}/discard`: discards a planned run (its document is deleted); then the runs list.
     */
    public function discard(Request $request, string $run): Response
    {
        return $this->handle($request, function (SeedScope $scope, ActorRef $actor, string $kind) use ($request, $run): Response {
            $form = $this->forms->create(SeedActionType::class);
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid()) {
                throw new AccessDeniedHttpException();
            }
            $this->runs->discard(new RunId($run), $scope, $actor);

            return new RedirectResponse($this->url($kind, 'runs', $request), Response::HTTP_SEE_OTHER);
        });
    }

    /**
     * `GET <mount>/runs/{run}/progress`: internal UI JSON `{status, batchesDone, batchesTotal, counts}` (not a public
     * API; never cached).
     */
    public function progress(Request $request, string $run): Response
    {
        return $this->handle($request, function (SeedScope $scope, ActorRef $actor) use ($run): Response {
            $record = $this->runs->progress(new RunId($run), $scope, $actor);
            $counts = [];
            foreach ([ChangeOp::Create, ChangeOp::Update, ChangeOp::Delete] as $op) {
                $counts[$op->value] = $record->counts[$op->value] ?? 0;
            }

            return new JsonResponse([
                'status'       => $record->status->value,
                'batchesDone'  => $record->batchesDone,
                'batchesTotal' => $record->manifest?->writingBatches() ?? $record->batchesDone,
                'counts'       => $counts,
                'errorCode'    => $record->errorCode,
            ], Response::HTTP_OK, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
        });
    }

    /**
     * The run page view model and template.
     *
     * @param FormInterface<mixed>|null $applyForm a submitted apply form to show again (errors)
     */
    private function runPage(RunView $view, string $kind, Request $request, ?FormInterface $applyForm = null, int $status = Response::HTTP_OK): Response
    {
        $run     = $view->run;
        $href    = $this->url($kind, 'run', $request, ['run' => $run->id->value]);
        $active  = !$view->stalled && in_array($run->status, [RunStatus::Queued, RunStatus::Running], true);
        $open    = $view->stalled || in_array($run->status, [RunStatus::Planned, RunStatus::PartiallyApplied], true);
        $canAct  = $view->owner && $view->plan instanceof SeedPlan && !$view->stale && $open;
        $actions = [];
        $apply   = null;
        if ($canAct) {
            $applyForm ??= $this->forms->create(SeedApplyType::class, ['plan_hash' => $run->planHash->value], ['mass_prune' => $run->isMassPrune(), 'action' => $this->url($kind, 'run_apply', $request, ['run' => $run->id->value])]);
            if (!$run->isMassPrune()) {
                $actions[] = new Action('run', $this->label('seed_kit.ui.run.apply'), $this->url($kind, 'run_apply', $request, ['run' => $run->id->value]), method: 'POST', confirm: new Confirm(
                    $this->label('seed_kit.ui.run.apply_confirm_title'),
                    $this->label('seed_kit.ui.run.apply_confirm', ['%changes%' => $run->changes()]),
                    $this->label('seed_kit.ui.run.apply'),
                    $run->prune && $run->planned(ChangeOp::Delete->value) > 0 ? 'danger' : 'primary',
                ), variant: 'primary', attributes: ['data-testid' => 'seed-kit-apply'], form: $applyForm->createView());
            } else {
                $apply = $applyForm->createView();
            }
        }
        // Its actor discards an open run; an admin of the kind (who can see it) discards a stalled one (ZCE-M1).
        if (($view->owner && $open) || $view->stalled) {
            $actions[] = new Action('cancel', $this->label('seed_kit.ui.run.discard'), $this->url($kind, 'run_discard', $request, ['run' => $run->id->value]), method: 'POST', confirm: new Confirm(
                $this->label('seed_kit.ui.run.discard_confirm_title'),
                $this->label('seed_kit.ui.run.discard_confirm'),
                $this->label('seed_kit.ui.run.discard'),
            ), variant: 'danger', attributes: ['data-testid' => 'seed-kit-discard'], form: $this->forms->create(SeedActionType::class)->createView());
        }
        $header = $this->header(
            $this->label('seed_kit.ui.run.title', ['%id%' => substr($run->id->value, 0, 8)]),
            $this->label('seed_kit.ui.run.intro'),
            $actions,
            $this->crumbs($kind, $request, [new Crumb($this->label('seed_kit.ui.runs.title'), $this->url($kind, 'runs', $request)), new Crumb(substr($run->id->value, 0, 8))]),
        )->withStatus(new PageStatus($this->label('seed_kit.ui.status.' . $run->status->value), $this->tone($run->status), attributes: ['data-testid' => 'seed-kit-run-status']));
        $total   = $run->manifest?->writingBatches() ?? 0;
        $summary = [
            new DetailItem($this->label('seed_kit.ui.run.providers'), implode(', ', array_keys($run->providers))),
            new DetailItem($this->label('seed_kit.ui.run.scope'), $run->scope->key()),
            new DetailItem($this->label('seed_kit.ui.run.planned'), $this->trans('seed_kit.ui.run.counts', ['%create%' => $run->planned('create'), '%update%' => $run->planned('update'), '%delete%' => $run->planned('delete'), '%unchanged%' => $run->planned('unchanged')]), valueAttributes: ['data-testid' => 'seed-kit-run-planned']),
            new DetailItem($this->label('seed_kit.ui.run.written'), $this->trans('seed_kit.ui.run.written_counts', ['%create%' => $run->counts['create'] ?? 0, '%update%' => $run->counts['update'] ?? 0, '%delete%' => $run->counts['delete'] ?? 0])),
            new DetailItem($this->label('seed_kit.ui.run.batches'), sprintf('%d / %d', $run->batchesDone, $total)),
            new DetailItem($this->label('seed_kit.ui.run.prune'), $run->prune),
            new DetailItem($this->label('seed_kit.ui.run.plan_hash'), $run->planHash->value, valueAttributes: ['data-testid' => 'seed-kit-plan-hash']),
            new DetailItem($this->label('seed_kit.ui.run.created'), $run->createdAt->format('Y-m-d H:i:s') . ' UTC'),
        ];
        if ($run->errorCode !== null) {
            $summary[] = new DetailItem($this->label('seed_kit.ui.run.error'), $run->errorCode . ' — ' . $this->trans('seed_kit.error.' . $run->errorCode), badge: 'danger');
        }

        return $this->render('@NowoSeedKitBundle/run.html.twig', [
            'page'          => new ShowPage($header, [new DetailSection($this->label('seed_kit.ui.run.summary'), $summary, 'seed-kit-run-summary')], bundle: FormProfile::DOMAIN, layout: $this->options->layout, attributes: ['data-testid' => 'seed-kit-run', 'data-status' => $run->status->value]),
            'run'           => $run,
            'documents'     => $view->plan instanceof SeedPlan ? $this->preview($view->plan) : [],
            'warnings'      => $view->plan instanceof SeedPlan ? $view->plan->warnings : [],
            'stale'         => $view->stale,
            'error'         => $view->error,
            'owner'         => $view->owner,
            'apply_form'    => $apply,
            'deletes'       => $run->planned(ChangeOp::Delete->value),
            'active'        => $active,
            'stalled'       => $view->stalled,
            'poll_ms'       => $this->options->pollMilliseconds,
            'self_href'     => $href,
            'upload_href'   => $this->url($kind, 'upload', $request),
            'progress_href' => $this->url($kind, 'run_progress', $request, ['run' => $run->id->value]),
        ], $status);
    }

    /**
     * The preview rows of a plan, ready for the template (values as short canonical JSON; hidden / absent marked).
     *
     * @return list<array{provider: string, counts: array<string, int>, rows: list<array{op: string, key: string, fields: list<array{path: string, before: string|null, after: string|null, before_kind: string, after_kind: string}>}>}>
     */
    private function preview(SeedPlan $plan): array
    {
        $documents = [];
        foreach ($plan->documents as $document) {
            $rows = [];
            foreach ($document->preview as $change) {
                $fields = [];
                foreach ($change->fields as $field) {
                    $fields[] = ['path' => $field->path, 'before' => $this->display($field->before), 'after' => $this->display($field->after), 'before_kind' => $field->before->kind, 'after_kind' => $field->after->kind];
                }
                $rows[] = ['op' => $change->op->value, 'key' => $this->keyText((string) $change->key), 'fields' => $fields];
            }
            $counts = [];
            foreach (ChangeOp::cases() as $op) {
                $counts[$op->value] = $document->count($op);
            }
            $documents[] = ['provider' => $document->provider->value, 'counts' => $counts, 'rows' => $rows];
        }

        return $documents;
    }

    /**
     * A value of the diff as short text (null for hidden / absent: the template prints a label).
     */
    private function display(FieldValueView $value): ?string
    {
        if ($value->kind !== FieldValueView::VALUE) {
            return null;
        }
        $text = is_string($value->value) ? $value->value : CanonicalJson::encode($value->value);

        return mb_strlen($text) > self::PREVIEW_CHARS ? mb_substr($text, 0, self::PREVIEW_CHARS) . '…' : $text;
    }

    /**
     * A natural key for display: the single component as is, else the canonical JSON array.
     */
    private function keyText(string $canonical): string
    {
        $value = json_decode($canonical, true);

        return is_array($value) && count($value) === 1 && (is_string($value[0] ?? null) || is_int($value[0] ?? null)) ? (string) $value[0] : $canonical;
    }

    /**
     * A row of the runs list.
     *
     * @return array<string, mixed>
     */
    private function runRow(RunRecord $run, string $kind, Request $request): array
    {
        return [
            'created'      => $run->createdAt->format('Y-m-d H:i'),
            'status'       => $run->status->value,
            'status_label' => $this->trans('seed_kit.ui.status.' . $run->status->value),
            'tone'         => $this->tone($run->status),
            'providers'    => implode(', ', array_keys($run->providers)),
            'changes'      => $run->changes(),
            'actor'        => $run->actor?->value,
            'channel'      => $run->channel->value,
            'href'         => $this->url($kind, 'run', $request, ['run' => $run->id->value]),
        ];
    }

    /**
     * The confirmation page of a personal-data / secret export (GET), or again after a refused POST.
     *
     * @param FormInterface<mixed> $form
     */
    private function exportConfirmation(SeedProviderInterface $provider, string $kind, Request $request, DocumentFormat $format, FormInterface $form, int $status): Response
    {
        $url = $this->url($kind, 'export', $request, ['provider' => $provider->name()->value], ['mode' => ExportMode::Current->value, 'format' => $format->value]);

        return $this->render('@NowoUiKitBundle/page/confirm.html.twig', ['page' => new ConfirmPage(
            $this->header('seed_kit.ui.export.confirm_title', null, [], $this->crumbs($kind, $request)),
            new Confirm($this->label('seed_kit.ui.export.confirm_title'), $this->label('seed_kit.ui.export.personal_data', ['%provider%' => $provider->name()->value]), $this->label('seed_kit.ui.export.download'), 'primary'),
            new Action('download', $this->label('seed_kit.ui.export.download'), $url, method: 'POST', attributes: ['data-testid' => 'seed-kit-export-confirm'], form: $form->createView()),
            $this->url($kind, 'index', $request),
            FormProfile::DOMAIN,
            $this->options->layout,
            ['data-testid' => 'seed-kit-export-confirmation'],
        )], $status);
    }

    /**
     * Row actions of a provider the viewer may export: empty template, current export (confirmed first when it holds
     * personal data or secrets), schema.
     *
     * @return list<Action>
     */
    private function downloadActions(SeedProviderInterface $provider, string $kind, Request $request): array
    {
        $name    = $provider->name()->value;
        $params  = ['provider' => $name];
        $current = $this->url($kind, 'export', $request, $params, ['mode' => ExportMode::Current->value]);
        $actions = [
            new Action('download', $this->label('seed_kit.ui.index.download_empty'), $this->url($kind, 'export', $request, $params, ['mode' => ExportMode::Empty->value]), attributes: ['data-testid' => 'seed-kit-empty-' . $name, 'download' => true]),
        ];
        if ($provider->schema()->isSensitive()) {
            $actions[] = new Action('download', $this->label('seed_kit.ui.index.download_current'), $current, method: 'POST', confirm: new Confirm(
                $this->label('seed_kit.ui.export.confirm_title'),
                $this->label('seed_kit.ui.export.personal_data', ['%provider%' => $name]),
                $this->label('seed_kit.ui.export.download'),
                'primary',
            ), attributes: ['data-testid' => 'seed-kit-current-' . $name], form: $this->forms->create(SeedActionType::class)->createView(), fallbackHref: $current);
        } else {
            $actions[] = new Action('download', $this->label('seed_kit.ui.index.download_current'), $current, attributes: ['data-testid' => 'seed-kit-current-' . $name, 'download' => true]);
        }
        $actions[] = new Action('download', $this->label('seed_kit.ui.index.download_schema'), $this->url($kind, 'schema', $request, $params), attributes: ['data-testid' => 'seed-kit-schema-' . $name, 'download' => true]);

        return $actions;
    }

    /**
     * The header action that opens the upload dialog (a link to the upload page without JavaScript).
     */
    private function uploadAction(string $kind, Request $request): Action
    {
        $url = $this->url($kind, 'upload', $request);

        return new Action('import', $this->label('seed_kit.ui.upload.open'), $url, variant: 'primary', attributes: [
            'data-testid'               => 'seed-kit-upload-open',
            'data-nowo-ui-dialog-open'  => self::UPLOAD_DIALOG,
            'data-nowo-ui-dialog-src'   => $url,
            'data-nowo-ui-dialog-form'  => true,
            'data-nowo-ui-dialog-title' => $this->trans('seed_kit.ui.upload.title'),
        ]);
    }

    /**
     * A provider of the scope the viewer may use for one of the actions; anything else is "not found".
     *
     * @param list<SeedAction> $actions
     *
     * @throws NotFoundHttpException
     */
    private function provider(string $name, SeedScope $scope, ActorRef $actor, array $actions): SeedProviderInterface
    {
        $wanted = new ProviderName($name);
        foreach ($this->operations->providers($scope) as $provider) {
            if ($provider->name()->value !== $wanted->value) {
                continue;
            }
            foreach ($actions as $action) {
                if ($this->operations->isGranted($action, $wanted, $scope, Channel::Http, $actor)) {
                    return $provider;
                }
            }
        }

        throw new NotFoundHttpException();
    }

    /**
     * Reads an uploaded file through the hardened reader with the web caps (`ui.max_upload_bytes`); the extension only
     * picks the parser, the content decides.
     *
     * @throws SeedKitException
     */
    private function read(UploadedFile $file): DocumentSet
    {
        $name   = strtolower($file->getClientOriginalName());
        $format = match (true) {
            str_ends_with($name, '.jsonl')                              => DocumentFormat::JsonLines,
            str_ends_with($name, '.json')                               => DocumentFormat::Json,
            str_ends_with($name, '.yaml'), str_ends_with($name, '.yml') => DocumentFormat::Yaml,
            default                                                     => null,
        };
        $stream = fopen($file->getPathname(), 'r') ?: throw new DocumentReadException('unreadable');
        try {
            return $this->reader->read($stream, $format, $this->limits);
        } finally {
            fclose($stream);
        }
    }

    /**
     * The row errors of an invalid document for the upload page (shown to the uploader only, never logged).
     *
     * @return list<array{index: int, provider: string, key: string|null, path: string, rule: string, message: string}>
     */
    private function rowErrors(DocumentInvalidException $e): array
    {
        $out = [];
        foreach ($e->errors as $error) {
            $out[] = [
                'index'    => $error->index,
                'provider' => $error->provider ?? '',
                'key'      => $error->key !== null ? $this->keyText((string) $error->key) : null,
                'path'     => $error->path,
                'rule'     => $error->rule,
                'message'  => $this->trans($error->messageKey, $error->messageParams),
            ];
        }

        return $out;
    }

    /**
     * Runs a page behind the guard and turns SeedKit failures into answers.
     *
     * @param callable(SeedScope, ActorRef, string): Response $page
     */
    private function handle(Request $request, callable $page): Response
    {
        try {
            [$scope, $actor, $kind] = $this->context($request);

            return $page($scope, $actor, $kind);
        } catch (ScopeNotFoundException|RunNotAvailableException|UnknownProviderException|InvalidValueException $e) {
            throw new NotFoundHttpException('Not found.', $e);
        } catch (AccessDeniedException $e) {
            throw new AccessDeniedHttpException('Forbidden.', $e);
        } catch (LeaseHeldException $e) {
            return $this->message($request, 'seed_kit.ui.message.conflict', $e, Response::HTTP_CONFLICT, ['Retry-After' => (string) $e->retryAfterSeconds, 'X-Conflict-Code' => $e->errorCode()]);
        } catch (ApplyInterruptedException $e) {
            $headers = $e->kind === ApplyInterruptedException::CONFLICT ? ['Retry-After' => (string) ($e->retryAfterSeconds ?? 1), 'X-Conflict-Code' => $e->errorCode()] : [];

            return $this->message($request, 'seed_kit.ui.message.interrupted', $e, $e->kind === ApplyInterruptedException::PROVIDER_FAILED ? Response::HTTP_INTERNAL_SERVER_ERROR : Response::HTTP_CONFLICT, $headers, ['%done%' => $e->batchesCommitted, '%total%' => $e->batchesTotal]);
        } catch (StalePlanException $e) {
            return $this->message($request, 'seed_kit.ui.message.stale', $e, Response::HTTP_CONFLICT);
        } catch (PlanTooLargeException $e) {
            return $this->message($request, 'seed_kit.ui.message.too_large', $e, Response::HTTP_REQUEST_ENTITY_TOO_LARGE, [], ['%changes%' => $e->changes, '%limit%' => $e->limit]);
        } catch (PruneRefusedException $e) {
            return $this->message($request, 'seed_kit.ui.message.refused', $e, Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ConcurrencyConflict $e) {
            return $this->message($request, 'seed_kit.ui.message.conflict', null, Response::HTTP_CONFLICT, ['Retry-After' => (string) $e->retryAfterSeconds(), 'X-Conflict-Code' => 'CONFLICT_001']);
        }
    }

    /**
     * The scope, actor and kind of the request: user (else the firewall's entry point), scope id (route or the host's
     * current scope), the host resolver (unknown / invisible ⇒ 404; `system` ⇒ refused), the area (⇒ 403).
     *
     * @throws SeedKitException
     *
     * @return array{0: SeedScope, 1: ActorRef, 2: string}
     */
    private function context(Request $request): array
    {
        $kind  = $request->attributes->get(SeedKitRouteLoader::KIND);
        $kind  = is_string($kind) ? $kind : throw new NotFoundHttpException();
        $actor = $this->actors->current();
        if (!$actor instanceof ActorRef) {
            if (!$this->options->allowUnauthenticated) {
                // The firewall turns this into its entry point: the login page (302) or 401.
                throw new SecurityAccessDenied();
            }
            $actor = new ActorRef('anonymous');
        }
        $scopeKind = new ScopeKind($kind);
        $id        = $request->attributes->get('scopeId');
        $scopeId   = is_string($id) ? new ScopeId($id) : $this->current?->currentId($scopeKind, $actor);
        $scope     = $this->operations->resolveScope(new SeedScope($scopeKind, $scopeId), $actor, Channel::Http);
        if (!$this->guard->canUseArea($scope)) {
            throw new AccessDeniedHttpException();
        }

        return [$scope, $actor, $kind];
    }

    /**
     * A page with a static message (conflicts, refusals) and the way back.
     *
     * @param array<string, string> $headers
     * @param array<string, int|string> $params
     */
    private function message(Request $request, string $key, ?SeedKitException $e, int $status, array $headers = [], array $params = []): Response
    {
        $kind  = $request->attributes->get(SeedKitRouteLoader::KIND);
        $kind  = is_string($kind) ? $kind : '';
        $items = [new DetailItem($this->label('seed_kit.ui.message.what'), $this->trans($key, $params))];
        if ($e instanceof SeedKitException) {
            $items[] = new DetailItem($this->label('seed_kit.ui.message.code'), $e->errorCode() . ' — ' . $this->trans($e->messageKey()));
        }
        $back = $this->url($kind, 'index', $request);

        return $this->render('@NowoSeedKitBundle/message.html.twig', [
            'page' => new ShowPage(
                $this->header('seed_kit.ui.message.title', null, [new Action('prev', $this->label('seed_kit.ui.message.back'), $back)], []),
                [new DetailSection(null, $items, 'seed-kit-message')],
                bundle: FormProfile::DOMAIN,
                layout: $this->options->layout,
                attributes: ['data-testid' => 'seed-kit-message', 'data-code' => $e?->errorCode() ?? 'CONFLICT_001'],
            ),
        ], $status, $headers);
    }

    /**
     * Renders a template with the bundle's response headers (never cached).
     *
     * @param array<string, mixed> $context
     * @param array<string, string> $headers
     */
    private function render(string $template, array $context, int $status = Response::HTTP_OK, array $headers = []): Response
    {
        return new Response($this->twig->render($template, $context), $status, $headers + ['Cache-Control' => 'no-store, private']);
    }

    /**
     * A page header.
     *
     * @param list<Action> $actions
     * @param list<Crumb> $crumbs
     */
    private function header(Label|string $title, Label|string|null $intro, array $actions, array $crumbs): PageHeader
    {
        return new PageHeader(
            is_string($title) ? $this->label($title) : $title,
            is_string($intro) ? $this->label($intro) : $intro,
            $actions,
            $this->options->breadcrumbs ? $crumbs : [],
            $this->options->breadcrumbCollection,
        );
    }

    /**
     * The crumbs of a page under the Import / export page.
     *
     * @param list<Crumb> $more
     *
     * @return list<Crumb>
     */
    private function crumbs(string $kind, Request $request, array $more = []): array
    {
        return [new Crumb($this->label('seed_kit.ui.index.title'), $more === [] ? null : $this->url($kind, 'index', $request)), ...$more];
    }

    /**
     * The URL of a page of the same mount (the scope id of the current route is kept).
     *
     * @param array<string, string> $params
     * @param array<string, string> $query
     */
    private function url(string $kind, string $page, Request $request, array $params = [], array $query = []): string
    {
        $id = $request->attributes->get('scopeId');
        if (is_string($id)) {
            $params['scopeId'] = $id;
        }

        return $this->urls->generate('nowo_seed_kit_' . $kind . '_' . $page, $params + $query);
    }

    /**
     * Headers of a download.
     *
     * @return array<string, string>
     */
    private function downloadHeaders(string $mediaType, string $filename): array
    {
        return [
            'Content-Type'           => $mediaType . '; charset=utf-8',
            'Content-Disposition'    => sprintf('attachment; filename="%s"', $filename),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'no-store, private',
        ];
    }

    /**
     * The UiKit tone of a run status.
     */
    private function tone(RunStatus $status): string
    {
        return match ($status) {
            RunStatus::Applied                                        => 'success',
            RunStatus::Failed                                         => 'danger',
            RunStatus::PartiallyApplied                               => 'warning',
            RunStatus::Queued, RunStatus::Running, RunStatus::Planned => 'info',
            RunStatus::Discarded, RunStatus::Expired                  => 'neutral',
        };
    }

    /**
     * A label of the bundle domain.
     *
     * @param array<string, int|string> $params
     */
    private function label(string $key, array $params = []): Label
    {
        return new Label($key, FormProfile::DOMAIN, $params);
    }

    /**
     * A translated message of the bundle domain (the key itself without a translator).
     *
     * @param array<string, mixed> $params
     */
    private function trans(string $key, array $params = []): string
    {
        return $this->translator?->trans($key, $params, FormProfile::DOMAIN) ?? $key;
    }
}
