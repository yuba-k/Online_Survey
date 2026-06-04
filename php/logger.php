
<?php
date_default_timezone_set('Asia/Tokyo');
function writeLog(string $dir ,string $level, string $message): void
{
    try{
        $logDir = __DIR__ . '/logs';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $log = sprintf(
            "[%s] [%s] %s %s\n",
            date('Y-m-d H:i:s'),
            $dir,
            $level,
            $message
        );

        error_log($log, 3, __DIR__ . '/logs/app.log');
    } catch (Throwable $e) {
        return;
    }
}