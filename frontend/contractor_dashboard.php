<?php
require 'config.php';
requireLogin();

$currentUser = apiRequest('GET', '/me');
$isAdmin = ($currentUser['role'] ?? '') === 'admin';
$selectedProfileId = max(0, (int)($_GET['contractor_profile_id'] ?? 0));
$approvedContractors = [];

if ($isAdmin) {
    $contractorResponse = apiRequest(
        'GET',
        '/admin/contractors?status=approved&per_page=100'
    );
    $approvedContractors = $contractorResponse['data'] ?? [];

    if (!$selectedProfileId && !empty($approvedContractors)) {
        $selectedProfileId = (int)($approvedContractors[0]['id'] ?? 0);
    }
}

$dashboardEndpoint = '/contractor/dashboard';
if ($isAdmin && $selectedProfileId) {
    $dashboardEndpoint .= '?' . http_build_query([
        'contractor_profile_id' => $selectedProfileId,
    ]);
}

$data = apiRequest('GET', $dashboardEndpoint);

if (($data['_http_code'] ?? 200) === 403) {
    include 'header.php';
    $status = $data['approval_status'] ?? 'not_submitted';
?>
    <div class="cd-shell">
        <section class="cd-empty">
            <span class="cd-kicker">Contractor access</span>
            <?php if ($isAdmin) { ?>
                <h1>No approved contractors are available</h1>
                <p>Approve a contractor from Manage Users, then return here to review their contractor dashboard.</p>
                <a class="cd-button" href="list_users.php">Manage users</a>
            <?php } else { ?>
                <h1>Your contractor dashboard is not active yet</h1>
                <p>Your current approval status is <strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $status))) ?></strong>. This dashboard opens automatically after approval.</p>
                <a class="cd-button" href="edit_profile.php">Review contractor profile</a>
            <?php } ?>
        </section>
    </div>
<?php
    include 'footer.php';
    exit;
}

if (!is_array($data) || !empty($data['error'])) {
    die('The contractor dashboard is temporarily unavailable.');
}

$profile = $data['profile'] ?? [];
$summary = $data['summary'] ?? [];
$jobs = $data['jobs'] ?? [];
$monthly = $data['monthly_earnings'] ?? [];
$payouts = $data['payouts'] ?? [];

function cdMoney($cents)
{
    return '$' . number_format(((int)$cents) / 100, 2);
}

function cdJobMoney($amount)
{
    return ($amount === null || $amount === '') ? 'Price pending' : '$' . number_format((float)$amount, 2);
}

function cdStatus($status)
{
    return ucwords(str_replace('_', ' ', (string)$status));
}

function cdTitle($description)
{
    $title = trim(strtok(trim((string)$description), "\n."));
    return $title ?: 'Trustfix Job';
}

$activeJobs = array_values(array_filter($jobs, fn($job) => in_array($job['status'] ?? '', ['accepted', 'scheduled', 'in_progress'], true)));
$historyJobs = array_values(array_filter($jobs, fn($job) => in_array($job['status'] ?? '', ['completed', 'cancelled', 'disputed'], true)));
$maxMonthly = 1;
foreach ($monthly as $point) {
    $maxMonthly = max($maxMonthly, (int)($point['net_cents'] ?? 0));
}

include 'header.php';
?>

