<?php
namespace App\Services;

use PDOException;
use App\Core\Database;
use App\Core\DatabaseException;
use App\Core\OrderException;

// Registers cashiers and manages staff accounts
class StaffService {
    private $pdo;

    public function __construct() {
        $this->pdo = Database::connect();
    }

    public function getAll() {
        return $this->pdo->query(
            "SELECT S.*, (SELECT COUNT(*) FROM CustomerOrder AS CO WHERE CO.CashierID = S.StaffID) AS OrderCount
             FROM Staff AS S
             ORDER BY S.Role, S.FullName"
        )->fetchAll();
    }

    private function checkName($name) {
        if ($name === '' || strlen($name) > 100) {
            throw new OrderException("Type the full name (up to 100 characters).", "Name Required");
        }
    }

    private function checkUsername($username) {
        if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) {
            throw new OrderException("Usernames use 3 to 30 letters, numbers or underscores, with no spaces.", "Invalid Username");
        }
    }

    private function checkPassword($password) {
        if (strlen($password) < 6 || strlen($password) > 72) {
            throw new OrderException("Use at least 6 characters.", "Password Too Short");
        }
    }

    // New staff accounts are always Cashiers
    public function addCashier($fullName, $username, $password, $password2) {
        $fullName = trim($fullName);
        $username = trim($username);
        $this->checkName($fullName);
        $this->checkUsername($username);
        $this->checkPassword($password);
        if ($password !== $password2) {
            throw new OrderException("Type the same password twice.", "Passwords Don't Match");
        }
        try {
            $this->pdo->prepare("INSERT INTO Staff (Username, PasswordHash, FullName, Role) VALUES (?, ?, ?, 'Cashier')")
                 ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName]);
        } catch (PDOException $exception) {
            throw new DatabaseException("That username already exists. Choose another.", "Username Taken");
        }
    }

    // The Owner's account belongs to the Owner Dashboard only. The admin page can never change it.
    private function refuseOwner($role) {
        if ($role === 'Owner') {
            throw new OrderException(
                "The owner's name, username and password can only be changed by the owner, in the Owner Dashboard.",
                "Owner Account Protected"
            );
        }
    }

    // The Admin page edits cashiers (name and username) and the admin's own name. It cannot touch the Owner.
    public function edit($id, $fullName, $username) {
        $fullName = trim($fullName);
        $username = trim($username);
        $stmt = $this->pdo->prepare("SELECT Role FROM Staff WHERE StaffID = ?");
        $stmt->execute([(int)$id]);
        $role = $stmt->fetchColumn();
        if ($role === false) {
            throw new DatabaseException("That account no longer exists.", "Not Found");
        }
        $this->refuseOwner($role);
        $this->checkName($fullName);
        try {
            if ($role === 'Cashier') {
                $this->checkUsername($username);
                $this->pdo->prepare("UPDATE Staff SET FullName = ?, Username = ? WHERE StaffID = ?")->execute([$fullName, $username, (int)$id]);
            } else {
                $this->pdo->prepare("UPDATE Staff SET FullName = ? WHERE StaffID = ?")->execute([$fullName, (int)$id]);
            }
        } catch (PDOException $exception) {
            throw new DatabaseException("That username already exists.", "Username Taken");
        }
    }

    // $requireCurrent is true when a person changes their OWN password; an admin can reset a cashier's forgotten password without the old one.
    public function resetPassword($id, $currentPassword, $newPassword, $requireCurrent = false) {
        $stmt = $this->pdo->prepare("SELECT Role, PasswordHash FROM Staff WHERE StaffID = ?");
        $stmt->execute([(int)$id]);
        $account = $stmt->fetch();
        if (!$account) {
            throw new DatabaseException("That account no longer exists.", "Not Found");
        }
        $this->refuseOwner($account['Role']);
        // the current (old) password is checked only when the person changes their own password
        if ($requireCurrent && !password_verify($currentPassword, $account['PasswordHash'])) {
            usleep(500000);
            throw new OrderException("The current password is wrong. The password was NOT changed.", "Wrong Password");
        }
        $this->checkPassword($newPassword);
        $this->pdo->prepare("UPDATE Staff SET PasswordHash = ? WHERE StaffID = ?")
             ->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$id]);
    }

    // Accounts are deactivated, never deleted, so old sales stay linked to the person.
    // Returns true when the account is now active.
    public function toggleActive($id) {
        $this->pdo->prepare("UPDATE Staff SET IsActive = NOT IsActive WHERE StaffID = ? AND Role = 'Cashier'")->execute([(int)$id]);
        $stmt = $this->pdo->prepare("SELECT IsActive FROM Staff WHERE StaffID = ?");
        $stmt->execute([(int)$id]);
        return (bool)$stmt->fetchColumn();
    }

    // The Owner changes HIS OWN username and password (Owner Dashboard > My Account).
    // The current password must be typed first. Leave the new password empty to keep the old one.
    public function changeOwnAccount($ownerId, $currentPassword, $newUsername, $newPassword, $newPassword2) {
        $stmt = $this->pdo->prepare("SELECT PasswordHash FROM Staff WHERE StaffID = ? AND Role = 'Owner' AND IsActive = TRUE");
        $stmt->execute([(int)$ownerId]);
        $hash = $stmt->fetchColumn();
        if ($hash === false) {
            throw new DatabaseException("Only the owner can change this account.", "Not Allowed");
        }
        if (!password_verify($currentPassword, $hash)) {
            usleep(500000);
            throw new OrderException("The current password is wrong. Nothing was changed.", "Wrong Password");
        }
        $newUsername = trim($newUsername);
        $this->checkUsername($newUsername);
        if ($newPassword !== '') {
            $this->checkPassword($newPassword);
            if ($newPassword !== $newPassword2) {
                throw new OrderException("Type the same new password twice.", "Passwords Don't Match");
            }
        }
        try {
            if ($newPassword !== '') {
                $this->pdo->prepare("UPDATE Staff SET Username = ?, PasswordHash = ? WHERE StaffID = ?")
                     ->execute([$newUsername, password_hash($newPassword, PASSWORD_DEFAULT), (int)$ownerId]);
            } else {
                $this->pdo->prepare("UPDATE Staff SET Username = ? WHERE StaffID = ?")->execute([$newUsername, (int)$ownerId]);
            }
        } catch (PDOException $exception) {
            throw new DatabaseException("That username already exists. Choose another.", "Username Taken");
        }
    }
}
