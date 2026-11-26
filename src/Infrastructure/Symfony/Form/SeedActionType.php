<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A button that only carries the CSRF token and a marker (discard a run; download a personal-data export). CSRF
 * `nowo_seed_kit`.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class SeedActionType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the marker field (a named form is "submitted" only when its name is in the request).
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function (): void {
            $this->addTypedField('confirm', HiddenType::class, ['data' => '1', 'label' => false, 'placeholder' => false, 'help' => false]);
        });
    }

    /**
     * Array data, CSRF `nowo_seed_kit`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => FormProfile::CSRF,
            'allow_extra_fields' => false,
            'translation_domain' => FormProfile::DOMAIN,
        ]);
    }

    /**
     * Field names `seed_kit_action[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'seed_kit_action';
    }
}
