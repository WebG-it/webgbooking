<?php

/**
 * WebG Booking — YOOtheme module bootstrap.
 * Loaded into the YOOtheme Application by plg_system_webgbooking; registers the
 * builder element via the verified `extend(Builder)` -> `addType()` mechanism
 * (same pattern used by YOOtheme's own builder-joomla package on 5.0.35).
 *
 * @license GNU General Public License version 2 or later
 */

namespace YOOtheme;

use YOOtheme\Builder;

return [
    'extend' => [
        Builder::class => function (Builder $builder) {
            $builder->addType('wgb_booking', __DIR__ . '/elements/booking/element.php');
        },
    ],
];
