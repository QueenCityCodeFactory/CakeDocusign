<?php

use Cake\Core\Configure;

$appEnv = Configure::read('App.environment');
$docusign = Configure::read('Docusign');

$hosts = [
    'production' => 'https://na2.docusign.net/restapi/v2',
    'develop' => 'https://demo.docusign.net/restapi'
];

$defaultConfig = [
    'config' => [
        'accountId' => '#######',
        'username' => 'user@email.com',
        'password' => 'myPassword',
        'integrator_key' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'
    ],
    'defaults' => [
        'email' => [
            'subject' => Configure::read('App.title') . ' - Signature Request'
        ]
    ]
];

$docusign = $docusign ? $docusign : $defaultConfig;
$docusign['paths'] = isset($docusign['paths']) ? $docusign['paths'] : [];
$docusign['config']['host'] = $appEnv == 'production' ? $hosts['production'] : $hosts['develop'];
$docusign['paths']['file'] = isset($docusign['paths']['file']) && !empty($docusign['paths']['file']) ? $docusign['paths']['file'] : '/tmp';

Configure::write('Docusign', $docusign);
