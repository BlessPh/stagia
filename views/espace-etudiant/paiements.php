<?php
declare(strict_types=1);

require_once __DIR__.'/../../config/config.php';
header('Location: '.BASE_URL.'/views/paiements/index.php',true,302);
exit;
