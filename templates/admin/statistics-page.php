<?php
/**
 * Template: Page Statistiques — coûts et tokens
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$table = $wpdb->prefix . 'beaubot_messages';

// Grille tarifaire ($ par 1M tokens)
$pricing = [
    'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
    'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
];

// Données agrégées par jour et par modèle (1 an glissant)
$daily_stats = $wpdb->get_results(
    "SELECT DATE(created_at) as day, 
            COALESCE(model, 'unknown') as model,
            COUNT(*) as requests,
            COALESCE(SUM(tokens_input), 0) as total_input, 
            COALESCE(SUM(tokens_output), 0) as total_output
     FROM {$table} 
     WHERE role = 'assistant' 
       AND created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)
       AND tokens_input IS NOT NULL
     GROUP BY day, model
     ORDER BY day ASC",
    ARRAY_A
);

// KPIs du mois en cours
$month_start = gmdate('Y-m-01');
$month_stats = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT COALESCE(model, 'unknown') as model,
                COUNT(*) as requests,
                COALESCE(SUM(tokens_input), 0) as total_input,
                COALESCE(SUM(tokens_output), 0) as total_output
         FROM {$table}
         WHERE role = 'assistant'
           AND created_at >= %s
           AND tokens_input IS NOT NULL
         GROUP BY model",
        $month_start
    ),
    ARRAY_A
);

$total_requests_month = 0;
$total_cost_month = 0.0;
$total_tokens_month = 0;

foreach ($month_stats as $row) {
    $model_key = $row['model'];
    $rates = $pricing[$model_key] ?? $pricing['gpt-4o'];
    $cost = ($row['total_input'] / 1_000_000) * $rates['input']
          + ($row['total_output'] / 1_000_000) * $rates['output'];

    $total_requests_month += (int) $row['requests'];
    $total_cost_month += $cost;
    $total_tokens_month += (int) $row['total_input'] + (int) $row['total_output'];
}

$avg_cost_per_request = $total_requests_month > 0
    ? $total_cost_month / $total_requests_month
    : 0;

// Préparer les données pour Chart.js
$chart_data = [];
foreach ($daily_stats as $row) {
    $model_key = $row['model'];
    $rates = $pricing[$model_key] ?? $pricing['gpt-4o'];
    $cost = ($row['total_input'] / 1_000_000) * $rates['input']
          + ($row['total_output'] / 1_000_000) * $rates['output'];

    $chart_data[] = [
        'day'      => $row['day'],
        'model'    => $model_key,
        'cost'     => round($cost, 6),
        'requests' => (int) $row['requests'],
        'input'    => (int) $row['total_input'],
        'output'   => (int) $row['total_output'],
    ];
}
?>
<div class="wrap beaubot-admin-wrap" style="max-width: 1100px;">
    <h1>
        <span class="dashicons dashicons-chart-area"></span>
        <?php esc_html_e('BeauBot — Statistiques', 'beaubot'); ?>
    </h1>

    <!-- KPIs du mois -->
    <div class="beaubot-stats-kpis">
        <div class="beaubot-card beaubot-kpi">
            <div class="beaubot-kpi-value"><?php echo esc_html('$' . number_format($total_cost_month, 4)); ?></div>
            <div class="beaubot-kpi-label"><?php esc_html_e('Coût ce mois', 'beaubot'); ?></div>
        </div>
        <div class="beaubot-card beaubot-kpi">
            <div class="beaubot-kpi-value"><?php echo esc_html(number_format($total_requests_month)); ?></div>
            <div class="beaubot-kpi-label"><?php esc_html_e('Requêtes ce mois', 'beaubot'); ?></div>
        </div>
        <div class="beaubot-card beaubot-kpi">
            <div class="beaubot-kpi-value"><?php echo esc_html('$' . number_format($avg_cost_per_request, 5)); ?></div>
            <div class="beaubot-kpi-label"><?php esc_html_e('Coût moyen / requête', 'beaubot'); ?></div>
        </div>
        <div class="beaubot-card beaubot-kpi">
            <div class="beaubot-kpi-value"><?php echo esc_html(number_format($total_tokens_month)); ?></div>
            <div class="beaubot-kpi-label"><?php esc_html_e('Tokens ce mois', 'beaubot'); ?></div>
        </div>
    </div>

    <!-- Grille tarifaire -->
    <div class="beaubot-card">
        <h2><?php esc_html_e('Tarifs appliqués', 'beaubot'); ?></h2>
        <table class="widefat striped" style="max-width: 500px;">
            <thead>
                <tr>
                    <th><?php esc_html_e('Modèle', 'beaubot'); ?></th>
                    <th><?php esc_html_e('Input ($/1M)', 'beaubot'); ?></th>
                    <th><?php esc_html_e('Output ($/1M)', 'beaubot'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pricing as $model_name => $rates): ?>
                <tr>
                    <td><strong><?php echo esc_html($model_name); ?></strong></td>
                    <td>$<?php echo esc_html(number_format($rates['input'], 2)); ?></td>
                    <td>$<?php echo esc_html(number_format($rates['output'], 2)); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Graphique -->
    <div class="beaubot-card">
        <h2><?php esc_html_e('Coût quotidien par modèle (1 an glissant)', 'beaubot'); ?></h2>
        <?php if (empty($chart_data)): ?>
            <p class="description"><?php esc_html_e('Aucune donnée disponible. Les statistiques apparaîtront après les premières conversations avec le comptage de tokens activé.', 'beaubot'); ?></p>
        <?php else: ?>
            <div style="position: relative; height: 400px;">
                <canvas id="beaubot-cost-chart"></canvas>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.beaubot-stats-kpis {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 20px;
}

.beaubot-kpi {
    text-align: center;
    padding: 20px 16px !important;
}

.beaubot-kpi-value {
    font-size: 24px;
    font-weight: 700;
    color: var(--beaubot-primary, #6366f1);
    margin-bottom: 4px;
}

.beaubot-kpi-label {
    font-size: 13px;
    color: var(--beaubot-text-muted, #64748b);
}

@media screen and (max-width: 960px) {
    .beaubot-stats-kpis {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>

<script>
    var beaubotStatsData = <?php echo wp_json_encode($chart_data); ?>;
</script>
