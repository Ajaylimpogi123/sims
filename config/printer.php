<?php

return [
    'default' => env('PRINTER_DEFAULT', 'main'),

    'printers' => [

        'main' => [
            'enabled' => env('PRINTER_MAIN_ENABLED', true),
            // POS-80 is on a virtual USB printer port (USB012), not a real
            // COM/serial port — 'windows' sends raw ESC/POS bytes through
            // the Windows print spooler using the printer's share name.
            'method' => env('PRINTER_MAIN_METHOD', 'windows'),
            'printer_name' => env('PRINTER_MAIN_NAME', 'POS-80'),
            // Kept in case you ever swap to a real serial/COM printer.
            'com_port' => env('PRINTER_MAIN_COM_PORT', 'COM1'),
            'com_baud' => env('PRINTER_MAIN_BAUD', 9600),
            'network_ip' => env('PRINTER_MAIN_IP', '192.168.1.100'),
            'network_port' => env('PRINTER_MAIN_PORT', 9100),
        ],

        'printer2' => [
            'enabled' => env('PRINTER_2_ENABLED', true),
            'method' => env('PRINTER_2_METHOD', 'com'),
            'com_port' => env('PRINTER_2_COM_PORT', 'COM4'),
            'com_baud' => env('PRINTER_2_BAUD', 9600),
            'network_ip' => env('PRINTER_2_IP', '192.168.1.101'),
            'network_port' => env('PRINTER_2_PORT', 9100),
        ],

        // 'printer3' => [
        //     'enabled' => env('PRINTER_3_ENABLED', false),
        //     'method' => env('PRINTER_3_METHOD', 'network'),
        //     'com_port' => env('PRINTER_3_COM_PORT', 'COM5'),
        //     'com_baud' => env('PRINTER_3_BAUD', 9600),
        //     'network_ip' => env('PRINTER_3_IP', '192.168.1.102'),
        //     'network_port' => env('PRINTER_3_PORT', 9100),
        // ],

        // Add more the same way — just copy a block and give it a new key.
    ],

    'connect_timeout' => env('PRINTER_TIMEOUT', 2),
    'store_name' => env('PRINTER_STORE_NAME', 'WESTPOINT PHARMACY'),
];
