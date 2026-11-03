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
 * The reseller sign-up (/partners/registro): same rules as
 * RegistrationFormType. Labels and constraint messages are keys in the
 * "partners" domain; the template translates the messages itself.
 */
class ResellerRegistrationFormType extends AbstractType
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
            ->add('company', TextType::class, [
                'label'       => 'register.company',
                'constraints' => [
                    new NotBlank(message: 'register.error.company_blank'),
                    new Length(min: 2, max: 100, minMessage: 'register.error.company_length', maxMessage: 'register.error.company_length'),
                ],
            ])
            ->add('name', TextType::class, [
                'label'       => 'register.name',
                'constraints' => [
                    new NotBlank(message: 'register.error.name_blank'),
                    new Length(max: 255, maxMessage: 'register.error.name_length'),
                ],
            ])
            ->add('email', EmailType::class, [
                'label'       => 'register.email',
                'constraints' => [
                    new NotBlank(message: 'register.error.email_blank'),
                    new Email(message: 'register.error.email_invalid'),
                ],
            ])
            ->add('password', RepeatedType::class, [
                'type'            => PasswordType::class,
                'first_options'   => ['label' => 'register.password'],
                'second_options'  => ['label' => 'register.password_repeat'],
                'invalid_message' => 'register.error.password_mismatch',
                'constraints'     => [
                    new NotBlank(message: 'register.error.password_blank'),
                    new Length(min: 8, minMessage: 'register.error.password_short'),
                ],
            ])
            ->add('adminLocale', ChoiceType::class, [
                'label'                     => 'register.admin_locale',
                'choices'                   => $adminLocaleChoices,
                'choice_translation_domain' => false,
                'data'                      => $options['detected_admin_locale'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'            => null,
            'translation_domain'    => 'partners',
            'detected_admin_locale' => $this->adminLocaleResolver->getDefaultLocale(),
        ]);
        $resolver->setAllowedTypes('detected_admin_locale', 'string');
    }
}
