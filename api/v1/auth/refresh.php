<?php

/* Alias temporaire de compatibilité ; le contrat mobile utilise /refresh-token. */
require_once __DIR__.'/../bootstrap.php';
apiDeprecation('/api/v1/refresh-token');
require __DIR__.'/refresh-token.php';
