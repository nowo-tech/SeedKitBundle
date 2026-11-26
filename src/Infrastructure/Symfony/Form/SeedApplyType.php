<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The apply of a run (contract section 12.5): the reviewed plan hash (hidden) and, for a mass prune, the number of
 * rows to delete typed again (contract section 10.4). CSRF `nowo_seed_kit`.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class SeedApplyType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the fields.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $mass = $options['mass_prune'] === true;
        $this->withBuilder($builder, function () use ($mass): void {
            $this->addTypedField('plan_hash', HiddenType::class, ['label' => false, 'placeholder' => false, 'help' => false]);
            if ($mass) {
                $this->addTypedField('confirm_deletes', TextType::class, ['label' => 'seed_kit.ui.run.confirm_deletes', 'help' => 'seed_kit.ui.run.confirm_deletes_help', 'placeholder' => false, 'required' => true, 'attr' => ['autocomplete' => 'off', 'inputmode' => 'numeric']]);
            }
        });
    }

    /**
     * Array data, CSRF `nowo_seed_kit`, option `mass_prune`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => FormProfile::CSRF,
            'allow_extra_fields' => false,
            'translation_domain' => FormProfile::DOMAIN,
            'mass_prune'         => false,
        ]);
        $resolver->setAllowedTypes('mass_prune', 'bool');
    }

    /**
     * Field names `seed_kit_apply[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'seed_kit_apply';
    }
}
