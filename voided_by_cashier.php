<?php
// voided_by_cashier.php  (Owner and Admin: every voided order, for all cashiers or one cashier)
session_start();
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['Owner', 'Admin'], true)) { header("Location: login.php"); exit(); }
require_once 'db.php';
require_once 'topbar.php';

use App\Core\Site;
use App\Services\ReportService;

$report = new ReportService();

function cleanDate($dateText) { return (isset($dateText) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateText)) ? $dateText : ''; }
function peso($amount) { return '₱' . number_format((float)$amount, 2); }
function qs($cashier, $from, $to) { return '?' . http_build_query(array_filter(['cashier' => $cashier, 'from' => $from, 'to' => $to], fn($value) => $value !== '')); }

$from = cleanDate($_GET['from'] ?? '');
$to   = cleanDate($_GET['to'] ?? '');
$selected = $_GET['cashier'] ?? 'all';

$cashiers = $report->getCashiers();
$validIds = array_map(fn($cashier) => (string)$cashier['StaffID'], $cashiers);
if ($selected !== 'all' && !in_array((string)$selected, $validIds, true)) { $selected = 'all'; }

// every voided order in the date range, then grouped by the cashier who made it
$voided = array_values(array_filter($report->getLedger(null, $from, $to), fn($order) => $order['Status'] === 'Voided'));
$perCashier = [];
foreach ($cashiers as $cashier) { $perCashier[(int)$cashier['StaffID']] = ['count' => 0, 'amount' => 0]; }
$noCashier = ['count' => 0, 'amount' => 0];
foreach ($voided as $order) {
    $id = (int)($order['CashierID'] ?? 0);
    if (isset($perCashier[$id])) { $perCashier[$id]['count']++; $perCashier[$id]['amount'] += $order['TotalAmount']; }
    else { $noCashier['count']++; $noCashier['amount'] += $order['TotalAmount']; }
}
$shown = $voided; $selName = 'All cashiers';
if ($selected !== 'all') {
    $shown = array_values(array_filter($voided, fn($order) => (string)($order['CashierID'] ?? '') === (string)$selected));
    foreach ($cashiers as $cashier) { if ((string)$cashier['StaffID'] === (string)$selected) { $selName = $cashier['FullName']; } }
}
$shownAmount = array_sum(array_column($shown, 'TotalAmount'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars(Site::getBusinessName()); ?> | Voided by Cashier</title>
    <?php topbarCss(); ?>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #faf8f5; margin: 40px; color:#333; }
        .tabs { display:flex; gap:10px; flex-wrap:wrap; margin: 5px 0 20px; }
        .tab { padding: 14px 24px; border-radius: 10px; border: 3px solid #ebdcb9; background: white; color:#5c3d2e; font-weight: bold; font-size: 14px; text-decoration: none; }
        .tab:hover { background:#fdf6e6; }
        .tab.active { background:#5c3d2e; color:white; border-color:#5c3d2e; }
        .cards { display:flex; gap:20px; flex-wrap:wrap; margin-bottom: 10px; }
        .card { background:white; border:3px solid #e8dec9; border-left:10px solid #865d43; border-radius:14px; padding:20px 26px; flex:1; min-width:200px; }
        .card h4 { margin:0; font-size:11px; color:#666; text-transform:uppercase; }
        .card p { margin:10px 0 0; font-size:27px; font-weight:bold; color:#5c3d2e; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; margin-top: 10px; }
        th, td { border: 2px solid #ebdcb9; padding: 12px 16px; text-align: left; font-size: 13px; }
        th { background: #f5eedc; color: #5c3d2e; }
        tfoot td { background: #f5eedc; font-weight: bold; color: #5c3d2e; }
        .num { text-align: right; white-space: nowrap; }
        h3.sec { color:#5c3d2e; font-size: 19px; margin: 35px 0 5px; }
        .filter { display:flex; gap:12px; align-items:end; flex-wrap:wrap; background:#fdfaf4; padding:14px 18px; border-radius:10px; border:2px dashed #865d43; }
        .filter label { display:block; font-size:11px; color:#666; margin-bottom:3px; }
        .filter input { padding:9px; font-size:13px; border:1px solid #ccc; border-radius:6px; }
        .filter button, .filter a.reset { padding:10px 18px; font-size:12px; font-weight:bold; border:none; border-radius:8px; background:#865d43; color:white; cursor:pointer; text-decoration:none; }
        .filter a.reset { background:#777; }
        .badge { padding:4px 10px; border-radius:6px; font-size:11px; font-weight:bold; background:#ffcdd2; color:#b71c1c; }
    </style>
</head>
<body>
    <?php topbar($role === 'Admin' ? 'Admin Console' : 'Owner Dashboard', $role === 'Admin' ? adminNav() : ownerNavLinks(), 'voids'); ?>

    <div class="tabs">
        <a class="tab <?php echo $selected === 'all' ? 'active' : ''; ?>" href="<?php echo qs('all', $from, $to); ?>">All Cashiers</a>
        <?php foreach ($cashiers as $cashier): ?>
            <a class="tab <?php echo (string)$selected === (string)$cashier['StaffID'] ? 'active' : ''; ?>" href="<?php echo qs($cashier['StaffID'], $from, $to); ?>"><?php echo htmlspecialchars($cashier['FullName']); ?><?php echo $cashier['IsActive'] ? '' : ' <small>(inactive)</small>'; ?></a>
        <?php endforeach; ?>
    </div>

    <form method="GET" class="filter">
        <input type="hidden" name="cashier" value="<?php echo htmlspecialchars((string)$selected); ?>">
        <div><label>From date</label><input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>"></div>
        <div><label>To date</label><input type="date" name="to" value="<?php echo htmlspecialchars($to); ?>"></div>
        <button type="submit">Apply</button>
        <a class="reset" href="<?php echo qs($selected, '', ''); ?>">Show all dates</a>
        <span style="color:#777;"><?php echo ($from || $to) ? 'Showing ' . htmlspecialchars($from ?: 'the beginning') . ' to ' . htmlspecialchars($to ?: 'today') : 'Showing all dates'; ?></span>
    </form>

<?php if ($selected === 'all'): ?>
    <h3 class="sec">Voided orders: all cashiers compared</h3>
    <table>
        <thead><tr><th>Cashier</th><th class="num">Voided orders</th><th class="num">Voided amount</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($cashiers as $cashier): $row = $perCashier[(int)$cashier['StaffID']]; ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($cashier['FullName']); ?></strong> <small style="color:#999;">(<?php echo htmlspecialchars($cashier['Username']); ?>)</small></td>
                <td class="num" style="color:#b71c1c;"><?php echo (int)$row['count']; ?></td>
                <td class="num" style="color:#b71c1c;"><?php echo peso($row['amount']); ?></td>
                <td><a href="<?php echo qs($cashier['StaffID'], $from, $to); ?>" style="color:#865d43; font-weight:bold;">View voided →</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($noCashier['count'] > 0): ?>
            <tr style="color:#777;"><td><em>Older orders (no cashier recorded)</em></td><td class="num"><?php echo (int)$noCashier['count']; ?></td><td class="num"><?php echo peso($noCashier['amount']); ?></td><td></td></tr>
        <?php endif; ?>
        </tbody>
        <tfoot><tr><td>ALL CASHIERS TOTAL</td><td class="num"><?php echo count($voided); ?></td><td class="num"><?php echo peso(array_sum(array_column($voided, 'TotalAmount'))); ?></td><td></td></tr></tfoot>
    </table>
<?php else: ?>
    <h3 class="sec">Voided orders of <?php echo htmlspecialchars($selName); ?></h3>
    <div class="cards">
        <div class="card"><h4>Voided orders</h4><p style="color:#b71c1c;"><?php echo count($shown); ?></p></div>
        <div class="card"><h4>Voided amount</h4><p style="color:#b71c1c;"><?php echo peso($shownAmount); ?></p></div>
    </div>
<?php endif; ?>

    <h3 class="sec">Every voided order<?php echo $selected === 'all' ? ' (all cashiers)' : ' by ' . htmlspecialchars($selName); ?></h3>
    <?php if (empty($shown)): ?>
        <p style="color:#777; font-style:italic;">No voided orders found for this selection.</p>
    <?php else: ?>
    <table>
        <thead><tr><th>Receipt</th><th>Date &amp; time</th><th>Customer</th><th>Cashier</th><th>Items</th><th class="num">Amount</th><th>Reason</th><th>Approved by</th></tr></thead>
        <tbody>
        <?php foreach ($shown as $order): ?>
            <tr style="background:#fff5f5; color:#b71c1c;">
                <td><strong>#<?php echo Site::receiptNo($order['OrderID']); ?></strong></td>
                <td><?php echo htmlspecialchars($order['OrderDate']); ?></td>
                <td><?php echo htmlspecialchars($order['CustomerName']); ?></td>
                <td><?php echo htmlspecialchars($order['CashierName'] ?? '—'); ?></td>
                <td><?php echo htmlspecialchars($order['Items'] !== '' ? $order['Items'] : '—'); ?></td>
                <td class="num" style="font-weight:bold;"><?php echo peso($order['TotalAmount']); ?></td>
                <td><?php echo htmlspecialchars($order['VoidReason'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($order['ApproverName'] ?? ''); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
<?php include 'footer.php'; ?>
</body>
</html>
