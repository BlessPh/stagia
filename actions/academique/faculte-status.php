<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';
requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);verifyAjaxCsrf();
jsonResponse(false,"Le statut d'une unité académique de référence est décidé par la gouvernance STAGIA.",[],403);
