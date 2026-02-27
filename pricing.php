<?php
$pageTitle = 'iSchool | Pricing';
$activePage = 'pricing';
require_once 'includes/landing-header.php';
?>

<main>
    <section class="container hero" style="grid-template-columns: 1fr;">
        <div data-reveal>
            <h1>Pricing built for growing schools.</h1>
            <p>Simple packages that scale with your school. Choose a plan and we will customize onboarding, training, and support.</p>
            <div class="hero-actions">
                <a class="btn btn-primary" href="contact.php">Request Pricing</a>
                <a class="btn btn-outline" href="contact.php">Talk to Sales</a>
            </div>
        </div>
    </section>

    <section class="container">
        <h2 class="section-title" data-reveal>Choose the plan that matches your operations.</h2>
        <p class="section-subtitle" data-reveal data-reveal-delay="80">All plans include core modules, secure access, and onboarding support.</p>
        <div class="pricing-grid" data-reveal data-reveal-delay="120" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));">
            <div class="pricing-card">
                <strong>Starter</strong>
                <p>Ideal for small schools moving from paper to digital.</p>
                <ul>
                    <li>Attendance &amp; student profiles</li>
                    <li>Results and report cards</li>
                    <li>Basic analytics</li>
                </ul>
                <a class="btn btn-outline" href="contact.php">Request Quote</a>
            </div>
            <div class="pricing-card" style="border: 2px solid var(--teal-600);">
                <strong>Growth</strong>
                <p>Full operational visibility for medium-sized schools.</p>
                <ul>
                    <li>Fee management &amp; receipts</li>
                    <li>Teacher performance tools</li>
                    <li>Advanced reporting</li>
                </ul>
                <a class="btn btn-primary" href="contact.php">Request Quote</a>
            </div>
            <div class="pricing-card">
                <strong>Enterprise</strong>
                <p>Multi-campus control and dedicated support.</p>
                <ul>
                    <li>Multi-school dashboards</li>
                    <li>Custom workflows</li>
                    <li>Priority support SLA</li>
                </ul>
                <a class="btn btn-outline" href="contact.php">Contact Sales</a>
            </div>
        </div>
    </section>

    <section class="container" data-reveal>
        <div class="cta-band">
            <div>
                <h2 class="section-title">Need a tailored plan?</h2>
                <p>We build plans based on student count, staff size, and support requirements.</p>
            </div>
            <a class="btn btn-primary" href="contact.php">Book a Pricing Call</a>
        </div>
    </section>
</main>

<?php require_once 'includes/landing-footer.php'; ?>
