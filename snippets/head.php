<?php
use Iperstudio\Impersonate\Impersonate;

$app = kirby();
if (!Impersonate::visible($app)) return;
$plugin = $app->plugin('iperstudio/impersonate-plugin');
?>
<?php if ($app->option(Impersonate::OPTION . 'styles', true)) : ?>
  <link rel="stylesheet" href="<?= esc($plugin->asset('impersonate.css')->url(), 'attr') ?>" data-impersonate-styles>
<?php endif ?>
<?php if ($target = Impersonate::target($app)) : ?>
  <meta name="referrer" content="no-referrer" data-impersonate-referrer>
  <script src="<?= esc($plugin->asset('impersonate.js')->url(), 'attr') ?>"
    data-impersonate-script
    data-impersonate-user="<?= esc($target->id(), 'attr') ?>"
    data-impersonate-read-only="<?= $app->option(Impersonate::OPTION . 'readOnly', false) ? 'true' : 'false' ?>"
    data-impersonate-blocked-methods="<?= esc(json_encode(Impersonate::blockedMethods($app), JSON_THROW_ON_ERROR), 'attr') ?>"></script>
<?php endif ?>
