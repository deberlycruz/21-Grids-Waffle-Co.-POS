<?php
namespace App\Services;

use PDOException;
use App\Core\Database;
use App\Core\DatabaseException;
use App\Core\OrderException;
use App\Core\Site;
use App\Algorithms\Searching;

// Loyal customers and their Privilege Cards: award a card, find a card, list the card holders
class PrivilegeCardService {
    private $pdo;

    public function __construct() {
        $this->pdo = Database::connect();
    }

    // The Owner awards a card to a loyal customer found by name
    public function award($holderName, $ownerId) {
        $holderName = trim($holderName);
        $length = function_exists('mb_strlen') ? mb_strlen($holderName) : strlen($holderName);
        if ($holderName === '' || $length > 100) {
            throw new OrderException("Type the customer's name (up to 100 characters) to award the card.", "Name Required");
        }
        // one active card per person
        $stmt = $this->pdo->prepare("SELECT CardID FROM PrivilegeCard WHERE HolderName = ? AND IsActive = TRUE");
        $stmt->execute([$holderName]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            throw new OrderException($holderName . " already has Privilege Card " . Site::cardNo($existing) . ".", "Card Already Awarded");
        }
        try {
            $this->pdo->prepare("INSERT INTO PrivilegeCard (HolderName, IssuedBy) VALUES (?, ?)")->execute([$holderName, (int)$ownerId]);
            return (int)$this->pdo->lastInsertId();
        } catch (PDOException $exception) {
            throw new DatabaseException("The Privilege Card could not be saved. Please try again.", "Award Failed");
        }
    }

    // The Owner corrects the holder's name on a card
    public function rename($cardId, $holderName) {
        $holderName = trim($holderName);
        $length = function_exists('mb_strlen') ? mb_strlen($holderName) : strlen($holderName);
        if ($holderName === '' || $length > 100) {
            throw new OrderException("Type the customer's name (up to 100 characters).", "Name Required");
        }
        $stmt = $this->pdo->prepare("SELECT CardID FROM PrivilegeCard WHERE HolderName = ? AND IsActive = TRUE AND CardID <> ?");
        $stmt->execute([$holderName, (int)$cardId]);
        if ($stmt->fetchColumn() !== false) {
            throw new OrderException($holderName . " already has an active Privilege Card.", "Card Already Awarded");
        }
        $this->pdo->prepare("UPDATE PrivilegeCard SET HolderName = ? WHERE CardID = ?")->execute([$holderName, (int)$cardId]);
    }

    // Stops or restarts a card (the history of its sales is kept)
    public function toggleActive($cardId) {
        $this->pdo->prepare("UPDATE PrivilegeCard SET IsActive = NOT IsActive WHERE CardID = ?")->execute([(int)$cardId]);
    }

    // One ACTIVE card by id, or null
    public function findActive($cardId) {
        $stmt = $this->pdo->prepare("SELECT CardID, HolderName, IssuedAt FROM PrivilegeCard WHERE CardID = ? AND IsActive = TRUE");
        $stmt->execute([(int)$cardId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // Every active card, A to Z by holder name, for the cashier's drop-down (card number + customer name)
    public function activeCards() {
        $rows = $this->pdo->query("SELECT CardID, HolderName FROM PrivilegeCard WHERE IsActive = TRUE ORDER BY HolderName, CardID")->fetchAll();
        $cards = [];
        foreach ($rows as $row) {
            $cards[] = ['id' => (int)$row['CardID'], 'card' => Site::cardNo($row['CardID']), 'holder' => $row['HolderName']];
        }
        return $cards;
    }

    // Active cards that match what the cashier typed: a card number (PC-00001, 1) or part of the holder's name
    public function searchActive($text) {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $cards = $this->pdo->query("SELECT CardID, HolderName FROM PrivilegeCard WHERE IsActive = TRUE ORDER BY CardID")->fetchAll();
        $cardId = Site::parseCardNo($text);
        $found = [];
        foreach ($cards as $card) {
            if (($cardId > 0 && (int)$card['CardID'] === $cardId) || stripos($card['HolderName'], $text) !== false) {
                $found[] = ['id' => (int)$card['CardID'], 'card' => Site::cardNo($card['CardID']), 'holder' => $card['HolderName']];
            }
            if (count($found) >= 6) {
                break;
            }
        }
        return $found;
    }

    // Every card with the number of times it was used and the discount it gave (Owner dashboard)
    public function holders() {
        return $this->pdo->query(
            "SELECT PC.CardID, PC.HolderName, PC.IssuedAt, PC.IsActive, S.FullName AS IssuedByName,
                    COUNT(CO.OrderID) AS TimesUsed, COALESCE(SUM(CO.DiscountAmount), 0) AS DiscountGiven
             FROM PrivilegeCard PC
             JOIN Staff S ON S.StaffID = PC.IssuedBy
             LEFT JOIN CustomerOrder CO ON CO.PrivilegeCardID = PC.CardID AND CO.Status = 'Completed'
             GROUP BY PC.CardID, PC.HolderName, PC.IssuedAt, PC.IsActive, S.FullName
             ORDER BY PC.CardID"
        )->fetchAll();
    }
}
