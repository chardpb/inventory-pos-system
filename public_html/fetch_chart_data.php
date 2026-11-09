<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

try {
    require 'db_connect.php';
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $period = $_GET['period'] ?? 'week';
    $selected_branch = $_GET['branch_id'] ?? 'all';

    $branch_sql = "";
    if ($selected_branch !== 'all' && $selected_branch !== '' && $selected_branch !== '0') {
        $branch_id_clean = (int)$selected_branch;
        $branch_sql = " AND s.branch_id = $branch_id_clean ";
    }

    // Define Period SQL Filter for KPIs and Supporting Charts
    $period_sql = "";
    if ($period === 'month' || $period === 'monthly') {
        $period_sql = " AND MONTH(s.sale_date) = MONTH(CURDATE()) AND YEAR(s.sale_date) = YEAR(CURDATE()) ";
    } elseif ($period === 'year' || $period === 'yearly') {
        $period_sql = " AND YEAR(s.sale_date) = YEAR(CURDATE()) ";
    } else {
        // Weekly (Mon - Sun)
        $period_sql = " AND YEARWEEK(s.sale_date, 1) = YEARWEEK(CURDATE(), 1) ";
    }

    // 1. KPI Aggregations (Filtered by Period)
    $total_rev = 0; $total_qty = 0; $trans_count = 0;
    $rev_res = $conn->query("SELECT COALESCE(SUM(total_price), 0) as total_rev, COUNT(*) as trans_count, COALESCE(SUM(quantity), 0) as qty 
                             FROM daily_sales s 
                             WHERE 1=1 $period_sql $branch_sql");
    if ($rev_res && $rev_data = $rev_res->fetch_assoc()) {
        $total_rev = (float)$rev_data['total_rev'];
        $total_qty = (int)$rev_data['qty'];
        $trans_count = (int)$rev_data['trans_count'];
    }
    $aov = $trans_count > 0 ? round($total_rev / $trans_count, 2) : 0;

    $branch_cnt = 1;
    $b_res = $conn->query("SELECT COUNT(*) as b_cnt FROM branches WHERE status = 'Active'");
    if ($b_res && $b_row = $b_res->fetch_assoc()) {
        $branch_cnt = (int)$b_row['b_cnt'];
    }

    // 2. Dynamic Period & Single-Point Horizon Forecasting
    $final_labels = [];
    $actual_values = [];
    $forecast_values = [];

    if ($period === 'month' || $period === 'monthly') {
        $result = $conn->query("SELECT MONTHNAME(sale_date) as month_name, SUM(total_price) as total 
                                FROM daily_sales s 
                                WHERE YEAR(sale_date) = YEAR(CURDATE()) $branch_sql 
                                GROUP BY MONTH(sale_date) 
                                HAVING total > 0 
                                ORDER BY MONTH(sale_date) ASC");
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $final_labels[] = substr($row['month_name'], 0, 3);
                $actual_values[] = (float)$row['total'];
            }
        }

        $m_avg_rev = 0;
        $m_base_sql = "SELECT ROUND(AVG(monthly_total), 2) as avg_revenue 
                       FROM (
                           SELECT SUM(total_price) as monthly_total 
                           FROM daily_sales s 
                           WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 4 MONTH) $branch_sql 
                           GROUP BY YEAR(sale_date), MONTH(sale_date)
                       ) as sub_monthly";
        $m_base_res = $conn->query($m_base_sql);
        if ($m_base_res && $m_row = $m_base_res->fetch_assoc()) {
            $m_avg_rev = (float)($m_row['avg_revenue'] ?? 0);
        }

        $final_labels[] = 'Next Month';
        $actual_values[] = null;
        
        $forecast_values = array_fill(0, count($actual_values) - 1, null);
        $forecast_values[] = $m_avg_rev;

    } elseif ($period === 'year' || $period === 'yearly') {
        $result = $conn->query("SELECT MONTHNAME(sale_date) as month_name, SUM(total_price) as total 
                                FROM daily_sales s 
                                WHERE YEAR(sale_date) = YEAR(CURDATE()) $branch_sql 
                                GROUP BY MONTH(sale_date) 
                                HAVING total > 0 
                                ORDER BY MONTH(sale_date) ASC");
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $final_labels[] = substr($row['month_name'], 0, 3);
                $actual_values[] = (float)$row['total'];
            }
        } else {
            $final_labels = ['Jan'];
            $actual_values = [0];
        }

        $y_avg_rev = 0;
        $y_base_sql = "SELECT ROUND(AVG(yearly_total), 2) as avg_revenue 
                       FROM (
                           SELECT SUM(total_price) as yearly_total 
                           FROM daily_sales s 
                           WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 3 YEAR) $branch_sql 
                           GROUP BY YEAR(sale_date)
                       ) as sub_yearly";
        $y_base_res = $conn->query($y_base_sql);
        if ($y_base_res && $y_row = $y_base_res->fetch_assoc()) {
            $y_avg_rev = (float)($y_row['avg_revenue'] ?? 0);
        }

        $final_labels[] = 'Next Year';
        $actual_values[] = null;

        $forecast_values = array_fill(0, count($actual_values) - 1, null);
        $forecast_values[] = $y_avg_rev;

    } else {
        // Weekly View (Mon to Sun)
        $days_map = [2 => 'Mon', 3 => 'Tue', 4 => 'Wed', 5 => 'Thu', 6 => 'Fri', 7 => 'Sat', 1 => 'Sun'];
        $data_map = ['Mon' => 0, 'Tue' => 0, 'Wed' => 0, 'Thu' => 0, 'Fri' => 0, 'Sat' => 0, 'Sun' => 0];

        $result = $conn->query("SELECT DAYOFWEEK(sale_date) as day_num, SUM(total_price) as total 
                                FROM daily_sales s 
                                WHERE YEARWEEK(sale_date, 1) = YEARWEEK(CURDATE(), 1) $branch_sql 
                                GROUP BY day_num");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $d_num = (int)$row['day_num'];
                if (isset($days_map[$d_num])) {
                    $data_map[$days_map[$d_num]] = (float)$row['total'];
                }
            }
        }
        $final_labels = array_keys($data_map);
        $actual_values = array_values($data_map);

        $next_day_forecast = 0;
        $w_base_sql = "SELECT ROUND(AVG(daily_total), 2) as avg_day_sales 
                       FROM (
                           SELECT SUM(total_price) as daily_total 
                           FROM daily_sales s 
                           WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 28 DAY) $branch_sql
                           GROUP BY DATE(sale_date)
                       ) as sub_weekly";
        $w_base_res = $conn->query($w_base_sql);

        if ($w_base_res && $row = $w_base_res->fetch_assoc()) {
            $next_day_forecast = (float)($row['avg_day_sales'] ?? 0);
        }

        $final_labels[] = 'Next Day';
        $actual_values[] = null;

        $forecast_values = array_fill(0, count($actual_values) - 1, null);
        $forecast_values[] = $next_day_forecast;
    }

    // 3. Top Selling Brands (Filtered by Period)
    $top_products = ['labels' => [], 'swaptank_values' => [], 'new_values' => []];
    $tp_sql = "SELECT CONCAT(p.brand_name, ' ', p.tank_size) AS product_label, 
                      SUM(CASE WHEN s.sale_type = 'swap' OR s.sale_type = 'Swap Tank' OR s.sale_type IS NULL THEN s.quantity ELSE 0 END) as swaptank_qty,
                      SUM(CASE WHEN s.sale_type = 'new' OR s.sale_type = 'New Tank' OR s.sale_type = 'Buy New Tank' THEN s.quantity ELSE 0 END) as new_qty
               FROM daily_sales s 
               JOIN products p ON s.product_id = p.id 
               WHERE 1=1 $period_sql $branch_sql 
               GROUP BY s.product_id 
               ORDER BY SUM(s.quantity) DESC 
               LIMIT 5";
    $tp_res = $conn->query($tp_sql);

    if ($tp_res && $tp_res->num_rows > 0) {
        while ($row = $tp_res->fetch_assoc()) {
            $top_products['labels'][] = $row['product_label'];
            $top_products['swaptank_values'][] = (int)$row['swaptank_qty'];
            $top_products['new_values'][] = (int)$row['new_qty'];
        }
    } else {
        $top_products['labels'] = ['No Sales'];
        $top_products['swaptank_values'] = [0];
        $top_products['new_values'] = [0];
    }

    // 4. Tank Ratio (Filtered by Period)
    $tank_ratio = ['swaptank' => 0, 'new_tank' => 0];
    $tr_sql = "SELECT 
                SUM(CASE WHEN s.sale_type = 'swap' OR s.sale_type = 'Swap Tank' OR s.sale_type IS NULL THEN s.quantity ELSE 0 END) as swaptank_count,
                SUM(CASE WHEN s.sale_type = 'new' OR s.sale_type = 'New Tank' OR s.sale_type = 'Buy New Tank' THEN s.quantity ELSE 0 END) as new_count
               FROM daily_sales s 
               WHERE 1=1 $period_sql $branch_sql";
    $tr_res = $conn->query($tr_sql);
    if ($tr_res && $tr_row = $tr_res->fetch_assoc()) {
        $tank_ratio['swaptank'] = (int)($tr_row['swaptank_count'] ?? 0);
        $tank_ratio['new_tank'] = (int)($tr_row['new_count'] ?? 0);
    }

    // 5. Peak Hours (Filtered by Period)
    $peak_hours = array_fill(8, 12, 0);
    $ph_res = $conn->query("SELECT HOUR(sale_date) as hr, SUM(quantity) as total_qty 
                            FROM daily_sales s 
                            WHERE 1=1 $period_sql $branch_sql 
                            GROUP BY hr ORDER BY hr ASC");
    if ($ph_res) {
        while ($row = $ph_res->fetch_assoc()) {
            $hr = (int)$row['hr'];
            if (isset($peak_hours[$hr])) {
                $peak_hours[$hr] = (int)$row['total_qty'];
            }
        }
    }

    // 6. JSON Response
    echo json_encode([
        'cards' => [
            'revenue' => '₱' . number_format($total_rev, 2),
            'cylinders' => number_format($total_qty) . ' Units',
            'aov' => '₱' . number_format($aov, 2),
            'branches' => $branch_cnt . ($branch_cnt > 1 ? ' Branches' : ' Branch')
        ],
        'labels' => $final_labels,
        'actual_values' => $actual_values,
        'forecast_values' => $forecast_values,
        'top_products' => $top_products,
        'tank_ratio' => $tank_ratio,
        'peak_hours' => [
            'labels' => ['8 AM', '9 AM', '10 AM', '11 AM', '12 PM', '1 PM', '2 PM', '3 PM', '4 PM', '5 PM', '6 PM', '7 PM'],
            'values' => array_values($peak_hours)
        ]
    ]);

} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage(),
        'line' => $e->getLine()
    ]);
}