<style>
    .cd-shell{max-width:1180px;margin:0 auto 50px;color:#18202a}
    .cd-hero{background:linear-gradient(135deg,#101820 0%,#1b3544 100%);color:#fff;border-radius:18px 18px 0 0;padding:30px 34px;display:flex;justify-content:space-between;gap:24px;align-items:center}
    .cd-kicker{color:#29c27f;text-transform:uppercase;letter-spacing:.12em;font-size:12px;font-weight:800}
    .cd-hero h1{margin:5px 0 3px;font-size:30px}.cd-hero p{margin:0;color:#c6d4dd}
    .cd-hero-stats{display:flex;gap:30px;text-align:center}.cd-hero-stats strong{display:block;font-size:26px}.cd-hero-stats span{font-size:12px;color:#c6d4dd}
    .cd-tabs{display:flex;gap:4px;background:#fff;border:1px solid #dce2e6;border-top:0;padding:0 24px;overflow:auto}
    .cd-tab{border:0;background:transparent;width:auto;padding:17px 18px;margin:0;color:#59636d;font-weight:700;cursor:pointer;border-bottom:3px solid transparent}
    .cd-tab.active{color:#13764f;border-bottom-color:#29c27f}
    .cd-panel{display:none;padding:28px;background:#f4f6f7;border:1px solid #dce2e6;border-top:0}.cd-panel.active{display:block}
    .cd-grid{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(300px,1fr);gap:24px}
    .cd-card{background:#fff;border:1px solid #dce2e6;border-radius:12px;padding:20px;box-shadow:0 2px 7px rgba(16,24,32,.05);margin-bottom:18px}
    .cd-card h2,.cd-card h3{margin-top:0}.cd-job{display:grid;grid-template-columns:110px minmax(0,1fr);gap:16px;padding:14px 0;border-bottom:1px solid #e7ebee}.cd-job:last-child{border-bottom:0}
    .cd-thumb{width:110px;height:82px;border-radius:8px;object-fit:cover;background:#e8ecee}.cd-placeholder{display:grid;place-items:center;color:#8a949c;font-size:12px}
    .cd-job-top{display:flex;justify-content:space-between;gap:12px}.cd-job h3{margin:0 0 5px;font-size:17px}.cd-meta{font-size:13px;color:#68737c}
    .cd-pill{display:inline-block;border-radius:99px;background:#e8f7f0;color:#116b48;padding:4px 9px;font-size:12px;font-weight:700;white-space:nowrap}
    .cd-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:10px}.cd-actions a{font-size:13px}
    .cd-button{display:inline-block;background:#16835a;color:#fff!important;border:0;border-radius:8px;padding:10px 15px;text-decoration:none;font-weight:700;cursor:pointer;width:auto}
    .cd-button.secondary{background:#263744}.cd-button.light{background:#e9f3ee;color:#126b49!important}
    .cd-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}.cd-metric{background:#fff;border:1px solid #dce2e6;border-radius:11px;padding:18px}.cd-metric strong{display:block;font-size:25px}.cd-metric span{color:#6d7780;font-size:13px}
    .cd-chart{height:240px;display:flex;align-items:end;gap:16px;padding:25px 10px 5px;border-bottom:1px solid #ccd4d9}.cd-bar-wrap{flex:1;text-align:center;min-width:42px}.cd-bar{background:linear-gradient(#29c27f,#16835a);border-radius:6px 6px 0 0;min-height:4px}.cd-bar-label{font-size:11px;color:#68737c;margin-top:7px}
    .cd-empty{background:#fff;border:1px dashed #b9c3c9;border-radius:12px;padding:35px;text-align:center}.cd-ready{color:#16835a}.cd-warn{color:#a65c00}
    .cd-admin-switcher{display:flex;justify-content:space-between;align-items:end;gap:20px;background:#fff7df;border:1px solid #ead49a;border-radius:12px;padding:16px 20px;margin-bottom:16px}
    .cd-admin-switcher label{display:block;font-weight:800;margin-top:3px}.cd-admin-switcher select{width:min(420px,100%);margin:0}
    @media(max-width:820px){.cd-grid{grid-template-columns:1fr}.cd-hero{align-items:flex-start;flex-direction:column}.cd-hero-stats{width:100%;justify-content:space-between}.cd-metrics{grid-template-columns:1fr}.cd-panel{padding:16px}.cd-job{grid-template-columns:80px minmax(0,1fr)}.cd-thumb{width:80px;height:68px}.cd-admin-switcher{align-items:stretch;flex-direction:column}}
</style>

<div class="cd-shell">
    <?php if ($isAdmin) { ?>
        <form method="GET" class="cd-admin-switcher">
            <div>
                <span class="cd-kicker">Administrator view</span>
                <label for="contractor_profile_id">Viewing contractor dashboard</label>
            </div>
            <select id="contractor_profile_id" name="contractor_profile_id" onchange="this.form.submit()">
                <?php foreach ($approvedContractors as $approvedContractor) { ?>
                    <option
                        value="<?= (int)($approvedContractor['id'] ?? 0) ?>"
                        <?= (int)($approvedContractor['id'] ?? 0) === $selectedProfileId ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars(
                            ($approvedContractor['business_name'] ?? 'Contractor') .
                            (!empty($approvedContractor['user']['name'])
                                ? ' — ' . $approvedContractor['user']['name']
                                : '')
                        ) ?>
                    </option>
                <?php } ?>
            </select>
        </form>
    <?php } ?>

    <header class="cd-hero">
        <div>
            <span class="cd-kicker">Approved contractor</span>
            <h1><?= htmlspecialchars($profile['business_name'] ?? 'Contractor Dashboard') ?></h1>
            <p>Jobs, performance, earnings, and payouts in one place.</p>
        </div>
        <div class="cd-hero-stats">
            <div><strong><?= (int)($summary['active_jobs'] ?? 0) ?></strong><span>Active jobs</span></div>
            <div><strong><?= (int)($summary['completed_jobs'] ?? 0) ?></strong><span>Completed</span></div>
            <div><strong><?= htmlspecialchars(cdMoney($summary['net_earnings_cents'] ?? 0)) ?></strong><span>Net earnings</span></div>
        </div>
    </header>

    <nav class="cd-tabs" aria-label="Contractor dashboard sections">
        <button class="cd-tab active" data-tab="overview">Overview</button>
        <button class="cd-tab" data-tab="jobs">Jobs</button>
        <button class="cd-tab" data-tab="analytics">Analytics</button>
        <button class="cd-tab" data-tab="earnings">Earnings</button>
        <button class="cd-tab" data-tab="payouts">Payouts</button>
    </nav>

    <section class="cd-panel active" id="cd-overview">
        <div class="cd-grid">
            <main>
                <div class="cd-card">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:15px">
                        <h2>Active work</h2><a href="available_jobs.php">Find jobs →</a>
                    </div>
                    <?php if (!$activeJobs) { ?><p>No active work right now. New accepted jobs will appear here.</p><?php } ?>
                    <?php foreach (array_slice($activeJobs, 0, 5) as $job) { include __DIR__ . '/contractor_job_row.php'; } ?>
                </div>
            </main>
            <aside>
                <div class="cd-card">
                    <h3>Payout account</h3>
                    <?php if (!empty($payouts['payouts_enabled'])) { ?>
                        <p class="cd-ready"><strong>✓ Ready for payouts</strong></p>
                    <?php } elseif ($isAdmin) { ?>
                        <p class="cd-warn"><strong>Contractor action required</strong></p>
                        <p>The contractor must sign in and complete their own secure Stripe onboarding.</p>
                    <?php } else { ?>
                        <p class="cd-warn"><strong>Action required</strong></p>
                        <p>Connect a bank account securely before customers can pay you through Trustfix.</p>
                        <form method="POST" action="start_contractor_onboarding.php"><button class="cd-button">Set up payouts</button></form>
                    <?php } ?>
                </div>
                <div class="cd-card">
                    <h3>Quick actions</h3>
                    <p><a href="available_jobs.php">Browse available jobs</a></p>
                    <p><a href="edit_profile.php">Update contractor profile</a></p>
                    <p><a href="#" data-open-tab="jobs">View job history</a></p>
                </div>
            </aside>
        </div>
    </section>

    <section class="cd-panel" id="cd-jobs">
        <div class="cd-card">
            <h2>All contractor jobs</h2>
            <p class="cd-meta">Open any historical job to review its workspace or add after-work photos.</p>
            <?php if (!$jobs) { ?><div class="cd-empty">You have not accepted any jobs yet.</div><?php } ?>
            <?php foreach ($jobs as $job) { include __DIR__ . '/contractor_job_row.php'; } ?>
        </div>
    </section>

    <section class="cd-panel" id="cd-analytics">
        <div class="cd-metrics">
            <div class="cd-metric"><strong><?= (int)($summary['total_jobs'] ?? 0) ?></strong><span>Total jobs</span></div>
            <div class="cd-metric"><strong><?= (int)($summary['completed_jobs'] ?? 0) ?></strong><span>Completed jobs</span></div>
            <div class="cd-metric"><strong><?= ($summary['total_jobs'] ?? 0) ? round((($summary['completed_jobs'] ?? 0) / $summary['total_jobs']) * 100) : 0 ?>%</strong><span>Completion rate</span></div>
        </div>
        <div class="cd-card" style="margin-top:18px"><h2>Performance notes</h2><p>Revenue analytics are based only on successful Trustfix payments. Cancelled and disputed jobs remain in history but do not count as earnings.</p></div>
    </section>

    <section class="cd-panel" id="cd-earnings">
        <div class="cd-metrics">
            <div class="cd-metric"><strong><?= htmlspecialchars(cdMoney($summary['gross_earnings_cents'] ?? 0)) ?></strong><span>Gross paid</span></div>
            <div class="cd-metric"><strong><?= htmlspecialchars(cdMoney($summary['platform_fees_cents'] ?? 0)) ?></strong><span>Trustfix fees</span></div>
            <div class="cd-metric"><strong><?= htmlspecialchars(cdMoney($summary['net_earnings_cents'] ?? 0)) ?></strong><span>Net earnings</span></div>
        </div>
        <div class="cd-card" style="margin-top:18px">
            <h2>Monthly net earnings</h2>
            <?php if (!$monthly) { ?><p>No successful payments to chart yet.</p><?php } else { ?>
                <div class="cd-chart">
                    <?php foreach ($monthly as $point) { $height = max(4, round(((int)$point['net_cents'] / $maxMonthly) * 190)); ?>
                        <div class="cd-bar-wrap" title="<?= htmlspecialchars(cdMoney($point['net_cents'])) ?>">
                            <div class="cd-bar" style="height:<?= (int)$height ?>px"></div>
                            <div class="cd-bar-label"><?= htmlspecialchars(date('M y', strtotime($point['month'] . '-01'))) ?></div>
                        </div>
                    <?php } ?>
                </div>
            <?php } ?>
        </div>
    </section>

    <section class="cd-panel" id="cd-payouts">
        <div class="cd-card">
            <h2>Stripe payout account</h2>
            <p>Trustfix uses Stripe Connect Express. Stripe securely collects your identity and bank information; Trustfix never stores your banking credentials.</p>
            <p><strong>Details submitted:</strong> <?= !empty($payouts['details_submitted']) ? 'Yes' : 'No' ?><br>
               <strong>Charges enabled:</strong> <?= !empty($payouts['charges_enabled']) ? 'Yes' : 'No' ?><br>
               <strong>Payouts enabled:</strong> <?= !empty($payouts['payouts_enabled']) ? 'Yes' : 'No' ?></p>
            <?php if ($isAdmin) { ?>
                <p class="cd-meta">Payout onboarding is read-only for administrators because the contractor must personally provide and verify banking information.</p>
            <?php } else { ?>
                <form method="POST" action="start_contractor_onboarding.php"><button class="cd-button"><?= !empty($payouts['details_submitted']) ? 'Update payout account' : 'Set up payout account' ?></button></form>
            <?php } ?>
        </div>
    </section>
</div>

<script>
document.querySelectorAll('.cd-tab').forEach(function(button) {
    button.addEventListener('click', function() {
        document.querySelectorAll('.cd-tab,.cd-panel').forEach(function(item) { item.classList.remove('active'); });
        button.classList.add('active');
        document.getElementById('cd-' + button.dataset.tab).classList.add('active');
    });
});
document.querySelectorAll('[data-open-tab]').forEach(function(link) {
    link.addEventListener('click', function(event) {
        event.preventDefault();
        document.querySelector('.cd-tab[data-tab="' + link.dataset.openTab + '"]').click();
    });
});
</script>

<?php include 'footer.php'; ?>
