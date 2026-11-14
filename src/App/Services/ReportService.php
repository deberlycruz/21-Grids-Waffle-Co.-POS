<?php
namespace App\Services;

use App\Core\Database;
use App\Algorithms\Sorting;
use App\Algorithms\Searching;
use App\Structures\MinHeap;

// Sales reports for the Owner (and Admin)
class ReportService {
    private $pdo;

    public function __construct() {
        $this->pdo = Database::connect();
    }

    // Builds " AND DATE(OrderDate) >= ?" parts for an optional date range
    private function dateFilter($from, $to, array &$params) {
        $sql = '';
        if ($from !== '') {
            $sql .= " AND DATE(OrderDate) >= ?";
            $params[] = $from;
        }
        if ($to !== '') {
            $sql .= " AND DATE(OrderDate) <= ?";
            $params[] = $to;
        }
        return $sql;
    }

    // Overall totals: completed revenue, completed count, voided count
    public function getTotals() {
        $totals = ['revenue' => 0, 'completed' => 0, 'voided' => 0, 'discounts' => 0];
        $rows = $this->pdo->query(
            "SELECT Status, COUNT(*) AS OrderCount, SUM(TotalAmount) AS Total, SUM(DiscountAmount) AS Discounts
             FROM CustomerOrder
             GROUP BY Status"
        )->fetchAll();
        foreach ($rows as $row) {
            if ($row['Status'] === 'Completed') {
                $totals['completed'] = (int)$row['OrderCount'];
                $totals['revenue'] = $row['Total'] ?? 0;
                $totals['discounts'] = $row['Discounts'] ?? 0;
            } else {
                $totals['voided'] = (int)$row['OrderCount'];
            }
        }
        return $totals;
    }

    // One row per date. $cashierId: null = everyone, 0 = orders with no cashier, number = that cashier
    public function getDailySales($cashierId = null, $from = '', $to = '') {
        $params = [];
        $where = "WHERE 1 = 1";
        if ($cashierId === 0) {
            $where .= " AND CashierID IS NULL";
        } elseif ($cashierId !== null) {
            $where .= " AND CashierID = ?";
            $params[] = (int)$cashierId;
        }
        $where .= $this->dateFilter($from, $to, $params);

        $stmt = $this->pdo->prepare(
            "SELECT DATE(OrderDate) AS SaleDate, Status, COUNT(*) AS OrderCount, SUM(TotalAmount) AS Total
             FROM CustomerOrder
             $where
             GROUP BY DATE(OrderDate), Status
             ORDER BY SaleDate DESC"
        );
        $stmt->execute($params);

        $days = [];
        foreach ($stmt->fetchAll() as $row) {
            $date = $row['SaleDate'];
            if (!isset($days[$date])) {
                $days[$date] = ['SaleDate' => $date, 'completed' => 0, 'net' => 0, 'voided' => 0, 'voidedAmount' => 0];
            }
            if ($row['Status'] === 'Completed') {
                $days[$date]['completed'] = (int)$row['OrderCount'];
                $days[$date]['net'] = $row['Total'];
            } else {
                $days[$date]['voided'] = (int)$row['OrderCount'];
                $days[$date]['voidedAmount'] = $row['Total'];
            }
        }
        return array_values($days);
    }

