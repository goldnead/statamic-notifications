<?php

/*
 * The words for the figures this addon contributes to statamic-insights.
 *
 * Their own file rather than a section of cp.php: the analytics addon is
 * optional, and a reader of that file should not have to work out which half of
 * it only applies when a sibling is installed.
 */

return [
    'group' => 'Notifications',

    'sent' => 'Sent',
    'sent_description' => 'Notifications that went out in this period.',

    'read' => 'Read',
    'read_description' => 'Reading that happened in this period, including on older notifications. Not the numerator of the read rate beside it.',

    'read_rate' => 'Read rate',
    'read_rate_description' => 'Of what went out in this period, the share that has since been read. Not "read divided by sent": that count includes older notifications.',

    'digests' => 'Digests',
    'digests_description' => 'Digest e-mails that actually went out. A run recorded but never sent is not counted.',

    'breakdown_type' => 'Type',

    'no_type' => 'Without a type',
];
