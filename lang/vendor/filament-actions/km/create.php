<?php

/*
 * Override for filament/actions' shipped km translation, whose
 * 'single.label' is a mistranslated "ថ្មី។ :label" ("New. :label") —
 * it renders as e.g. "ថ្មី។ បន្ទប់" on every Create button. Only the
 * one broken key differs from vendor; the rest are copied verbatim so
 * the file resolves as a complete replacement.
 */

return [

    'single' => [

        'label' => 'បង្កើត :label ថ្មី',

        'modal' => [

            'heading' => 'បង្កើត :label',

            'actions' => [

                'create' => [
                    'label' => 'បង្កើត',
                ],

                'create_another' => [
                    'label' => 'បង្កើត & បង្កើតឡើងវិញ',
                ],

            ],

        ],

        'notifications' => [

            'created' => [
                'title' => 'បានបង្កើត',
            ],

        ],

    ],

];
