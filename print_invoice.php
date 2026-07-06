<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    die("Unauthorized access.");
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid Invoice ID.");
}

require_once __DIR__ . "/config.php";
if ($conn->connect_error) {
    die("Database connection failed.");
}

$id = intval($_GET['id']);
$stmt = $conn->prepare("SELECT * FROM campaign_bookings WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Invoice not found.");
}

$b = $result->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #<?php echo $b['id']; ?> - GanaTube Ads</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 40px;
            color: #000;
            background: #fff;
            line-height: 1.6;
        }
        .invoice-container {
            max-width: 800px;
            margin: 0 auto;
            border: 1px solid #ccc;
            padding: 40px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #000;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            text-transform: uppercase;
        }
        .header .company-details {
            text-align: right;
            font-size: 14px;
            color: #555;
        }
        .billing-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
        }
        .billing-info div {
            width: 48%;
        }
        .billing-info h3 {
            margin-top: 0;
            margin-bottom: 10px;
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
            font-size: 16px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 12px;
            text-align: left;
        }
        th {
            background-color: #f9f9f9;
            font-weight: bold;
        }
        .total-row td {
            font-weight: bold;
            font-size: 18px;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border: 1px solid #000;
            border-radius: 4px;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 12px;
        }
        .footer {
            text-align: center;
            font-size: 12px;
            color: #777;
            margin-top: 50px;
            border-top: 1px solid #eee;
            padding-top: 20px;
        }
        
        @media print {
            body { padding: 0; }
            .invoice-container { border: none; padding: 0; }
            .no-print { display: none !important; }
            * { -webkit-print-color-adjust: exact !important; color-adjust: exact !important; }
        }
        
        .print-btn {
            display: block;
            margin: 0 auto 30px;
            padding: 10px 20px;
            background: #000;
            color: #fff;
            border: none;
            cursor: pointer;
            font-size: 16px;
            border-radius: 4px;
        }
    </style>
</head>
<body onload="window.print()">

    <button onclick="window.print()" class="print-btn no-print">Print Invoice</button>

    <div class="invoice-container">
        <div class="header">
            <div>
                <h1>INVOICE</h1>
                <p style="margin: 5px 0 0; font-size: 14px;"><strong>Invoice ID:</strong> #<?php echo str_pad($b['id'], 6, "0", STR_PAD_LEFT); ?></p>
                <p style="margin: 5px 0 0; font-size: 14px;"><strong>Date:</strong> <?php echo date('F d, Y', strtotime($b['created_at'])); ?></p>
                <p style="margin: 5px 0 0; font-size: 14px;"><strong>Status:</strong> <span class="status-badge"><?php echo htmlspecialchars($b['status']); ?></span></p>
            </div>
            <div class="company-details">
                <strong style="color:#000; font-size:18px;">GanaTube Ads Pro</strong><br>
                Advertising Department<br>
                contact@ganatube.com<br>
                www.ganatube.com
            </div>
        </div>

        <div class="billing-info">
            <div>
                <h3>Billed To</h3>
                <strong><?php echo htmlspecialchars($b['name']); ?></strong><br>
                Brand: <?php echo htmlspecialchars($b['brand_name']); ?><br>
                Email: <?php echo htmlspecialchars($b['email']); ?>
            </div>
            <div>
                <h3>Campaign Details</h3>
                <strong>Target Audience:</strong> <?php echo htmlspecialchars($b['target_audience']); ?><br>
                <strong>From:</strong> <?php echo date('M d, Y H:i', strtotime($b['start_date_time'])); ?><br>
                <strong>To:</strong> <?php echo date('M d, Y H:i', strtotime($b['end_date_time'])); ?>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Placements</th>
                    <th>Duration</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong>Advertising Campaign</strong><br>
                        <span style="font-size: 13px; color: #555;"><?php echo htmlspecialchars($b['ad_description'] ?? 'No description provided.'); ?></span><br>
                        <?php if(!empty($b['ad_link'])): ?>
                            <span style="font-size: 13px; color: #555;">Link: <?php echo htmlspecialchars($b['ad_link']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php 
                            $pnames = explode(',', $b['placement_id']);
                            foreach($pnames as $p) {
                                echo "• " . htmlspecialchars(trim($p)) . "<br>";
                            }
                        ?>
                    </td>
                    <td><?php echo htmlspecialchars($b['duration_hours']); ?> Hours</td>
                    <td>₹<?php echo number_format($b['total_price']); ?></td>
                </tr>
                <tr class="total-row">
                    <td colspan="3" style="text-align: right;">Total Amount Due</td>
                    <td>₹<?php echo number_format($b['total_price']); ?></td>
                </tr>
            </tbody>
        </table>

        <div class="footer">
            <p>Thank you for choosing GanaTube Ads. For any inquiries, please contact our support team.</p>
            <p>This is a computer-generated invoice and requires no signature.</p>
        </div>
    </div>

</body>
</html>
