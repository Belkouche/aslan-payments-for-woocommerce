<?php

declare(strict_types=1);

namespace Aslan\Woo\Service;

final class DiagnosticLog
{
    public const OPTION = 'aslan_woo_log';
    public const MAX    = 100;

    public function record(string $msg): void
    {
        $rows   = (array) get_option(self::OPTION, []);
        $rows[] = ['at' => gmdate('c'), 'msg' => $msg];
        if (count($rows) > self::MAX) {
            $rows = array_slice($rows, -self::MAX);
        }
        update_option(self::OPTION, $rows, false);
    }
}
