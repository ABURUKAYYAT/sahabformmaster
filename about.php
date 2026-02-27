<?php
$pageTitle = 'iSchool | About';
$activePage = 'about';
require_once 'includes/landing-header.php';
?>

<main>
    <section class="container hero" style="grid-template-columns: 1fr;">
        <div data-reveal>
            <h1>Built for the people who run schools every day.</h1>
            <p>iSchool is a modern school management platform designed to give principals, teachers, and proprietors the visibility they need to lead confidently.</p>
        </div>
    </section>

    <section class="container">
        <h2 class="section-title" data-reveal>Our mission</h2>
        <p class="section-subtitle" data-reveal data-reveal-delay="80">To help schools run smarter by replacing fragmented systems with one trusted platform.</p>
        <div class="story-wrap" data-reveal data-reveal-delay="120">
            <div class="story-panel">
                <h3>What we solve</h3>
                <p>School leaders lose time reconciling attendance, results, and payments across spreadsheets. iSchool brings those workflows into one source of truth so decisions are faster and more confident.</p>
            </div>
            <div class="story-panel">
                <h3>How we work</h3>
                <p>We partner with school teams to map their workflows, migrate data safely, and train staff with tailored onboarding.</p>
            </div>
        </div>
    </section>

    <section class="container" data-reveal>
        <h2 class="section-title">Our values</h2>
        <div class="feature-grid">
            <div class="feature-card">
                <span>Clarity</span>
                <p>Every dashboard is designed to remove ambiguity and highlight what matters.</p>
            </div>
            <div class="feature-card">
                <span>Reliability</span>
                <p>Offline-ready tools and secure backups keep your operations moving.</p>
            </div>
            <div class="feature-card">
                <span>Partnership</span>
                <p>We train your teams and support your adoption from day one.</p>
            </div>
        </div>
    </section>

    <section class="container" data-reveal>
        <div class="cta-band">
            <div>
                <h2 class="section-title">Want to see iSchool in action?</h2>
                <p>Book a walkthrough with our onboarding team.</p>
            </div>
            <a class="btn btn-primary" href="contact.php">Request Demo</a>
        </div>
    </section>
</main>

<?php require_once 'includes/landing-footer.php'; ?>
