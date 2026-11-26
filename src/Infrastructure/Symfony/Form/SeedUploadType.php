<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotNull;

use function is_int;
use function max;

/**
 * The upload of a seed file (contract section 12.5): the file (at most `ui.max_upload_bytes`; the reader counts the
 * bytes again while reading) and, only for a viewer who may prune, the prune switch. CSRF `nowo_seed_kit`.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class SeedUploadType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the fields.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $maxBytes = is_int($options['max_bytes']) ? max(1, $options['max_bytes']) : 2097152;
        $prune    = $options['allow_prune'] === true;
        $this->withBuilder($builder, function () use ($maxBytes, $prune): void {
            $this->addTypedField('file', FileType::class, [
                'label'       => 'seed_kit.ui.upload.file',
                'help'        => 'seed_kit.ui.upload.file_help',
                'placeholder' => false,
                'attr'        => ['accept' => '.yaml,.yml,.json,.jsonl,application/json,application/yaml,text/yaml'],
                'constraints' => [new NotNull(), new File(maxSize: $maxBytes)],
            ]);
            if ($prune) {
                $this->addTypedField('prune', CheckboxType::class, ['label' => 'seed_kit.ui.upload.prune', 'help' => 'seed_kit.ui.upload.prune_help', 'placeholder' => false, 'required' => false]);
            }
        });
    }

    /**
     * Array data, CSRF `nowo_seed_kit`, options `max_bytes` and `allow_prune`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => FormProfile::CSRF,
            'allow_extra_fields' => false,
            'translation_domain' => FormProfile::DOMAIN,
            'max_bytes'          => 2097152,
            'allow_prune'        => false,
        ]);
        $resolver->setAllowedTypes('max_bytes', 'int');
        $resolver->setAllowedTypes('allow_prune', 'bool');
    }

    /**
     * Field names `seed_kit_upload[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'seed_kit_upload';
    }
}
