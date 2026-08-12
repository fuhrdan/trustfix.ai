</main>

<footer class="tf-footer">
    <div class="tf-footer-inner">
        <img src="images/7749CCB4-A449-4A1E-961A-6D6A38CE5E12.png" alt="TrustFix logo" class="tf-footer-logo">
        <div class="tf-footer-company">TRUSTFIX TECHNOLOGY CORP</div>
        <?php $footerSupportEmail = supportEmail(); ?>
        <a href="mailto:<?= htmlspecialchars($footerSupportEmail, ENT_QUOTES, 'UTF-8') ?>" class="tf-footer-email"><?= htmlspecialchars($footerSupportEmail, ENT_QUOTES, 'UTF-8') ?></a>
        <?php if (!empty($_SESSION['jwt_token'])): ?>
            <div><a href="support.php" class="tf-footer-email">Support Center</a></div>
        <?php endif; ?>
        <div class="tf-footer-copy">Copyright ©2026 All rights reserved | TrustFix</div>
    </div>
</footer>

</body>
</html>
