<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\DependencyInjection\Compiler;

use LogicException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fails closed when form CSRF protection is unavailable while the pages are on (REQ-SEC-005, ZCE-L3).
 *
 * Every state change of the UI — upload, apply, discard and the confirmed personal-data export — is a FormKit form that
 * relies on Symfony's form CSRF extension. A host that disables `framework.form.csrf_protection` (or has no CSRF token
 * manager) would silently turn those checks off, so the container refuses to compile instead.
 */
final class CsrfProtectionPass implements CompilerPassInterface
{
    /** Parameter set by the extension: whether `ui.enabled` is on. */
    public const UI_PARAMETER = 'nowo_seed_kit.ui_enabled';

    /**
     * Throws when the UI is enabled and form CSRF protection or the CSRF token manager is unavailable.
     *
     * @throws LogicException
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::UI_PARAMETER) || $container->getParameter(self::UI_PARAMETER) !== true) {
            return;
        }
        $formCsrfDisabled = $container->hasParameter('form.type_extension.csrf.enabled')
            && $container->getParameter('form.type_extension.csrf.enabled') === false;
        if (!$container->has('security.csrf.token_manager') || !$container->hasDefinition('form.type_extension.csrf') || $formCsrfDisabled) {
            throw new LogicException('NowoSeedKitBundle: the import / export pages (nowo_seed_kit.ui.enabled) require CSRF protection. Enable framework.csrf_protection and framework.form.csrf_protection (composer require symfony/security-csrf).');
        }
    }
}
