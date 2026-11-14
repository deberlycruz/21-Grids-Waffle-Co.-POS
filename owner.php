<?php
// owner.php
session_start();
if (($_SESSION['role'] ?? '') !== 'Owner') { header("Location: login.php"); exit(); }
require_once 'db.php';
require_once 'topbar.php';

use App\Core\Site;
use App\Core\DatabaseException;
use App\Core\OrderException;
use App\Services\ReportService;
use App\Services\PrivilegeCardService;
use App\Services\StaffService;

$ownerId = (int)($_SESSION['staff_id'] ?? 0);
$cardService = new PrivilegeCardService();
$staffService = new StaffService();
$modal = null;   // [title, message] shown in a window after an action
$tabs = ['overview' => 'Dashboard', 'cards' => 'Privilege Card Holders', 'find' => 'Find Loyal Customer', 'best' => 'Best Sellers', 'daily' => 'Daily Sales', 'ledger' => 'Audit Ledger', 'account' => 'My Account'];
$tab = $_GET['tab'] ?? 'overview';
if (isset($_POST['change_account'])) { $tab = 'account'; }
if (isset($_POST['award_card']) || isset($_POST['toggle_card']) || isset($_POST['edit_card'])) { $tab = 'cards'; }
if (isset($_GET['find']) && !isset($_GET['tab'])) { $tab = 'find'; }
if (!isset($tabs[$tab])) { $tab = 'overview'; }
if (!isset($tabs[$tab])) { $tab = 'overview'; }

// --- Owner actions: award a Privilege Card, stop/restart a card, change my own username/password ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['award_card'])) {
            $cardId = $cardService->award($_POST['holder_name'] ?? '', $ownerId);
            $modal = ['Privilege Card Awarded', trim($_POST['holder_name']) . ' now has Privilege Card ' . Site::cardNo($cardId) . '. The cashier can find the card by the name or the card number.'];
        } elseif (isset($_POST['edit_card'])) {
            $cardService->rename((int)($_POST['card_id'] ?? 0), $_POST['holder_name'] ?? '');
            $modal = ['Card Updated', 'The holder name on ' . Site::cardNo((int)($_POST['card_id'] ?? 0)) . ' was changed.'];
        } elseif (isset($_POST['toggle_card'])) {
            $cardService->toggleActive((int)($_POST['card_id'] ?? 0));
            $modal = ['Card Updated', 'A stopped card cannot be used at the cashier terminal, but its past sales are kept.'];
        } elseif (isset($_POST['change_account'])) {
            $staffService->changeOwnAccount($ownerId, $_POST['current_password'] ?? '', $_POST['new_username'] ?? '', $_POST['new_password'] ?? '', $_POST['new_password2'] ?? '');
            $modal = ['Account Updated', 'Your username' . (($_POST['new_password'] ?? '') !== '' ? ' and password were' : ' was') . ' changed. Use the new login the next time you sign in.'];
        }
    } catch (OrderException | DatabaseException $exception) {
        $modal = [$exception->getTitle(), $exception->getMessage()];
    }
}

$report = new ReportService();
$totals     = $report->getTotals();        // revenue, completed, voided
$dailySales = $report->getDailySales();    // every date that has sales
$ledger     = $report->getLedger();        // every order with its items, cashier and approver
$topSellers = $report->getTopSellers(5);   // PRIORITY QUEUE (min-heap): the 5 best-selling products
$buttons    = $report->getButtonTotals();  // totals of the four clickable buttons
$allOrders  = $ledger;                     // the full ledger, for the lists behind the buttons
$cardHolders = $cardService->holders();
$cardByName = [];
foreach ($cardHolders as $holderRow) { if ($holderRow['IsActive']) { $cardByName[strtolower($holderRow['HolderName'])] = $holderRow; } }
$me = null;
foreach ($staffService->getAll() as $staffRow) { if ((int)$staffRow['StaffID'] === $ownerId) { $me = $staffRow; } }

// Ledger tools: find a customer (LINEAR SEARCH) and sort the result (MERGE SORT)
$sortKey = in_array($_GET['sort'] ?? '', ['date', 'amount', 'customer', 'cashier', 'receipt'], true) ? $_GET['sort'] : 'date';
$sortDir = (($_GET['dir'] ?? 'desc') === 'asc') ? 'asc' : 'desc';
$find    = trim($_GET['find'] ?? '');
$totalRecords = count($ledger);
$searchSteps = 0;
$comparisons = 0;
$customerOrders = $ledger;
if ($find !== '') {
    $customerOrders = $report->findCustomer($ledger, $find, $searchSteps);   // linear search by name
}
$customers = $report->customerSummary($customerOrders);   // one row per customer, biggest spender first
$ordersOf = [];
foreach ($customerOrders as $oneOrder) { $ordersOf[strtolower($oneOrder['CustomerName'])][] = $oneOrder; }
$ledger = $report->sortLedger($ledger, $sortKey, $sortDir, $comparisons);

