<?php
// lookup_card.php  (used by the cashier terminal: finds the customer's Privilege Card by name or card number)
session_start();
header('Content-Type: application/json; charset=utf-8');
if (($_SESSION['role'] ?? '') !== 'Cashier') { http_response_code(403); echo json_encode(['error' => 'Not allowed']); exit(); }
require_once 'db.php';

use App\Services\PrivilegeCardService;

$cards = new PrivilegeCardService();
echo json_encode(['cards' => $cards->searchActive($_GET['q'] ?? '')]);
