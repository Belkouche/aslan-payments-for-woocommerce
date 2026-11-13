<?php

declare(strict_types=1);

namespace Aslan\Woo\Service;

final class DiagnosticLog
{
    public const OPTION          = 'aslan_woo_log';
    public const MAX             = 100;
    public const MAX_MSG_LENGTH  = 500;

    public function record(string $msg): void
    {
        if (strlen($msg) > self::MAX_MSG_LENGTH) {
            $msg = substr($msg, 0, self::MAX_MSG_LENGTH - 1) . '…';
        }
        $rows   = (array) get_option(self::OPTION, []);
        $rows[] = ['at' => gmdate('c'), 'msg' => $msg];
        if (count($rows) > self::MAX) {
            $rows = array_slice($rows, -self::MAX);
        }
        update_option(self::OPTION, $rows, false);
    }
}
