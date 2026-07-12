<?php

namespace App\Form;

use App\Service\AdminLocaleResolver;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class RegistrationFormType extends AbstractType
{
    public function __construct(private readonly AdminLocaleResolver $adminLocaleResolver)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $adminLocaleChoices = [];
        foreach ($this->adminLocaleResolver->getLocales() as $code => $meta) {
            $adminLocaleChoices[$meta['flag'] . ' ' . $meta['name']] = $code;
        }

        $builder
            ->add('restaurantName', TextType::class, [
                'label'       => 'Nombre del restaurante',
                'constraints' => [
                    new NotBlank(message: 'Introduce el nombre del restaurante.'),
                    new Length(min: 2, max: 100),
                ],
            ])
            ->add('email', EmailType::class, [
                'label'       => 'Email',
                'constraints' => [
                    new NotBlank(message: 'Introduce tu email.'),
                    new Email(message: 'El email no es válido.'),
                ],
            ])
            ->add('password', RepeatedType::class, [
                'type'            => PasswordType::class,
                'first_options'   => ['label' => 'Contraseña'],
                'second_options'  => ['label' => 'Repetir contraseña'],
                'invalid_message' => 'Las contraseñas no coinciden.',
                'constraints'     => [
                    new NotBlank(message: 'Introduce una contraseña.'),
                    new Length(min: 8, minMessage: 'Mínimo 8 caracteres.'),
                ],
            ])
            ->add('currency', ChoiceType::class, [
                'label'   => 'Moneda principal',
                'choices' => [
                    'Euro (€)'           => 'EUR',
                    'Dólar USD ($)'      => 'USD',
                    'Dólar NZD ($)'      => 'NZD',
                    'Dólar AUD ($)'      => 'AUD',
                    'Libra esterlina (£)' => 'GBP',
                    'Yen japonés (¥)'    => 'JPY',
                ],
            ])
            ->add('language', ChoiceType::class, [
                'label'   => 'Idioma principal del menú',
                'choices' => [
                    'Español'  => 'es',
                    'English'  => 'en',
                    'Français' => 'fr',
                    'Deutsch'  => 'de',
                    '中文'      => 'zh',
                    '日本語'    => 'ja',
                ],
            ])
            ->add('adminLocale', ChoiceType::class, [
                'label'   => 'Idioma del Panel de Administración',
                'help'    => 'Solo afecta a tu panel de gestión, no a la carta de tus clientes.',
                'choices' => $adminLocaleChoices,
                'data'    => $options['detected_admin_locale'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'             => null,
            'detected_admin_locale'  => $this->adminLocaleResolver->getDefaultLocale(),
        ]);
        $resolver->setAllowedTypes('detected_admin_locale', 'string');
    }
}
