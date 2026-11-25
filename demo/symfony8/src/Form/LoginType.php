<?php

declare(strict_types=1);

namespace App\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * REQ-DEMO-015: a FormKit form (profile `demo`) with CSRF. Demo login (admin / demo and viewer / demo). The empty block
 * prefix keeps `_username` / `_password` / `_csrf_token` flat for `form_login`; the submit button is the one of UiKit's auth page.
 *
 * @extends AbstractType<null>
 */
#[FormKitConfig('demo')]
final class LoginType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addText($builder, '_username', [
            'label'       => 'Username',
            'data'        => $options['last_username'],
            'placeholder' => false,
            'help'        => false,
            'attr'        => ['autocomplete' => 'username'],
        ]);
        $this->addPassword($builder, '_password', [
            'label'        => 'Password',
            'always_empty' => true,
            'placeholder'  => false,
            'help'         => false,
            'attr'         => ['autocomplete' => 'current-password'],
        ]);
        $this->addWithDefaults($builder, '_target_path', HiddenType::class, [
            'data' => $options['target_path'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_csrf_token',
            'csrf_token_id'   => 'authenticate',
            'last_username'   => '',
            'target_path'     => '/',
        ]);
        $resolver->setAllowedTypes('last_username', 'string');
        $resolver->setAllowedTypes('target_path', 'string');
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
