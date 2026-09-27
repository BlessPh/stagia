<?php
require_once __DIR__.'/../api-auth.php';require_once __DIR__.'/../../../includes/student-communication-api.php';
requireApiMethod('GET');$student=requireApiStudent($pdo);apiResponse(true,'',studentNotificationCounts($pdo,(int)$student['user_id']));
