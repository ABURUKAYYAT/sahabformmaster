<?php
$pageTitle = 'iSchool | Contact';
$activePage = 'contact';
$submitted = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = true;
}
require_once 'includes/landing-header.php';
?>

<main>
    <section class="container hero" style="grid-template-columns: 1fr;">
        <div data-reveal>
            <h1>Let us talk about your school.</h1>
            <p>Tell us about your needs and we will tailor a rollout plan that fits your team.</p>
        </div>
    </section>

    <section class="container" data-reveal>
        <div class="story-wrap">
            <div class="story-panel">
                <h2 class="section-title">Contact form</h2>
                <?php if ($submitted): ?>
                    <p>Thanks for reaching out. A member of our team will contact you shortly.</p>
                <?php endif; ?>
                <form method="POST" class="contact-form">
                    <label>
                        School name
                        <input type="text" name="school_name" required>
                    </label>
                    <label>
                        Your name
                        <input type="text" name="full_name" required>
                    </label>
                    <label>
                        Work email
                        <input type="email" name="email" required>
                    </label>
                    <label>
                        Role
                        <input type="text" name="role" placeholder="Principal, Teacher, Proprietor">
                    </label>
                    <label>
                        Message
                        <textarea name="message" rows="4" placeholder="Tell us about your school"></textarea>
                    </label>
                    <button class="btn btn-primary" type="submit">Send request</button>
                </form>
            </div>
            <aside class="proof-panel">
                <h3>Reach us directly</h3>
                <p><strong>Email:</strong> support@ischool.app</p>
                <p><strong>Phone:</strong> +234 800 000 0000</p>
                <p><strong>Office:</strong> 12 Learning Way, Lagos</p>
                <div style="margin-top: 16px;">
                    <p><strong>Hours:</strong> Mon - Fri, 9:00am - 6:00pm</p>
                </div>
            </aside>
        </div>
    </section>

    <section class="container" data-reveal>
        <div class="cta-band">
            <div>
                <h2 class="section-title">Need a quick demo?</h2>
                <p>We can schedule a walkthrough within 24 hours.</p>
            </div>
            <a class="btn btn-primary" href="pricing.php">View Pricing</a>
        </div>
    </section>
</main>

<?php require_once 'includes/landing-footer.php'; ?>
