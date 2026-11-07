<?php

namespace App\Form;

use App\Service\AdminLocaleResolver;
use App\Service\MenuPreferencesResolver;
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
    /** The menu language preselected when the form's own language isn't a public-menu one. */
    public const DEFAULT_MENU_LANGUAGE = 'es';

    public function __construct(
        private readonly AdminLocaleResolver $adminLocaleResolver,
        private readonly MenuPreferencesResolver $menuPreferences,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $adminLocaleChoices = [];
        foreach ($this->adminLocaleResolver->getLocales() as $code => $meta) {
            $adminLocaleChoices[$meta['flag'] . ' ' . $meta['name']] = $code;
        }

        // The menu's main language: every public-menu language
        // (config/languages.php), shown like Configuración's own select
        // (flag + native name) — the same list the owner can switch to later.
        $menuLanguageChoices = [];
        foreach ($this->menuPreferences->getLanguages() as $code => $meta) {
            $menuLanguageChoices[$meta['flag'] . ' ' . $meta['name']] = $code;
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
                'choices' => $menuLanguageChoices,
                // Preselected: the language the form itself is shown in
                // (the login's selector / the browser's) — Spanish if that
                // isn't a public-menu language.
                'data'    => in_array($options['display_locale'], $menuLanguageChoices, true)
                    ? $options['display_locale']
                    : self::DEFAULT_MENU_LANGUAGE,
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
            // The language the page is shown in (Request::getLocale(), set by AdminLocaleSubscriber).
            'display_locale'         => self::DEFAULT_MENU_LANGUAGE,
        ]);
        $resolver->setAllowedTypes('detected_admin_locale', 'string');
        $resolver->setAllowedTypes('display_locale', 'string');
    }
}
