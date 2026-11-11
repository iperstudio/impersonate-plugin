<?php

use Iperstudio\Impersonate\Impersonate;
use Kirby\Cms\App;

require_once __DIR__ . '/Impersonate.php';

App::plugin('iperstudio/impersonate-plugin', [
    'options' => [
        'enabled' => true,
        'styles' => true,
        'readOnly' => false,
        'blockedMethods' => [],
        'blockedRoutes' => [],
        'block' => null,
    ],
    'snippets' => [
        'impersonate/head' => __DIR__ . '/snippets/head.php',
        'impersonate/toolbar' => __DIR__ . '/snippets/toolbar.php',
    ],
    'translations' => [
        'en' => [
            'impersonate.label' => 'View as',
            'impersonate.placeholder' => 'Account ID or email',
            'impersonate.open' => 'Open preview',
            'impersonate.active' => 'Preview as',
            'impersonate.read-only' => 'Read-only preview, changes and submissions are disabled.',
            'impersonate.exit' => 'Back to admin view',
        ],
        'it' => [
            'impersonate.label' => 'Visualizza come',
            'impersonate.placeholder' => 'ID account o email',
            'impersonate.open' => 'Apri anteprima',
            'impersonate.active' => 'Anteprima come',
            'impersonate.read-only' => 'Anteprima in sola lettura. Modifiche e invii sono disabilitati.',
            'impersonate.exit' => 'Torna alla vista admin',
        ],
        'fr' => [
            'impersonate.label' => 'Voir en tant que',
            'impersonate.placeholder' => 'Identifiant du compte ou e-mail',
            'impersonate.open' => 'Ouvrir l’aperçu',
            'impersonate.active' => 'Aperçu en tant que',
            'impersonate.read-only' => 'Aperçu en lecture seule. Les modifications et les envois sont désactivés.',
            'impersonate.exit' => 'Retour à la vue admin',
        ],
        'es' => [
            'impersonate.label' => 'Ver como',
            'impersonate.placeholder' => 'ID de cuenta o correo electrónico',
            'impersonate.open' => 'Abrir vista previa',
            'impersonate.active' => 'Vista previa como',
            'impersonate.read-only' => 'Vista previa de solo lectura. Los cambios y los envíos están deshabilitados.',
            'impersonate.exit' => 'Volver a la vista de administrador',
        ],
    ],
]);
