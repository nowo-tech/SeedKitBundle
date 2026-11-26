<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Controller;

/**
 * The processed `ui.*` settings the controller needs (immutable configuration only).
 */
final readonly class UiOptions
{
    /**
     * @param string|null $layout base layout of every page (`ui.layout`; null = UiKit's `nowo_ui_kit.layouts.admin`)
     * @param int $maxUploadBytes `ui.max_upload_bytes` (default 2 MiB)
     * @param int $runsPerPage rows of the runs list (keyset pager)
     * @param bool $allowUnauthenticated `security.allow_unauthenticated` (demos only)
     * @param bool $breadcrumbs page-header crumbs (rendered by BreadcrumbKit through UiKit when installed)
     * @param string|null $breadcrumbCollection BreadcrumbKit collection of the crumbs
     * @param int $pollMilliseconds progress refresh interval of a queued / running run
     */
    public function __construct(
        public ?string $layout = null,
        public int $maxUploadBytes = 2097152,
        public int $runsPerPage = 50,
        public bool $allowUnauthenticated = false,
        public bool $breadcrumbs = true,
        public ?string $breadcrumbCollection = null,
        public int $pollMilliseconds = 3000,
    ) {
    }
}
