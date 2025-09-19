<?php

use Drupal\rnfleet_stripe\RnFleetStripeCustomerClass\RnFleetStripeCustomerClass;
use Stripe\Stripe;
use Stripe\Customer;

/**
 * Recherche un client Stripe par email et met à jour son email en minuscule si nécessaire.
 */
function devel_findAndFixCustomerEmail($email = FALSE) {
    if (!$email) {
        throw new \Exception('No email provided');
    }

    try {
        // Récupération des clés Stripe depuis la configuration Drupal.
        $configFactory = \Drupal::service('config.factory');
        $config = $configFactory->get('fleet_stripe.settings');
        $secret_key = $config->get('stripe_secret_key');

        if (empty($secret_key)) {
            throw new \Exception('La clé secrète Stripe est introuvable dans la configuration.');
        }

        // Initialisation de Stripe avec la clé secrète récupérée
        Stripe::setApiKey($secret_key);

        // Recherche de clients par adresse e-mail
        $customers = Customer::all([
            'email' => $email,
        ]);

        // Vérification et mise à jour si un client est trouvé
        if (!empty($customers->data)) {
            $customer = $customers->data[0]; // Récupère le premier client trouvé
            $current_email = $customer->email;
            $lowercase_email = strtolower($current_email);

            if ($current_email !== $lowercase_email) {
                // Mise à jour de l'email et ajout de la métadonnée
                $updated_customer = Customer::update($customer->id, [
                    'email' => $lowercase_email,
                    'metadata' => [
                        'fix_update' => "Email mis à jour de {$current_email} vers {$lowercase_email} le " . date('Y-m-d H:i:s'),
                    ],
                ]);

                \Drupal::messenger()->addStatus(t('Email du client mis à jour en minuscule : @email', ['@email' => $lowercase_email]));
                dpm($updated_customer, 'Client Stripe mis à jour');
            }
            else {
                \Drupal::messenger()->addStatus(t('L\'email du client est déjà en minuscule : @email', ['@email' => $current_email]));
                dpm($customer, 'Aucune mise à jour nécessaire');
            }
            return $customer;
        }
        else {
            \Drupal::messenger()->addError(t('Aucun client trouvé avec cet email.'));
            dpm('Aucun client trouvé avec cet email.');
            return NULL;
        }
    }
    catch (\Exception $e) {
        // Gestion des erreurs
        \Drupal::messenger()
            ->addError(t('Erreur lors de la recherche/mise à jour du client Stripe : @message', ['@message' => $e->getMessage()]));
        return NULL;
    }
}

// Exemple d'utilisation
$mail = 'Vivien@gtest.com';
$customer = devel_findAndFixCustomerEmail($mail);