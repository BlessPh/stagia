<?php

require_once __DIR__.'/env.php';

$username=(string)(getenv('SMTP_USERNAME')?:'');

return [
    'host'=>(string)(getenv('SMTP_HOST')?:'smtp.gmail.com'),
    'port'=>(int)(getenv('SMTP_PORT')?:587),
    'username'=>$username,
    'password'=>(string)(getenv('SMTP_PASSWORD')?:''),
    'from_email'=>(string)(getenv('SMTP_FROM_EMAIL')?:$username),
    'from_name'=>(string)(getenv('SMTP_FROM_NAME')?:'STAGIA-RDC')
];
