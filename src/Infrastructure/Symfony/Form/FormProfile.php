<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Form;

/**
 * FormKit profile of the bundle's forms (REQ-TWIG-005 rule 11, REQ-DEMO-015), prepended by the bundle when the host
 * does not declare it (labels in the `NowoSeedKitBundle` domain).
 */
final class FormProfile
{
    /** The profile name. */
    public const NAME = 'seed_kit';

    /** CSRF token id of every bundle form and POST action. */
    public const CSRF = 'nowo_seed_kit';

    /** Translation domain of the labels. */
    public const DOMAIN = 'NowoSeedKitBundle';
}
