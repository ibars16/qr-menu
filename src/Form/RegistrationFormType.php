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

/**
 * The restaurant sign-up (/register and a reseller's /alta/{code}). Labels
 * and constraint messages are keys in the "register" domain; the template
 * translates the messages itself.
 */
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
                'label'       => 'restaurant_name',
                'constraints' => [
                    new NotBlank(message: 'error.restaurant_blank'),
                    new Length(min: 2, max: 100, minMessage: 'error.restaurant_length', maxMessage: 'error.restaurant_length'),
                ],
            ])
            ->add('email', EmailType::class, [
                'label'       => 'email',
                'constraints' => [
                    new NotBlank(message: 'error.email_blank'),
                    new Email(message: 'error.email_invalid'),
                ],
            ])
            ->add('password', RepeatedType::class, [
                'type'            => PasswordType::class,
                'first_options'   => ['label' => 'password'],
                'second_options'  => ['label' => 'password_repeat'],
                'invalid_message' => 'error.password_mismatch',
                'constraints'     => [
                    new NotBlank(message: 'error.password_blank'),
                    new Length(min: 8, minMessage: 'error.password_short'),
                ],
            ])
            ->add('currency', ChoiceType::class, [
                'invalid_message' => 'error.choice',
                'label'   => 'currency',
                'choices' => [
                    'currency_choice.eur' => 'EUR',
                    'currency_choice.usd' => 'USD',
                    'currency_choice.nzd' => 'NZD',
                    'currency_choice.aud' => 'AUD',
                    'currency_choice.gbp' => 'GBP',
                    'currency_choice.jpy' => 'JPY',
                ],
            ])
            ->add('language', ChoiceType::class, [
                'invalid_message' => 'error.choice',
                'label'   => 'language',
                'choice_translation_domain' => false,
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
                'label'   => 'admin_locale',
                'help'    => 'admin_locale_help',
                'choices' => $adminLocaleChoices,
                'choice_translation_domain' => false,
                'data'    => $options['detected_admin_locale'],
                'invalid_message' => 'error.admin_locale',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'             => null,
            'translation_domain'     => 'register',
            // Shown above the form (errors of the form itself).
            'csrf_message'           => 'error.expired',
            'extra_fields_message'   => 'error.general',
            'detected_admin_locale'  => $this->adminLocaleResolver->getDefaultLocale(),
        ]);
        $resolver->setAllowedTypes('detected_admin_locale', 'string');
    }
}
