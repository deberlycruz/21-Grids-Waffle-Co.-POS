<?php
namespace App\Core;

// The name of the business, shown in headers, titles and receipts, and the receipt number format.
class Site {
    private static $businessName = '21 Grids Waffle Co.';

    public static function getBusinessName() {
        return self::$businessName;
    }

    // The receipt number is stored as a plain number (1, 2, 3 ...) and shown with 5 digits: 00001, 00002 ...
    public static function receiptNo($orderId) {
        return str_pad((string)(int)$orderId, 5, '0', STR_PAD_LEFT);
    }

    // The Privilege Card number is stored as a plain number and shown as PC-00001, PC-00002 ...
    public static function cardNo($cardId) {
        return 'PC-' . str_pad((string)(int)$cardId, 5, '0', STR_PAD_LEFT);
    }

    // Turns what the person typed (PC-00001, pc00001 or 1) back into the card id, or 0 when it is not a card number
    public static function parseCardNo($text) {
        if (preg_match('/^\s*(?:PC)?[\- ]?0*([0-9]+)\s*$/i', $text, $m)) {
            return (int)$m[1];
        }
        return 0;
    }
}
