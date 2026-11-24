<?php
use Iperstudio\Impersonate\Impersonate;

$app = kirby();
if (!Impersonate::visible($app)) return;
$target = Impersonate::target($app);
$query = $app->request()->query()->toArray();
unset($query['as']);
$action = $app->request()->url(['query' => null, 'fragment' => null])->toString();
$hidden = static function (array $values, string $prefix = '') use (&$hidden): void {
    foreach ($values as $key => $value) {
        $name = $prefix === '' ? (string)$key : $prefix . '[' . $key . ']';
        if (is_array($value)) {
            $hidden($value, $name);
        } elseif (is_scalar($value)) {
            echo '<input type="hidden" name="' . esc($name, 'attr') . '" value="' . esc((string)$value, 'attr') . '">';
        }
    }
};
?>
<aside data-impersonate-toolbar data-impersonate-state="<?= $target ? 'active' : 'idle' ?>" aria-label="<?= esc(t('impersonate.label', 'View as'), 'attr') ?>">
  <?php if ($target) : ?>
    <div data-impersonate-active>
      <p data-impersonate-identity>
        <?= esc(t('impersonate.active', 'Preview as')) ?> <?= esc($target->email()) ?>
        (<?= esc($target->role()->title()) ?>).
        <?php if ($app->option(Impersonate::OPTION . 'readOnly', false)) : ?>
          <span data-impersonate-notice><?= esc(t('impersonate.read-only', 'Read-only preview, changes and submissions are disabled.')) ?></span>
        <?php endif ?>
      </p>
      <a href="<?= esc(Impersonate::exitUrl($app), 'attr') ?>" data-impersonate-exit><?= esc(t('impersonate.exit', 'Back to admin view')) ?></a>
    </div>
  <?php else : ?>
    <form method="get" action="<?= esc($action, 'attr') ?>" data-impersonate-controls>
      <?php $hidden($query) ?>
      <label for="impersonate-account" data-impersonate-label><?= esc(t('impersonate.label', 'View as')) ?></label>
      <input id="impersonate-account" name="as" type="text" required autocomplete="off" spellcheck="false" data-impersonate-input placeholder="<?= esc(t('impersonate.placeholder', 'Account ID or email'), 'attr') ?>">
      <button type="submit" data-impersonate-submit><?= esc(t('impersonate.open', 'Open preview')) ?></button>
    </form>
  <?php endif ?>
</aside>