// The orders behind each button
$lists = [
    'completed' => ['title' => 'Completed Orders', 'color' => '#2e7d32', 'rows' => []],
    'voided'    => ['title' => 'Voided Orders', 'color' => '#b71c1c', 'rows' => []],
    'scpwd'     => ['title' => 'SC/PWD Discount Sales', 'color' => '#0d47a1', 'rows' => []],
    'card'      => ['title' => 'Privilege Card Sales', 'color' => '#6a1b9a', 'rows' => []],
];
foreach ($allOrders as $order) {
    if ($order['Status'] === 'Voided') { $lists['voided']['rows'][] = $order; continue; }
    $lists['completed']['rows'][] = $order;
    if ($order['AppliesTo'] === 'One Waffle and One Drink') { $lists['scpwd']['rows'][] = $order; }
    if ($order['AppliesTo'] === 'One Highest Item') { $lists['card']['rows'][] = $order; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars(Site::getBusinessName()); ?> | Owner Dashboard</title>
    <?php topbarCss(); ?>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #faf8f5; margin: 40px; }
        h2 { color: #5c3d2e; font-size: 30px; }
        .nav-link { background: #faf8f5; color: #5c3d2e; text-decoration: none; padding: 12px 24px; border-radius: 8px; font-weight: bold; font-size: 14px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: background 0.2s; }
        .nav-link:hover { background: #eee; }
        .analytics-flex { display: flex; gap: 30px; margin-top: 30px; }
        .stat-card { background: white; border: 3px solid #e8dec9; border-left: 10px solid #865d43; padding: 40px; border-radius: 16px; flex: 1; box-shadow: 0 8px 16px rgba(0,0,0,0.02); }
        .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 22px; margin-top: 30px; }
        .kpi-grid .span2 { grid-column: span 2; }
        .kpi-grid .stat-card { padding: 26px 30px; }
        .kpi-grid .stat-card p { font-size: 30px; margin-top: 12px; }
        .kpi-grid .stat-card small { display: block; margin-top: 6px; color: #777; font-size: 13px; }
        button.stat-card { text-align: left; cursor: pointer; font-family: inherit; width: 100%; transition: transform 0.1s, box-shadow 0.1s; }
        button.stat-card:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(0,0,0,0.08); }
        button.stat-card .more { display: block; margin-top: 10px; font-size: 12px; font-weight: bold; color: #865d43; }
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 9999; }
        .modal-box { background: white; border: 4px solid #5c3d2e; border-radius: 20px; width: 520px; max-width: 94%; padding: 30px; max-height: 92vh; overflow-y: auto; }
        .modal-box.wide { width: 1100px; }
        .modal-box table { margin-top: 10px; }
        .btn-s { border: none; border-radius: 8px; padding: 10px 16px; font-size: 13px; font-weight: bold; color: #fff; cursor: pointer; background: #865d43; }
        .box { background:#fdfaf4; padding:18px; border-radius:12px; border:2px dashed #865d43; display:flex; gap:12px; flex-wrap:wrap; align-items:end; }
        .box label { display:block; font-size:11px; color:#666; margin-bottom:4px; }
        .box input { padding:10px; font-size:13px; border:1px solid #ccc; border-radius:6px; }
        .stat-card h3 { margin: 0; font-size: 16px; color: #666; text-transform: uppercase; font-weight: bold; }
        .stat-card p { margin: 20px 0 0 0; font-size: 43px; font-weight: bold; color: #5c3d2e; }

        table { width: 100%; border-collapse: collapse; margin-top: 20px; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 8px 16px rgba(0,0,0,0.02); }
        th, td { border: 2px solid #ebdcb9; padding: 14px; text-align: left; font-size: 13px; }
        th { background: #f5eedc; color: #5c3d2e; }
        .section-title { color:#5c3d2e; font-size: 21px; margin: 50px 0 5px 0; }
        select.filter { padding: 12px; font-size: 14px; border-radius: 8px; border: 2px solid #ccc; margin-top: 10px; }
    
        /* a roomier drop-down arrow, not stuck to the edge */
        select { -webkit-appearance: none; appearance: none; padding-right: 40px !important;
                 background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'><path d='M1 1l5 5 5-5' fill='none' stroke='%235c3d2e' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/></svg>");
                 background-repeat: no-repeat; background-position: right 16px center; background-size: 12px 8px; background-color: #fff; }
        /* one pane at a time, so nothing is crowded in one long page */
        .topfix { position: sticky; top: 0; z-index: 1000; background: #5c3d2e; margin: -40px -40px 20px -40px; padding: 12px 30px 12px; box-shadow: 0 4px 8px rgba(0,0,0,0.12); }
        .toprow { display: flex; justify-content: space-between; align-items: center; }
        .toprow h2 { color: white; margin: 0; font-size: 21px; }
        .navrow { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .navrow .nav-link { border: none; cursor: pointer; font-family: inherit; padding: 9px 18px; font-size: 13px; background: rgba(255,255,255,0.14); color: #fff; box-shadow: none; }
        .navrow .nav-link:hover { background: rgba(255,255,255,0.28); }
        .navrow .nav-link.on { background: #faf8f5; color: #5c3d2e; }
        .tabbar-old { display: flex; flex-wrap: wrap; gap: 10px; margin: 18px 0 4px; }
        .tabbar button, .tabbar a { background: #fff; color: #5c3d2e; border: 2px solid #e8dec9; padding: 12px 22px; border-radius: 10px; font-weight: bold; font-size: 14px; cursor: pointer; text-decoration: none; font-family: inherit; }
        .tabbar button:hover, .tabbar a:hover { background: #f5eedc; }
        .tabbar button.on { background: #5c3d2e; color: #fff; border-color: #5c3d2e; }
        .pane { display: none; }
        .pane.on { display: block; }
        .pane > .section-title:first-child { margin-top: 18px; }
        .scroll { max-height: 58vh; overflow: auto; margin-top: 14px; border-radius: 12px; }
        .scroll table { margin-top: 0; }
        .scroll th { position: sticky; top: 0; z-index: 1; }
        .mhead { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 6px; }
        .mhead h3 { margin: 0; font-size: 22px; color: #5c3d2e; }
        .back { background: #5c3d2e; color: #fff; border: none; border-radius: 8px; padding: 10px 18px; font-size: 13px; font-weight: bold; cursor: pointer; white-space: nowrap; font-family: inherit; }
        .back:hover { background: #3f2a1f; }
        .toolbar { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin: 4px 0 6px; }
        .toolbar input { padding: 10px; font-size: 13px; border: 1px solid #ccc; border-radius: 6px; width: 320px; max-width: 100%; }
        .lbl { display: block; font-size: 12px; color: #666; margin: 10px 0 4px; }
        .wide-input { width: 100%; padding: 12px; font-size: 14px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; margin-bottom: 14px; }
        .listsearch { display: flex; gap: 10px; align-items: center; margin: 12px 0 0; flex-wrap: wrap; }
        .listsearch input { padding: 10px; font-size: 13px; border: 1px solid #ccc; border-radius: 6px; width: 320px; max-width: 100%; }
    </style>
    <script>
        // Filter the ledger by date (or show all)
        function showPane(name) {
            document.querySelectorAll('.pane').forEach(function (p) { p.classList.toggle('on', p.id === 'pane-' + name); });
            document.querySelectorAll('.navrow button').forEach(function (b) { b.classList.toggle('on', b.dataset.tab === name); });
            try { history.replaceState(null, '', 'owner.php?tab=' + name); } catch (e) {}
            window.scrollTo(0, 0);
        }
        // Search inside the window behind one of the four buttons (receipt, customer, item, ID or card)
        function searchList(key) {
            var text = document.getElementById('q_' + key).value.trim().toLowerCase();
            var from = document.getElementById('f_' + key).value, to = document.getElementById('t_' + key).value;
            var shown = 0;
            document.querySelectorAll('#list_' + key + ' tbody tr').forEach(function (row) {
                var day = row.getAttribute('data-date');
                var match = (text === '' || row.textContent.toLowerCase().indexOf(text) !== -1) && (from === '' || day >= from) && (to === '' || day <= to);
                row.style.display = match ? '' : 'none';
                if (match) { shown++; }
            });
            document.getElementById('n_' + key).textContent = shown + ' shown';
        }
        function filterHolders() {
            var text = document.getElementById('holderQ').value.trim().toLowerCase();
            document.querySelectorAll('#holderTable tbody tr').forEach(function (row) {
                row.style.display = (text === '' || row.textContent.toLowerCase().indexOf(text) !== -1) ? '' : 'none';
            });
        }
        function openEditCard(id, name, number) {
            document.getElementById('editCardId').value = id;
            document.getElementById('editHolder').value = name;
            document.getElementById('editNo').textContent = number;
            openList('m_edit');
        }
        // click a customer: show every order of that customer
        // click a date: show every sale of that day
        function openDay(date, label) {
            document.getElementById('dayName').textContent = label;
            document.getElementById('dayBody').innerHTML = document.getElementById('dd_' + date).innerHTML;
            openList('m_day');
        }
        function openCustomer(index, name) {
            document.getElementById('custName').textContent = name;
            document.getElementById('custBody').innerHTML = document.getElementById('co_' + index).innerHTML;
            openList('m_cust');
        }
        function clearList(key) { document.getElementById('q_' + key).value = ''; document.getElementById('f_' + key).value = ''; document.getElementById('t_' + key).value = ''; searchList(key); }
        function openList(id) { document.getElementById(id).style.display = 'flex'; }
        function closeList(id) { document.getElementById(id).style.display = 'none'; }
        function filterLedger() {
            var chosen = document.getElementById("dateFilter").value;
            var from = document.getElementById("ledFrom").value, to = document.getElementById("ledTo").value;
            var shown = 0;
            document.querySelectorAll("#ledgerBody tr").forEach(function (r) {
                var day = r.getAttribute("data-date");
                var ok = (chosen === "all" || day === chosen) && (from === "" || day >= from) && (to === "" || day <= to);
                r.style.display = ok ? "" : "none";
                if (ok) { shown++; }
            });
            document.getElementById("ledCount").textContent = shown + " shown";
        }
        function clearLedger() {
            document.getElementById("dateFilter").value = "all";
            document.getElementById("ledFrom").value = ""; document.getElementById("ledTo").value = "";
            filterLedger();
        }
    </script>
</head>
<body>

    <!-- FIXED TOP BAR: stays on screen while the page scrolls; every owner page is one button here -->
    <?php
    $ownerNav = [];
    foreach ($tabs as $key => $label) { $ownerNav[$key] = ['label' => $label, 'onclick' => "showPane('" . $key . "')"]; }
    $ownerNav['sales'] = ['label' => 'Sales by Cashier', 'href' => 'cashier_sales.php'];
    $ownerNav['voids'] = ['label' => 'Voided by Cashier', 'href' => 'voided_by_cashier.php'];
    topbar('Owner Dashboard', $ownerNav, $tab);
    ?>

    <div class="pane<?php echo $tab === 'overview' ? ' on' : ''; ?>" id="pane-overview">
    <div class="kpi-grid">
        <div class="stat-card span2">
            <h3>Net Active Revenue (Excluding Voids)</h3>
            <p>₱<?php echo number_format($totals['revenue'], 2); ?></p>
        </div>
        <div class="stat-card">
            <h3>Discounts Given</h3>
            <p>₱<?php echo number_format($totals['discounts'], 2); ?></p>
        </div>
        <div class="stat-card">
            <h3>Privilege Cards Awarded</h3>
            <p><?php echo count($cardHolders); ?></p>
        </div>
        <?php foreach ($lists as $key => $list): ?>
        <button type="button" class="stat-card" style="border-left-color: <?php echo $list['color']; ?>;" onclick="openList('list_<?php echo $key; ?>')">
            <h3><?php echo htmlspecialchars($list['title']); ?></h3>
            <p style="color: <?php echo $list['color']; ?>;"><?php echo $key === 'voided' ? '-' : ''; ?>₱<?php echo number_format($buttons[$key]['sales'], 2); ?></p>
            <small><?php echo (int)$buttons[$key]['orders']; ?> order(s)<?php echo ($key === 'scpwd' || $key === 'card') ? ' | discount given ₱' . number_format($buttons[$key]['discount'], 2) : ''; ?></small>
            <span class="more">Click to see the orders &rarr;</span>
        </button>
        <?php endforeach; ?>
    </div>

    </div>

    <div class="pane<?php echo $tab === 'cards' ? ' on' : ''; ?>" id="pane-cards">
        <h3 class="section-title">Privilege Card Holders</h3>
        <div class="toolbar">
            <input type="text" id="holderQ" placeholder="Search a holder by name or card no." oninput="filterHolders()">
            <button type="button" class="btn-s" onclick="filterHolders()">Search</button>
            <button type="button" class="btn-s" style="background:#6a1b9a;" onclick="openList('m_new')">+ Award New Card</button>
            <button type="button" class="btn-s" style="background:#0d47a1;" onclick="showPane('find')">Find a Loyal Customer</button>
        </div>
        <?php if (empty($cardHolders)): ?>
            <p style="color:#777; font-style:italic; font-size:14px;">No Privilege Card has been awarded yet. Use "Find a Loyal Customer" or "+ Award New Card".</p>
        <?php else: ?>
        <div class="scroll"><table id="holderTable">
            <thead><tr><th>Card no.</th><th>Holder</th><th>Awarded by</th><th>Date awarded</th><th>Times used</th><th>Discount given</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($cardHolders as $holderRow): ?>
                <tr style="<?php echo $holderRow['IsActive'] ? '' : 'color:#999; background:#fafafa;'; ?>">
                    <td><strong><?php echo Site::cardNo($holderRow['CardID']); ?></strong></td>
                    <td><?php echo htmlspecialchars($holderRow['HolderName']); ?></td>
                    <td><?php echo htmlspecialchars($holderRow['IssuedByName']); ?></td>
                    <td><?php echo htmlspecialchars(date('M d, Y', strtotime($holderRow['IssuedAt']))); ?></td>
                    <td><?php echo (int)$holderRow['TimesUsed']; ?></td>
                    <td>₱<?php echo number_format($holderRow['DiscountGiven'], 2); ?></td>
                    <td><?php echo $holderRow['IsActive'] ? 'Active' : 'Stopped'; ?></td>
                    <td style="white-space:nowrap;">
                        <button type="button" class="btn-s" style="background:#0277bd;" onclick="openEditCard(<?php echo (int)$holderRow['CardID']; ?>, <?php echo htmlspecialchars(json_encode($holderRow['HolderName']), ENT_QUOTES); ?>, '<?php echo Site::cardNo($holderRow['CardID']); ?>')">Edit</button>
                        <form method="POST" action="owner.php?tab=cards" style="margin:0; display:inline;" onsubmit="return askConfirm(event, this, '<?php echo $holderRow['IsActive'] ? 'Stop this card? It cannot be used at the terminal.' : 'Use this card again?'; ?>');">
                            <input type="hidden" name="card_id" value="<?php echo (int)$holderRow['CardID']; ?>">
                            <button type="submit" name="toggle_card" class="btn-s" style="background:<?php echo $holderRow['IsActive'] ? '#ef6c00' : '#2e7d32'; ?>;"><?php echo $holderRow['IsActive'] ? 'Stop card' : 'Activate'; ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <div class="pane<?php echo $tab === 'find' ? ' on' : ''; ?>" id="pane-find">
        <h3 class="section-title">Find Loyal Customer</h3>
        <form method="GET" action="owner.php" class="toolbar">
            <input type="hidden" name="tab" value="find">
            <input type="text" name="find" value="<?php echo htmlspecialchars($find); ?>" placeholder="Search a customer by name">
            <button type="submit" class="btn-s">Search</button>
            <?php if ($find !== ''): ?><a href="owner.php?tab=find" class="btn-s" style="background:#777; text-decoration:none;">Clear</a><?php endif; ?>
        </form>
        <p style="color:#777; font-size:11px; margin:6px 0 0;">Biggest spender first. Click a customer to see all of their orders.</p>
        <?php if (empty($customers)): ?>
            <p style="color:#777; font-style:italic;"><?php echo $find !== '' ? 'No customer with a completed order matches "' . htmlspecialchars($find) . '".' : 'No customers yet.'; ?></p>
        <?php else: ?>
        <div class="scroll"><table>
            <thead><tr><th>Customer</th><th>Completed orders</th><th>Total spent</th><th>Last visit</th><th>Privilege Card</th></tr></thead>
            <tbody>
            <?php foreach ($customers as $index => $person): $holder = $cardByName[strtolower($person['name'])] ?? null; ?>
                <tr style="cursor:pointer;" onclick="if (!event.target.closest('form')) { openCustomer(<?php echo $index; ?>, <?php echo htmlspecialchars(json_encode($person['name']), ENT_QUOTES); ?>); }">
                    <td><strong><?php echo htmlspecialchars($person['name']); ?></strong></td>
                    <td><?php echo (int)$person['orders']; ?></td>
                    <td style="font-weight:bold; color:#2e7d32;">₱<?php echo number_format($person['spent'], 2); ?></td>
                    <td><?php echo htmlspecialchars(date('M d, Y', strtotime($person['last']))); ?></td>
                    <td>
                        <?php if ($holder): ?>
                            <span style="background:#e1bee7; color:#6a1b9a; padding:5px 10px; border-radius:6px; font-weight:bold; font-size:11px;"><?php echo Site::cardNo($holder['CardID']); ?></span>
                        <?php else: ?>
                            <form method="POST" action="owner.php?tab=cards" style="margin:0;" onsubmit="return askConfirm(event, this, 'Award a Privilege Card to <?php echo htmlspecialchars(addslashes($person['name']), ENT_QUOTES); ?>?');">
                                <input type="hidden" name="holder_name" value="<?php echo htmlspecialchars($person['name']); ?>">
                                <button type="submit" name="award_card" class="btn-s" style="background:#6a1b9a;">Award Privilege Card</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php foreach ($customers as $index => $person): ?>
        <div id="co_<?php echo $index; ?>" style="display:none;"><table>
            <thead><tr><th>Receipt</th><th>Date &amp; Time</th><th>Type</th><th>Items Sold</th><th>Discount</th><th>Invoice Value</th><th>Payment</th><th>Cashier</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($ordersOf[strtolower($person['name'])] as $row): $isVoid = ($row['Status'] === 'Voided'); ?>
                <tr style="<?php echo $isVoid ? 'background:#fff5f5; color:#b71c1c;' : ''; ?>">
                    <td><strong>#<?php echo Site::receiptNo($row['OrderID']); ?></strong></td>
                    <td><?php echo htmlspecialchars($row['OrderDate']); ?></td>
                    <td><?php echo htmlspecialchars($row['OrderType']); ?></td>
                    <td><?php echo htmlspecialchars($row['Items'] !== '' ? $row['Items'] : '—'); ?></td>
                    <td><?php if ($row['DiscountAmount'] > 0): ?><?php echo htmlspecialchars($row['DiscountName']); ?><br><small>-₱<?php echo number_format($row['DiscountAmount'], 2); ?> (of ₱<?php echo number_format($row['SubtotalAmount'], 2); ?>)<br><?php echo $row['PrivilegeCardID'] ? 'Card: ' . Site::cardNo($row['PrivilegeCardID']) . ' ' . htmlspecialchars($row['CardHolder']) : 'ID: ' . htmlspecialchars($row['DiscountIDNumber']); ?><br><?php echo htmlspecialchars(implode('; ', $row['DiscountedItems'])); ?></small><?php else: ?>—<?php endif; ?></td>
                    <td style="font-weight:bold;"><?php echo $isVoid ? '-' : ''; ?>₱<?php echo number_format($row['TotalAmount'], 2); ?></td>
                    <td><?php echo htmlspecialchars($row['PaymentMethod']); ?><?php if (!empty($row['ReferenceNumber'])): ?><br><small>Ref: <?php echo htmlspecialchars($row['ReferenceNumber']); ?></small><?php endif; ?></td>
                    <td><?php echo htmlspecialchars($row['CashierName'] ?? '—'); ?></td>
                    <td><?php echo $isVoid ? '<strong>VOIDED</strong>' . (!empty($row['VoidReason']) ? '<br><small>Reason: ' . htmlspecialchars($row['VoidReason']) . '</small>' : '') : 'COMPLETED'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="pane<?php echo $tab === 'best' ? ' on' : ''; ?>" id="pane-best">
    <!-- BEST SELLERS (priority queue / min-heap) -->
    <h3 class="section-title">Best Sellers (Top 5)</h3>
    <p style="color:#777; font-size:11px; margin:0 0 5px;">Voided orders are not counted.</p>
    <?php if (empty($topSellers)): ?>
        <p style="color: #777; font-style: italic; font-size: 14px;">No sales recorded yet.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Rank</th><th>Product</th><th>Units Sold</th><th>Revenue</th></tr></thead>
            <tbody>
                <?php foreach ($topSellers as $rank => $top): ?>
                    <tr>
                        <td><strong>#<?php echo $rank + 1; ?></strong></td>
                        <td><?php echo htmlspecialchars($top['ProductName']); ?></td>
                        <td><?php echo (int)$top['UnitsSold']; ?></td>
                        <td style="font-weight:bold; color:#2e7d32;">₱<?php echo number_format($top['Revenue'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    </div>

    <div class="pane<?php echo $tab === 'daily' ? ' on' : ''; ?>" id="pane-daily">
    <!-- DAILY SALES BY DATE (ALL DATES AT ONCE) -->
    <h3 class="section-title">Daily Sales Summary (All Dates)</h3>
    <p style="color:#777; font-size:11px; margin:0 0 5px;">Click a date to see all of its sales.</p>
    <?php if (empty($dailySales)): ?>
        <p style="color: #777; font-style: italic; font-size: 14px;">No sales recorded yet.</p>
    <?php else: ?>
        <div class="scroll"><table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Completed Orders</th>
                    <th>Net Sales</th>
                    <th>Voided Orders</th>
                    <th>Voided Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dailySales as $day): ?>
                    <tr style="cursor:pointer;" onclick="openDay('<?php echo htmlspecialchars($day['SaleDate']); ?>', '<?php echo htmlspecialchars(date('M d, Y (D)', strtotime($day['SaleDate']))); ?>')">
                        <td><strong><?php echo htmlspecialchars(date('M d, Y (D)', strtotime($day['SaleDate']))); ?></strong></td>
                        <td><?php echo (int)$day['completed']; ?></td>
                        <td style="font-weight:bold; color:#2e7d32;">₱<?php echo number_format($day['net'], 2); ?></td>
                        <td><?php echo (int)$day['voided']; ?></td>
                        <td style="color:#b71c1c;">₱<?php echo number_format($day['voidedAmount'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:#f5eedc; font-weight:bold;">
                    <td>GRAND TOTAL</td>
                    <td><?php echo (int)$totals['completed']; ?></td>
                    <td style="color:#2e7d32;">₱<?php echo number_format($totals['revenue'], 2); ?></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table></div>
    <?php endif; ?>

    </div>

    <?php
    $ordersByDay = [];
    foreach ($allOrders as $oneOrder) { $ordersByDay[$oneOrder['SaleDate']][] = $oneOrder; }
    foreach ($dailySales as $day): $dayOrders = $ordersByDay[$day['SaleDate']] ?? []; ?>
    <div id="dd_<?php echo htmlspecialchars($day['SaleDate']); ?>" style="display:none;">
        <p style="margin:6px 0 10px; font-size:14px;">Completed orders: <strong><?php echo (int)$day['completed']; ?></strong> &nbsp;|&nbsp; Net sales: <strong style="color:#2e7d32;">₱<?php echo number_format($day['net'], 2); ?></strong> &nbsp;|&nbsp; Voided orders: <strong style="color:#b71c1c;"><?php echo (int)$day['voided']; ?> (₱<?php echo number_format($day['voidedAmount'], 2); ?>)</strong></p>
        <table>
            <thead><tr><th>Receipt</th><th>Time</th><th>Customer</th><th>Type</th><th>Items Sold</th><th>Discount</th><th>Invoice Value</th><th>Payment</th><th>Cashier</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($dayOrders as $row): $isVoid = ($row['Status'] === 'Voided'); ?>
                <tr style="<?php echo $isVoid ? 'background:#fff5f5; color:#b71c1c;' : ''; ?>">
                    <td><strong>#<?php echo Site::receiptNo($row['OrderID']); ?></strong></td>
                    <td><?php echo htmlspecialchars(substr($row['OrderDate'], 11)); ?></td>
                    <td><?php echo htmlspecialchars($row['CustomerName']); ?></td>
                    <td><?php echo htmlspecialchars($row['OrderType']); ?></td>
                    <td><?php echo htmlspecialchars($row['Items'] !== '' ? $row['Items'] : '—'); ?></td>
                    <td><?php if ($row['DiscountAmount'] > 0): ?><?php echo htmlspecialchars($row['DiscountName']); ?><br><small>-₱<?php echo number_format($row['DiscountAmount'], 2); ?> (of ₱<?php echo number_format($row['SubtotalAmount'], 2); ?>)<br><?php echo $row['PrivilegeCardID'] ? 'Card: ' . Site::cardNo($row['PrivilegeCardID']) . ' ' . htmlspecialchars($row['CardHolder']) : 'ID: ' . htmlspecialchars($row['DiscountIDNumber']); ?><br><?php echo htmlspecialchars(implode('; ', $row['DiscountedItems'])); ?></small><?php else: ?>—<?php endif; ?></td>
                    <td style="font-weight:bold;"><?php echo $isVoid ? '-' : ''; ?>₱<?php echo number_format($row['TotalAmount'], 2); ?></td>
                    <td><?php echo htmlspecialchars($row['PaymentMethod']); ?><?php if (!empty($row['ReferenceNumber'])): ?><br><small>Ref: <?php echo htmlspecialchars($row['ReferenceNumber']); ?></small><?php endif; ?></td>
                    <td><?php echo htmlspecialchars($row['CashierName'] ?? '—'); ?></td>
                    <td><?php echo $isVoid ? '<strong>VOIDED</strong>' . (!empty($row['VoidReason']) ? '<br><small>Reason: ' . htmlspecialchars($row['VoidReason']) . '</small>' : '') : 'COMPLETED'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>
    <div class="modal-overlay" id="m_day">
        <div class="modal-box wide">
            <div class="mhead"><h3>Sales on <span id="dayName"></span></h3><button type="button" class="back" onclick="closeList('m_day')">&larr; Back</button></div>
            <div class="scroll" id="dayBody"></div>
        </div>
    </div>

    <div class="pane<?php echo $tab === 'ledger' ? ' on' : ''; ?>" id="pane-ledger">
    <!-- FULL TRANSACTION LEDGER WITH DATE FILTER -->
    <h3 class="section-title" id="ledger">Live System Master Audit Ledger</h3>
    <?php if ($totalRecords > 0): ?>
        <form method="GET" action="owner.php" style="display:flex; gap:12px; flex-wrap:wrap; align-items:end; background:#fdfaf4; border:2px dashed #865d43; border-radius:10px; padding:14px 18px; margin:10px 0;">
            <input type="hidden" name="tab" value="ledger">
            <div><label style="display:block; font-size:11px; color:#666;">Sort by</label>
                <select name="sort" style="padding:10px; font-size:13px; border:1px solid #ccc; border-radius:6px;">
                    <?php foreach (['date' => 'Date', 'amount' => 'Amount', 'customer' => 'Customer', 'cashier' => 'Cashier', 'receipt' => 'Receipt number'] as $value => $label): ?>
                        <option value="<?php echo $value; ?>" <?php echo $sortKey === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div><label style="display:block; font-size:11px; color:#666;">Order</label>
                <select name="dir" style="padding:10px; font-size:13px; border:1px solid #ccc; border-radius:6px;">
                    <option value="desc" <?php echo $sortDir === 'desc' ? 'selected' : ''; ?>>Largest / newest first</option>
                    <option value="asc" <?php echo $sortDir === 'asc' ? 'selected' : ''; ?>>Smallest / oldest first</option>
                </select></div>
            <button type="submit" style="padding:11px 20px; font-size:13px; font-weight:bold; border:none; border-radius:8px; background:#865d43; color:white; cursor:pointer;">Apply</button>
            <a href="owner.php?tab=ledger" style="padding:11px 18px; font-size:13px; font-weight:bold; border-radius:8px; background:#777; color:white; text-decoration:none;">Reset</a>
        </form>
    <?php endif; ?>
    <?php if ($totalRecords === 0): ?>
        <p style="color: #777; font-style: italic; font-size: 14px;">No transaction history exists inside the relational database engine.</p>
    <?php elseif (empty($ledger)): ?>
        <p style="color: #777; font-style: italic; font-size: 14px;">No customer name contains "<?php echo htmlspecialchars($find); ?>".</p>
    <?php else: ?>
        <div style="display:flex; gap:14px; flex-wrap:wrap; align-items:flex-end; margin:12px 0 0;">
            <div><label class="lbl" style="margin:0 0 4px;">Filter by date</label>
            <select id="dateFilter" onchange="filterLedger();" style="height:42px; box-sizing:border-box; padding:0 40px 0 12px; font-size:13px; border:1px solid #ccc; border-radius:6px; margin:0; width:190px;">
                <option value="all">Show all dates</option>
                <?php foreach ($dailySales as $day): ?>
                    <option value="<?php echo htmlspecialchars($day['SaleDate']); ?>"><?php echo htmlspecialchars(date('M d, Y', strtotime($day['SaleDate']))); ?></option>
                <?php endforeach; ?>
            </select></div>
            <div><label class="lbl" style="margin:0 0 4px;">From date</label><input type="date" id="ledFrom" style="height:42px; box-sizing:border-box; width:170px; margin:0;" onchange="filterLedger();"></div>
            <div><label class="lbl" style="margin:0 0 4px;">To date</label><input type="date" id="ledTo" style="height:42px; box-sizing:border-box; width:170px; margin:0;" onchange="filterLedger();"></div>
            <button type="button" class="back" style="background:#777; height:42px; box-sizing:border-box; margin:0;" onclick="clearLedger();">Clear dates</button>
            <span id="ledCount" style="color:#777; font-size:12px; line-height:42px;"></span>
        </div>

        <div class="scroll"><table>
            <thead>
                <tr>
                    <th>Transaction ID</th>
                    <th>Date &amp; Time</th>
                    <th>Customer</th>
                    <th>Type</th>
                    <th>Items Sold</th>
                    <th>Discount</th>
                    <th>Invoice Value</th>
                    <th>Payment</th>
                    <th>Cashier</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody id="ledgerBody">
                <?php foreach ($ledger as $transaction): ?>
                    <tr data-date="<?php echo htmlspecialchars($transaction['SaleDate']); ?>" style="<?php echo ($transaction['Status'] === 'Voided') ? 'background: #fff5f5; color: #b71c1c;' : 'background: #fdfdfd; color: #333;'; ?>">
                        <td><strong>#<?php echo \App\Core\Site::receiptNo($transaction['OrderID']); ?></strong></td>
                        <td><?php echo htmlspecialchars($transaction['OrderDate']); ?></td>
                        <td><?php echo htmlspecialchars($transaction['CustomerName']); ?></td>
                        <td><?php echo htmlspecialchars($transaction['OrderType']); ?></td>
                        <td><?php echo htmlspecialchars($transaction['Items'] !== '' ? $transaction['Items'] : '—'); ?></td>
                        <td>
                            <?php if ($transaction['DiscountAmount'] > 0): ?>
                                <?php echo htmlspecialchars($transaction['DiscountName']); ?><br>
                                <small>-₱<?php echo number_format($transaction['DiscountAmount'], 2); ?> (of ₱<?php echo number_format($transaction['SubtotalAmount'], 2); ?>)<br>
                                <?php echo $transaction['PrivilegeCardID'] ? 'Card: ' . Site::cardNo($transaction['PrivilegeCardID']) . ' ' . htmlspecialchars($transaction['CardHolder']) : 'ID: ' . htmlspecialchars($transaction['DiscountIDNumber']); ?><br>
                                <?php echo htmlspecialchars(implode('; ', $transaction['DiscountedItems'])); ?></small>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td style="font-weight: bold;">
                            <?php echo ($transaction['Status'] === 'Voided') ? '-₱' : '₱'; ?><?php echo number_format($transaction['TotalAmount'], 2); ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($transaction['PaymentMethod']); ?>
                            <?php if (!empty($transaction['ReferenceNumber'])): ?><br><small>Ref: <?php echo htmlspecialchars($transaction['ReferenceNumber']); ?></small><?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($transaction['CashierName'] ?? '—'); ?></td>
                        <td>
                            <?php if ($transaction['Status'] === 'Voided'): ?>
                                <span style="background: #ffcdd2; color: #b71c1c; padding: 6px 12px; border-radius: 6px; font-weight: bold; font-size: 11px;">VOIDED / REVERSED</span>
                                <?php if (!empty($transaction['ApproverName'])): ?><br><small>Approved by <?php echo htmlspecialchars($transaction['ApproverName']); ?></small><?php endif; ?>
                                <?php if (!empty($transaction['VoidReason'])): ?><br><small>Reason: <?php echo htmlspecialchars($transaction['VoidReason']); ?></small><?php endif; ?>
                            <?php else: ?>
                                <span style="background: #c8e6c9; color: #2e7d32; padding: 6px 12px; border-radius: 6px; font-weight: bold; font-size: 11px;">COMPLETED</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>


    </div>

    <div class="pane<?php echo $tab === 'account' ? ' on' : ''; ?>" id="pane-account">
    <!-- MY ACCOUNT: the owner changes his own username and password here (the Admin cannot) -->
    <h3 class="section-title" id="account">My Account (Owner Login)</h3>
    <p style="color:#777; font-size:12px; margin:0 0 8px;">Only the owner can change this login, and only from this dashboard. Type your current password to confirm. Leave the new password empty to keep the old one.</p>
    <form method="POST" action="owner.php?tab=account" class="box" autocomplete="off">
        <div><label>New username (letters, numbers, _)</label><input type="text" name="new_username" required maxlength="30" value="<?php echo htmlspecialchars($me['Username'] ?? ''); ?>"></div>
        <div><label>New password (6+ characters)</label><input type="password" name="new_password"></div>
        <div><label>Type new password again</label><input type="password" name="new_password2"></div>
        <div><label>Current password *</label><input type="password" name="current_password" required></div>
        <button type="submit" name="change_account" class="btn-s" style="padding:12px 20px;">Save My Login</button>
    </form>

    </div>

    <!-- WINDOWS OF THE PRIVILEGE CARDS PAGE -->
    <div class="modal-overlay" id="m_new">
        <div class="modal-box">
            <div class="mhead"><h3>Award a New Privilege Card</h3><button type="button" class="back" onclick="closeList('m_new')">&larr; Back</button></div>
            <form method="POST" action="owner.php?tab=cards" autocomplete="off" onsubmit="return askConfirm(event, this, 'Award a Privilege Card to ' + this.holder_name.value + '?');">
                <label class="lbl">Customer name</label>
                <input type="text" name="holder_name" required maxlength="100" placeholder="e.g., Grace Tan" class="wide-input">
                <button type="submit" name="award_card" class="btn-s" style="background:#6a1b9a; width:100%; padding:13px;">Award Card</button>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="m_edit">
        <div class="modal-box">
            <div class="mhead"><h3>Edit Privilege Card <span id="editNo"></span></h3><button type="button" class="back" onclick="closeList('m_edit')">&larr; Back</button></div>
            <form method="POST" action="owner.php?tab=cards" autocomplete="off">
                <input type="hidden" name="card_id" id="editCardId">
                <label class="lbl">Holder name</label>
                <input type="text" name="holder_name" id="editHolder" required maxlength="100" class="wide-input">
                <button type="submit" name="edit_card" class="btn-s" style="background:#2e7d32; width:100%; padding:13px;">Save Changes</button>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="m_cust">
        <div class="modal-box wide">
            <div class="mhead"><h3>Orders of <span id="custName"></span></h3><button type="button" class="back" onclick="closeList('m_cust')">&larr; Back</button></div>
            <div class="scroll" id="custBody"></div>
        </div>
    </div>

    <!-- WINDOWS BEHIND THE FOUR BUTTONS -->
    <?php foreach ($lists as $key => $list): ?>
    <div class="modal-overlay" id="list_<?php echo $key; ?>">
        <div class="modal-box wide" style="border-color: <?php echo $list['color']; ?>;">
            <div class="mhead"><h3 style="color:<?php echo $list['color']; ?>;"><?php echo htmlspecialchars($list['title']); ?>: <?php echo $key === 'voided' ? '-' : ''; ?>₱<?php echo number_format($buttons[$key]['sales'], 2); ?> (<?php echo (int)$buttons[$key]['orders']; ?> orders)</h3>
                <button type="button" class="back" onclick="closeList('list_<?php echo $key; ?>')">&larr; Back</button>
            </div>
            <?php if ($key === 'scpwd'): ?><p style="color:#666; font-size:12px;">Senior Citizen / PWD: 5% off ONE highest-priced waffle and ONE highest-priced drink (1 piece each).</p><?php endif; ?>
            <?php if ($key === 'card'): ?><p style="color:#666; font-size:12px;">Privilege Card: 50% off ONLY the single highest-priced item (1 piece).</p><?php endif; ?>
            <?php if (empty($list['rows'])): ?>
                <p style="color:#777; font-style:italic;">No orders in this group yet.</p>
            <?php else: ?>
            <div class="listsearch">
                <input type="text" id="q_<?php echo $key; ?>" placeholder="Receipt, customer, item<?php echo ($key === 'scpwd' || $key === 'card') ? ', ' . ($key === 'card' ? 'card no.' : 'ID no.') : ''; ?>" onkeydown="if (event.key === 'Enter') { searchList('<?php echo $key; ?>'); }">
                <label style="font-size:12px; color:#666;">From <input type="date" id="f_<?php echo $key; ?>" style="width:auto;"></label>
                <label style="font-size:12px; color:#666;">To <input type="date" id="t_<?php echo $key; ?>" style="width:auto;"></label>
                <button type="button" class="btn-s" onclick="searchList('<?php echo $key; ?>')">Search</button>
                <button type="button" class="btn-s" style="background:#777;" onclick="clearList('<?php echo $key; ?>')">Clear</button>
                <span id="n_<?php echo $key; ?>" style="color:#777; font-size:12px;"><?php echo count($list['rows']); ?> shown</span>
            </div>
            <div class="scroll" style="max-height:52vh;"><table>
                <thead><tr><th>Receipt</th><th>Date &amp; time</th><th>Customer</th><th>Cashier</th><th>Items</th><?php if ($key !== 'completed' && $key !== 'voided'): ?><th><?php echo $key === 'card' ? 'Card' : 'ID no.'; ?></th><th>Items discounted</th><?php endif; ?><th>Subtotal</th><th>Discount</th><th>Total</th><?php if ($key === 'voided'): ?><th>Voided: reason / approved by</th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($list['rows'] as $row): ?>
                    <tr data-date="<?php echo htmlspecialchars(substr($row['OrderDate'], 0, 10)); ?>">
                        <td><strong>#<?php echo Site::receiptNo($row['OrderID']); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['OrderDate']); ?></td>
                        <td><?php echo htmlspecialchars($row['CustomerName']); ?></td>
                        <td><?php echo htmlspecialchars($row['CashierName'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($row['Items']); ?></td>
                        <?php if ($key !== 'completed' && $key !== 'voided'): ?>
                            <td><?php echo $row['PrivilegeCardID'] ? Site::cardNo($row['PrivilegeCardID']) . '<br><small>' . htmlspecialchars($row['CardHolder']) . '</small>' : htmlspecialchars($row['DiscountIDNumber']); ?></td>
                            <td><small><?php echo htmlspecialchars(implode('; ', $row['DiscountedItems'])); ?></small></td>
                        <?php endif; ?>
                        <td>₱<?php echo number_format($row['SubtotalAmount'], 2); ?></td>
                        <td><?php echo $row['DiscountAmount'] > 0 ? '-₱' . number_format($row['DiscountAmount'], 2) : '—'; ?></td>
                        <td style="font-weight:bold;">₱<?php echo number_format($row['TotalAmount'], 2); ?></td>
                        <?php if ($key === 'voided'): ?><td><small><?php echo htmlspecialchars(($row['VoidReason'] ?? '') . ' / ' . ($row['ApproverName'] ?? '')); ?></small></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if ($modal): ?>
    <div class="modal-overlay" style="display:flex;">
        <div class="modal-box" style="text-align:center;">
            <h3 style="margin-top:0;"><?php echo htmlspecialchars($modal[0]); ?></h3>
            <p style="background:#fdfaf4; padding:15px; border-left:6px solid #865d43; text-align:left;"><?php echo htmlspecialchars($modal[1]); ?></p>
            <button class="btn-s" style="background:#5c3d2e; width:100%; padding:14px;" onclick="window.location.href='owner.php?tab=<?php echo $tab; ?>';">Acknowledge</button>
        </div>
    </div>
    <?php endif; ?>

<?php include 'confirmbox.php'; ?>
<?php include 'footer.php'; ?>
</body>
</html>