    // Every order with its items, cashier and approver
    public function getLedger($cashierId = null, $from = '', $to = '') {
        $params = [];
        $where = "WHERE 1 = 1";
        if ($cashierId === 0) {
            $where .= " AND CO.CashierID IS NULL";
        } elseif ($cashierId !== null) {
            $where .= " AND CO.CashierID = ?";
            $params[] = (int)$cashierId;
        }
        $dateParams = [];
        $dateSql = str_replace('OrderDate', 'CO.OrderDate', $this->dateFilter($from, $to, $dateParams));
        $params = array_merge($params, $dateParams);

        $stmt = $this->pdo->prepare(
            "SELECT CO.*, DATE(CO.OrderDate) AS SaleDate, C.FullName AS CashierName,
                    A.FullName AS ApproverName, V.VoidReason, D.DiscountName, D.AppliesTo, PC.HolderName AS CardHolder
             FROM CustomerOrder AS CO
             LEFT JOIN Discount AS D ON D.DiscountID = CO.DiscountID
             LEFT JOIN PrivilegeCard AS PC ON PC.CardID = CO.PrivilegeCardID
             LEFT JOIN Staff AS C ON C.StaffID = CO.CashierID
             LEFT JOIN OrderVoid AS V ON V.OrderID = CO.OrderID
             LEFT JOIN Staff AS A ON A.StaffID = V.ApprovedBy
             $where $dateSql
             ORDER BY CO.OrderDate DESC"
        );
        $stmt->execute($params);
        $orders = $stmt->fetchAll();

        // the items sold in each order, joined into text such as "Cafe Latte x2, Hot Chocolate x1"
        $itemRows = $this->pdo->query(
            "SELECT OI.OrderID, P.ProductName, OI.Quantity
             FROM OrderItem AS OI
             INNER JOIN Product AS P ON P.ProductID = OI.ProductID
             ORDER BY OI.OrderID, P.ProductName"
        )->fetchAll();
        $itemText = [];
        foreach ($itemRows as $item) {
            $itemText[$item['OrderID']][] = $item['ProductName'] . ' x' . $item['Quantity'];
        }
        // the items that received the discount (one piece each), e.g. "Plain Waffle -P34.50"
        $discountRows = $this->pdo->query(
            "SELECT OI.OrderID, P.ProductName, OI.DiscountAmount
             FROM OrderItem AS OI
             INNER JOIN Product AS P ON P.ProductID = OI.ProductID
             WHERE OI.DiscountAmount > 0
             ORDER BY OI.OrderID, P.ProductName"
        )->fetchAll();
        $discountText = [];
        foreach ($discountRows as $row) {
            $discountText[$row['OrderID']][] = '1 x ' . $row['ProductName'] . ' (-' . "\u{20B1}" . number_format($row['DiscountAmount'], 2) . ')';
        }
        foreach ($orders as $index => $order) {
            $orders[$index]['Items'] = isset($itemText[$order['OrderID']]) ? implode(', ', $itemText[$order['OrderID']]) : '';
            $orders[$index]['DiscountedItems'] = $discountText[$order['OrderID']] ?? [];
        }
        return $orders;
    }

    // The four buttons of the Owner Dashboard: how many orders and how much sales each one holds.
    // Completed / Voided: by order status.  SC/PWD and Privilege Card: completed orders that used that discount.
    public function getButtonTotals() {
        $rows = $this->pdo->query(
            "SELECT CO.Status, D.AppliesTo, COUNT(*) AS OrderCount, SUM(CO.TotalAmount) AS Total, SUM(CO.DiscountAmount) AS Discounts
             FROM CustomerOrder AS CO
             JOIN Discount AS D ON D.DiscountID = CO.DiscountID
             GROUP BY CO.Status, D.AppliesTo"
        )->fetchAll();
        $result = [
            'completed' => ['orders' => 0, 'sales' => 0, 'discount' => 0],
            'voided'    => ['orders' => 0, 'sales' => 0, 'discount' => 0],
            'scpwd'     => ['orders' => 0, 'sales' => 0, 'discount' => 0],
            'card'      => ['orders' => 0, 'sales' => 0, 'discount' => 0]
        ];
        foreach ($rows as $row) {
            $key = $row['Status'] === 'Completed' ? 'completed' : 'voided';
            $result[$key]['orders']   += (int)$row['OrderCount'];
            $result[$key]['sales']    += $row['Total'];
            $result[$key]['discount'] += $row['Discounts'];
            if ($row['Status'] === 'Completed' && $row['AppliesTo'] === 'One Waffle and One Drink') {
                $result['scpwd'] = ['orders' => (int)$row['OrderCount'], 'sales' => $row['Total'], 'discount' => $row['Discounts']];
            }
            if ($row['Status'] === 'Completed' && $row['AppliesTo'] === 'One Highest Item') {
                $result['card'] = ['orders' => (int)$row['OrderCount'], 'sales' => $row['Total'], 'discount' => $row['Discounts']];
            }
        }
        return $result;
    }

    // Groups the completed orders found by the name search into one row per customer
    // (orders, total spent), biggest spender first (MERGE SORT). Used to pick the loyal customer.
    public function customerSummary(array $foundOrders) {
        $people = [];
        foreach ($foundOrders as $order) {
            if ($order['Status'] !== 'Completed') {
                continue;
            }
            $key = strtolower($order['CustomerName']);
            if (!isset($people[$key])) {
                $people[$key] = ['name' => $order['CustomerName'], 'orders' => 0, 'spent' => 0, 'last' => $order['OrderDate']];
            }
            $people[$key]['orders']++;
            $people[$key]['spent'] += $order['TotalAmount'];
            if ($order['OrderDate'] > $people[$key]['last']) {
                $people[$key]['last'] = $order['OrderDate'];
            }
        }
        return Sorting::mergeSort(array_values($people), function ($first, $second) {
            return $second['spent'] <=> $first['spent'];
        });
    }


