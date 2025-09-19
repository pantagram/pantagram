<?php

use Drupal\redirect\Entity\Redirect;

/**
 * Importer des redirections depuis un fichier CSV hébergé sur GitHub.
 *
 * CSV attendu (séparateur ";") :
 * -----------------------------------------------
 * Destination à rediriger (A partir de);Rediriger vers :;Statut
 * https://www.xxx.fr/ancienne-url;https://www.xxx.fr/nouvelle-url;301
 * -----------------------------------------------
 *
 * Le script :
 * - Lit chaque ligne
 * - Extrait le path source (ex: "ancienne-url")
 * - Résout la cible :
 *     -> internal:/node/XX si c’est un alias vers un node existant
 *     -> internal:/taxonomy/term/XX si c’est un alias vers un terme existant
 *     -> internal:/alias sinon
 * - Crée ou met à jour une redirection
 */

// URL brute du CSV (GitHub raw).
$url = '<URL A DEFINIR ICI>';

// Ouverture du CSV distant
$handle = fopen($url, 'r');
if (!$handle) {
  throw new \Exception("Impossible d’ouvrir le fichier CSV depuis $url");
}

// Lecture de l’entête avec séparateur ";"
$header = fgetcsv($handle, 0, ';');
foreach ($header as $k => $h) {
  $header[$k] = preg_replace('/^\xEF\xBB\xBF/', '', trim($h)); // nettoyage BOM
}

// Indices des colonnes
$idx_source = array_search('Destination à rediriger (A partir de)', $header);
$idx_target = array_search('Rediriger vers :', $header);
$idx_status = array_search('Statut', $header);

// Services Drupal
$storage       = \Drupal::entityTypeManager()->getStorage('redirect');
$alias_manager = \Drupal::service('path_alias.manager');
$node_storage  = \Drupal::entityTypeManager()->getStorage('node');
$term_storage  = \Drupal::entityTypeManager()->getStorage('taxonomy_term');

// Compteurs
$created  = 0;
$updated  = 0;
$warnings = 0;

// Lecture du CSV ligne par ligne
while (($row = fgetcsv($handle, 0, ';')) !== FALSE) {
  $source_url = trim($row[$idx_source] ?? '');
  $target_url = trim($row[$idx_target] ?? '');
  $status     = (int) ($row[$idx_status] ?? 301);

  if ($source_url === '' || $target_url === '') {
    continue;
  }

  // --- 1. Traitement de la source ---
  $p = parse_url($source_url);
  $source_path = $p['path'] ?? '/';
  if (!empty($p['query'])) {
    $source_path .= '?' . $p['query'];
  }
  $source_path = ltrim($source_path, '/'); // Drupal stocke sans "/" initial

  // --- 2. Traitement de la cible ---
  $tp = parse_url($target_url);
  $target_path = $tp['path'] ?? '/';

  // Résolution via PathAliasManager
  $system_path = $alias_manager->getPathByAlias($target_path);
  $target_uri = 'internal:' . $target_path; // fallback par défaut

  if ($system_path !== $target_path) {
    // Cas Node
    if (preg_match('#^/node/(\d+)$#', $system_path, $m)) {
      $nid = (int) $m[1];
      if ($node_storage->load($nid)) {
        $target_uri = 'internal:' . $system_path;
      } else {
        \Drupal::messenger()->addWarning("⚠️ Node $nid inexistant pour cible $target_path, fallback alias.");
        $warnings++;
      }
    }
    // Cas Taxonomy term
    elseif (preg_match('#^/taxonomy/term/(\d+)$#', $system_path, $m)) {
      $tid = (int) $m[1];
      if ($term_storage->load($tid)) {
        $target_uri = 'internal:' . $system_path;
      } else {
        \Drupal::messenger()->addWarning("⚠️ Terme $tid inexistant pour cible $target_path, fallback alias.");
        $warnings++;
      }
    }
    // Autres routes internes
    else {
      $target_uri = 'internal:' . $system_path;
    }
  }

  // --- 3. Vérifier si redirection existe déjà ---
  $existing = \Drupal::entityQuery('redirect')
    ->condition('redirect_source.path', $source_path)
    ->accessCheck(FALSE)
    ->execute();

  if (!empty($existing)) {
    // Mise à jour
    $rid = reset($existing);
    $redirect = $storage->load($rid);
    $redirect->set('redirect_redirect', ['uri' => $target_uri]);
    $redirect->set('status_code', $status);
    $redirect->save();
    $updated++;
    \Drupal::messenger()->addMessage("Mise à jour : $source_path → $target_uri");
  } else {
    // Création
    $redirect = $storage->create([
      'redirect_source' => ['path' => $source_path],
      'redirect_redirect' => ['uri' => $target_uri],
      'status_code' => $status,
    ]);
    $redirect->save();
    $created++;
    \Drupal::messenger()->addMessage("Créé : $source_path → $target_uri");
  }
}

fclose($handle);

// Résumé final
\Drupal::messenger()->addMessage("Résumé import : $created créées, $updated mises à jour, $warnings warnings.");
