<?php

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Database\Database;
use Stripe\Stripe;
use Stripe\Customer;

// 🔹 Changer le répertoire d'exécution pour éviter les erreurs de chemin
chdir('/opt/drupal/web');

// 🔹 Initialisation de Drupal en CLI
$autoloader = require 'autoload.php';
$kernel = DrupalKernel::createFromRequest(Request::createFromGlobals(), $autoloader, 'prod');
$kernel->boot();
$container = $kernel->getContainer();
\Drupal::service('kernel')->preHandle(new Request());

// 🔹 Définition de DRUPAL_ROOT pour garantir le bon chargement des fichiers
define('DRUPAL_ROOT', getcwd());

// 🔹 Définition des paramètres pour le batch
$batch_size = 50; // Pour éviter la surcharge mémoire

// 🔹 Récupération de la clé Stripe depuis la configuration Drupal
$configFactory = \Drupal::service('config.factory');
$config = $configFactory->get('fleet_stripe.settings');
$secret_key = $config->get('stripe_secret_key');

if (empty($secret_key)) {
    die("❌ Erreur : La clé secrète Stripe est introuvable dans la configuration.\n");
}

// 🔹 Initialisation de Stripe
Stripe::setApiKey($secret_key);

/**
 * Met à jour directement l'email d'un node en base de données.
 */
function update_node_field_directly($nid, $lowercase_email) {
    $database = Database::getConnection();

    try {
        // 🔹 Mise à jour directe dans la base de données
        $database->update('node__field_email')
            ->fields(['field_email_value' => $lowercase_email])
            ->condition('entity_id', $nid)
            ->execute();

        //echo "✅ Mise à jour directe du Node {$nid} → {$lowercase_email}\n";
    } catch (\Exception $e) {
        echo "❌ Erreur lors de la mise à jour directe du Node : " . $e->getMessage() . "\n";
    }
}

/**
 * Récupère un batch de nodes avec un email en majuscules et un `field_uid_stripe` défini.
 */
function get_nodes_with_uppercase_email($offset, $limit) {
    $query = "SELECT nfd.nid, nfe.field_email_value, nfs.field_uid_stripe_value
              FROM node__field_email nfe
              JOIN node_field_data nfd ON nfe.entity_id = nfd.nid
              JOIN node__field_uid_stripe nfs ON nfe.entity_id = nfs.entity_id
              WHERE nfe.field_email_value <> LOWER(nfe.field_email_value)
                AND nfs.field_uid_stripe_value IS NOT NULL
                AND nfs.field_uid_stripe_value != ''
              ORDER BY nfd.nid ASC 
              LIMIT :limit OFFSET :offset";

    return \Drupal::database()->query($query, [
        ':limit' => $limit,
        ':offset' => $offset,
    ])->fetchAll();
}

/**
 * Met à jour l'email du client dans Stripe et met à jour le node Drupal.
 */
function update_customer_email($nid, $email, $uid_stripe) {
    try {
        // Mise en minuscule de l'email
        $lowercase_email = strtolower($email);

        // 🔹 Étape 1 : Vérifier si l'email doit être mis à jour dans Stripe
        $customers = Customer::all(['email' => $email]);

        if (!empty($customers->data)) {
            $customer = $customers->data[0]; // Prend le premier client trouvé
            $current_email = $customer->email;

            if ($current_email !== $lowercase_email) {
                // 🔹 Étape 2 : Mise à jour de l’email dans Stripe (désactivée pour tests)

                Customer::update($customer->id, [
                    'email' => $lowercase_email,
                    'metadata' => [
                        'fix_update' => "Email mis à jour de {$current_email} vers {$lowercase_email} le " . date('Y-m-d H:i:s'),
                    ],
                ]);

                echo "✅ Mise à jour Stripe : {$current_email} → {$lowercase_email}\n";
            } else {
                echo "ℹ️ L’email Stripe est déjà en minuscule : {$lowercase_email}\n";
            }
        } else {
            echo "⚠️ Aucun client Stripe trouvé pour {$email}\n";
            return;
        }

        // 🔹 Étape 3 : Mise à jour du node Drupal
        update_node_field_directly($nid, $lowercase_email);
        echo "✅ Mise à jour Drupal : Node {$nid} → {$lowercase_email}\n";

    } catch (\Exception $e) {
        echo "❌ Erreur lors de la mise à jour de l'email : " . $e->getMessage() . "\n";
    }
}

// Étape 1 : Chargement des nodes par batch
$offset = 0;
$total_updated = 0;

while (true) {
    echo "🔹 Chargement des nodes de $offset à " . ($offset + $batch_size - 1) . "...\n";
    $nodes = get_nodes_with_uppercase_email($offset, $batch_size);

    if (empty($nodes)) {
        break; // Plus de nodes à traiter
    }

    foreach ($nodes as $node) {
        update_customer_email($node->nid, $node->field_email_value, $node->field_uid_stripe_value);
        $total_updated++;
    }

    // 🔹 Augmenter l'offset pour passer au batch suivant
    $offset += $batch_size;
}

echo "🎉 Fin du traitement : {$total_updated} clients mis à jour !\n";