    // Sorts the ledger with MERGE SORT. $key: date, amount, customer, cashier or receipt.
    // $direction: 'asc' or 'desc'. $comparisons returns how many comparisons the sort made.
    public function sortLedger(array $ledger, $key, $direction, &$comparisons = 0) {
        $compare = function ($first, $second) use ($key) {
            switch ($key) {
                case 'amount':   return $first['TotalAmount'] <=> $second['TotalAmount'];
                case 'customer': return strcasecmp($first['CustomerName'], $second['CustomerName']);
                case 'cashier':  return strcasecmp($first['CashierName'] ?? '', $second['CashierName'] ?? '');
                case 'receipt':  return $first['OrderID'] <=> $second['OrderID'];
                default:         return strcmp($first['OrderDate'], $second['OrderDate']);   // date
            }
        };
        if ($direction === 'desc') {
            $ascending = $compare;
            $compare = function ($first, $second) use ($ascending) { return $ascending($second, $first); };
        }
        return Sorting::mergeSort($ledger, $compare, $comparisons);
    }

    // Finds every order whose customer name contains the text, using LINEAR SEARCH
    // (the ledger is not sorted by name, so binary search cannot be used).
    public function findCustomer(array $ledger, $text, &$steps = 0) {
        $text = trim($text);
        return Searching::linearSearchAll($ledger, function ($order) use ($text) {
            return stripos($order['CustomerName'], $text) !== false;
        }, $steps);
    }


    // Best sellers using a PRIORITY QUEUE (min-heap).
    // A min-heap gives the SMALLEST number first, so the priority is the units sold with a
    // minus sign: the biggest seller has the smallest (most negative) priority.
    // Every product is inserted, then the $limit best sellers are extracted.
    public function getTopSellers($limit = 5) {
        $rows = $this->pdo->query(
            "SELECT P.ProductName, SUM(OI.Quantity) AS UnitsSold, SUM(OI.Quantity * OI.UnitPrice) AS Revenue
             FROM OrderItem AS OI
             INNER JOIN CustomerOrder AS CO ON CO.OrderID = OI.OrderID
             INNER JOIN Product AS P ON P.ProductID = OI.ProductID
             WHERE CO.Status = 'Completed'
             GROUP BY P.ProductID, P.ProductName"
        )->fetchAll();

        $heap = new MinHeap();
        foreach ($rows as $row) {
            $heap->insert(-$row['UnitsSold'], $row);
        }
        $top = [];
        while (!$heap->isEmpty() && count($top) < $limit) {
            $entry = $heap->extractMin();
            $top[] = $entry[1];
        }
        return $top;
    }

    public function getCashiers() {
        return $this->pdo->query("SELECT StaffID, FullName, Username, IsActive FROM Staff WHERE Role = 'Cashier' ORDER BY FullName")->fetchAll();
    }

    private function emptySummary() {
        return ['completed' => 0, 'net' => 0, 'cash' => 0, 'online' => 0, 'voided' => 0, 'voidedAmount' => 0];
    }

    // Adds one grouped row (status + payment method) into a summary
    private function addToSummary(array &$summary, array $row) {
        if ($row['Status'] === 'Completed') {
            $summary['completed'] += $row['OrderCount'];
            $summary['net'] += $row['Total'];
            if ($row['PaymentMethod'] === 'Cash') {
                $summary['cash'] += $row['Total'];
            } else {
                $summary['online'] += $row['Total'];
            }
        } else {
            $summary['voided'] += $row['OrderCount'];
            $summary['voidedAmount'] += $row['Total'];
        }
    }

    // Totals for every cashier, plus orders that have no cashier recorded, plus a grand total
    public function getCashierSummary($from = '', $to = '') {
        $params = [];
        $dateSql = $this->dateFilter($from, $to, $params);
        $stmt = $this->pdo->prepare(
            "SELECT CashierID, Status, PaymentMethod, COUNT(*) AS OrderCount, SUM(TotalAmount) AS Total
             FROM CustomerOrder
             WHERE 1 = 1 $dateSql
             GROUP BY CashierID, Status, PaymentMethod"
        );
        $stmt->execute($params);

        $perCashier = [];     // keyed by CashierID
        $unassigned = $this->emptySummary();
        $grand = $this->emptySummary();

        foreach ($stmt->fetchAll() as $row) {
            $id = $row['CashierID'];
            $this->addToSummary($grand, $row);
            if ($id === null) {
                $this->addToSummary($unassigned, $row);
            } else {
                if (!isset($perCashier[$id])) {
                    $perCashier[$id] = $this->emptySummary();
                }
                $this->addToSummary($perCashier[$id], $row);
            }
        }
        return ['perCashier' => $perCashier, 'unassigned' => $unassigned, 'grand' => $grand, 'empty' => $this->emptySummary()];
    }
}
