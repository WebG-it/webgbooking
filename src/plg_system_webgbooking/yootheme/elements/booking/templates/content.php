<?php

/**
 * WebG Booking element — content template (no markup, for search/portability).
 *
 * @license GNU General Public License version 2 or later
 */

$title = htmlspecialchars((string) ($props['title'] ?? ''), ENT_QUOTES, 'UTF-8');
$service = htmlspecialchars((string) ($props['service'] ?? ''), ENT_QUOTES, 'UTF-8');

?>
<?php if ($title !== '') : ?>
<h3><?= $title ?></h3>
<?php endif ?>
<?php if ($service !== '') : ?>
<p><?= $service ?></p>
<?php endif ?>
