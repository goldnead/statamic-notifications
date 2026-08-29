<?php

/*
 * Die Worte fuer die Zahlen, die dieses Addon an statamic-insights meldet.
 *
 * Eigene Datei statt eines Abschnitts in cp.php: das Analytics-Addon ist
 * optional, und wer cp.php liest, soll nicht heraussuchen muessen, welche
 * Haelfte davon nur mit einem Geschwister-Addon ueberhaupt greift.
 */

return [
    'group' => 'Benachrichtigungen',

    'sent' => 'Verschickt',
    'sent_description' => 'Benachrichtigungen, die in diesem Zeitraum herausgegangen sind.',

    'read' => 'Gelesen',
    'read_description' => 'Was in diesem Zeitraum gelesen wurde, auch an älteren Meldungen. Nicht der Zähler der Leserate daneben.',

    'read_rate' => 'Leserate',
    'read_rate_description' => 'Von dem, was in diesem Zeitraum herausging, der Anteil, der inzwischen gelesen ist. Nicht „Gelesen geteilt durch Verschickt": darin stecken auch ältere Meldungen.',

    'digests' => 'Zusammenfassungen',
    'digests_description' => 'Tatsächlich verschickte Sammelmails. Ein vorgemerkter, aber nie ausgeführter Lauf zählt nicht mit.',

    'breakdown_type' => 'Art',

    'no_type' => 'Ohne Art',
];
